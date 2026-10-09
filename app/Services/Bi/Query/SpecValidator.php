<?php

namespace App\Services\Bi\Query;

use App\Models\BiColumn;
use App\Models\BiDataset;
use Illuminate\Support\Collection;

/**
 * Turns an untrusted array into a QuerySpec, or refuses it with a classified code.
 *
 * This is the only door into the query layer from the outside world — a request
 * body, a saved dashboard tile, or the AI analyst's function call all arrive as
 * the same nested array and all come through here.
 *
 * THE RESOLUTION RULE, which is the security property:
 *
 * A caller names a column by `bi_columns.id` or by its physical name (`c3`).
 * Either way the name is resolved against a map built from THIS DATASET'S OWN
 * COLUMNS, loaded once up front — never by querying with the caller's string as
 * a predicate, and never by trusting it as an identifier. An id belonging to
 * another dataset is not in the map, so it is not "denied"; it is simply not
 * found, which is the same answer the caller gets for a typo. There is no code
 * path in which a value from the array becomes part of a SQL identifier.
 *
 * Physical names are accepted alongside ids because they are OURS (`c0`, `c1`,
 * assigned by ordinal) and because a dashboard tile that survives a
 * rematerialization is worth having — rebuilding a dataset issues new
 * `bi_columns` rows with new ids but the same ordinals.
 *
 * Everything this class rejects, it rejects with a `BiQueryException` carrying a
 * code that has a sentence in all three locales. There are no bare messages here
 * for the same reason there are none in ingestion: the owner reads them.
 */
class SpecValidator
{
    /**
     * @param  array<string, mixed>  $input
     *
     * @throws BiQueryException
     */
    public function validate(BiDataset $dataset, array $input): QuerySpec
    {
        $columns = $this->columnMap($dataset);

        $detail = ($input['detail'] ?? false) === true;
        $dimensions = [];
        foreach ($this->listOf($input, 'dimensions') as $raw) {
            $dimensions[] = $this->dimension($raw, $columns, $detail);
        }

        $measures = [];
        foreach ($this->listOf($input, 'measures') as $raw) {
            $measures[] = $this->measure($raw, $columns);
        }

        $filters = [];
        foreach ($this->listOf($input, 'filters') as $raw) {
            $filters[] = $this->filter($raw, $columns);
        }

        // Sorts are resolved AFTER dimensions and measures, because a sort names
        // an output alias and the aliases only exist once those are positioned.
        $aliases = $this->aliasMap($dimensions, $measures);

        $sorts = [];
        foreach ($this->listOf($input, 'sorts') as $raw) {
            $sorts[] = $this->sort($raw, $aliases);
        }

        return new QuerySpec(
            dataset: $dataset,
            dimensions: $dimensions,
            measures: $measures,
            filters: $filters,
            sorts: $sorts,
            limit: (int) ($input['limit'] ?? 100),
            offset: (int) ($input['offset'] ?? 0),
            detail: $detail,
        );
    }

    /**
     * Every column of this dataset, reachable by id AND by physical name.
     *
     * One query, not one per field: a spec with eight fields would otherwise be
     * eight round trips before a single row is read, and `tests/Feature/Perf/`
     * exists to catch exactly that shape.
     *
     * @return array<string, BiColumn>
     */
    protected function columnMap(BiDataset $dataset): array
    {
        /** @var Collection<int, BiColumn> $columns */
        $columns = $dataset->relationLoaded('columns')
            ? $dataset->columns
            : $dataset->columns()->get();

        $map = [];

        foreach ($columns as $column) {
            $map['#'.$column->getKey()] = $column;
            $map['@'.$column->physical_name] = $column;
        }

        return $map;
    }

    /**
     * @param  array<string, BiColumn>  $columns
     */
    protected function resolve(mixed $reference, array $columns, string $role): BiColumn
    {
        // Prefixed before lookup so an id can never collide with a physical name.
        // Without it a caller passing the string "0" would be ambiguous, and the
        // disambiguation would depend on PHP's array-key casting rather than on
        // anything we decided.
        $key = is_int($reference) || (is_string($reference) && ctype_digit($reference))
            ? '#'.(int) $reference
            : '@'.(is_string($reference) ? $reference : '');

        if (! isset($columns[$key])) {
            throw new BiQueryException(
                BiQueryException::UNKNOWN_COLUMN,
                "A {$role} names a column that is not in this dataset."
            );
        }

        return $columns[$key];
    }

    /**
     * @param  array<string, BiColumn>  $columns
     */
    protected function dimension(mixed $raw, array $columns, bool $detail = false): Dimension
    {
        $raw = $this->asArray($raw, 'dimension');
        $column = $this->resolve($raw['column'] ?? null, $columns, 'dimension');

        $grain = null;

        if (($raw['grain'] ?? null) !== null && $raw['grain'] !== '') {
            $grain = TimeGrain::tryFrom((string) $raw['grain']);

            if ($grain === null) {
                throw new BiQueryException(
                    BiQueryException::UNKNOWN_OPERATION,
                    'Unknown time grain ['.(string) $raw['grain'].'].'
                );
            }
        }

        // A date dimension with no grain stated gets one, rather than grouping on
        // raw timestamps. Grouping a datetime column by its exact value produces
        // one bucket per row, which is not a chart — it is the table again, drawn
        // slowly. Month is the grain a sales export is almost always wanted at.
        if (! $detail && $grain === null && $column->type->isTemporal()) {
            $grain = TimeGrain::Month;
        }

        return new Dimension($column, $grain);
    }

    /**
     * @param  array<string, BiColumn>  $columns
     */
    protected function measure(mixed $raw, array $columns): Measure
    {
        $raw = $this->asArray($raw, 'measure');

        $aggregate = Aggregate::tryFrom((string) ($raw['aggregate'] ?? ''));

        if ($aggregate === null) {
            throw new BiQueryException(
                BiQueryException::UNKNOWN_OPERATION,
                'Unknown aggregate ['.(string) ($raw['aggregate'] ?? '').'].'
            );
        }

        if (! $aggregate->needsColumn()) {
            return new Measure($aggregate);
        }

        return new Measure($aggregate, $this->resolve($raw['column'] ?? null, $columns, 'measure'));
    }

    /**
     * @param  array<string, BiColumn>  $columns
     */
    protected function filter(mixed $raw, array $columns): Filter
    {
        $raw = $this->asArray($raw, 'filter');
        $column = $this->resolve($raw['column'] ?? null, $columns, 'filter');

        $operator = FilterOperator::tryFrom((string) ($raw['operator'] ?? ''));

        if ($operator === null) {
            throw new BiQueryException(
                BiQueryException::UNKNOWN_OPERATION,
                'Unknown filter operator ['.(string) ($raw['operator'] ?? '').'].'
            );
        }

        if ($operator->needsOrdering() && ! $this->isOrdered($column)) {
            throw new BiQueryException(
                BiQueryException::UNKNOWN_OPERATION,
                "Operator [{$operator->value}] needs an ordered column, and "
                    ."[{$column->physical_name}] is {$column->type->value}."
            );
        }

        $values = $this->values($raw);
        $arity = $operator->arity();

        // `null` arity means "one or more", which still has to be at least one:
        // `IN ()` is a syntax error in MySQL and silently false in SQLite, and
        // neither is an answer worth giving an owner.
        $wrong = $arity === null
            ? $values === []
            : count($values) !== $arity;

        if ($wrong) {
            throw new BiQueryException(
                BiQueryException::BAD_FILTER_VALUE,
                "Operator [{$operator->value}] takes ".($arity ?? 'at least one')
                    .' value(s), got '.count($values).'.'
            );
        }

        return new Filter($column, $operator, $values);
    }

    /**
     * @param  array<string, bool>  $aliases
     */
    protected function sort(mixed $raw, array $aliases): Sort
    {
        $raw = $this->asArray($raw, 'sort');

        $alias = (string) ($raw['field'] ?? $raw['alias'] ?? '');

        if (! isset($aliases[$alias])) {
            throw new BiQueryException(
                BiQueryException::UNSORTABLE,
                "Cannot sort by [{$alias}]: this query does not select it."
            );
        }

        $direction = SortDirection::tryFrom(strtolower((string) ($raw['direction'] ?? 'asc')));

        if ($direction === null) {
            throw new BiQueryException(
                BiQueryException::UNKNOWN_OPERATION,
                'Sort direction must be asc or desc.'
            );
        }

        return new Sort($alias, $direction);
    }

    /**
     * The aliases a spec built from these fields WILL have.
     *
     * Mirrors the positioning QuerySpec does, which is a duplication worth
     * flagging: the spec assigns positions itself and does not trust these. This
     * map exists only so a bad sort is refused with "you did not select m3"
     * rather than with an exception from deeper down.
     *
     * @param  array<int, Dimension>  $dimensions
     * @param  array<int, Measure>  $measures
     * @return array<string, bool>
     */
    protected function aliasMap(array $dimensions, array $measures): array
    {
        $aliases = [];

        foreach (array_keys(array_values($dimensions)) as $i) {
            $aliases['d'.$i] = true;
        }

        foreach (array_keys(array_values($measures)) as $i) {
            $aliases['m'.$i] = true;
        }

        return $aliases;
    }

    /** Whether `<`, `>` and `between` mean anything on this column. */
    protected function isOrdered(BiColumn $column): bool
    {
        return $column->type->isNumeric() || $column->type->isTemporal();
    }

    /**
     * A filter's values, always as a list.
     *
     * A scalar is accepted and wrapped: `['operator' => 'eq', 'value' => 5]` is
     * how every caller naturally writes a single-value filter, and insisting on
     * `['values' => [5]]` would be pedantry that produces bug reports.
     *
     * @param  array<string, mixed>  $raw
     * @return array<int, mixed>
     */
    protected function values(array $raw): array
    {
        if (array_key_exists('values', $raw)) {
            return is_array($raw['values']) ? array_values($raw['values']) : [$raw['values']];
        }

        if (array_key_exists('value', $raw)) {
            return is_array($raw['value']) ? array_values($raw['value']) : [$raw['value']];
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<int, mixed>
     */
    protected function listOf(array $input, string $key): array
    {
        $value = $input[$key] ?? [];

        return is_array($value) ? array_values($value) : [];
    }

    /** @return array<string, mixed> */
    protected function asArray(mixed $raw, string $role): array
    {
        if (! is_array($raw)) {
            throw new BiQueryException(
                BiQueryException::UNKNOWN_OPERATION,
                "Each {$role} must be an object."
            );
        }

        return $raw;
    }
}
