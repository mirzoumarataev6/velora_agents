<?php

namespace Tests\Support;

use App\Models\BiSource;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\ODS\Writer as OdsWriter;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xls as XlsWriter;

/**
 * Builds the real files ingestion has to cope with, and the BiSource rows that
 * point at them.
 *
 * REAL files, written by real writers, not strings pretending to be spreadsheets.
 * A hand-built `<xml>` blob would pass through OpenSpout's reader by accident or
 * fail for reasons that have nothing to do with what is being tested; a genuine
 * .xlsx exercises the shared-string table, the date format records and the zip
 * container the way a customer's export does.
 *
 * Files land under the local disk inside the test's own fake storage, at the same
 * `bi/{user}/sources/{id}` path production uses, so the normalizer is exercised
 * through exactly the path it takes in production rather than a shortcut.
 */
trait BuildsBiFixtures
{
    /**
     * A source whose staged file contains `$rows` as a delimited file.
     *
     * @param  array<int, array<int, mixed>>  $rows  Header row first.
     */
    protected function csvSource(User $user, array $rows, string $delimiter = ',', string $encoding = 'UTF-8', string $extension = 'csv'): BiSource
    {
        $lines = [];

        foreach ($rows as $row) {
            $cells = array_map(function ($cell) use ($delimiter) {
                $value = (string) $cell;

                // Quote only what needs it, the way a real exporter does.
                return str_contains($value, $delimiter) || str_contains($value, '"') || str_contains($value, "\n")
                    ? '"'.str_replace('"', '""', $value).'"'
                    : $value;
            }, $row);

            $lines[] = implode($delimiter, $cells);
        }

        $content = implode("\r\n", $lines)."\r\n";

        if ($encoding !== 'UTF-8') {
            $content = (string) mb_convert_encoding($content, $encoding, 'UTF-8');
        }

        return $this->sourceWithContent($user, $content, $extension);
    }

    /**
     * A real .xlsx (or .ods) workbook.
     *
     * @param  array<string, array<int, array<int, mixed>>>  $sheets  name => rows
     */
    protected function spreadsheetSource(User $user, array $sheets, string $extension = 'xlsx'): BiSource
    {
        $source = $this->emptySource($user, $extension);
        $path = Storage::disk('local')->path((string) $source->disk_path);

        @mkdir(dirname($path), 0777, true);

        $writer = $extension === 'ods' ? new OdsWriter : new XlsxWriter;
        $writer->openToFile($path);

        $first = true;

        foreach ($sheets as $name => $rows) {
            if ($first) {
                $writer->getCurrentSheet()->setName((string) $name);
                $first = false;
            } else {
                $writer->addNewSheetAndMakeItCurrent()->setName((string) $name);
            }

            foreach ($rows as $row) {
                $writer->addRow(new Row(array_map($this->spreadsheetCell(...), array_values($row))));
            }
        }

        $writer->close();

        $source->forceFill(['bytes' => (int) filesize($path)])->save();

        return $source;
    }

    /**
     * One cell, with a NUMBER FORMAT applied to dates.
     *
     * This is not decoration — it is the whole fidelity of the fixture. In both
     * .xlsx and .ods a date is stored as a plain serial number, and ONLY the cell's
     * number format marks it as a date. A DateTimeImmutable written without a
     * format round-trips as the integer 46082, so a fixture built that way would
     * test the wrong thing entirely: it would prove inference can read a number
     * column while claiming to prove it reads dates. Real exports always carry the
     * format, so the fixture must too.
     */
    private function spreadsheetCell(mixed $value): Cell
    {
        if (! $value instanceof \DateTimeInterface) {
            return Cell::fromValue($value);
        }

        $format = $value->format('H:i:s') === '00:00:00' ? 'yyyy-mm-dd' : 'yyyy-mm-dd hh:mm:ss';

        return new Cell\DateTimeCell($value, (new Style)->setFormat($format));
    }

    /**
     * A real legacy .xls workbook, which is the one format that cannot be streamed.
     *
     * @param  array<int, array<int, mixed>>  $rows
     */
    protected function legacyXlsSource(User $user, array $rows): BiSource
    {
        $source = $this->emptySource($user, 'xls');
        $path = Storage::disk('local')->path((string) $source->disk_path);

        @mkdir(dirname($path), 0777, true);

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();

        foreach ($rows as $rowIndex => $row) {
            foreach (array_values($row) as $columnIndex => $value) {
                $sheet->setCellValue([$columnIndex + 1, $rowIndex + 1], $value);
            }
        }

        (new XlsWriter($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        $source->forceFill(['bytes' => (int) filesize($path)])->save();

        return $source;
    }

    /**
     * @param  array<int|string, mixed>  $documents
     */
    protected function jsonSource(User $user, array $documents): BiSource
    {
        return $this->sourceWithContent(
            $user,
            (string) json_encode($documents, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            'json'
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $documents
     */
    protected function ndjsonSource(User $user, array $documents, string $trailing = ''): BiSource
    {
        $lines = array_map(
            fn ($document) => (string) json_encode($document, JSON_UNESCAPED_UNICODE),
            $documents
        );

        // `$trailing` exists for the truncated-final-line case, which is what a
        // log export interrupted mid-write actually looks like.
        return $this->sourceWithContent($user, implode("\n", $lines)."\n".$trailing, 'ndjson');
    }

    /** A source whose staged file holds exactly these bytes. */
    protected function sourceWithContent(User $user, string $content, string $extension): BiSource
    {
        $source = $this->emptySource($user, $extension);

        Storage::disk('local')->put((string) $source->disk_path, $content);

        $source->forceFill(['bytes' => strlen($content)])->save();

        return $source;
    }

    /** A source row pointing at a path that has not been written yet. */
    protected function emptySource(User $user, string $extension): BiSource
    {
        $source = new BiSource([
            'kind' => BiSource::KIND_FILE,
            'name' => "fixture.{$extension}",
            'original_filename' => "fixture.{$extension}",
            'extension' => $extension,
            'bytes' => 0,
            'status' => BiSource::STATUS_PENDING,
        ]);

        $source->user_id = $user->id;
        $source->save();

        $source->forceFill([
            'disk_path' => $source->storageDirectory().'/original.'.$extension,
        ])->save();

        return $source;
    }
}
