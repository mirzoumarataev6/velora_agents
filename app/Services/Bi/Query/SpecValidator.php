<?php

namespace App\Services\Bi\Query;

use App\Models\BiColumn;
use App\Models\BiDataset;

class SpecValidator
{
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

    protected function columnMap(BiDataset $dataset): array
    {

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

    protected function resolve(mixed $reference, array $columns, string $role): BiColumn
    {

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

        if (! $detail && $grain === null && $column->type->isTemporal()) {
            $grain = TimeGrain::Month;
        }

        return new Dimension($column, $grain);
    }

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

    protected function isOrdered(BiColumn $column): bool
    {
        return $column->type->isNumeric() || $column->type->isTemporal();
    }

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

    protected function listOf(array $input, string $key): array
    {
        $value = $input[$key] ?? [];

        return is_array($value) ? array_values($value) : [];
    }

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
