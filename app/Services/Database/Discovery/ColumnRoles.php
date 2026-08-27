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
    /**
     * @param  array<string, string>  $columns  field => column name
     * @param  array<string, bool>  $numeric  field => whether that column is numeric
     */
    public function __construct(
        public readonly array $columns = [],
        public readonly array $numeric = [],
    ) {}

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
