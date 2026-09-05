<?php

namespace App\Services\Database\Discovery;

use App\Services\Llm\LlmProviderInterface;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The optional last step: ask a model what a shortlist of table and column NAMES
 * appears to mean.
 *
 * Read the limits before the code, because they are the design:
 *
 *  - IT RUNS AT CONNECTION TIME ONLY, never while answering a customer. A
 *    customer's question never reaches it and never waits for it.
 *  - IT IS SENT NAMES AND TYPES. No row data, no credentials, no host. The
 *    payload is built from DiscoveredSchema, which holds nothing else.
 *  - IT RETURNS A SUGGESTION, which SuggestionValidator then checks against the
 *    discovered schema and discards whole if it names anything that is not
 *    there.
 *  - IT NEVER WRITES SQL. There is no path from its output to a query: its
 *    answer becomes a proposed mapping, the owner confirms that, and the mapping
 *    goes through the same closed-set validation as one typed by hand.
 *  - IT IS OFF BY DEFAULT, and even when enabled it is only consulted when the
 *    deterministic pass came back genuinely undecided. A schema that names its
 *    tables `products` and `categories` never reaches this class.
 *
 * If it fails, times out, or answers nonsense, discovery continues with the
 * deterministic ranking. Nothing here is on the critical path.
 */
class SchemaInterpreter
{
    /** Columns per table described to the model. A wide table is truncated. */
    protected const MAX_COLUMNS = 40;

    protected const SYSTEM = <<<'PROMPT'
        You are helping identify which table in a shop's database holds its product catalog.

        You will be given table names and column names with their SQL types. You will NEVER
        be given any row data, and you must never ask for any.

        Answer with JSON only, in exactly this shape:
        {"table": "<one of the given table names>",
         "mapping": {"name": "<column>", "price": "<column>", "stock": "<column>",
                     "category": "<column>", "description": "<column>", "sku": "<column>", "url": "<column>"}}

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

    /**
     * The second, NARROWER question: not "which table" but "what do these two
     * columns of this one table mean".
     *
     * It is asked only when the deterministic pass — vocabulary plus
     * transliteration — could not read a REQUIRED field, which on a
     * conventionally or even a transliterated schema never happens. The shape
     * that reaches it is a real one: an Uzbek or Tajik shop whose columns are
     * `atalishi` and `qiymati`, words no vocabulary of ours carries and no
     * transliteration turns into one.
     *
     * The constraints are identical to the broad question above — names and
     * types only, answer validated against the schema, discarded whole if it
     * names anything unreal, owner confirms — with one more on top: it may only
     * answer about the fields it was asked about.
     */
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

    /**
     * Ask what specific columns of ONE table mean.
     *
     * @param  array<int, string>  $fields  The unresolved fields, and the only ones an
     *                                      answer may speak about.
     * @return array<string, string> field => column, validated. [] when there is no usable answer.
     */
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
            // Never fatal. Without an answer the table simply stays unoffered,
            // which is exactly where it was before we asked.
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

    /**
     * Ask the model to choose between the shortlisted tables.
     *
     * @param  array<int, TableCandidate>  $shortlist
     * @return array{table: string, mapping: array<string, string>}|null Validated, or null.
     */
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
                // A schema reading is not a creative task.
                'temperature' => 0.0,
                'max_output_tokens' => 500,
            ]);
        } catch (Throwable $e) {
            // Never fatal: the deterministic ranking is the answer, and this was
            // only ever a tie-break on top of it.
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

    /**
     * The payload: table names, column names, column types. Nothing else exists
     * in a DiscoveredSchema, which is precisely why it is what gets described.
     *
     * @param  array<int, string>  $tables
     */
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

    /**
     * One table's columns and the fields we are stuck on. Same payload rules as
     * describe(): names and types, nothing else exists to send.
     *
     * @param  array<int, string>  $fields
     */
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

    /**
     * Decode the model's reply, tolerating a code fence or a sentence around the
     * JSON — models add those even when told not to.
     *
     * @return array<string, mixed>|null
     */
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
