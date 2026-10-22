<?php

namespace App\Services\Bi\Query;

use RuntimeException;
use Throwable;

class BiQueryException extends RuntimeException
{
    public const UNKNOWN_COLUMN = 'unknown_column';

    public const UNKNOWN_OPERATION = 'unknown_operation';

    public const NOT_NUMERIC = 'not_numeric';

    public const NOT_TEMPORAL = 'not_temporal';

    public const BAD_FILTER_VALUE = 'bad_filter_value';

    public const EMPTY_SPEC = 'empty_spec';

    public const TOO_MANY_FIELDS = 'too_many_fields';

    public const UNSORTABLE = 'unsortable';

    public const WIDGET_SHAPE = 'widget_shape';

    public const NOT_READY = 'not_ready';

    public const EXTERNAL_UNSUPPORTED = 'external_unsupported';

    public const TABLE_MISSING = 'table_missing';

    public const TIMEOUT = 'timeout';

    public const EXECUTION_FAILED = 'execution_failed';

    public const UNSAFE_SQL = 'unsafe_sql';

    public const TRANSIENT = [self::TIMEOUT, self::EXECUTION_FAILED, self::TABLE_MISSING];

    public function __construct(
        public readonly string $reason,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function isTransient(): bool
    {
        return in_array($this->reason, self::TRANSIENT, true);
    }

    public function translationKey(): string
    {
        return 'bi.errors.'.$this->reason;
    }

    public function detail(): string
    {
        $detail = preg_replace('#(/|\\\\)[^\s:,;\'"]+(/|\\\\)#', '', $this->getMessage()) ?? $this->getMessage();

        return mb_substr(trim($detail), 0, 500);
    }
}
