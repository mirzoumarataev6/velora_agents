<?php

namespace App\Services\Database\Discovery;

use App\Services\Dataset\ColumnMapper;

/**
 * Which table holds the products, decided WITHOUT asking a model.
 *
 * This is the pass the whole feature is built to lean on. The signals it uses
 * are facts the schema states about itself — a declared foreign key, a column's
 * type, the name the shop's own developers chose — and on a conventionally named
 * database they settle the question outright. A language model is a tie-breaker
 * for the shortlist this produces and nothing more; if this pass is confident,
 * the model is never consulted at all.
 *
 * Three real-world cases shape the design:
 *
 *  - THE NAME MAY LIVE ELSEWHERE. OpenCart keeps price and quantity on
 *    `oc_product` and the actual product name on `oc_product_description`. A
 *    hard "must have a name column" gate would rule out the right table, so the
 *    gate is satisfiable one relationship hop away, at a lower confidence.
 *  - RETAIL AND WHOLESALE BOTH EXIST. Two tables that both look like products
 *    are not an error to be resolved by picking the higher number; the ranking
 *    is reported as close and the owner chooses. Hence word-boundary matching:
 *    `wholesale_products` must match "products" and must NOT be penalized as a
 *    "sale" table.
 *  - VIEWS ARE OFTEN THE BEST ANSWER. A `v_catalog` someone built to flatten
 *    their schema is exactly what we want, so views are ranked on the same scale
 *    with a small bonus when their name says catalog.
 */
class CandidateScorer
{
    /**
     * Weights, as plain integers so a ranking can be explained and asserted.
     *
     * They are ordered by how much they deserve to be believed: what the table
     * is CALLED and whether it has the two columns a product cannot do without
     * dominate, relationships confirm, and size only ever breaks a tie.
     */
    protected const W_NAME_EXACT = 50;

    protected const W_NAME_TOKEN = 30;

    protected const W_HAS_NAME_COLUMN = 40;

    protected const W_NAME_VIA_RELATION = 15;

    protected const W_NUMERIC_PRICE = 30;

    protected const W_TEXT_PRICE = 10;

    protected const W_NUMERIC_STOCK = 15;

    protected const W_TEXT_STOCK = 5;

    protected const W_STOCK_VIA_RELATION = 8;

    protected const W_CATEGORY_LINK = 15;

    protected const W_REFERENCED = 10;

    protected const W_SKU = 5;

    protected const W_DESCRIPTION = 5;

    protected const W_CATALOG_VIEW = 10;

    protected const W_SIZE_MAX = 10;

    protected const P_SERVICE_TABLE = -60;

    /**
     * Heavy enough to sink an orders table outright, not merely rank it second.
     * `order_items` has a product name, a price and a quantity, so on shape
     * alone it is indistinguishable from a catalog — and offering a customer a
     * row from it would quote them somebody else's past purchase. A table whose
     * name genuinely also says "products" earns most of this back.
     */
    protected const P_TRANSACTION_TABLE = -70;

    protected const P_LINK_TABLE = -30;

    /**
     * `categories` and `stocks` have a name column and get referenced by the
     * catalog, so on shape alone they look plausible. They are the catalog's
     * SUPPORTING tables — the ones discovery will go on to join — and offering
     * them as the catalog itself would be a confusing way to be wrong.
     *
     * Deliberately mild: a shop that really does call its product table
     * `inventory` still clears the plausibility floor on its other signals.
     */
    protected const P_SUPPORTING_TABLE = -25;

    /** Below this a table is not worth showing the owner at all. */
    public const PLAUSIBLE_SCORE = 40;

    /**
     * Two candidates within this many points are a genuine choice, not a
     * ranking. Both are surfaced and neither is presented as certain.
     */
    public const AMBIGUOUS_MARGIN = 15;

    /**
     * The vocabularies, shared with the join planner — see TableWords for why
     * there must be exactly one copy of them. Matched on whole words, never
     * substrings, so `wholesale_products` is a catalog rather than a sale.
     *
     * @var string[]
     */
    protected const PRODUCT_WORDS = TableWords::PRODUCT;

    /** @var string[] */
    protected const CATEGORY_WORDS = TableWords::CATEGORY;

    /** @var string[] */
    protected const STOCK_WORDS = TableWords::STOCK;

    /** @var string[] */
    protected const SERVICE_WORDS = TableWords::SERVICE;

    /** @var string[] */
    protected const TRANSACTION_WORDS = TableWords::TRANSACTION;

    public function __construct(
        protected ColumnRoleResolver $roles,
        protected RelationResolver $relations,
    ) {}

    /**
     * Rank every table in the schema, best first.
     *
     * Tables with no path to a product NAME or to a PRICE are left out entirely
     * rather than ranked last. A table the agent could not name a single product
     * from is not a weak candidate, it is not a candidate — and neither is one
     * with no price, which is what `customers` and `suppliers` are. Offering
     * those at low confidence still put "Название товара: Иван Петров" in front
     * of an owner.
     *
     * @return array<int, TableCandidate>
     */
    public function rank(DiscoveredSchema $schema): array
    {
        $candidates = [];

        foreach ($schema->tableNames() as $table) {
            $candidate = $this->score($schema, $table);

            if ($candidate !== null) {
                $candidates[] = $candidate;
            }
        }

        usort($candidates, function (TableCandidate $a, TableCandidate $b) {
            // A stable tie-break by name keeps the order deterministic, so the
            // same schema always produces the same screen.
            return [$b->score, $a->table] <=> [$a->score, $b->table];
        });

        return $this->applyRelativeConfidence($candidates);
    }

    /**
     * The candidates worth putting in front of the owner.
     *
     * More than one means a real choice — retail and wholesale, or a table and
     * the view built over it — and the caller shows them all rather than picking.
     *
     * @param  array<int, TableCandidate>  $ranked
     * @return array<int, TableCandidate>
     */
    public function shortlist(array $ranked, int $limit = 3): array
    {
        $plausible = array_values(array_filter(
            $ranked,
            fn (TableCandidate $candidate) => $candidate->score >= self::PLAUSIBLE_SCORE,
        ));

        return array_slice($plausible, 0, max(1, $limit));
    }

    /**
     * Whether the top two are close enough that choosing between them is the
     * owner's call rather than ours.
     *
     * @param  array<int, TableCandidate>  $ranked
     */
    public function isAmbiguous(array $ranked): bool
    {
        return count($ranked) >= 2
            && $ranked[0]->score >= self::PLAUSIBLE_SCORE
            && $ranked[1]->score >= self::PLAUSIBLE_SCORE
            && ($ranked[0]->score - $ranked[1]->score) <= self::AMBIGUOUS_MARGIN;
    }

    /**
     * Score one table, or null when it cannot be a product table at all.
     */
    protected function score(DiscoveredSchema $schema, string $table): ?TableCandidate
    {
        $roles = $this->roles->resolve($schema, $table);
        $words = $this->words($table);

        $score = 0;
        $reasons = [];

        // ---- What the table is called -------------------------------------

        $exactName = $this->matchesWholeName($table, self::PRODUCT_WORDS);
        $tokenName = $this->hasWord($words, self::PRODUCT_WORDS);

        if ($exactName) {
            $score += self::W_NAME_EXACT;
            $reasons[] = ['code' => 'table_name_matches', 'params' => ['table' => $table]];
        } elseif ($tokenName) {
            $score += self::W_NAME_TOKEN;
            $reasons[] = ['code' => 'table_name_matches', 'params' => ['table' => $table]];
        }

        // ---- The two columns a product cannot do without -------------------

        $nameFrom = null;

        if ($roles->hasName()) {
            $score += self::W_HAS_NAME_COLUMN;
            $reasons[] = ['code' => 'has_name_column', 'params' => ['column' => $roles->column('name')]];
        } else {
            // OpenCart and friends: the price lives here, the name one hop away.
            //
            // Asked of the JOIN PLANNER rather than merely of the neighbouring
            // tables, so a candidate is never credited for a name the query
            // builder could not actually fetch — a related table with several
            // rows per product cannot be joined, and therefore cannot rescue a
            // table that has no name of its own.
            $nameFrom = $this->relations->nameSource($schema, $table);

            if ($nameFrom === null) {
                return null;
            }

            $score += self::W_NAME_VIA_RELATION;
            $reasons[] = ['code' => 'name_column_in_related', 'params' => ['table' => $nameFrom]];
        }

        $priceFrom = null;

        if ($roles->hasNumericPrice()) {
            $score += self::W_NUMERIC_PRICE;
            $reasons[] = ['code' => 'has_price_column', 'params' => ['column' => $roles->column('price')]];
        } elseif ($roles->has('price')) {
            // Usable, but it cannot answer "cheaper than 500" — the search drops
            // numeric filters on a text price, and the owner should know why.
            $score += self::W_TEXT_PRICE;
            $reasons[] = ['code' => 'price_not_numeric', 'params' => ['column' => $roles->column('price')]];
        } else {
            // THE PLAUSIBILITY FLOOR. A shop's catalog has a price, here or one
            // safe hop away — that is what makes it a catalog rather than a list
            // of things.
            //
            // Without this gate, `customers` and `suppliers` qualify on a name
            // column alone and are offered as product tables, with previews
            // reading "Название товара: Иван Петров". Marking them low
            // confidence was never enough: the honest answer is that they are
            // not candidates, the same way `migrations` is not. A schema odd
            // enough to keep its prices somewhere we cannot reach still has the
            // advanced panel, which is what it is for.
            $priceFrom = $this->relations->fieldSource($schema, $table, 'price');

            if ($priceFrom === null) {
                return null;
            }

            // Said, but not SCORED. A price next door is what makes this table
            // admissible; it is not evidence that this table rather than its
            // neighbour is the catalog, and awarding points for it lifted a
            // narrow `product_stock` link table over the plausibility floor.
            $reasons[] = ['code' => 'price_column_in_related', 'params' => ['table' => $priceFrom]];
        }

        // ---- Supporting shape ----------------------------------------------

        if ($roles->has('stock')) {
            $score += $roles->isNumeric('stock') ? self::W_NUMERIC_STOCK : self::W_TEXT_STOCK;
            $reasons[] = ['code' => 'has_stock_column', 'params' => ['column' => $roles->column('stock')]];
        }

        if ($roles->has('sku')) {
            $score += self::W_SKU;
        }

        if ($roles->has('description')) {
            $score += self::W_DESCRIPTION;
        }

        // ---- What the schema says about how it connects ---------------------

        $score += $this->relationshipScore($schema, $table, $roles, $reasons);

        // ---- Shape and size -------------------------------------------------

        if ($schema->isView($table) && ($exactName || $tokenName)) {
            $score += self::W_CATALOG_VIEW;
            $reasons[] = ['code' => 'is_view'];
        }

        $rowCount = $schema->rowCount($table);

        if ($rowCount !== null && $rowCount > 0) {
            // Log-scaled and capped: a catalog of 8,000 outranks a lookup table
            // of 20, but a catalog of 800,000 does not outrank everything else
            // put together.
            $score += min(self::W_SIZE_MAX, (int) floor(log10($rowCount)) * 3);
        }

        $score += $this->penalties($schema, $table, $words, $reasons);

        // Said once per candidate, because it colours every relationship reason
        // above it: on a schema that declares nothing, the links were worked out
        // from column names rather than read off the catalog.
        if (! $schema->hasDeclaredForeignKeys()) {
            $reasons[] = ['code' => 'no_declared_keys'];
        }

        return new TableCandidate(
            table: $table,
            type: $schema->typeOf($table),
            score: $score,
            confidence: $this->confidenceFor($schema, $roles, $exactName || $tokenName, $nameFrom, $priceFrom),
            reasons: $reasons,
            roles: $roles,
            rowCount: $rowCount,
            nameFrom: $nameFrom,
        );
    }

    /**
     * Points for the relationships the schema declares around this table.
     *
     * @param  array<int, array{code: string, params?: array<string, mixed>}>  $reasons
     */
    protected function relationshipScore(DiscoveredSchema $schema, string $table, ColumnRoles $roles, array &$reasons): int
    {
        $score = 0;

        foreach ($schema->keysFrom($table) as $key) {
            if ($this->hasWord($this->words($key->foreignTable), self::CATEGORY_WORDS)) {
                $score += self::W_CATEGORY_LINK;
                $reasons[] = ['code' => 'linked_to_categories', 'params' => ['table' => $key->foreignTable]];
                break;
            }
        }

        $referencedBy = $schema->keysTo($table);

        if ($referencedBy !== []) {
            // Things point AT a catalog: order lines, stock rows, images. A
            // lookup table that nothing references is rarely the main entity.
            $score += self::W_REFERENCED;
            $reasons[] = ['code' => 'referenced_by_others', 'params' => ['count' => count($referencedBy)]];
        }

        if (! $roles->has('stock')) {
            foreach ($referencedBy as $key) {
                if ($this->hasWord($this->words($key->table), self::STOCK_WORDS)) {
                    $score += self::W_STOCK_VIA_RELATION;
                    $reasons[] = ['code' => 'linked_stock_table', 'params' => ['table' => $key->table]];
                    break;
                }
            }
        }

        return $score;
    }

    /**
     * @param  string[]  $words
     * @param  array<int, array{code: string, params?: array<string, mixed>}>  $reasons
     */
    protected function penalties(DiscoveredSchema $schema, string $table, array $words, array &$reasons): int
    {
        $penalty = 0;

        if ($this->hasWord($words, self::SERVICE_WORDS)) {
            $penalty += self::P_SERVICE_TABLE;
            $reasons[] = ['code' => 'looks_like_service_table'];
        }

        if ($this->hasWord($words, self::TRANSACTION_WORDS)) {
            // The one false positive that would actively harm a customer:
            // answering "what do you sell" out of somebody's past order.
            $penalty += self::P_TRANSACTION_TABLE;
            $reasons[] = ['code' => 'looks_like_orders'];
        }

        // Only when the name does not ALSO say products: `product_categories`
        // is a catalog table by one word and a lookup by another, and the
        // product word is the more specific claim.
        if (! $this->hasWord($words, self::PRODUCT_WORDS)
            && $this->hasWord($words, [...self::CATEGORY_WORDS, ...self::STOCK_WORDS])) {
            $penalty += self::P_SUPPORTING_TABLE;
            $reasons[] = ['code' => 'looks_like_supporting_table'];
        }

        if ($this->looksLikeLinkTable($schema, $table)) {
            $penalty += self::P_LINK_TABLE;
            $reasons[] = ['code' => 'looks_like_link_table'];
        }

        return $penalty;
    }

    /**
     * A narrow table of nothing but keys — `product_category`, `item_tag` — is
     * plumbing between two entities, not an entity.
     */
    protected function looksLikeLinkTable(DiscoveredSchema $schema, string $table): bool
    {
        $columns = $schema->columns($table);

        if ($columns === [] || count($columns) > 4) {
            return false;
        }

        foreach ($columns as $column) {
            $folded = ColumnMapper::fold($column['name']);

            if (! $column['numeric'] && ! str_ends_with($folded, ' id') && $folded !== 'id') {
                return false;
            }
        }

        return true;
    }

    /**
     * How much this reading deserves to be believed, before the ranking as a
     * whole is taken into account.
     */
    protected function confidenceFor(DiscoveredSchema $schema, ColumnRoles $roles, bool $nameMatched, ?string $nameFrom, ?string $priceFrom = null): string
    {
        // Recognized by column shape alone, or leaning on a related table for
        // the product name or its price — either way the owner is being asked to
        // check something we are genuinely unsure of.
        if (! $nameMatched || $nameFrom !== null || $priceFrom !== null) {
            return TableCandidate::LOW;
        }

        if (! $roles->hasName() || ! $roles->hasNumericPrice()) {
            return TableCandidate::MEDIUM;
        }

        // The full case: the schema says what this table is called, its columns
        // are the right types, and it declares how it connects to the rest.
        return $schema->hasDeclaredForeignKeys() ? TableCandidate::HIGH : TableCandidate::MEDIUM;
    }

    /**
     * Downgrade a leader the runner-up is breathing down the neck of.
     *
     * Confidence is about the ANSWER, not the table: "products scores 140" means
     * nothing if `wholesale_products` scores 138.
     *
     * @param  array<int, TableCandidate>  $candidates
     * @return array<int, TableCandidate>
     */
    protected function applyRelativeConfidence(array $candidates): array
    {
        if (! $this->isAmbiguous($candidates)) {
            return $candidates;
        }

        $candidates[0] = $candidates[0]->confidence === TableCandidate::HIGH
            ? $candidates[0]->withConfidence(TableCandidate::MEDIUM)
            : $candidates[0]->withConfidence(TableCandidate::LOW);

        return $candidates;
    }

    /**
     * A table name as comparable words: `oc_product_description` becomes
     * ['oc', 'product', 'description'], and a schema qualifier is dropped.
     *
     * Word-boundary matching rather than substring is what lets
     * `wholesale_products` be recognized as products without being mistaken for
     * a sales table.
     *
     * @return string[]
     */
    protected function words(string $table): array
    {
        return TableWords::of($table);
    }

    /**
     * @param  string[]  $words
     * @param  string[]  $vocabulary
     */
    protected function hasWord(array $words, array $vocabulary): bool
    {
        return TableWords::any($words, $vocabulary);
    }

    /**
     * @param  string[]  $vocabulary
     */
    protected function matchesWholeName(string $table, array $vocabulary): bool
    {
        $words = $this->words($table);

        return count($words) === 1 && $this->hasWord($words, $vocabulary);
    }
}
