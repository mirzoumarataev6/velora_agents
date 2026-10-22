<?php

namespace App\Services\Bi\Ingestion;

use App\Services\Bi\BiIngestionException;
use Generator;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Reader\Xls as XlsSpreadsheetReader;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Throwable;

final class LegacyExcelReader implements TabularReader
{
    private ?Spreadsheet $spreadsheet = null;

    public function __construct(private readonly string $path) {}

    public function sheets(): iterable
    {
        $spreadsheet = $this->load();

        $maxSheets = max(1, (int) config('agent.bi.ingest.max_sheets', 25));
        $seen = 0;

        foreach ($spreadsheet->getAllSheets() as $index => $sheet) {

            if ($sheet->getSheetState() !== Worksheet::SHEETSTATE_VISIBLE) {
                continue;
            }

            if (++$seen > $maxSheets) {
                throw new BiIngestionException(
                    BiIngestionException::TOO_MANY_SHEETS,
                    "The workbook has more than {$maxSheets} visible sheets."
                );
            }

            yield new ReadSheet(
                (string) $sheet->getTitle(),
                (int) $index,
                fn (): Generator => $this->readRows($sheet),
            );
        }
    }

    private function readRows(object $sheet): Generator
    {
        $highestColumn = (int) Coordinate::columnIndexFromString(
            $sheet->getHighestDataColumn()
        );

        foreach ($sheet->getRowIterator() as $row) {
            $cells = $row->getCellIterator();

            $cells->setIterateOnlyExistingCells(false);

            $values = [];

            foreach ($cells as $cell) {
                if (count($values) >= $highestColumn) {
                    break;
                }

                $values[] = $this->cellValue($cell);
            }

            yield array_pad($values, $highestColumn, null);
        }
    }

    private function cellValue(object $cell): mixed
    {
        $value = $cell->getValue();

        if ($value instanceof RichText) {
            return $value->getPlainText();
        }

        if (! is_int($value) && ! is_float($value)) {
            return $value;
        }

        try {
            if (! ExcelDate::isDateTime($cell)) {
                return $value;
            }

            $date = ExcelDate::excelToDateTimeObject((float) $value);

            return \DateTimeImmutable::createFromMutable(
                $date instanceof \DateTime ? $date : \DateTime::createFromInterface($date)
            );
        } catch (Throwable) {

            return $value;
        }
    }

    public function close(): void
    {
        $this->spreadsheet?->disconnectWorksheets();
        $this->spreadsheet = null;
    }

    private function load(): Spreadsheet
    {
        if ($this->spreadsheet instanceof Spreadsheet) {
            return $this->spreadsheet;
        }

        $maxKb = max(1, (int) config('agent.bi.ingest.max_legacy_xls_size_kb', 15 * 1024));
        $bytes = @filesize($this->path);

        if ($bytes === false) {
            throw new BiIngestionException(
                BiIngestionException::FILE_UNREADABLE,
                'The uploaded file could not be read from disk.'
            );
        }

        if ($bytes > $maxKb * 1024) {
            throw new BiIngestionException(
                BiIngestionException::LEGACY_XLS_TOO_LARGE,
                'A .xls workbook of '.round($bytes / 1048576, 1)."MB is over the {$maxKb}KB limit for this format."
            );
        }

        try {
            $reader = new XlsSpreadsheetReader;

            $reader->setReadDataOnly(true);

            return $this->spreadsheet = $reader->load($this->path);
        } catch (Throwable $e) {
            throw new BiIngestionException(
                BiIngestionException::CORRUPT_FILE,
                'The file could not be opened as a legacy Excel workbook: '.$e->getMessage(),
                $e,
            );
        }
    }
}
