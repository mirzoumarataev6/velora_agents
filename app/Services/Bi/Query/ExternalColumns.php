<?php

namespace App\Services\Bi\Query;

use App\Models\BiColumn;
use App\Models\BiDataset;

/**
 * The only difference between a materialized column and a live one is where
 * its identifier comes from. Both are resolved by SpecValidator against the
 * current dataset before reaching these methods; a source label or chat string
 * can never become an identifier.
 */
trait ExternalColumns
{
    protected function quoteTable(BiDataset $dataset): string
    {
        $name = (string) $dataset->external_table;
        $parts = explode('.', $name);
        if (count($parts) > 2 || in_array('', $parts, true)) {
            throw new BiQueryException(BiQueryException::UNSAFE_SQL, 'The registered table name is invalid.');
        }

        return implode('.', array_map($this->quote(...), $parts));
    }

    protected function column(BiColumn $column): string
    {
        // QuerySpec has checked that this exact registry row belongs to the
        // dataset. The source name is the server-discovered identifier.
        return $this->quote($column->source_name);
    }

    protected function detailOrder(QuerySpec $spec): string
    {
        // Live tables do not have our internal _row key. Ordering by every
        // selected field keeps pages deterministic even when the owner did not
        // choose a sort. Tied rows are identical in this projection.
        return implode(', ', array_map(fn ($dimension) => $this->quote($dimension->alias()).' ASC', $spec->dimensions));
    }
}
