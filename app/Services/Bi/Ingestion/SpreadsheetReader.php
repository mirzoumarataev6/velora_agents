<?php

namespace App\Services\Bi\Ingestion;

use App\Services\Bi\BiIngestionException;
use Generator;
use OpenSpout\Common\Entity\Cell\FormulaCell;
use OpenSpout\Reader\ODS\Options as OdsOptions;
use OpenSpout\Reader\ODS\Reader as OdsSpoutReader;
use OpenSpout\Reader\ReaderInterface;
use OpenSpout\Reader\XLSX\Options as XlsxOptions;
use OpenSpout\Reader\XLSX\Reader as XlsxSpoutReader;
use Throwable;

final class SpreadsheetReader implements TabularReader
{
    private ?ReaderInterface $reader = null;

    public function __construct(
        private readonly string $path,
        private readonly bool $isOds,
    ) {}

    public function sheets(): iterable
    {
        $reader = $this->reader();

        $maxSheets = max(1, (int) config('agent.bi.ingest.max_sheets', 25));
        $seen = 0;

        try {
            foreach ($reader->getSheetIterator() as $sheet) {

                if (method_exists($sheet, 'isVisible') && ! $sheet->isVisible()) {
                    continue;
                }

                if (++$seen > $maxSheets) {
                    throw new BiIngestionException(
                        BiIngestionException::TOO_MANY_SHEETS,
                        "The workbook has more than {$maxSheets} visible sheets."
                    );
                }

                yield new ReadSheet(
                    (string) $sheet->getName(),
                    (int) $sheet->getIndex(),
                    fn (): Generator => $this->readRows($sheet),
                );
            }
        } catch (BiIngestionException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new BiIngestionException(
                BiIngestionException::CORRUPT_FILE,
                'The spreadsheet could not be read: '.$e->getMessage(),
                $e,
            );
        }
    }

    private function readRows(object $sheet): Generator
    {
        try {
            foreach ($sheet->getRowIterator() as $row) {

                $values = [];
                foreach ($row->getCells() as $cell) {
                    $values[] = $cell instanceof FormulaCell
                        ? $cell->getComputedValue()
                        : $cell->getValue();
                }

                yield array_values($values);
            }
        } catch (Throwable $e) {
            throw new BiIngestionException(
                BiIngestionException::CORRUPT_FILE,
                'A sheet could not be read to the end: '.$e->getMessage(),
                $e,
            );
        }
    }

    public function close(): void
    {
        $this->reader?->close();
        $this->reader = null;
    }

    private function reader(): ReaderInterface
    {
        if ($this->reader instanceof ReaderInterface) {
            return $this->reader;
        }

        if ($this->isOds) {
            $options = new OdsOptions;
            $options->SHOULD_FORMAT_DATES = false;
            $options->SHOULD_PRESERVE_EMPTY_ROWS = true;
            $reader = new OdsSpoutReader($options);
        } else {
            $options = new XlsxOptions;
            $options->SHOULD_FORMAT_DATES = false;
            $options->SHOULD_PRESERVE_EMPTY_ROWS = true;
            $reader = new XlsxSpoutReader($options);
        }

        try {
            $reader->open($this->path);
        } catch (Throwable $e) {

            throw new BiIngestionException(
                BiIngestionException::CORRUPT_FILE,
                'The file could not be opened as a spreadsheet: '.$e->getMessage(),
                $e,
            );
        }

        return $this->reader = $reader;
    }
}
