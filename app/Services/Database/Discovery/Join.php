<?php

namespace App\Services\Database\Discovery;

/**
 * One table brought alongside the product table, and the columns it supplies.
 *
 * A join is only ever built from a relationship that is already in the
 * DiscoveredSchema — declared by the customer's own foreign keys, or inferred by
 * name and marked as such. Nothing here is composable from a request: the fields
 * are read off a stored plan that was validated against the schema when the
 * owner confirmed it, and validated again before every query.
 *
 * The ALIAS is ours, not theirs. `j0`, `j1` — generated in order, matching
 * /^j\d+$/, and the only identifier in a composed query that did not come from
 * the customer's catalog. That is deliberate: two tables in one query can have a
 * column of the same name, and resolving that with a customer-supplied alias
 * would put a string we did not read off information_schema into the SQL.
 *
 * An AGGREGATED join is the one shape that is allowed to hang off a column the
 * engine does not promise is unique. `stocks(product_id, quantity)` with a row
 * per warehouse and no unique key on `product_id` is the commonest table in real
 * shops, and a plain join onto it would return each product once per warehouse.
 * So it is not joined as a table at all: it is joined as
 *
 *     LEFT JOIN (SELECT product_id, SUM(quantity) AS quantity
 *                  FROM stocks GROUP BY product_id) AS j1 ON t.id = j1.product_id
 *
 * which has exactly one row per product BY CONSTRUCTION — the grouping key is
 * the join key — and so restores the guarantee the missing unique index took
 * away. Everything else is unchanged: still one SELECT, still only identifiers
 * read off the customer's own catalog, still the same row cap and timeout.
 */
final class Join
{
    /** The alias pattern. Anything else is refused rather than quoted. */
    public const ALIAS_PATTERN = '/^j[0-9]{1,2}$/';

    /**
     * The closed set of aggregate functions a join may fold rows with.
     *
     * One entry, and it is the only one that is semantically right for the case
     * this exists for: stock across warehouses is the SUM of the rows, not the
     * first of them.
     *
     * @var string[]
     */
    public const AGGREGATES = ['sum'];

    /**
     * @param  string  $localAlias  The alias this join attaches to: '' for the product
     *                              table itself, otherwise an earlier join's alias.
     * @param  array<string, string>  $columns  field => column name on $table
     * @param  string|null  $aggregate  One of AGGREGATES to fold many rows per product
     *                                  into one, or null for a plain join.
     */
    public function __construct(
        public readonly string $table,
        public readonly string $alias,
        public readonly string $localAlias,
        public readonly string $localColumn,
        public readonly string $foreignColumn,
        public readonly array $columns,
        public readonly bool $declared = true,
        public readonly ?string $aggregate = null,
    ) {}

    /** Whether this join folds several rows per product into one. */
    public function isAggregated(): bool
    {
        return $this->aggregate !== null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $columns = [];
        foreach (is_array($data['columns'] ?? null) ? $data['columns'] : [] as $field => $column) {
            if (is_string($field) && is_string($column)) {
                $columns[$field] = $column;
            }
        }

        $aggregate = self::text($data, 'aggregate');

        return new self(
            table: self::text($data, 'table'),
            alias: self::text($data, 'alias'),
            localAlias: self::text($data, 'local_alias'),
            localColumn: self::text($data, 'local_column'),
            foreignColumn: self::text($data, 'foreign_column'),
            columns: $columns,
            declared: (bool) ($data['declared'] ?? true),
            // Read back as a plain join unless the stored value is one of ours.
            // A snapshot written by an older version, or edited, must produce a
            // NARROWER plan than it claims, never a wider one.
            aggregate: in_array($aggregate, self::AGGREGATES, true) ? $aggregate : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'table' => $this->table,
            'alias' => $this->alias,
            'local_alias' => $this->localAlias,
            'local_column' => $this->localColumn,
            'foreign_column' => $this->foreignColumn,
            'columns' => $this->columns,
            'declared' => $this->declared,
            'aggregate' => $this->aggregate,
        ];
    }

    /** Whether this join hangs off the product table rather than another join. */
    public function isDirect(): bool
    {
        return $this->localAlias === '';
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function text(array $data, string $key): string
    {
        return is_scalar($data[$key] ?? null) ? (string) $data[$key] : '';
    }
}
