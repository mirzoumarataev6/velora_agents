<?php

namespace App\Services\Database\Discovery;

/**
 * What each column of one table appears to MEAN: field => column name.
 *
 * The same shape as the mapping the owner confirms by hand, because that is
 * exactly what it becomes if they press Confirm. Nothing here is authoritative —
 * it is a reading of column names and types, offered for confirmation.
 *
 * Every column named in one of these came from a DiscoveredSchema, so the names
 * are inside the closed set by construction. Callers still re-check before use;
 * this type carries no privilege.
 */
final class ColumnRoles
{
    /** Read straight off the vocabulary, in one of the platform's languages. */
    public const DIRECT = 'direct';

    /** Read after transliterating a latin spelling back to Cyrillic. */
    public const TRANSLITERATED = 'transliterated';

    /** Identified by a language model when nothing else could. */
    public const INTERPRETED = 'interpreted';

    /**
     * @param  array<string, string>  $columns  field => column name
     * @param  array<string, bool>  $numeric  field => whether that column is numeric
     * @param  array<string, string>  $sources  field => how it was recognized. Absent means
     *                                          DIRECT, which is both the commonest case and the one that needs no
     *                                          explaining to the owner.
     */
    public function __construct(
        public readonly array $columns = [],
        public readonly array $numeric = [],
        public readonly array $sources = [],
    ) {}

    /** How this field's column was recognized. */
    public function source(string $field): string
    {
        return $this->sources[$field] ?? self::DIRECT;
    }

    /**
     * The fields recognized by one route, in the catalog's field order.
     *
     * What the owner is shown is built from this: a column we transliterated or
     * asked a model about is a column they should look at twice, and saying
     * which ones costs nothing while leaving it unsaid costs them the chance to
     * catch it.
     *
     * @return array<string, string> field => column
     */
    public function from(string $source): array
    {
        $fields = [];

        foreach ($this->columns as $field => $column) {
            if ($this->source($field) === $source) {
                $fields[$field] = $column;
            }
        }

        return $fields;
    }

    /**
     * The same roles with more fields added, each tagged with where it came
     * from. Existing fields are never overwritten: whatever resolved a field
     * first did so from stronger evidence than whatever is arriving now.
     *
     * @param  array<string, string>  $columns  field => column
     * @param  array<string, bool>  $numeric  field => whether that column is numeric
     */
    public function with(array $columns, array $numeric, string $source): self
    {
        $merged = $this->columns;
        $mergedNumeric = $this->numeric;
        $sources = $this->sources;

        foreach ($columns as $field => $column) {
            if (isset($merged[$field])) {
                continue;
            }

            $merged[$field] = $column;
            $mergedNumeric[$field] = (bool) ($numeric[$field] ?? false);
            $sources[$field] = $source;
        }

        return new self($merged, $mergedNumeric, $sources);
    }

    public function has(string $field): bool
    {
        return isset($this->columns[$field]);
    }

    public function column(string $field): ?string
    {
        return $this->columns[$field] ?? null;
    }

    public function isNumeric(string $field): bool
    {
        return (bool) ($this->numeric[$field] ?? false);
    }

    /**
     * A product needs a name. Without one, a row is not something the agent can
     * offer a customer — the same rule the manual mapping already enforces.
     */
    public function hasName(): bool
    {
        return $this->has('name');
    }

    /**
     * A price we can actually FILTER on, as opposed to merely show. A price
     * stored as text still reaches the customer; it just cannot answer "under
     * 500", which is what the search's numeric conditions need.
     */
    public function hasNumericPrice(): bool
    {
        return $this->has('price') && $this->isNumeric('price');
    }

    /**
     * The mapping shape stored on the connection, dropping unfilled fields the
     * way StoreMappingRequest does.
     *
     * @return array<string, string>
     */
    public function toMapping(): array
    {
        return $this->columns;
    }

    public function isEmpty(): bool
    {
        return $this->columns === [];
    }
}
