<?php

namespace App\Services\Bi\Query;

use App\Models\BiColumn;
use App\Models\BiDataset;

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

        return $this->quote($column->source_name);
    }

    protected function detailOrder(QuerySpec $spec): string
    {

        return implode(', ', array_map(fn ($dimension) => $this->quote($dimension->alias()).' ASC', $spec->dimensions));
    }
}
