<?php

namespace App\Services\Database\Discovery;

use App\Services\Database\LiveProductSource;

class SuggestionValidator
{
    public function validate(DiscoveredSchema $schema, array $allowedTables, array $suggestion): ?array
    {
        $table = is_scalar($suggestion['table'] ?? null) ? (string) $suggestion['table'] : '';

        if ($table === '' || ! in_array($table, $allowedTables, true) || ! $schema->hasTable($table)) {
            return null;
        }

        $mapping = $suggestion['mapping'] ?? null;

        if (! is_array($mapping) || $mapping === []) {
            return null;
        }

        $checked = [];

        foreach ($mapping as $field => $column) {

            if (! is_string($field) || ! in_array($field, LiveProductSource::FIELDS, true)) {
                return null;
            }

            if (! is_string($column) || $column === '') {
                return null;
            }

            if (! $schema->hasColumn($table, $column)) {
                return null;
            }

            $checked[$field] = $column;
        }

        if (! isset($checked['name'])) {
            return null;
        }

        return ['table' => $table, 'mapping' => $checked];
    }

    public function validateFields(DiscoveredSchema $schema, string $table, array $wanted, array $suggestion): array
    {
        if ($table === '' || $wanted === [] || ! $schema->hasTable($table)) {
            return [];
        }

        $mapping = $suggestion['mapping'] ?? null;

        if (! is_array($mapping) || $mapping === []) {
            return [];
        }

        $checked = [];

        foreach ($mapping as $field => $column) {
            if (! is_string($field) || ! in_array($field, $wanted, true)) {
                return [];
            }

            if (! is_string($column) || $column === '') {
                return [];
            }

            if (! $schema->hasColumn($table, $column)) {
                return [];
            }

            if (! ColumnRoleResolver::accepts($field, $schema->isNumeric($table, $column))) {
                return [];
            }

            if (in_array($column, $checked, true)) {
                return [];
            }

            $checked[$field] = $column;
        }

        return $checked;
    }

    public function toRoles(DiscoveredSchema $schema, string $table, array $mapping): ColumnRoles
    {
        $numeric = [];

        foreach ($mapping as $field => $column) {
            $numeric[$field] = $schema->isNumeric($table, $column);
        }

        return new ColumnRoles($mapping, $numeric);
    }
}
