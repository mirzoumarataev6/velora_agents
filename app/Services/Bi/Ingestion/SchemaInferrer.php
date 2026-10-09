<?php

namespace App\Services\Bi\Ingestion;

use App\Models\BiColumn;
use App\Services\Bi\Schema\ColumnRole;
use App\Services\Bi\Schema\ColumnType;

/**
 * Watches a sample of rows go past and decides what each column is.
 *
 * Stateful and single-pass on purpose: the normalizer is already streaming every
 * row to disk, so inference rides along on that one read rather than costing a
 * second pass over a 2M-row file. It stops accumulating after
 * `bi.ingest.inference_rows`, and the rest of the file streams past untouched.
 *
 * THE RULE THAT MATTERS, and the one decision worth defending: a type is only
 * given to a column when at least `inference_threshold` (95% by default) of its
 * NON-BLANK values cast to it cleanly. Below that, the column is text.
 *
 * That is what makes a mixed column safe. A "Цена" column where 4,800 rows are
 * numbers and 200 say "по запросу" is not a numeric column with 200 holes in it —
 * calling it one would let an owner build a revenue chart that silently omits 4%
 * of their business. Left as text, it is honest, it is still groupable, and the
 * schema editor can offer the owner the numeric reading with the cost stated
 * ("200 значений не будут учтены"). That choice is theirs, not ours.
 *
 * Blanks are excluded from the denominator throughout. An empty cell is not
 * evidence against any type — a column that is 90% empty and 10% clean dates is
 * a date column.
 */
class SchemaInferrer
{
    /**
     * Non-blank values a column needs before "every value is distinct" is taken as
     * evidence that it is an id rather than a dimension. See applyIndexBudget().
     */
    private const UNIQUENESS_EVIDENCE_ROWS = 20;

    /** @var array<int, string> */
    private array $headers;

    /**
     * Per-column accumulators, keyed by ordinal.
     *
     * @var array<int, array{
     *     nonBlank: int, blank: int, maxLength: int,
     *     casts: array<string, int>,
     *     distinct: array<string, true>, distinctOverflowed: bool,
     *     samples: array<int, string>,
     *     voter: DateFormatVoter, dateCandidates: int,
     *     min: ?float, max: ?float, minText: ?string, maxText: ?string,
     * }>
     */
    private array $stats = [];

    private int $rowsObserved = 0;

    public function __construct(
        array $headers,
        private readonly TypeCaster $caster = new TypeCaster,
    ) {
        $this->headers = array_values($headers);

        foreach ($this->headers as $ordinal => $_) {
            $this->stats[$ordinal] = [
                'nonBlank' => 0,
                'blank' => 0,
                'maxLength' => 0,
                'casts' => [],
                'distinct' => [],
                'distinctOverflowed' => false,
                'samples' => [],
                'voter' => new DateFormatVoter,
                'dateCandidates' => 0,
                'min' => null,
                'max' => null,
                'minText' => null,
                'maxText' => null,
            ];
        }
    }

    /** Whether this inferrer still wants rows, or has seen enough. */
    public function wantsMore(): bool
    {
        return $this->rowsObserved < max(1, (int) config('agent.bi.ingest.inference_rows', 5000));
    }

    /**
     * Offer one data row (not the header).
     *
     * @param  array<int, mixed>  $row
     */
    public function observe(array $row): void
    {
        if (! $this->wantsMore()) {
            return;
        }

        $this->rowsObserved++;

        foreach ($this->headers as $ordinal => $_) {
            $this->observeCell($ordinal, $row[$ordinal] ?? null);
        }
    }

    private function observeCell(int $ordinal, mixed $raw): void
    {
        $stats = &$this->stats[$ordinal];

        // A date-formatted spreadsheet cell arrives as a real object. It is
        // counted as a clean date without any parsing or voting — the file
        // already said so unambiguously, which is why .xlsx dates are never the
        // ones inference gets wrong.
        if ($raw instanceof \DateTimeInterface) {
            $stats['nonBlank']++;
            $stats['casts'][ColumnType::Date->value] = ($stats['casts'][ColumnType::Date->value] ?? 0) + 1;

            // Midnight means the cell carried a date and no clock. Distinguishing
            // them is what decides DATE versus DATETIME, and getting it wrong
            // either loses the time or puts a meaningless 00:00:00 on every axis
            // label.
            if ($raw->format('H:i:s') !== '00:00:00') {
                $stats['casts'][ColumnType::DateTime->value] = ($stats['casts'][ColumnType::DateTime->value] ?? 0) + 1;
            }

            $text = $raw->format('Y-m-d H:i:s');
            $this->noteText($stats, $text);
            $this->noteTextExtremes($stats, $raw->format('Y-m-d H:i:s'));

            return;
        }

        if ($raw === null) {
            $stats['blank']++;

            return;
        }

        if (is_int($raw) || is_float($raw)) {
            $stats['nonBlank']++;
            $stats['casts'][ColumnType::Decimal->value] = ($stats['casts'][ColumnType::Decimal->value] ?? 0) + 1;

            if (is_int($raw) || $raw === floor((float) $raw)) {
                $stats['casts'][ColumnType::Integer->value] = ($stats['casts'][ColumnType::Integer->value] ?? 0) + 1;
            }

            $this->noteNumber($stats, (float) $raw);
            $this->noteText($stats, $this->caster->clean((string) $raw));

            return;
        }

        if (is_bool($raw)) {
            $stats['nonBlank']++;
            $stats['casts'][ColumnType::Boolean->value] = ($stats['casts'][ColumnType::Boolean->value] ?? 0) + 1;
            $this->noteText($stats, $raw ? 'true' : 'false');

            return;
        }

        $value = $this->caster->clean((string) $raw);

        if ($this->caster->isBlank($value)) {
            $stats['blank']++;

            return;
        }

        $stats['nonBlank']++;
        $this->noteText($stats, $value);

        // Numbers are cheap to test and are tested on every value. Each cast is
        // kept rather than repeated: the decimal result is needed twice (as a
        // vote and as the min/max sample), and casting the same cell twice across
        // 150 columns and 5,000 rows is 750,000 avoidable parses.
        $decimal = $this->caster->cast($value, ColumnType::Decimal);
        $integer = $this->caster->cast($value, ColumnType::Integer);
        $boolean = $this->caster->cast($value, ColumnType::Boolean);

        foreach ([ColumnType::Decimal->value => $decimal, ColumnType::Integer->value => $integer, ColumnType::Boolean->value => $boolean] as $key => $result) {
            if ($result->isUsable()) {
                $stats['casts'][$key] = ($stats['casts'][$key] ?? 0) + 1;
            }
        }

        if ($decimal->isUsable()) {
            $this->noteNumber($stats, (float) $decimal->value);
        } else {
            $this->noteTextExtremes($stats, $value);
        }

        // The date voter tries 22 formats per value, so it is only offered values
        // that could plausibly be a date at all. Without this pre-filter a
        // 150-column file of numbers would spend most of inference proving that
        // none of them are dates.
        if ($this->looksLikeDate($value)) {
            $stats['dateCandidates']++;
            $stats['voter']->observe($value);
        }
    }

    /**
     * Cheap gate before the 22-format vote: has digits, has a date separator or a
     * written-out month, and is short enough to be a date rather than a sentence
     * that happens to contain one.
     */
    private function looksLikeDate(string $value): bool
    {
        if (mb_strlen($value) > 40 || preg_match('/\d/', $value) !== 1) {
            return false;
        }

        return preg_match('/\d[.\/-]\d/', $value) === 1
            || preg_match('/^\d{1,2}[\s.]+\p{L}{3,}/u', $value) === 1;
    }

    /**
     * @param  array<string, mixed>  $stats
     */
    private function noteText(array &$stats, string $value): void
    {
        $stats['maxLength'] = max($stats['maxLength'], mb_strlen($value));

        $limit = max(2, (int) config('agent.bi.ingest.max_distinct_tracked', 1001));

        if (isset($stats['distinct'][$value])) {
            return;
        }

        if (count($stats['distinct']) >= $limit) {
            // Stop growing the set but remember that it was truncated, so the
            // distinct count is reported as "more than N" rather than as N.
            $stats['distinctOverflowed'] = true;

            return;
        }

        $stats['distinct'][$value] = true;

        $sampleLimit = max(1, (int) config('agent.bi.ingest.sample_values', 5));

        if (count($stats['samples']) < $sampleLimit) {
            $stats['samples'][] = mb_substr($value, 0, 200);
        }
    }

    /**
     * @param  array<string, mixed>  $stats
     */
    private function noteNumber(array &$stats, float $value): void
    {
        $stats['min'] = $stats['min'] === null ? $value : min($stats['min'], $value);
        $stats['max'] = $stats['max'] === null ? $value : max($stats['max'], $value);
    }

    /**
     * @param  array<string, mixed>  $stats
     */
    private function noteTextExtremes(array &$stats, string $value): void
    {
        $stats['minText'] = $stats['minText'] === null ? $value : min($stats['minText'], $value);
        $stats['maxText'] = $stats['maxText'] === null ? $value : max($stats['maxText'], $value);
    }

    /**
     * The finished schema.
     *
     * @return array<int, InferredColumn>
     */
    public function columns(): array
    {
        $threshold = (float) config('agent.bi.ingest.inference_threshold', 0.95);
        $dimensionLimit = max(1, (int) config('agent.bi.ingest.dimension_distinct_limit', 1000));

        $columns = [];

        foreach ($this->headers as $ordinal => $header) {
            $stats = $this->stats[$ordinal];
            $nonBlank = $stats['nonBlank'];

            [$type, $format] = $this->decideType($stats, $nonBlank, $threshold);

            $distinct = count($stats['distinct']);

            $columns[] = new InferredColumn(
                ordinal: $ordinal,
                sourceName: $header,
                physicalName: BiColumn::physicalNameFor($ordinal),
                type: $type,
                role: $type->defaultRole($this->looksLikeYear($type, $stats)),
                format: $format,
                distinctCount: $distinct,
                distinctExact: ! $stats['distinctOverflowed'],
                blankCount: $stats['blank'],
                nonBlankCount: $stats['nonBlank'],
                maxLength: $stats['maxLength'],
                sampleValues: $stats['samples'],
                minValue: $this->extreme($stats, 'min'),
                maxValue: $this->extreme($stats, 'max'),
                // Provisional: the index BUDGET is applied across the whole
                // dataset afterwards, since "worth indexing" is a per-column
                // judgement but "how many indexes this table gets" is not.
                indexed: false,
            );
        }

        return $this->applyIndexBudget($columns, $dimensionLimit);
    }

    /**
     * @param  array<string, mixed>  $stats
     * @return array{0: ColumnType, 1: array<string, mixed>}
     */
    private function decideType(array $stats, int $nonBlank, float $threshold): array
    {
        if ($nonBlank === 0) {
            // Nothing but blanks. Text is the only honest answer — and the column
            // is kept rather than dropped, because a column that is empty in this
            // export may not be in the next one.
            return [ColumnType::Text, []];
        }

        $needed = (int) ceil($threshold * $nonBlank);

        // Dates first. The voter's own matched count is measured against the
        // column's whole non-blank population, not against the values that passed
        // the looks-like-a-date filter — otherwise a column of three dates and
        // 5,000 product names would be a date column.
        $decision = $stats['voter']->decide();

        if ($decision !== null) {
            $matched = $decision['matched'];
            // A spreadsheet's own date cells never reach the voter, so their count
            // is added here: a column mixing real date cells and text dates is
            // still a date column.
            $native = $stats['casts'][ColumnType::Date->value] ?? 0;

            if ($matched + $native >= $needed) {
                return [
                    $decision['has_time'] ? ColumnType::DateTime : ColumnType::Date,
                    [
                        'date_format' => $decision['format'],
                        'date_order' => $decision['order'],
                        // Carried through to the schema editor, which shows
                        // "assumed день.месяц" when it is set. The whole reason
                        // the voter reports it.
                        'date_ambiguous' => $decision['ambiguous'],
                    ],
                ];
            }
        }

        // Native spreadsheet dates with no text dates at all.
        $nativeDates = $stats['casts'][ColumnType::Date->value] ?? 0;

        if ($nativeDates >= $needed) {
            $withTime = $stats['casts'][ColumnType::DateTime->value] ?? 0;

            return [$withTime > 0 ? ColumnType::DateTime : ColumnType::Date, []];
        }

        if (($stats['casts'][ColumnType::Integer->value] ?? 0) >= $needed) {
            return [ColumnType::Integer, []];
        }

        if (($stats['casts'][ColumnType::Decimal->value] ?? 0) >= $needed) {
            return [ColumnType::Decimal, []];
        }

        // Boolean requires EVERY distinct value to be a truth word, not merely
        // the threshold share: a column of "да"/"нет" with one "возможно" in it is
        // not a boolean, it is a three-valued category, and flattening the third
        // value to NULL would lose a real answer.
        if ($this->isBooleanColumn($stats, $nonBlank)) {
            return [ColumnType::Boolean, []];
        }

        return [ColumnType::Text, []];
    }

    /**
     * @param  array<string, mixed>  $stats
     */
    private function isBooleanColumn(array $stats, int $nonBlank): bool
    {
        // An overflowed distinct set cannot be a boolean by definition, and this
        // also means the check below is always over a complete set.
        if ($stats['distinctOverflowed'] || $stats['distinct'] === []) {
            return false;
        }

        if (count($stats['distinct']) > 4) {
            return false;
        }

        return ($stats['casts'][ColumnType::Boolean->value] ?? 0) === $nonBlank;
    }

    /**
     * Whether an integer column is holding YEARS rather than quantities.
     *
     * The one case where a number should not start life as a measure. Three
     * conditions together, because any one of them alone would catch real
     * measures: the values must be whole numbers, every one of them must fall in a
     * plausible year range, and there must be few enough of them to be a span of
     * years rather than a spread of amounts. A price column fails the range test, a
     * quantity column fails it, and an id column fails the cardinality test.
     *
     * @param  array<string, mixed>  $stats
     */
    private function looksLikeYear(ColumnType $type, array $stats): bool
    {
        if ($type !== ColumnType::Integer) {
            return false;
        }

        $min = $stats['min'];
        $max = $stats['max'];

        if ($min === null || $max === null) {
            return false;
        }

        // Three centuries of distinct values is still a year column; more than that
        // is something else that happens to fall in the range.
        if ($stats['distinctOverflowed'] || count($stats['distinct']) > 300) {
            return false;
        }

        return $min >= 1900.0 && $max <= 2200.0;
    }

    /**
     * @param  array<string, mixed>  $stats
     */
    private function extreme(array $stats, string $which): ?string
    {
        $number = $stats[$which];

        if ($number !== null) {
            return rtrim(rtrim(number_format((float) $number, 6, '.', ''), '0'), '.') ?: '0';
        }

        $text = $stats[$which.'Text'];

        return $text === null ? null : mb_substr((string) $text, 0, 512);
    }

    /**
     * Decide which columns actually get an index.
     *
     * Two separate judgements, which is why this is not a flag on each column:
     *
     *  - WORTH indexing: a time column always (every dashboard filters by date),
     *    and a low-cardinality dimension that is short enough for a VARCHAR key.
     *    Never a measure — nothing GROUPs BY revenue.
     *  - AFFORDABLE: a 150-column file could have 100 columns that each qualify,
     *    and 100 indexes would make the insert pass several times slower for
     *    indexes no query will use. So there is a budget, and time columns claim
     *    it first, then the lowest-cardinality dimensions — which are the ones a
     *    GROUP BY benefits from most.
     *
     * @param  array<int, InferredColumn>  $columns
     * @return array<int, InferredColumn>
     */
    private function applyIndexBudget(array $columns, int $dimensionLimit): array
    {
        $budget = max(0, (int) config('agent.bi.ingest.max_indexes', 24));

        $eligible = [];

        foreach ($columns as $position => $column) {
            if ($column->type->isTemporal()) {
                $eligible[$position] = 0;

                continue;
            }

            if ($column->role !== ColumnRole::Dimension) {
                continue;
            }

            if ($column->isLongText() || ! $column->distinctExact || $column->distinctCount > $dimensionLimit) {
                continue;
            }

            // A column with ONE distinct value groups into a single bucket and is
            // useless to index.
            if ($column->distinctCount <= 1) {
                continue;
            }

            // A column where EVERY value is distinct is an id, an order number or a
            // free-text field — not a dimension anybody groups by, and the most
            // expensive kind of index to maintain.
            //
            // The row floor is the honest part: in a five-row sample "every value is
            // distinct" is equally true of a real category, so below it there is not
            // enough evidence to refuse and the column keeps its index. The
            // heuristic needs data to work, and pretending otherwise would misfile
            // small datasets instead of large ones.
            if ($column->nonBlankCount >= self::UNIQUENESS_EVIDENCE_ROWS
                && $column->distinctCount >= $column->nonBlankCount) {
                continue;
            }

            // Rank: time first (0 above), then by cardinality ascending.
            $eligible[$position] = 1 + $column->distinctCount;
        }

        asort($eligible);
        $chosen = array_slice(array_keys($eligible), 0, $budget, true);

        foreach ($chosen as $position) {
            $column = $columns[$position];

            $columns[$position] = new InferredColumn(
                ordinal: $column->ordinal,
                sourceName: $column->sourceName,
                physicalName: $column->physicalName,
                type: $column->type,
                role: $column->role,
                format: $column->format,
                distinctCount: $column->distinctCount,
                distinctExact: $column->distinctExact,
                blankCount: $column->blankCount,
                nonBlankCount: $column->nonBlankCount,
                maxLength: $column->maxLength,
                sampleValues: $column->sampleValues,
                minValue: $column->minValue,
                maxValue: $column->maxValue,
                indexed: true,
            );
        }

        return $columns;
    }
}
