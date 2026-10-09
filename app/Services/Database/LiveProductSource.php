<?php

namespace App\Services\Database;

use App\Models\DatabaseConnection;
use App\Services\Database\Discovery\JoinPlan;
use App\Services\Dataset\NormalizesSearchTerms;
use App\Services\Dataset\PresentsProducts;
use App\Services\Dataset\ProductSource;
use Illuminate\Support\Facades\Log;

/**
 * Products read live from the business's own database.
 *
 * This is the class that stands between a language model and a customer's
 * production server, so it is worth being explicit about what it does and does
 * not do:
 *
 *  - The model never writes SQL and never names a table or a column. It fills in
 *    the same seven search_products parameters it fills in for an uploaded
 *    catalog. Everything else comes from the stored connection.
 *  - Every identifier is resolved through the stored mapping AND checked against
 *    the columns discovery actually found. A mapping left over from before the
 *    shop renamed a column is dropped rather than sent to the server.
 *  - Numeric filters are applied only to columns the schema says are numeric, so
 *    a shop whose "stock" column holds "в наличии" gets a text match instead of
 *    a type error.
 *  - The connection is loaded by agent_id from the Agent the reply pipeline is
 *    holding. One agent, one connection; there is no id in the tool's parameters
 *    for anything to point elsewhere.
 */
class LiveProductSource implements ProductSource
{
    use NormalizesSearchTerms;
    use PresentsProducts;

    /** Fields the search reads. `extra` has no meaning against a live table. */
    public const FIELDS = ['name', 'price', 'stock', 'category', 'description', 'sku', 'url', 'image_url'];

    /** Columns a free-text query is matched against, in order of usefulness. */
    // The category is searched as text as well as filtered on, for the same
    // reason the imported catalog folds it into its search text: "игровые
    // ноутбуки" is the shop's category column talking, not its name column.
    protected const TEXT_FIELDS = ['name', 'description', 'sku', 'category'];

    public function __construct(
        protected DatabaseConnection $connection,
        protected DriverManager $drivers,
    ) {}

    public function key(): string
    {
        return 'database';
    }

    /**
     * Answered from the stored configuration, never by dialling the customer's
     * server: this runs on every incoming message, and a shop whose database is
     * briefly down must not have the tool silently disappear from the prompt
     * mid-conversation. A connection that is down degrades inside fetch(),
     * where the model is told to answer without it.
     */
    public function hasProducts(): bool
    {
        return $this->connection->isUsable() && $this->columns() !== [];
    }

    /**
     * Answered from the stored mapping, like hasProducts() and for the same
     * reason: no round trip to the customer's server to decide what to ask it.
     *
     * A shop that mapped no stock column, or mapped a textual one ("да"/"нет"),
     * cannot answer a stock filter — buildQuery() has always dropped it in that
     * case rather than comparing a word to zero. This exposes that same fact
     * before the query is built, so the model can be told the filter was not
     * applied instead of quietly getting unfiltered rows back.
     */
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

    /**
     * The confirmed joins, but only if they still describe the stored schema.
     *
     * A plan is validated on every search rather than trusted because it was
     * validated once. Between the owner confirming it and a customer asking a
     * question, a table can be dropped or a unique key removed — and a plan that
     * no longer matches must cost the shop its category column, not its answers.
     * So a plan that fails is dropped and the search falls back to the product
     * table alone, which is always a correct thing to read.
     */
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

    /**
     * Compose the validated query spec. Split out from fetch() so the exact
     * structure a set of tool arguments produces can be asserted in a test
     * without a live server.
     *
     * @param  array<string, ColumnRef>  $columns  field => real column
     * @param  array<string, mixed>  $filters
     */
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

            // No usable term (a query of "%" folds to nothing) means no match,
            // never "everything". An impossible condition on the name column
            // says that in SQL the driver already knows how to build.
            if ($terms === [] || $searchable === []) {
                $conditions[] = ['column' => $columns['name'], 'operator' => '=', 'value' => "\x00no-match\x00"];
            }

            foreach ($terms as $term) {
                // AND between terms, OR across the searchable columns: every
                // word the customer said has to appear somewhere in the row.
                $textGroups[] = ['columns' => $searchable, 'value' => $term];
            }
        }

        if (isset($filters['category'], $columns['category'])) {
            // Every stem of the filter must appear in the category name, in any
            // order — see DatasetProductSource for why a whole-string LIKE loses
            // "Ноутбук (игровой)".
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

        // A non-numeric stock column ("да"/"нет", "in stock") cannot be compared
        // to zero. The filter is dropped rather than guessed at; the model still
        // sees each row's stock value and can read it out.
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

    /**
     * The mapping, plus the fields the joins bring with them.
     *
     * The product table wins every contest: a shop that has a `category` column
     * of its own AND a categories table gets its own column, because that is
     * what the owner confirmed on the mapping screen.
     *
     * @param  array<string, ColumnRef>  $columns
     * @return array<string, ColumnRef>
     */
    protected function withJoinedFields(array $columns, JoinPlan $joins): array
    {
        foreach ($joins->fields() as $field => $source) {
            if (! isset($columns[$field]) && in_array($field, self::FIELDS, true)) {
                $columns[$field] = new ColumnRef($source['column'], $source['alias']);
            }
        }

        return $columns;
    }

    /**
     * The stored mapping, reduced to the fields whose columns discovery has
     * actually seen.
     *
     * This is the check that makes identifier injection a non-question: nothing
     * reaches the SQL builder that was not read off the customer's own
     * information_schema by us.
     *
     * @return array<string, ColumnRef>
     */
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

    /**
     * Whether a mapped column holds numbers, wherever it lives.
     *
     * A joined column's type comes from the schema snapshot, the same place the
     * join itself was validated against — so a category table's price column is
     * judged by what that table really holds, not by what the product table's
     * columns happen to be.
     */
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
