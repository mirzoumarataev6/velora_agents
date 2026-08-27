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

    public function __construct(
        protected LlmProviderInterface $llm,
        protected SuggestionValidator $validator,
    ) {}

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
