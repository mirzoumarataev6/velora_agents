<?php

namespace App\Services\Database\Discovery;

use App\Services\Llm\LlmProviderInterface;
use Illuminate\Support\Facades\Log;
use Throwable;

class SchemaInterpreter
{
    protected const MAX_COLUMNS = 40;

    protected const SYSTEM = <<<'PROMPT'
        You are helping identify which table in a shop's database holds its product catalog.

        You will be given table names and column names with their SQL types. You will NEVER
        be given any row data, and you must never ask for any.

        Answer with JSON only, in exactly this shape:
        {"table": "<one of the given table names>",
         "mapping": {"name": "<column>", "price": "<column>", "stock": "<column>",
                     "category": "<column>", "description": "<column>", "sku": "<column>", "url": "<column>", "image_url": "<column>"}}

        Rules:
        - "table" MUST be one of the table names given to you. Do not invent one.
        - Every column MUST be one of the columns listed for that table. Do not invent one.
        - "name" is required: it is the column holding the product's name as a customer
          would read it. It must be a text column.
        - Omit any field you are not confident about rather than guessing.
        - Prefer a numeric price column over a textual one.
        - Do not choose a table that records orders, sales or cart contents.
        - Output the JSON and nothing else. No explanation, no code fence.
        PROMPT;

    protected const FIELD_SYSTEM = <<<'PROMPT'
        You are reading the column names of one table in a shop's database. Some of them are
        in a language or spelling that our dictionary does not cover.

        You will be given the table's column names with their SQL types, and a list of the
        fields we could not identify. You will NEVER be given any row data, and you must
        never ask for any.

        Answer with JSON only, in exactly this shape:
        {"mapping": {"<field>": "<column>"}}

        Rules:
        - Use ONLY the field names you were asked about. Do not answer about any other field.
        - Every column MUST be one of the columns listed for that table. Do not invent one.
        - "name" is the column holding the product's name as a customer would read it, and
          must be a text column. "price" is what the product costs, and should be numeric
          if such a column exists.
        - Omit any field you are not confident about. An incomplete answer is useful; a
          guessed one is not.
        - Output the JSON and nothing else. No explanation, no code fence.
        PROMPT;

    public function __construct(
        protected LlmProviderInterface $llm,
        protected SuggestionValidator $validator,
    ) {}

    public function resolveFields(DiscoveredSchema $schema, string $table, array $fields): array
    {
        if ($fields === [] || ! $schema->hasTable($table)) {
            return [];
        }

        try {
            $response = $this->llm->generate(self::FIELD_SYSTEM, [
                ['role' => 'user', 'text' => $this->describeTable($schema, $table, $fields)],
            ], [
                'temperature' => 0.0,
                'max_output_tokens' => 300,
            ]);
        } catch (Throwable $e) {

            Log::info('Column interpretation unavailable; the table stays unresolved', [
                'table' => $table,
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        $decoded = $this->decode((string) ($response['text'] ?? ''));

        if ($decoded === null) {
            return [];
        }

        $mapping = $this->validator->validateFields($schema, $table, $fields, $decoded);

        if ($mapping === []) {
            Log::info('Column interpretation rejected: it named something the schema does not contain', [
                'table' => $table,
                'asked' => $fields,
            ]);
        }

        return $mapping;
    }

    public function interpret(DiscoveredSchema $schema, array $shortlist): ?array
    {
        if ($shortlist === []) {
            return null;
        }

        $tables = array_map(fn (TableCandidate $candidate) => $candidate->table, $shortlist);

        try {
            $response = $this->llm->generate(self::SYSTEM, [
                ['role' => 'user', 'text' => $this->describe($schema, $tables)],
            ], [

                'temperature' => 0.0,
                'max_output_tokens' => 500,
            ]);
        } catch (Throwable $e) {

            Log::info('Schema interpretation unavailable; keeping the deterministic ranking', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        $decoded = $this->decode((string) ($response['text'] ?? ''));

        if ($decoded === null) {
            return null;
        }

        $validated = $this->validator->validate($schema, $tables, $decoded);

        if ($validated === null) {
            Log::info('Schema interpretation rejected: it named something the schema does not contain', [
                'offered' => $tables,
                'suggested_table' => is_scalar($decoded['table'] ?? null) ? (string) $decoded['table'] : null,
            ]);
        }

        return $validated;
    }

    protected function describe(DiscoveredSchema $schema, array $tables): string
    {
        $described = [];

        foreach ($tables as $table) {
            $columns = [];

            foreach (array_slice($schema->columns($table), 0, self::MAX_COLUMNS) as $column) {
                $columns[] = ['name' => $column['name'], 'type' => $column['type']];
            }

            $described[] = [
                'table' => $table,
                'kind' => $schema->typeOf($table),
                'columns' => $columns,
            ];
        }

        return (string) json_encode($described, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    protected function describeTable(DiscoveredSchema $schema, string $table, array $fields): string
    {
        $columns = [];

        foreach (array_slice($schema->columns($table), 0, self::MAX_COLUMNS) as $column) {
            $columns[] = ['name' => $column['name'], 'type' => $column['type']];
        }

        return (string) json_encode([
            'table' => $table,
            'columns' => $columns,
            'identify' => array_values($fields),
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    protected function decode(string $text): ?array
    {
        $text = trim($text);

        if ($text === '') {
            return null;
        }

        $start = strpos($text, '{');
        $end = strrpos($text, '}');

        if ($start === false || $end === false || $end <= $start) {
            return null;
        }

        $decoded = json_decode(substr($text, $start, $end - $start + 1), true);

        return is_array($decoded) ? $decoded : null;
    }
}
