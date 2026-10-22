<?php

namespace App\Services\Database;

use App\Models\DatabaseConnection;
use App\Services\Database\Discovery\JoinPlan;
use App\Services\Dataset\NormalizesSearchTerms;
use App\Services\Dataset\PresentsProducts;
use App\Services\Dataset\ProductSource;
use Illuminate\Support\Facades\Log;

class LiveProductSource implements ProductSource
{
    use NormalizesSearchTerms;
    use PresentsProducts;

    public const FIELDS = ['name', 'price', 'stock', 'category', 'description', 'sku', 'url', 'image_url'];

    protected const TEXT_FIELDS = ['name', 'description', 'sku', 'category'];

    public function __construct(
        protected DatabaseConnection $connection,
        protected DriverManager $drivers,
    ) {}

    public function key(): string
    {
        return 'database';
    }

    public function hasProducts(): bool
    {
        return $this->connection->isUsable() && $this->columns() !== [];
    }

    public function tracksStock(): bool
    {
        $columns = $this->withJoinedFields($this->columns(), $this->joins());

        return isset($columns['stock']) && $this->isNumeric($columns['stock']);
    }

    public function fetch(array $filters, int $limit): array
    {
        $columns = $this->columns();

        if (! isset($columns['name'])) {
            throw new DatabaseConnectionException(
                DatabaseConnectionException::SCHEMA_CHANGED,
                'The mapped product-name column no longer exists in the discovered schema.'
            );
        }

        $driver = $this->drivers->make(ConnectionConfig::fromModel($this->connection));

        $rows = $driver->query($this->buildQuery($columns, $filters, $limit));

        return array_map(fn (array $row) => $this->presentRow($row), $rows);
    }

    protected function joins(): JoinPlan
    {
        $plan = $this->connection->joinPlan();

        if ($plan->isEmpty()) {
            return $plan;
        }

        $schema = $this->connection->discoveredSchema();
        $table = (string) $this->connection->table_name;

        if ($schema !== null && $plan->isValid($schema, $table)) {
            return $plan;
        }

        Log::warning('Stored join plan no longer matches the discovered schema; querying the product table alone', [
            'agent_id' => $this->connection->agent_id,
            'table' => $table,
        ]);

        return new JoinPlan;
    }

    public function buildQuery(array $columns, array $filters, int $limit): ProductQuery
    {
        $joins = $this->joins();
        $columns = $this->withJoinedFields($columns, $joins);

        $textGroups = [];
        $conditions = [];

        if (isset($filters['query'])) {
            $searchable = array_values(array_intersect_key(
                $columns,
                array_flip(self::TEXT_FIELDS),
            ));

            $terms = $this->terms($filters['query']);

            if ($terms === [] || $searchable === []) {
                $conditions[] = ['column' => $columns['name'], 'operator' => '=', 'value' => "\x00no-match\x00"];
            }

            foreach ($terms as $term) {

                $textGroups[] = ['columns' => $searchable, 'value' => $term];
            }
        }

        if (isset($filters['category'], $columns['category'])) {

            foreach ($this->categoryTerms($filters['category']) as $term) {
                $textGroups[] = ['columns' => [$columns['category']], 'value' => $term];
            }
        }

        $priceIsNumeric = isset($columns['price']) && $this->isNumeric($columns['price']);

        if ($priceIsNumeric && isset($filters['min_price'])) {
            $conditions[] = ['column' => $columns['price'], 'operator' => '>=', 'value' => $filters['min_price']];
        }

        if ($priceIsNumeric && isset($filters['max_price'])) {
            $conditions[] = ['column' => $columns['price'], 'operator' => '<=', 'value' => $filters['max_price']];
        }

        if (! empty($filters['in_stock_only']) && isset($columns['stock']) && $this->isNumeric($columns['stock'])) {
            $conditions[] = ['column' => $columns['stock'], 'operator' => '>', 'value' => 0];
        }

        $sort = $filters['sort'] ?? null;
        $orderColumn = $priceIsNumeric && in_array($sort, ['price_asc', 'price_desc'], true)
            ? $columns['price']
            : null;

        return new ProductQuery(
            table: (string) $this->connection->table_name,
            columns: $columns,
            conditions: $conditions,
            textGroups: $textGroups,
            orderColumn: $orderColumn,
            orderDirection: $sort === 'price_desc' ? 'desc' : 'asc',
            limit: $limit,
            joins: $joins,
            schema: $joins->isEmpty() ? null : $this->connection->discoveredSchema(),
        );
    }

    protected function withJoinedFields(array $columns, JoinPlan $joins): array
    {
        foreach ($joins->fields() as $field => $source) {
            if (! isset($columns[$field]) && in_array($field, self::FIELDS, true)) {
                $columns[$field] = new ColumnRef($source['column'], $source['alias']);
            }
        }

        return $columns;
    }

    public function columns(): array
    {
        $discovered = $this->connection->columns();
        $columns = [];

        foreach (self::FIELDS as $field) {
            $column = $this->connection->mapping[$field] ?? null;

            if (is_string($column) && $column !== '' && isset($discovered[$column])) {
                $columns[$field] = new ColumnRef($column);
            }
        }

        return $columns;
    }

    protected function isNumeric(ColumnRef $ref): bool
    {
        if ($ref->isBase()) {
            return (bool) ($this->connection->columns()[$ref->column]['numeric'] ?? false);
        }

        $schema = $this->connection->discoveredSchema();

        foreach ($this->connection->joinPlan()->joins as $join) {
            if ($join->alias === $ref->alias) {
                return $schema !== null && $schema->isNumeric($join->table, $ref->column);
            }
        }

        return false;
    }
}
