<?php

namespace App\Services\Dataset;

use App\Models\Agent;
use App\Models\Dataset;
use App\Models\DatasetProduct;
use App\Models\ProductImage;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class DatasetImporter
{
    protected const INSERT_CHUNK = 500;

    public function __construct(
        protected ValueParser $values,
    ) {}

    public function import(Agent $agent, string $filename, array $headers, array $rows, array $mapping): array
    {
        $mapping = $this->validateMapping($mapping, count($headers));
        [$products, $reasons] = $this->parseRows($agent, $headers, $rows, $mapping);

        if ($products === []) {
            throw new InvalidArgumentException('no_valid_rows');
        }

        $skipped = array_sum($reasons);

        DB::transaction(function () use ($agent, $filename, $headers, $mapping, $products, $reasons, $skipped) {
            $dataset = $this->upsertDataset($agent, $filename, $headers, $mapping, count($products), $skipped, $reasons);

            ProductImage::purgeFilesForAgent($agent->id);
            DatasetProduct::query()->where('agent_id', $agent->id)->delete();

            $this->insertRows($products, $dataset->id, $agent);
            $this->syncRowCount($dataset, $agent);
        });

        return ['imported' => count($products), 'skipped' => $skipped, 'reasons' => $reasons];
    }

    public function update(Agent $agent, string $filename, array $headers, array $rows, array $mapping, bool $markMissingOutOfStock = true): array
    {
        $mapping = $this->validateMapping($mapping, count($headers));
        [$products, $reasons] = $this->parseRows($agent, $headers, $rows, $mapping);

        if ($products === []) {
            throw new InvalidArgumentException('no_valid_rows');
        }

        $skipped = array_sum($reasons);
        $summary = ['updated' => 0, 'added' => 0, 'out_of_stock' => 0, 'ambiguous' => 0];

        DB::transaction(function () use ($agent, $filename, $headers, $mapping, $products, $reasons, $skipped, $markMissingOutOfStock, &$summary) {
            $dataset = $this->upsertDataset($agent, $filename, $headers, $mapping, count($products), $skipped, $reasons);

            $existing = DatasetProduct::where('agent_id', $agent->id)->orderBy('position')->orderBy('id')->get();
            [$pairs, $matchedNewIndexes, $matchedExistingIds, $ambiguousNewIndexes] = $this->matchRows($existing, $products);

            foreach ($pairs as [$existingProduct, $newIndex]) {
                $this->applyMatchedRow($existingProduct, $products[$newIndex], $mapping);
                $summary['updated']++;
            }

            $toInsert = [];
            foreach ($products as $index => $product) {
                if (isset($matchedNewIndexes[$index])) {
                    continue;
                }
                $toInsert[] = $product;
                $summary[isset($ambiguousNewIndexes[$index]) ? 'ambiguous' : 'added']++;
            }
            $this->insertRows($toInsert, $dataset->id, $agent);

            if ($markMissingOutOfStock) {
                $orphanIds = $existing->reject(fn (DatasetProduct $e) => isset($matchedExistingIds[$e->id]))->pluck('id');
                if ($orphanIds->isNotEmpty()) {

                    DatasetProduct::whereIn('id', $orphanIds)->update(['stock' => 0, 'updated_at' => now()]);
                    $summary['out_of_stock'] = $orphanIds->count();
                }
            }

            $this->syncRowCount($dataset, $agent);
        });

        return $summary + ['skipped' => $skipped, 'reasons' => $reasons];
    }

    public function previewUpdate(Agent $agent, array $headers, array $rows, array $mapping): array
    {
        $mapping = $this->validateMapping($mapping, count($headers));
        [$products] = $this->parseRows($agent, $headers, $rows, $mapping);

        $existing = DatasetProduct::where('agent_id', $agent->id)->orderBy('position')->orderBy('id')->get();
        [$pairs, , $matchedExistingIds, $ambiguousNewIndexes] = $this->matchRows($existing, $products);

        $missing = $existing->filter(fn (DatasetProduct $e) => ! isset($matchedExistingIds[$e->id]))->count();

        return [
            'updated' => count($pairs),
            'added' => count($products) - count($pairs) - count($ambiguousNewIndexes),
            'ambiguous' => count($ambiguousNewIndexes),
            'missing' => $missing,
        ];
    }

    private function matchRows($existing, array $products): array
    {
        $pairs = [];
        $matchedNewIndexes = [];
        $matchedExistingIds = [];
        $ambiguousNewIndexes = [];

        $existingBySku = [];
        foreach ($existing as $product) {
            $sku = trim((string) $product->sku);
            if ($sku !== '') {
                $existingBySku[$sku][] = $product;
            }
        }
        $newBySku = [];
        foreach ($products as $index => $product) {
            $sku = trim((string) ($product['sku'] ?? ''));
            if ($sku !== '') {
                $newBySku[$sku][] = $index;
            }
        }

        foreach ($existingBySku as $sku => $group) {
            if (count($group) !== 1 || ($newBySku[$sku] ?? null) === null || count($newBySku[$sku]) !== 1) {
                continue;
            }
            $existingProduct = $group[0];
            $newIndex = $newBySku[$sku][0];
            $pairs[] = [$existingProduct, $newIndex];
            $matchedNewIndexes[$newIndex] = true;
            $matchedExistingIds[$existingProduct->id] = true;
        }

        $existingByName = [];
        foreach ($existing as $product) {
            if (isset($matchedExistingIds[$product->id])) {
                continue;
            }
            $existingByName[$this->normalizeName($product->name)][] = $product;
        }
        $newByName = [];
        foreach ($products as $index => $product) {
            if (isset($matchedNewIndexes[$index])) {
                continue;
            }
            $newByName[$this->normalizeName($product['name'])][] = $index;
        }

        foreach ($newByName as $name => $newIndexGroup) {
            $existingGroup = $existingByName[$name] ?? [];

            if (count($existingGroup) !== count($newIndexGroup)) {

                if ($existingGroup !== []) {
                    foreach ($newIndexGroup as $newIndex) {
                        $ambiguousNewIndexes[$newIndex] = true;
                    }
                }

                continue;
            }

            foreach ($newIndexGroup as $position => $newIndex) {
                $existingProduct = $existingGroup[$position];
                $pairs[] = [$existingProduct, $newIndex];
                $matchedNewIndexes[$newIndex] = true;
                $matchedExistingIds[$existingProduct->id] = true;
            }
        }

        return [$pairs, $matchedNewIndexes, $matchedExistingIds, $ambiguousNewIndexes];
    }

    private function normalizeName(string $name): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $name)));
    }

    private function applyMatchedRow(DatasetProduct $existingProduct, array $newProduct, array $mapping): void
    {
        $fill = ['name' => $newProduct['name'], 'position' => $newProduct['position']];

        foreach (['price', 'stock', 'category', 'description', 'sku', 'url', 'image_url'] as $field) {
            if (isset($mapping[$field]) && $newProduct[$field] !== null) {
                $fill[$field] = $newProduct[$field];
            }
        }

        if (isset($mapping['extra']) && $newProduct['extra_array'] !== null) {
            $fill['extra'] = $newProduct['extra_array'];
        }

        $existingProduct->fill($fill)->save();
    }

    private function insertRows(array $products, int $datasetId, Agent $agent): void
    {
        foreach (array_chunk($products, self::INSERT_CHUNK) as $chunk) {
            DatasetProduct::query()->insert(array_map(

                fn (array $product) => array_diff_key($product, ['extra_array' => null]) + ['dataset_id' => $datasetId, 'team_id' => $agent->team_id],
                $chunk,
            ));
        }
    }

    private function syncRowCount(Dataset $dataset, Agent $agent): void
    {
        $dataset->update(['row_count' => DatasetProduct::where('agent_id', $agent->id)->count()]);
    }

    private function upsertDataset(Agent $agent, string $filename, array $headers, array $mapping, int $rowCount, int $skipped, array $reasons): Dataset
    {
        $dataset = Dataset::query()->firstOrNew(['agent_id' => $agent->id]);
        $dataset->agent_id = $agent->id;
        $dataset->fill([
            'original_filename' => $filename,
            'row_count' => $rowCount,
            'skipped_count' => $skipped,
            'skipped_reasons' => $reasons === [] ? null : $reasons,
            'mapping' => $mapping,
            'columns' => $headers,
            'imported_at' => now(),
        ])->save();

        return $dataset;
    }

    private function parseRows(Agent $agent, array $headers, array $rows, array $mapping): array
    {
        $nameColumn = $mapping['name'];
        $extraColumns = $mapping['extra'] ?? [];

        $products = [];
        $reasons = [];
        $position = 0;
        $now = now();

        foreach ($rows as $row) {
            $name = $this->values->text($row[$nameColumn] ?? null);

            if ($name === null) {
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
                'image_url' => isset($mapping['image_url']) ? $this->values->url($row[$mapping['image_url']] ?? null) : null,
                'search_text' => DatasetProduct::searchText($name, $description, $sku, $category, $extra),
                'category_key' => DatasetProduct::categoryKey($category),
                'extra' => $extra === [] ? null : json_encode($extra, JSON_UNESCAPED_UNICODE),
                'extra_array' => $extra === [] ? null : $extra,
                'position' => $position++,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        return [$products, $reasons];
    }

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
