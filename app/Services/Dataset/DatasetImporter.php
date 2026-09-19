<?php

namespace App\Services\Dataset;

use App\Models\Agent;
use App\Models\Dataset;
use App\Models\DatasetProduct;
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
                'search_text' => DatasetProduct::searchText($name, $description, $sku, $category, $extra),
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
