<?php

namespace App\Services\Database\Discovery;

use App\Services\Database\LiveProductSource;

/**
 * The gate every model suggestion passes through before anything is done with
 * it.
 *
 * The security position of this whole feature is that identifiers reaching the
 * SQL builder were read off the customer's own catalog by us. A language model
 * is allowed to READ those names and say what it thinks they mean; it is not a
 * source of names. So its answer is checked here against the schema that was
 * actually discovered, and anything naming a table or column that does not exist
 * is discarded.
 *
 * DISCARDED WHOLE, not repaired. A suggestion that names one column we cannot
 * find is a suggestion we have no reason to trust the rest of — keeping the
 * plausible half would mean shipping the model's guess about the parts we did
 * not check. Falling back to the deterministic ranking costs an owner one extra
 * click in the advanced panel; trusting a half-verified answer costs them
 * whatever the agent then tells their customers.
 */
class SuggestionValidator
{
    /**
     * Check a decoded suggestion, returning it in normalized form or null.
     *
     * @param  array<int, string>  $allowedTables  The shortlist the model was shown. A
     *                                             table outside it is refused even if it exists: the model was asked to
     *                                             choose between these, not to go looking.
     * @param  array<string, mixed>  $suggestion
     * @return array{table: string, mapping: array<string, string>}|null
     */
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
            // A field outside the catalog vocabulary is not something the search
            // could use even if the column were real.
            if (! is_string($field) || ! in_array($field, LiveProductSource::FIELDS, true)) {
                return null;
            }

            if (! is_string($column) || $column === '') {
                return null;
            }

            // The question this class exists to ask.
            if (! $schema->hasColumn($table, $column)) {
                return null;
            }

            $checked[$field] = $column;
        }

        // Without a product name a row is not a product — the same rule the
        // manual mapping screen enforces.
        if (! isset($checked['name'])) {
            return null;
        }

        return ['table' => $table, 'mapping' => $checked];
    }

    /**
     * The same gate for the narrow question: which columns of ONE table mean the
     * handful of fields we could not read.
     *
     * Three things are checked beyond "does this column exist", and each of them
     * is a rule the deterministic pass would have applied itself:
     *
     *  - the answer may only speak about the fields it was asked about, so a
     *    model cannot volunteer a re-reading of a column the vocabulary already
     *    resolved;
     *  - the TYPE has to suit the field — a numeric column is not a product
     *    name, however confident the answer sounds;
     *  - one column cannot be two fields at once, the same rule the mapping
     *    screen enforces.
     *
     * Discarded whole on any violation, for the reason in the class comment: an
     * answer wrong about a part we checked is not evidence about the parts we
     * did not.
     *
     * @param  array<int, string>  $wanted  Fields the model was asked about.
     * @param  array<string, mixed>  $suggestion
     * @return array<string, string> field => column. Empty when unusable.
     */
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

            // The question this class exists to ask.
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

    /**
     * Turn a validated mapping into roles, taking each column's type from the
     * schema rather than from anything the model said about it.
     *
     * @param  array<string, string>  $mapping
     */
    public function toRoles(DiscoveredSchema $schema, string $table, array $mapping): ColumnRoles
    {
        $numeric = [];

        foreach ($mapping as $field => $column) {
            $numeric[$field] = $schema->isNumeric($table, $column);
        }

        return new ColumnRoles($mapping, $numeric);
    }
}
