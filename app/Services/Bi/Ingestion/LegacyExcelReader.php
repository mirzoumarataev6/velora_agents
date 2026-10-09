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

/**
 * Legacy Excel (.xls, the BIFF8 binary format), read the only way it can be.
 *
 * There is no streaming reader for BIFF in PHP, in any library. The format
 * interleaves a shared string table, per-sheet records and a format table with
 * byte offsets that point backwards, so a parser genuinely cannot emit row one
 * before it has read the file — PhpSpreadsheet is not being wasteful here, it is
 * being correct. That makes this the one format whose ceiling is memory, and it
 * is therefore capped far lower than the others (`bi.max_legacy_xls_size_kb`) and
 * refused with its own error code so the owner is told to re-save as .xlsx rather
 * than being handed a fatal.
 *
 * Kept at all because it is what a decade-old 1C or accounting export still
 * produces, and telling those owners "convert it yourself first" would be
 * refusing the files most in need of a dashboard.
 *
 * Dates arrive as Excel serial numbers here, not as objects — so they are
 * converted to DateTimeImmutable on the way out, giving the rest of the pipeline
 * the same unambiguous shape SpreadsheetReader provides.
 */
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
            // PhpSpreadsheet spells this as a sheet STATE rather than a boolean,
            // with 'hidden' and 'veryHidden' both meaning the author did not mean
            // to publish it.
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

    /**
     * @return Generator<int, array<int, mixed>>
     */
    private function readRows(object $sheet): Generator
    {
        $highestColumn = (int) Coordinate::columnIndexFromString(
            $sheet->getHighestDataColumn()
        );

        foreach ($sheet->getRowIterator() as $row) {
            $cells = $row->getCellIterator();
            // Blank cells must still be yielded, or a row with a gap in the
            // middle would have every later value shifted one column left.
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

    /**
     * One cell's value, with a date cell turned into a DateTimeImmutable.
     *
     * An Excel date is a float counting days from 1900 (or 1904), and ONLY the
     * cell's number format says whether 45000 is the 18th of March or eighteen
     * thousand somethings. So the decision has to ask the cell, not the value —
     * which is why this cannot be a pure function of the number.
     *
     * Doing it here means the rest of the pipeline sees the same unambiguous
     * shape it gets from .xlsx, and DateFormatVoter is never asked to guess at a
     * date that the file already stated precisely.
     */
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
            // A serial outside Excel's representable range, or a corrupt format
            // record. The raw number is the honest fallback — it is what the
            // file actually contains.
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
            // Formatting, styles and charts are dead weight for an import that
            // only wants values, and they are a large share of what BIFF
            // parsing spends its memory on.
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
