<?php

namespace App\Services\Bi;

use RuntimeException;
use Throwable;

class BiIngestionException extends RuntimeException
{
    public const UNSUPPORTED_FORMAT = 'unsupported_format';

    public const FILE_TOO_LARGE = 'file_too_large';

    public const LEGACY_XLS_TOO_LARGE = 'legacy_xls_too_large';

    public const FILE_UNREADABLE = 'file_unreadable';

    public const EMPTY_FILE = 'empty_file';

    public const CORRUPT_FILE = 'corrupt_file';

    public const ENCODING_UNREADABLE = 'encoding_unreadable';

    public const NO_HEADER = 'no_header';

    public const NO_ROWS = 'no_rows';

    public const TOO_MANY_COLUMNS = 'too_many_columns';

    public const TOO_MANY_SHEETS = 'too_many_sheets';

    public const TOO_MANY_DATASETS = 'too_many_datasets';

    public const NOT_TABULAR = 'not_tabular';

    public const UNSAFE_TABLE_NAME = 'unsafe_table_name';

    public const CONNECTION_FAILED = 'connection_failed';

    public function __construct(
        public readonly string $reason,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function detail(): string
    {
        $detail = preg_replace('#(/|\\\\)[^\s:,;\'"]+(/|\\\\)#', '', $this->getMessage()) ?? $this->getMessage();

        return mb_substr(trim($detail), 0, 500);
    }

    public function translationKey(): string
    {
        return 'bi.errors.'.$this->reason;
    }
}
