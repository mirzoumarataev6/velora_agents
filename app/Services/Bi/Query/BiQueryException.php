<?php

namespace App\Services\Bi\Query;

use RuntimeException;
use Throwable;

/**
 * Every way a BI query can be refused or fail, as a classified code.
 *
 * Same contract as BiIngestionException, and deliberately a SEPARATE type rather
 * than more cases on that one: ingestion failures are about a file and are stored
 * on a row for the owner to find later, whereas these are about a request and are
 * answered in the response. Folding them together would mean a chart error could
 * be written to `bi_sources.error_code`, where nothing would ever clear it.
 *
 * The split between "refused" and "failed" below matters to the caller: a refusal
 * is the spec's fault and will fail identically forever, so retrying is pointless
 * and the UI should say what is wrong. A failure is the server's, and may not
 * happen again.
 */
class BiQueryException extends RuntimeException
{
    // --- Refusals: the spec is wrong, and will be wrong next time too. ---

    /** A spec named a column that is not in this dataset's `bi_columns`. */
    public const UNKNOWN_COLUMN = 'unknown_column';

    /** An aggregate, operator, grain or direction outside its closed set. */
    public const UNKNOWN_OPERATION = 'unknown_operation';

    /** SUM or AVG on something that is not a number. */
    public const NOT_NUMERIC = 'not_numeric';

    /** A time grain asked of a column that is not a date. */
    public const NOT_TEMPORAL = 'not_temporal';

    /** A filter given the wrong number of values for its operator. */
    public const BAD_FILTER_VALUE = 'bad_filter_value';

    /** Nothing to select: no measures and no dimensions. */
    public const EMPTY_SPEC = 'empty_spec';

    /** More dimensions or measures than `bi.query.max_fields` allows. */
    public const TOO_MANY_FIELDS = 'too_many_fields';

    /** Sorting by something the query does not actually select. */
    public const UNSORTABLE = 'unsortable';

    /**
     * The spec is a valid question, but not one this widget type can draw.
     *
     * A refusal about PRESENTATION rather than about data — a donut with two
     * measures is a perfectly good query and an impossible pie. Kept here with
     * the other refusals rather than in a dashboard-specific exception because
     * the UI handles all of them identically: translate the code, show it on
     * the card, and do not offer a retry.
     */
    public const WIDGET_SHAPE = 'widget_shape';

    // --- Refusals about the dataset rather than the spec. ---

    /** The dataset is still ingesting, or its last ingest failed. */
    public const NOT_READY = 'not_ready';

    /**
     * A live-connection dataset reached the analytical query path.
     *
     * Its own reader is the live driver in `app/Services/Database`, which has a
     * different threat model (someone else's server, someone else's schema). This
     * compiler assumes a table WE created with columns WE named, so pointing it at
     * an external table would quietly void that assumption.
     */
    public const EXTERNAL_UNSUPPORTED = 'external_unsupported';

    /** The physical table is missing — mid-rematerialization, usually. */
    public const TABLE_MISSING = 'table_missing';

    // --- Failures: the server's fault, and possibly transient. ---

    /** The engine gave up on the statement, or we did. */
    public const TIMEOUT = 'timeout';

    /** Anything the driver raised that we did not classify. */
    public const EXECUTION_FAILED = 'execution_failed';

    /**
     * The compiled string failed its own last-line-of-defence check.
     *
     * Unreachable by construction, like BiDataset::UNSAFE_TABLE_NAME: nothing a
     * caller supplies lands in an identifier position. If it ever fires it has
     * caught OUR bug, before that bug reached the server.
     */
    public const UNSAFE_SQL = 'unsafe_sql';

    /**
     * Codes the caller may sensibly retry. Everything else is the spec's fault and
     * will be refused identically next time, so a retry button on it is a lie.
     *
     * @var array<int, string>
     */
    public const TRANSIENT = [self::TIMEOUT, self::EXECUTION_FAILED, self::TABLE_MISSING];

    /**
     * `$reason`, not `$code`, for the same unavoidable reason as every other
     * exception in this module: `Exception::$code` already exists as a non-readonly
     * int, and shadowing it with a promoted readonly string is a fatal error at
     * class-load time — one that only shows up the first time something throws.
     */
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

    /** The translation key whose sentence the owner is shown. */
    public function translationKey(): string
    {
        return 'bi.errors.'.$this->reason;
    }

    /**
     * The technical remainder, path-stripped — same treatment as ingestion, for
     * the same reason: the owner gains nothing from our filesystem layout, and it
     * is exactly what should not travel in a screenshot.
     */
    public function detail(): string
    {
        $detail = preg_replace('#(/|\\\\)[^\s:,;\'"]+(/|\\\\)#', '', $this->getMessage()) ?? $this->getMessage();

        return mb_substr(trim($detail), 0, 500);
    }
}
