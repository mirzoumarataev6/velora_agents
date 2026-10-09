<?php

namespace App\Services\Bi;

use RuntimeException;
use Throwable;

/**
 * Every way ingestion can fail, as a classified code rather than a message.
 *
 * The code is the contract: it is stored on `bi_sources.error_code`, translated
 * through `bi.errors.{code}` into ru/tg/en, and asserted in tests. The message
 * this carries is for the log and for `error_detail`, never for the browser —
 * which is the whole point. A PhpSpreadsheet stack trace mentioning a temp path
 * is not an explanation, and "Что-то пошло не так" is not one either.
 *
 * `detail()` is the middle ground the owner actually needs: the technical
 * remainder, trimmed and stripped of anything that names our filesystem, shown
 * under the translated sentence for them to paste into a support message.
 */
class BiIngestionException extends RuntimeException
{
    /** The upload's extension is not one we can read at all. */
    public const UNSUPPORTED_FORMAT = 'unsupported_format';

    /** The file is larger than the configured budget. */
    public const FILE_TOO_LARGE = 'file_too_large';

    /** Legacy .xls specifically, which has a much lower ceiling. */
    public const LEGACY_XLS_TOO_LARGE = 'legacy_xls_too_large';

    /** The file vanished, or the disk refused to read it. */
    public const FILE_UNREADABLE = 'file_unreadable';

    /** Zero bytes, or nothing but whitespace. */
    public const EMPTY_FILE = 'empty_file';

    /** The reader opened it but it is not the format it claimed to be. */
    public const CORRUPT_FILE = 'corrupt_file';

    /** Bytes that are not valid text in any encoding we can detect. */
    public const ENCODING_UNREADABLE = 'encoding_unreadable';

    /** A header row was expected and the first row was entirely blank. */
    public const NO_HEADER = 'no_header';

    /** A header, but not one data row under it. */
    public const NO_ROWS = 'no_rows';

    /** Wider than `bi.ingest.max_columns` — a hard refusal, never a truncation. */
    public const TOO_MANY_COLUMNS = 'too_many_columns';

    /** More sheets in one workbook than the budget allows. */
    public const TOO_MANY_SHEETS = 'too_many_sheets';

    /** This owner already holds `max_datasets_per_owner` datasets. */
    public const TOO_MANY_DATASETS = 'too_many_datasets';

    /** JSON that parsed but is not a list of objects we can treat as rows. */
    public const NOT_TABULAR = 'not_tabular';

    /** The physical table name failed its own guard — a bug, never input. */
    public const UNSAFE_TABLE_NAME = 'unsafe_table_name';

    /**
     * A live connection could not be read. The driver's own classified reason is
     * carried in the detail line, so "wrong password" stays distinguishable from
     * "host unreachable" without this module having to mirror every one of
     * DatabaseConnectionException's cases.
     */
    public const CONNECTION_FAILED = 'connection_failed';

    /**
     * `$reason` rather than `$code`, matching DatabaseConnectionException — and for
     * the same unavoidable reason: `Exception::$code` already exists as a non-readonly
     * int, so a promoted `readonly string $code` is a fatal error at class-load time.
     * Which means it only fails the first time something actually throws one.
     */
    public function __construct(
        public readonly string $reason,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * The technical remainder shown to the owner beneath the translated
     * sentence.
     *
     * Absolute paths are stripped to their basename: the owner gains nothing
     * from knowing where on our disk their upload was staged, and it is exactly
     * the kind of detail that should not travel in a screenshot.
     */
    public function detail(): string
    {
        $detail = preg_replace('#(/|\\\\)[^\s:,;\'"]+(/|\\\\)#', '', $this->getMessage()) ?? $this->getMessage();

        return mb_substr(trim($detail), 0, 500);
    }

    /** The translation key whose sentence the owner is shown. */
    public function translationKey(): string
    {
        return 'bi.errors.'.$this->reason;
    }
}
