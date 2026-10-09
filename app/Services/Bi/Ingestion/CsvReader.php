<?php

namespace App\Services\Bi\Ingestion;

use App\Services\Bi\BiIngestionException;
use Generator;
use League\Csv\CharsetConverter;
use League\Csv\Info;
use League\Csv\Reader as CsvDocument;
use Throwable;

/**
 * Delimited text: .csv, .tsv, .txt.
 *
 * Three things about a real-world CSV have to be got right before a single value
 * can be trusted, and none of them is stated anywhere in the file:
 *
 *  1. THE ENCODING. Every export from a Russian-language accounting system is
 *     Windows-1251, and reading it as UTF-8 gives a header row of mojibake that
 *     then becomes the column names the owner has to look at forever. Detected
 *     from a sample and converted through a stream filter, so a 100MB file is
 *     never held in memory to be converted.
 *  2. THE DELIMITER. Semicolon in the CIS (because Excel's Russian locale writes
 *     it), comma elsewhere, tab in database dumps, pipe occasionally. Chosen by
 *     counting fields across several lines rather than one — a single line can
 *     be won by a comma that only appears inside one quoted address.
 *  3. THE ROW WIDTH — which this reader deliberately does NOT normalize. Ragged
 *     rows are normal (a trailing empty column dropped by whoever saved it, or one
 *     extra field in a row that had a comma in a name), and they are padded,
 *     truncated and COUNTED by SourceNormalizer instead. Fitting them here too
 *     meant the normalizer never saw one, so the owner was never told.
 */
final class CsvReader implements TabularReader
{
    /** Bytes sampled for encoding and delimiter detection. */
    private const SNIFF_BYTES = 65536;

    /**
     * Lines the delimiter vote looks at. More than one, for the quoted-comma
     * case above; few enough that detection stays a single cheap read.
     */
    private const SNIFF_LINES = 12;

    private const DELIMITERS = [';', ',', "\t", '|'];

    /**
     * Encodings tried, most likely first. Windows-1251 before the Latin-1
     * family because this product's files come from Russian and Tajik
     * businesses, and 1251 and 8859-1 are indistinguishable by byte validity
     * alone — every byte is legal in both, so order IS the decision.
     */
    private const ENCODINGS = ['UTF-8', 'Windows-1251', 'KOI8-R', 'ISO-8859-1'];

    public function __construct(private readonly string $path) {}

    public function sheets(): iterable
    {
        // A delimited file is one table. The name is left empty so the caller
        // falls back to the filename, which is what the owner recognizes.
        yield new ReadSheet('', 0, fn (): Generator => $this->readRows());
    }

    public function close(): void
    {
        // Nothing retained between sheets: the document is opened inside
        // readRows() and released when its generator is collected.
    }

    /**
     * @return Generator<int, array<int, mixed>>
     */
    private function readRows(): Generator
    {
        $encoding = $this->detectEncoding();
        $document = $this->open($encoding);

        try {
            foreach ($document->getRecords() as $record) {
                $row = array_values($record);

                // Entirely blank rows are dropped here rather than counted as
                // skipped: a trailing newline, or the blank line a spreadsheet
                // leaves between two blocks, is not a row anybody meant to send
                // and reporting thousands of them as "skipped" would bury the
                // rejections that matter.
                if ($this->isBlankRow($row)) {
                    continue;
                }

                // Emitted at whatever width it was found. Fitting it to the
                // header's width is SourceNormalizer's job, deliberately: doing it
                // here as well meant the normalizer never saw a ragged row and so
                // could never report one, which turned "312 rows had a different
                // number of columns" into silence.
                yield $row;
            }
        } catch (BiIngestionException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new BiIngestionException(
                BiIngestionException::CORRUPT_FILE,
                'The delimited file could not be read: '.$e->getMessage(),
                $e,
            );
        }
    }

    /**
     * @param  array<int, mixed>  $row
     */
    private function isBlankRow(array $row): bool
    {
        foreach ($row as $cell) {
            if ($cell !== null && trim((string) $cell) !== '') {
                return false;
            }
        }

        return true;
    }

    private function open(string $encoding): CsvDocument
    {
        try {
            $document = CsvDocument::createFromPath($this->path, 'r');
        } catch (Throwable $e) {
            throw new BiIngestionException(
                BiIngestionException::FILE_UNREADABLE,
                'The uploaded file could not be opened: '.$e->getMessage(),
                $e,
            );
        }

        // Header handling is the normalizer's job, not the parser's: the header
        // row may need de-duplicating and blank-filling, and getRecords() with a
        // header offset would key every row by names we have not fixed yet.
        $document->setHeaderOffset(null);
        $document->setDelimiter($this->detectDelimiter($encoding));
        $document->skipInputBOM();

        if ($encoding !== 'UTF-8') {
            // A stream filter, so conversion happens chunk by chunk as the file
            // is read rather than by loading it.
            CharsetConverter::addTo($document, $encoding, 'UTF-8');
        }

        return $document;
    }

    /**
     * The encoding this file is most plausibly in.
     *
     * A BOM is proof and ends the question. Failing that, the first encoding in
     * which the sample is valid text wins — and because 1251 and 8859-1 both
     * accept every byte, this is a documented preference rather than a
     * detection. A file in neither (a .zip renamed to .csv, say) is refused
     * with ENCODING_UNREADABLE instead of producing a table of replacement
     * characters.
     */
    private function detectEncoding(): string
    {
        $sample = @file_get_contents($this->path, false, null, 0, self::SNIFF_BYTES);

        if ($sample === false) {
            throw new BiIngestionException(
                BiIngestionException::FILE_UNREADABLE,
                'The uploaded file could not be read from disk.'
            );
        }

        if ($sample === '') {
            throw new BiIngestionException(
                BiIngestionException::EMPTY_FILE,
                'The uploaded file is empty.'
            );
        }

        foreach ([["\xEF\xBB\xBF", 'UTF-8'], ["\xFF\xFE", 'UTF-16LE'], ["\xFE\xFF", 'UTF-16BE']] as [$bom, $encoding]) {
            if (str_starts_with($sample, $bom)) {
                return $encoding;
            }
        }

        // BINARY DETECTION, before any encoding is tried.
        //
        // This has to come first because it cannot be reached afterwards:
        // ISO-8859-1 accepts EVERY byte, so `mb_check_encoding` succeeds on a PNG,
        // a zip or an .exe and the file then parses as one long line of gibberish.
        // The owner's error was "no data rows found", which is true and useless —
        // the real answer is "this is not a text file at all".
        //
        // A NUL is proof. Failing that, a high proportion of control bytes is: real
        // delimited text contains almost none outside tab, CR and LF.
        if (str_contains($sample, "\0") || $this->looksBinary($sample)) {
            throw new BiIngestionException(
                BiIngestionException::ENCODING_UNREADABLE,
                'The file contains binary data and is not delimited text.'
            );
        }

        foreach (self::ENCODINGS as $encoding) {
            if (mb_check_encoding($sample, $encoding)) {
                return $encoding;
            }
        }

        throw new BiIngestionException(
            BiIngestionException::ENCODING_UNREADABLE,
            'The file is not valid text in any encoding this reader supports.'
        );
    }

    /**
     * Whether a sample carries more control bytes than any real text file would.
     *
     * Tab, carriage return and line feed are excluded — they are what a CSV is made
     * of. Everything else below 0x20, plus the C1 range, is a strong signal: a
     * legitimate export contains essentially none, while any compressed or image
     * format is full of them. 2% is far above what text reaches and far below what
     * binary does, so the threshold is not a fine judgement.
     */
    private function looksBinary(string $sample): bool
    {
        $length = strlen($sample);

        if ($length === 0) {
            return false;
        }

        $control = 0;

        for ($i = 0; $i < $length; $i++) {
            $byte = ord($sample[$i]);

            if (($byte < 0x20 && $byte !== 0x09 && $byte !== 0x0A && $byte !== 0x0D) || $byte === 0x7F) {
                $control++;
            }
        }

        return $control / $length > 0.02;
    }

    /**
     * The delimiter that yields the most fields, consistently, across a sample.
     *
     * league/csv's own statistics do the counting; the tie-break is ours. A file
     * where nothing splits produces one column, which is a legitimate answer —
     * a single-column list of SKUs is a real thing people upload.
     */
    private function detectDelimiter(string $encoding): string
    {
        try {
            $probe = CsvDocument::createFromPath($this->path, 'r');
            $probe->setHeaderOffset(null);
            $probe->skipInputBOM();

            if ($encoding !== 'UTF-8') {
                CharsetConverter::addTo($probe, $encoding, 'UTF-8');
            }

            $stats = Info::getDelimiterStats($probe, self::DELIMITERS, self::SNIFF_LINES);
        } catch (Throwable) {
            return ',';
        }

        $best = ',';
        $bestCount = 0;

        foreach (self::DELIMITERS as $delimiter) {
            $count = (int) ($stats[$delimiter] ?? 0);

            // Strictly greater, so DELIMITERS order breaks ties — semicolon
            // first, for the Excel-in-a-Russian-locale case.
            if ($count > $bestCount) {
                $best = $delimiter;
                $bestCount = $count;
            }
        }

        return $best;
    }
}
