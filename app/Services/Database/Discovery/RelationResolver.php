<?php

namespace App\Services\Database\Discovery;

use App\Services\Database\LiveProductSource;
use App\Services\Dataset\ColumnMapper;

/**
 * How the product table connects to the rest of the schema, and which of those
 * connections the platform is willing to build a query out of.
 *
 * Two jobs, in this order:
 *
 *  1. INFER the relationships a legacy schema forgot to declare. A MyISAM or
 *     hand-built database states nothing, but `products.category_id` next to a
 *     `categories` table is still a relationship — just one we worked out rather
 *     than one we were told. Inferred keys are added to the schema marked
 *     undeclared, so everything downstream can see which is which.
 *  2. PLAN the joins. Given the product table and what it is missing, find the
 *     nearest table that can supply each field, within the depth cap.
 *
 * The rule that shapes all of it: A JOIN MUST NOT MULTIPLY ROWS. Joining
 * `stocks` when a product has a row per warehouse would return that product once
 * per warehouse, and the search's 20-row cap would then spend itself on repeats
 * of the same three items. So a join is only ever offered onto a column the
 * engine guarantees is unique — or, where that guarantee is missing, onto a
 * GROUPED subquery that supplies the guarantee itself.
 *
 * That second case is not an exception to the rule, it is the rule applied to
 * the commonest table in real shops. `stocks(product_id, quantity)` with no
 * unique index on `product_id` is not a broken schema: a product legitimately
 * has one row per warehouse. What a customer means by "is it in stock" is the
 * total across them, so the planner asks for the total — `SUM(quantity) GROUP BY
 * product_id` — which is both one row per product and the semantically right
 * answer. Aggregation is deliberately narrow: only AGGREGATABLE_FIELDS, only
 * numeric columns, only one hop off the product table. Summing prices across
 * warehouses would be worse than not joining at all.
 *
 * What still cannot be joined is not silently dropped: stockNotes() reports the
 * plausible stock table that was left out and why, because a missing stock
 * column reads to an owner as "there is no stock data".
 */
class RelationResolver
{
    /**
     * Fields worth crossing a table boundary for.
     *
     * `price` and `stock` are deliberately included and `name` deliberately
     * first: the OpenCart shape (price here, name next door) is the case that
     * makes joining worth building at all.
     */
    protected const JOINABLE_FIELDS = ['name', 'category', 'stock', 'price', 'description', 'sku', 'url'];

    /**
     * Fields an aggregated join may supply, and the function it folds them with.
     *
     * Only stock, and only SUM. Adding a shop's warehouses together is what the
     * word "остаток" means to the person asking; adding its prices together is
     * not what any word means.
     *
     * @var array<string, string>
     */
    protected const AGGREGATABLE_FIELDS = ['stock' => 'sum'];

    public function __construct(protected ColumnRoleResolver $roles) {}

    /**
     * The schema plus any relationships its own naming implies.
     *
     * Only ever ADDS: a declared key on a column is a fact and is never replaced
     * by a guess about the same column. Both ends are checked against the schema
     * before the inference is kept, so this cannot introduce an identifier
     * nobody discovered.
     */
    public function infer(DiscoveredSchema $schema): DiscoveredSchema
    {
        $inferred = [];

        foreach ($schema->tableNames() as $table) {
            foreach ($schema->columns($table) as $column) {
                $target = $this->targetFor($schema, $table, $column);

                if ($target !== null) {
                    $inferred[] = new ForeignKey(
                        table: $table,
                        column: $column['name'],
                        foreignTable: $target[0],
                        foreignColumn: $target[1],
                        declared: false,
                    );
                }
            }
        }

        return $schema->withInferredKeys($inferred);
    }

    /**
     * Compose the joins that fill in what the product table itself lacks.
     *
     * Nearest first: everything reachable in one hop is considered before
     * anything two hops out, so a category on the product table's own
     * `category_id` always wins over the same field further away.
     */
    public function plan(DiscoveredSchema $schema, string $table, ColumnRoles $roles, ?int $maxDepth = null): JoinPlan
    {
        $maxDepth ??= max(1, (int) config('agent.database.discovery.max_join_depth', 2));

        $missing = [];
        foreach (self::JOINABLE_FIELDS as $field) {
            if (! $roles->has($field)) {
                $missing[$field] = true;
            }
        }

        if ($missing === [] || ! $schema->hasTable($table)) {
            return new JoinPlan;
        }

        $joins = [];
        // alias => [table, depth]. The product table is the root, depth 0.
        $reached = ['' => [$table, 0]];
        $used = [$table => true];

        // Breadth-first over safe edges. Bounded twice over — by the depth cap
        // and by the number of fields left to fill — so a wide schema cannot
        // turn this into a graph walk.
        for ($depth = 1; $depth <= $maxDepth && $missing !== []; $depth++) {
            foreach ($this->aliasesAtDepth($reached, $depth - 1) as $localAlias) {
                [$localTable] = $reached[$localAlias];

                foreach ($this->safeEdgesFrom($schema, $localTable) as $edge) {
                    if ($missing === [] || count($joins) >= $maxDepth) {
                        break 2;
                    }

                    [$targetTable, $localColumn, $foreignColumn, $declared, $asDimension, $aggregate] = $edge;

                    // A table already in the query is not visited again: it would
                    // add nothing and open the door to a cycle.
                    if (isset($used[$targetTable])) {
                        continue;
                    }

                    // An aggregated join groups by the column the product row is
                    // matched on, which only makes it one-row-per-product when
                    // that row IS the product's. Off a joined alias it would be
                    // one row per something else.
                    if ($aggregate && $localAlias !== '') {
                        continue;
                    }

                    $folds = null;

                    if ($aggregate) {
                        $folded = $this->aggregable($schema, $targetTable, $missing, $foreignColumn);
                        $supplies = $folded === null ? [] : $folded['columns'];
                        $folds = $folded['aggregate'] ?? null;
                    } else {
                        $supplies = $this->supplied($schema, $targetTable, $missing, $asDimension);
                    }

                    if ($supplies === []) {
                        continue;
                    }

                    $alias = 'j'.count($joins);

                    $joins[] = new Join(
                        table: $targetTable,
                        alias: $alias,
                        localAlias: $localAlias,
                        localColumn: $localColumn,
                        foreignColumn: $foreignColumn,
                        columns: $supplies,
                        declared: $declared,
                        aggregate: $folds,
                    );

                    $used[$targetTable] = true;
                    $reached[$alias] = [$targetTable, $depth];

                    foreach ($supplies as $field => $column) {
                        unset($missing[$field]);
                    }
                }
            }
        }

        $plan = new JoinPlan($joins);

        // Built from the schema, and checked against it anyway. The plan is
        // about to be stored and replayed on every customer question; a bug here
        // should surface now rather than on someone's production database.
        return $plan->isValid($schema, $table, $maxDepth) ? $plan : new JoinPlan;
    }

    /**
     * The table a product NAME could be joined from, or null.
     *
     * The scorer uses this rather than merely asking whether some neighbour has
     * a name column: crediting a candidate for a name we could not actually
     * fetch would propose a table the query builder cannot deliver.
     */
    public function nameSource(DiscoveredSchema $schema, string $table, ?int $maxDepth = null): ?string
    {
        return $this->fieldSource($schema, $table, 'name', $maxDepth);
    }

    /**
     * Edges out of one table that a query may safely be built along.
     *
     * Every edge carries whether the far table is a DIMENSION or an EXTENSION,
     * and that distinction is read off the schema rather than guessed from
     * names:
     *
     *  - `products.category_id → categories.id`, where `category_id` is NOT
     *    unique: many products share one row. That row describes a GROUP the
     *    product belongs to, so the only thing it can sensibly contribute is the
     *    category label. Its `name` column is a category name — taking it as the
     *    product name would have the agent reading out a list of categories.
     *  - `stocks.product_id → products.id`, where `product_id` IS unique: one
     *    row per product. That row is part OF the product, so anything on it —
     *    stock, price, description — is that product's.
     *
     * Both directions require the far side to be unique — except for the third
     * kind of edge, listed LAST because it is the last resort: a reverse edge
     * whose referencing column is NOT unique, offered only as an AGGREGATED
     * join. A plain join along it would repeat the product; a grouped subquery
     * along it cannot, and it is how `stocks(product_id, quantity)` with a row
     * per warehouse gets read at all.
     *
     * @return array<int, array{0: string, 1: string, 2: string, 3: bool, 4: bool, 5: bool}> [table, localColumn, foreignColumn, declared, isDimension, aggregate]
     */
    protected function safeEdgesFrom(DiscoveredSchema $schema, string $table): array
    {
        $edges = [];
        $aggregable = [];

        // Forward: this table holds the reference. The far column is the target
        // of a key, so it is normally unique — checked rather than assumed,
        // because an inferred key has nothing guaranteeing it.
        foreach ($schema->keysFrom($table) as $key) {
            if ($schema->isUnique($key->foreignTable, $key->foreignColumn)) {
                // A unique local column means one row each way: a 1:1 extension
                // split off the product row rather than a shared lookup.
                $isDimension = ! $schema->isUnique($table, $key->column);

                $edges[] = [$key->foreignTable, $key->column, $key->foreignColumn, $key->declared, $isDimension, false];
            }
        }

        // Reverse: something else points at us. A plain join is safe only when
        // ITS referencing column is unique — one stock row per product, not one
        // per warehouse. Where it is not, the same edge is still offered, but
        // only for the fields that can be folded into a single row per product.
        foreach ($schema->keysTo($table) as $key) {
            if ($schema->isUnique($key->table, $key->column)) {
                $edges[] = [$key->table, $key->foreignColumn, $key->column, $key->declared, false, false];

                continue;
            }

            if ($this->foldsIntoStock($key->table)) {
                $aggregable[] = [$key->table, $key->foreignColumn, $key->column, $key->declared, false, true];
            }
        }

        // Last, so an exact one-to-one companion table is always preferred over
        // adding rows up.
        return [...$edges, ...$aggregable];
    }

    /**
     * Which of the still-missing fields this table can supply.
     *
     * A dimension contributes exactly one thing — the category label — and is
     * read from its category column if it has one, or its name column, which on
     * a `categories` table IS the category name.
     *
     * With ONE exception, and it is the multi-language OpenCart shape:
     * `oc_product_description(product_id, language_id, name)` has a composite
     * key, so `product_id` is not unique and `oc_product` is classified a
     * dimension of it — many description rows to one product row. That is
     * arithmetically true and semantically misleading: the "group" here is the
     * product itself, and its price IS this row's price. So when the far table's
     * name says PRODUCT, it may supply the product's own fields.
     *
     * `name` is never among them, whatever the far table is called. That is the
     * whole point of the dimension rule: a row shared by many rows cannot lend
     * them its name, or the agent reads out a list of groups.
     *
     * @param  array<string, bool>  $missing
     * @return array<string, string> field => column
     */
    protected function supplied(DiscoveredSchema $schema, string $table, array $missing, bool $asDimension): array
    {
        $roles = $this->roles->resolve($schema, $table);

        $fields = array_keys($missing);
        $supplies = [];

        if ($asDimension) {
            $column = $roles->column('category') ?? $roles->column('name');

            if (isset($missing['category']) && $column !== null) {
                $supplies['category'] = $column;
            }

            if (! TableWords::describes($table, TableWords::PRODUCT)) {
                return $supplies;
            }

            $fields = array_diff($fields, ['name', 'category']);
        }

        foreach ($fields as $field) {
            $column = $roles->column($field);

            if ($column !== null) {
                $supplies[$field] = $column;
            }
        }

        return $supplies;
    }

    /**
     * Whether adding this table's rows up would produce a STOCK figure.
     *
     * Judged by the table's name, because its shape cannot answer it: a stock
     * table and an order-line table both hold a product reference and a
     * quantity, and summing the second one would tell a customer that a
     * sold-out product has 240 in the warehouse. A table whose name does not say
     * "stock" is not aggregated at all — it is reported through stockNotes()
     * instead, and the owner maps it by hand if that is what they meant.
     */
    protected function foldsIntoStock(string $table): bool
    {
        return TableWords::describes($table, TableWords::STOCK) && $this->couldHoldStock($table);
    }

    /**
     * Whether a quantity on this table could be a shop's stock at all — that is,
     * whether the table is neither a record of what was sold nor plumbing.
     */
    protected function couldHoldStock(string $table): bool
    {
        $words = TableWords::of($table);

        return ! TableWords::any($words, TableWords::TRANSACTION)
            && ! TableWords::any($words, TableWords::SERVICE);
    }

    /**
     * Which still-missing fields this table can supply when its rows have to be
     * FOLDED — one row per warehouse into one row per product.
     *
     * Three conditions, all of them narrow on purpose:
     *
     *  - the field is one aggregation is meaningful for (stock, and nothing
     *    else, per AGGREGATABLE_FIELDS);
     *  - the column holds numbers, because SUM of a label is not a value;
     *  - the column is not the grouping key itself, which is already selected.
     *
     * All of the fields returned share one function, because a Join carries one.
     *
     * @param  array<string, bool>  $missing
     * @return array{columns: array<string, string>, aggregate: string}|null
     */
    protected function aggregable(DiscoveredSchema $schema, string $table, array $missing, string $groupBy): ?array
    {
        $roles = $this->roles->resolve($schema, $table);

        $columns = [];
        $aggregate = null;

        foreach (self::AGGREGATABLE_FIELDS as $field => $function) {
            if (! isset($missing[$field]) || ($aggregate !== null && $aggregate !== $function)) {
                continue;
            }

            $column = $roles->column($field);

            if ($column === null || $column === $groupBy || ! $schema->isNumeric($table, $column)) {
                continue;
            }

            $columns[$field] = $column;
            $aggregate = $function;
        }

        return $aggregate === null ? null : ['columns' => $columns, 'aggregate' => $aggregate];
    }

    /**
     * Plausible stock tables that were found but NOT read, and why.
     *
     * Silence here is the failure mode this exists to prevent: a proposal with
     * no stock column reads as "this shop has no stock data", when the truth may
     * be "there is a stock table and we would not guess at it". Every entry is a
     * table one hop from the product table that resolves a stock column of its
     * own and did not make it into the plan.
     *
     * @return array<int, array{code: string, params: array<string, string>}>
     */
    public function stockNotes(DiscoveredSchema $schema, string $table, ColumnRoles $roles, JoinPlan $plan): array
    {
        // Nothing to report when the agent will have a stock value either way.
        if ($roles->has('stock') || $plan->supplies('stock')) {
            return [];
        }

        $inPlan = array_flip($plan->tables());
        $notes = [];
        $seen = [];

        foreach ([...$schema->keysTo($table), ...$schema->keysFrom($table)] as $key) {
            $related = $key->table === $table ? $key->foreignTable : $key->table;

            if ($related === $table || isset($inPlan[$related]) || isset($seen[$related])) {
                continue;
            }

            $seen[$related] = true;

            $column = $this->roles->resolve($schema, $related)->column('stock');

            // A quantity column alone is not a stock table: an order line has
            // one too, and telling the owner we found their stock in `orders`
            // would be a worse kind of wrong than saying nothing.
            if ($column === null || ! $this->couldHoldStock($related)) {
                continue;
            }

            $notes[] = [
                // A stock column that holds text ("в наличии") cannot be added
                // up, which is a different answer to the owner than "we ran out
                // of room for another join".
                'code' => $schema->isNumeric($related, $column)
                    ? 'stock_table_not_joined'
                    : 'stock_table_not_numeric',
                'params' => ['table' => $related, 'column' => $column],
            ];
        }

        return $notes;
    }

    /**
     * The table one safe hop away that could supply a field, or null.
     *
     * Asked of the JOIN PLANNER rather than of the neighbouring tables, so a
     * caller is never told about a value the query builder could not actually
     * fetch.
     */
    public function fieldSource(DiscoveredSchema $schema, string $table, string $field, ?int $maxDepth = null): ?string
    {
        $plan = $this->plan($schema, $table, new ColumnRoles, $maxDepth);

        foreach ($plan->joins as $join) {
            if (isset($join->columns[$field])) {
                return $join->table;
            }
        }

        return null;
    }

    /**
     * The table and column `<something>_id` refers to, if the naming is
     * unambiguous and the target really is unique.
     *
     * @param  array{name: string, type: string, numeric: bool}  $column
     * @return array{0: string, 1: string}|null
     */
    protected function targetFor(DiscoveredSchema $schema, string $table, array $column): ?array
    {
        if (! $column['numeric']) {
            return null;
        }

        $folded = ColumnMapper::fold($column['name']);

        if (! str_ends_with($folded, ' id')) {
            return null;
        }

        $stem = trim(substr($folded, 0, -3));

        if ($stem === '') {
            return null;
        }

        $matches = [];

        foreach ($schema->tableNames() as $candidate) {
            if ($candidate === $table || ! $this->nameMatchesStem($candidate, $stem)) {
                continue;
            }

            // The target has to be a column the engine says is unique, or the
            // join built on it would multiply rows. `id` first, then a column
            // named exactly like the referencing one (`product_id` →
            // `products.product_id`, which is how several CMSes name keys).
            foreach (['id', $column['name']] as $target) {
                if ($schema->hasColumn($candidate, $target) && $schema->isUnique($candidate, $target)) {
                    $matches[] = [$candidate, $target];
                    break;
                }
            }
        }

        // Exactly one plausible target, or none. Two tables answering to the
        // same name is an ambiguity, and guessing between them is precisely the
        // kind of silent choice this feature is built not to make.
        return count($matches) === 1 ? $matches[0] : null;
    }

    /**
     * Does this table name answer to `<stem>_id`? Handles the plural a
     * convention implies: category_id → categories, product_id → products.
     */
    protected function nameMatchesStem(string $table, string $stem): bool
    {
        $name = str_contains($table, '.') ? substr($table, strrpos($table, '.') + 1) : $table;
        $name = ColumnMapper::fold($name);

        // A prefixed schema is the norm in CMS databases: oc_products,
        // wp_posts. Compare on the last word as well as the whole name.
        $words = array_values(array_filter(explode(' ', $name), fn ($word) => $word !== ''));
        $last = $words === [] ? '' : $words[count($words) - 1];

        foreach ([$name, $last] as $subject) {
            if ($subject === '') {
                continue;
            }

            if ($subject === $stem || $subject === $stem.'s' || $subject === $stem.'es') {
                return true;
            }

            // category → categories
            if (str_ends_with($stem, 'y') && $subject === substr($stem, 0, -1).'ies') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, array{0: string, 1: int}>  $reached
     * @return array<int, string>
     */
    protected function aliasesAtDepth(array $reached, int $depth): array
    {
        $aliases = [];

        foreach ($reached as $alias => [$table, $reachedAt]) {
            if ($reachedAt === $depth) {
                $aliases[] = $alias;
            }
        }

        return $aliases;
    }

    /**
     * The fields a plan and a table together can offer, for the summary the
     * owner reads before confirming.
     *
     * @return array<int, string>
     */
    public function coveredFields(ColumnRoles $roles, JoinPlan $plan): array
    {
        $fields = [];

        foreach (LiveProductSource::FIELDS as $field) {
            if ($roles->has($field) || $plan->supplies($field)) {
                $fields[] = $field;
            }
        }

        return $fields;
    }
}
