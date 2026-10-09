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

/**
 * Modern spreadsheets: .xlsx and .ods, read as a stream.
 *
 * Streaming is the whole reason this class exists instead of another call to
 * PhpSpreadsheet. Both formats are zipped XML, and PhpSpreadsheet builds the
 * entire workbook as an object graph before a caller sees row one — which for a
 * 60MB .xlsx is well past any sane memory_limit. OpenSpout pulls rows through an
 * XML reader and holds one at a time, so the ceiling becomes queue time rather
 * than memory.
 *
 * SHOULD_FORMAT_DATES is left OFF deliberately. A date cell then arrives as a
 * DateTimeImmutable rather than as a string formatted by whatever the workbook's
 * locale happened to be — which means an Excel date needs no format guessing at
 * all, and the one genuinely ambiguous case (is 03.04.2026 April or March?)
 * never arises for these formats. It arises only for text dates in CSV, where
 * DateFormatVoter has to vote on it. Turning formatting on would create the
 * ambiguity for no gain.
 *
 * Empty rows ARE preserved, so a row's position in the file is its position in
 * the sheet. Dropping them here would shift every later row number and make the
 * "row 4,812 could not be parsed" message point at the wrong line.
 */
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
                // A hidden sheet is almost always scratch work or a lookup table
                // the author did not mean to publish. Skipped rather than
                // refused: a workbook with three hidden helper sheets is normal
                // and should still import its visible one.
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

    /**
     * @return Generator<int, array<int, mixed>>
     */
    private function readRows(object $sheet): Generator
    {
        try {
            foreach ($sheet->getRowIterator() as $row) {
                // Row::toArray() returns FormulaCell::getValue(), which is the
                // formula text (`=SUM(...)`), not the result. Treating that as
                // business data made formulas appear as product names and text
                // columns. Use the value cached by Excel; OpenSpout does not
                // evaluate formulas, so an uncached formula becomes blank rather
                // than a fabricated number or a technical string in a chart.
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
            // The overwhelmingly common cause is a file that is not the format
            // its extension claims: a .csv renamed to .xlsx, or an HTML table
            // Excel saved with the wrong extension. Both are the owner's to fix,
            // and both need to be told apart from "our disk broke".
            throw new BiIngestionException(
                BiIngestionException::CORRUPT_FILE,
                'The file could not be opened as a spreadsheet: '.$e->getMessage(),
                $e,
            );
        }

        return $this->reader = $reader;
    }
}
