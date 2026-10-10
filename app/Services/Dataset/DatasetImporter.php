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

    // Создаёт импортёр датасета с сервисом разбора значений ячеек
    public function __construct(
        protected ValueParser $values,
    ) {}

    // Импортирует строки таблицы в каталог товаров агента, заменяя прежний каталог
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

            // A replace retires every old row's id — their uploaded photos
            // cascade at the DB level, but never their files, same reasoning
            // as DatasetController::destroy().
            ProductImage::purgeFilesForAgent($agent->id);
            DatasetProduct::query()->where('agent_id', $agent->id)->delete();

            $this->insertRows($products, $dataset->id);
            $this->syncRowCount($dataset, $agent);
        });

        return ['imported' => count($products), 'skipped' => $skipped, 'reasons' => $reasons];
    }

    /**
     * Updates the agent's existing catalog instead of replacing it: a row
     * matched to an existing product (by sku, then by name — see
     * matchRows()) is updated in place, keeping its id and every uploaded
     * photo untouched; only the fields THIS file actually mapped are
     * overwritten, so a file with no stock column cannot zero out prices or
     * vice versa. An existing row nothing in the file matched is marked
     * stock=0 rather than deleted — still visible, still photographed,
     * just no longer offered as available — unless $markMissingOutOfStock is
     * false, which leaves those rows completely untouched (the owner's own
     * opt-out, confirmed at the mapping step once they've seen the count).
     * A new file row nothing existing matched is inserted, same as import()
     * would.
     */
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
            $this->insertRows($toInsert, $dataset->id);

            if ($markMissingOutOfStock) {
                $orphanIds = $existing->reject(fn (DatasetProduct $e) => isset($matchedExistingIds[$e->id]))->pluck('id');
                if ($orphanIds->isNotEmpty()) {
                    // Only stock changes — name/description/sku/category (and so
                    // search_text) are untouched, so a plain query update is
                    // enough; no need to load these as models.
                    DatasetProduct::whereIn('id', $orphanIds)->update(['stock' => 0, 'updated_at' => now()]);
                    $summary['out_of_stock'] = $orphanIds->count();
                }
            }

            $this->syncRowCount($dataset, $agent);
        });

        return $summary + ['skipped' => $skipped, 'reasons' => $reasons];
    }

    /**
     * The same matching pass update() would run, without writing anything —
     * what the mapping step shows the owner before they commit, so "N
     * products not found in the file will be marked out of stock" is a real
     * count and not a guess they only learn the cost of after confirming.
     *
     * @return array{updated: int, added: int, ambiguous: int, missing: int}
     */
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

    /**
     * Pairs existing rows with file rows: exact sku match first (only where
     * the sku is non-blank and occurs exactly once on BOTH sides — sku
     * comparison is NOT case/whitespace-normalized, since a sku is an
     * identifier the shop controls), then — among whatever neither side's
     * sku pass claimed — name match where a name occurs the SAME number of
     * times on both sides, paired in file order; name comparison IS
     * case-insensitive and collapses internal whitespace (see
     * normalizeName()), so "HP Omen 16" and "hp  omen 16" count as the same
     * name. Everything else is left unmatched; a count mismatch is reported
     * back as ambiguous rather than guessed at.
     *
     * @return array{0: list<array{0: DatasetProduct, 1: int}>, 1: array<int, true>, 2: array<int, true>, 3: array<int, true>}
     */
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
                // A name that exists on both sides but in different counts
                // is exactly the case nothing here should guess at — every
                // index in this group is reported ambiguous and inserted
                // fresh; the existing row(s) it didn't pair with fall
                // through to the out-of-stock pass untouched.
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

    /** Case/whitespace-insensitive grouping key for name matching — "HP Omen 16" and "hp  omen  16" are the same product; sku matching stays exact, since a sku is an identifier, not prose. */
    private function normalizeName(string $name): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $name)));
    }

    /**
     * Overwrites only the fields this file's mapping actually assigned a
     * column to — everything else, including the row's photos, is left
     * exactly as it was. Within a mapped field, a blank cell ALSO leaves the
     * existing value alone: a file that maps "Остаток" but has that cell
     * empty for one row means "not entered", not "zero" — ValueParser
     * already returns null for a blank/unparseable cell, so null here is
     * indistinguishable from "didn't say", and the owner never asked for a
     * blank cell to erase a price or empty out a description.
     */
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

        // search_text/category_key are recomputed from whatever this leaves
        // as the FINAL state (old values for anything not just overwritten)
        // by DatasetProduct::booted()'s own saving hook — the same one
        // every other write to this model already goes through.
        $existingProduct->fill($fill)->save();
    }

    /** The shared bulk-insert step import() and update() both end with, for whichever rows turned out to be genuinely new. */
    private function insertRows(array $products, int $datasetId): void
    {
        foreach (array_chunk($products, self::INSERT_CHUNK) as $chunk) {
            DatasetProduct::query()->insert(array_map(
                fn (array $product) => array_diff_key($product, ['extra_array' => null]) + ['dataset_id' => $datasetId],
                $chunk,
            ));
        }
    }

    /**
     * Keeps dataset.row_count meaning what the Каталог tab actually shows:
     * the catalog's real size right now. upsertDataset() is called before
     * any product row is written, with only this file's own row count to go
     * on — fine for import() (a replace ends up with exactly that many
     * rows), wrong for update() (kept + reappeared + newly out-of-stock
     * rows can leave the catalog far bigger than the file that updated it).
     * Called last, once every row this operation touches has been written.
     */
    private function syncRowCount(Dataset $dataset, Agent $agent): void
    {
        $dataset->update(['row_count' => DatasetProduct::where('agent_id', $agent->id)->count()]);
    }

    /** The one Dataset row per agent, filled in identically whichever import mode ran. */
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

    /**
     * Parses every row into the product shape both import() and update()
     * need. `extra_array` carries the decoded form alongside the
     * already-json-encoded `extra` string: the bulk insert path needs the
     * encoded string (it bypasses Eloquent casting entirely), update()'s
     * matched-row path needs the array to hand to Eloquent's own cast.
     *
     * @return array{0: list<array<string, mixed>>, 1: array<string, int>}
     */
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

    // Проверяет и очищает сопоставление столбцов, требуя обязательные поля
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

    // Проверяет, что все ячейки строки пусты
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
