<?php

namespace App\Services\Dataset;

use App\Models\Agent;
use App\Models\Dataset;
use App\Models\DatasetProduct;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Imports a parsed catalog file into an agent's products table under a mapping
 * the owner has confirmed.
 *
 * Two guarantees this class exists to provide:
 *
 *  - REPLACE IS ATOMIC. A re-upload clears the agent's products and reinserts
 *    them inside one transaction, so a failure halfway through leaves the old
 *    catalog intact rather than half a new one. An agent whose dataset silently
 *    lost half its products would keep answering — with wrong answers.
 *
 *  - ONE BAD ROW IS NOT A BAD FILE. A row missing a name, or with junk in the
 *    price column, is skipped and counted, never fatal. The counts and reasons
 *    are reported back so the owner learns their file had 12 empty rows instead
 *    of wondering why the catalog is short.
 */
class DatasetImporter
{
    /** Rows inserted per statement. Large enough to be fast, small enough to fit MySQL's packet. */
    protected const INSERT_CHUNK = 500;

    public function __construct(
        protected ValueParser $values,
    ) {}

    /**
     * @param  string[]  $headers
     * @param  array<int, array<int, string>>  $rows  Data rows only (no header).
     * @param  array<string, int|int[]>  $mapping  field => column index; 'extra' => column indexes.
     * @return array{imported: int, skipped: int, reasons: array<string, int>}
     */
    public function import(Agent $agent, string $filename, array $headers, array $rows, array $mapping): array
    {
        $mapping = $this->validateMapping($mapping, count($headers));

        $nameColumn = $mapping['name'];
        $extraColumns = $mapping['extra'] ?? [];

        $products = [];
        $reasons = [];
        $position = 0;
        $now = now();

        foreach ($rows as $row) {
            $name = $this->values->text($row[$nameColumn] ?? null);

            if ($name === null) {
                // A trailing blank row is spreadsheet noise; a row that has
                // content but no product name is a real data problem, and the
                // owner is told which of the two they have.
                $reason = $this->isBlankRow($row) ? 'empty_row' : 'missing_name';
                $reasons[$reason] = ($reasons[$reason] ?? 0) + 1;

                continue;
            }

            $extra = [];
            foreach ($extraColumns as $column) {
                $value = $this->values->text($row[$column] ?? null, 500);
                if ($value !== null) {
                    $extra[$headers[$column]] = $value;
                }
            }

            $category = isset($mapping['category']) ? $this->values->text($row[$mapping['category']] ?? null) : null;
            $description = isset($mapping['description']) ? $this->values->longText($row[$mapping['description']] ?? null) : null;
            $sku = isset($mapping['sku']) ? $this->values->text($row[$mapping['sku']] ?? null, 190) : null;

            $products[] = [
                'agent_id' => $agent->id,
                'name' => $name,
                'price' => isset($mapping['price']) ? $this->values->price($row[$mapping['price']] ?? null) : null,
                'stock' => isset($mapping['stock']) ? $this->values->stock($row[$mapping['stock']] ?? null) : null,
                'category' => $category,
                'description' => $description,
                'sku' => $sku,
                'url' => isset($mapping['url']) ? $this->values->url($row[$mapping['url']] ?? null) : null,
                // Set here, not by the model's saving hook: these rows are bulk
                // inserted and never instantiated.
                'search_text' => DatasetProduct::searchText($name, $description, $sku),
                'category_key' => DatasetProduct::categoryKey($category),
                'extra' => $extra === [] ? null : json_encode($extra, JSON_UNESCAPED_UNICODE),
                'position' => $position++,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($products === []) {
            throw new InvalidArgumentException('no_valid_rows');
        }

        $skipped = array_sum($reasons);

        DB::transaction(function () use ($agent, $filename, $headers, $mapping, $products, $reasons, $skipped) {
            $dataset = Dataset::query()->firstOrNew(['agent_id' => $agent->id]);
            $dataset->agent_id = $agent->id;
            $dataset->fill([
                'original_filename' => $filename,
                'row_count' => count($products),
                'skipped_count' => $skipped,
                'skipped_reasons' => $reasons === [] ? null : $reasons,
                'mapping' => $mapping,
                'columns' => $headers,
                'imported_at' => now(),
            ])->save();

            // Replace, not merge: the uploaded file is the catalog now. Scoped by
            // agent_id as well as dataset_id so a stray product from an earlier
            // schema could never survive into another tenant's results.
            DatasetProduct::query()->where('agent_id', $agent->id)->delete();

            foreach (array_chunk($products, self::INSERT_CHUNK) as $chunk) {
                DatasetProduct::query()->insert(array_map(
                    fn (array $product) => $product + ['dataset_id' => $dataset->id],
                    $chunk,
                ));
            }
        });

        return ['imported' => count($products), 'skipped' => $skipped, 'reasons' => $reasons];
    }

    /**
     * Reject a mapping before a single row is read: an out-of-range column index
     * or a missing name column would otherwise import a catalog of nulls that
     * looks successful until a customer asks a question.
     *
     * @param  array<string, mixed>  $mapping
     * @param  int  $columnCount  Number of columns in the file.
     * @return array<string, int|int[]>
     */
    public function validateMapping(array $mapping, int $columnCount): array
    {
        $clean = [];

        foreach ($mapping as $field => $column) {
            if (! in_array($field, ColumnMapper::FIELDS, true)) {
                continue;
            }

            if ($field === 'extra') {
                $columns = array_values(array_filter(
                    array_map('intval', (array) $column),
                    fn (int $index) => $index >= 0 && $index < $columnCount,
                ));

                if ($columns !== []) {
                    $clean['extra'] = array_values(array_unique($columns));
                }

                continue;
            }

            if (! is_numeric($column)) {
                continue;
            }

            $index = (int) $column;
            if ($index >= 0 && $index < $columnCount) {
                $clean[$field] = $index;
            }
        }

        foreach (ColumnMapper::REQUIRED_FIELDS as $required) {
            if (! isset($clean[$required])) {
                throw new InvalidArgumentException("missing_{$required}");
            }
        }

        return $clean;
    }

    /**
     * @param  array<int, string>  $row
     */
    protected function isBlankRow(array $row): bool
    {
        foreach ($row as $cell) {
            if (trim((string) $cell) !== '') {
                return false;
            }
        }

        return true;
    }
}
