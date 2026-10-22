<?php

namespace App\Services\Database\Discovery;

use App\Services\Dataset\ColumnMapper;

class CandidateScorer
{
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

    protected const P_TRANSACTION_TABLE = -70;

    protected const P_LINK_TABLE = -30;

    protected const P_SUPPORTING_TABLE = -25;

    public const PLAUSIBLE_SCORE = 40;

    protected const DISQUALIFYING_REASONS = [
        'looks_like_orders',
        'looks_like_service_table',
        'looks_like_link_table',
        'looks_like_supporting_table',
    ];

    public const AMBIGUOUS_MARGIN = 15;

    protected const PRODUCT_WORDS = TableWords::PRODUCT;

    protected const CATEGORY_WORDS = TableWords::CATEGORY;

    protected const STOCK_WORDS = TableWords::STOCK;

    protected const SERVICE_WORDS = TableWords::SERVICE;

    protected const TRANSACTION_WORDS = TableWords::TRANSACTION;

    public function __construct(
        protected ColumnRoleResolver $roles,
        protected RelationResolver $relations,
    ) {}

    public function rank(DiscoveredSchema $schema): array
    {
        return $this->survey($schema)['ranked'];
    }

    public function survey(DiscoveredSchema $schema): array
    {
        $ranked = [];
        $unresolved = [];

        foreach ($schema->tableNames() as $table) {
            $candidate = $this->score($schema, $table);

            if ($candidate === null) {
                continue;
            }

            if ($candidate->missing === []) {
                $ranked[] = $candidate;
            } elseif ($this->worthInterpreting($schema, $candidate)) {
                $unresolved[] = $candidate;
            }
        }

        return [
            'ranked' => $this->applyRelativeConfidence($this->sorted($ranked)),
            'unresolved' => $this->sorted($unresolved),
        ];
    }

    protected function sorted(array $candidates): array
    {
        usort($candidates, function (TableCandidate $a, TableCandidate $b) {

            return [$b->score, $a->table] <=> [$a->score, $b->table];
        });

        return $candidates;
    }

    protected function worthInterpreting(DiscoveredSchema $schema, TableCandidate $candidate): bool
    {
        foreach ($candidate->reasons as $reason) {
            if (in_array($reason['code'], self::DISQUALIFYING_REASONS, true)) {
                return false;
            }
        }

        $text = 0;
        $numeric = 0;

        foreach ($schema->columns($candidate->table) as $column) {
            $column['numeric'] ? $numeric++ : $text++;
        }

        return $text >= 1 && $numeric >= 1;
    }

    public function shortlist(array $ranked, int $limit = 3): array
    {
        $plausible = array_values(array_filter(
            $ranked,
            fn (TableCandidate $candidate) => $candidate->score >= self::PLAUSIBLE_SCORE,
        ));

        return array_slice($plausible, 0, max(1, $limit));
    }

    public function isAmbiguous(array $ranked): bool
    {
        return count($ranked) >= 2
            && $ranked[0]->score >= self::PLAUSIBLE_SCORE
            && $ranked[1]->score >= self::PLAUSIBLE_SCORE
            && ($ranked[0]->score - $ranked[1]->score) <= self::AMBIGUOUS_MARGIN;
    }

    protected function score(DiscoveredSchema $schema, string $table): ?TableCandidate
    {
        $roles = $this->roles->resolve($schema, $table);
        $words = $this->words($table);

        $score = 0;
        $reasons = [];

        $exactName = $this->matchesWholeName($table, self::PRODUCT_WORDS);
        $tokenName = $this->hasWord($words, self::PRODUCT_WORDS);

        if ($exactName) {
            $score += self::W_NAME_EXACT;
            $reasons[] = ['code' => 'table_name_matches', 'params' => ['table' => $table]];
        } elseif ($tokenName) {
            $score += self::W_NAME_TOKEN;
            $reasons[] = ['code' => 'table_name_matches', 'params' => ['table' => $table]];
        }

        $nameFrom = null;

        $missing = [];

        if ($roles->hasName()) {
            $score += self::W_HAS_NAME_COLUMN;
            $reasons[] = ['code' => 'has_name_column', 'params' => ['column' => $roles->column('name')]];
        } else {

            $nameFrom = $this->relations->nameSource($schema, $table);

            if ($nameFrom === null) {
                $missing[] = 'name';
            } else {
                $score += self::W_NAME_VIA_RELATION;
                $reasons[] = ['code' => 'name_column_in_related', 'params' => ['table' => $nameFrom]];
            }
        }

        $priceFrom = null;

        if ($roles->hasNumericPrice()) {
            $score += self::W_NUMERIC_PRICE;
            $reasons[] = ['code' => 'has_price_column', 'params' => ['column' => $roles->column('price')]];
        } elseif ($roles->has('price')) {

            $score += self::W_TEXT_PRICE;
            $reasons[] = ['code' => 'price_not_numeric', 'params' => ['column' => $roles->column('price')]];
        } else {

            $priceFrom = $this->relations->fieldSource($schema, $table, 'price');

            if ($priceFrom === null) {
                $missing[] = 'price';
            } else {

                $reasons[] = ['code' => 'price_column_in_related', 'params' => ['table' => $priceFrom]];
            }
        }

        $transliterated = $roles->from(ColumnRoles::TRANSLITERATED);

        if ($transliterated !== []) {
            $reasons[] = [
                'code' => 'transliterated_columns',
                'params' => ['columns' => implode(', ', $transliterated)],
            ];
        }

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

        $score += $this->relationshipScore($schema, $table, $roles, $reasons);

        if ($schema->isView($table) && ($exactName || $tokenName)) {
            $score += self::W_CATALOG_VIEW;
            $reasons[] = ['code' => 'is_view'];
        }

        $rowCount = $schema->rowCount($table);

        if ($rowCount !== null && $rowCount > 0) {

            $score += min(self::W_SIZE_MAX, (int) floor(log10($rowCount)) * 3);
        }

        $score += $this->penalties($schema, $table, $words, $reasons);

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
            missing: $missing,
        );
    }

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

    protected function penalties(DiscoveredSchema $schema, string $table, array $words, array &$reasons): int
    {
        $penalty = 0;

        if ($this->hasWord($words, self::SERVICE_WORDS)) {
            $penalty += self::P_SERVICE_TABLE;
            $reasons[] = ['code' => 'looks_like_service_table'];
        }

        if ($this->hasWord($words, self::TRANSACTION_WORDS)) {

            $penalty += self::P_TRANSACTION_TABLE;
            $reasons[] = ['code' => 'looks_like_orders'];
        }

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

    protected function confidenceFor(DiscoveredSchema $schema, ColumnRoles $roles, bool $nameMatched, ?string $nameFrom, ?string $priceFrom = null): string
    {

        if (! $nameMatched || $nameFrom !== null || $priceFrom !== null) {
            return TableCandidate::LOW;
        }

        if (! $roles->hasName() || ! $roles->hasNumericPrice()) {
            return TableCandidate::MEDIUM;
        }

        return $schema->hasDeclaredForeignKeys() ? TableCandidate::HIGH : TableCandidate::MEDIUM;
    }

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

    protected function words(string $table): array
    {
        return TableWords::of($table);
    }

    protected function hasWord(array $words, array $vocabulary): bool
    {
        return TableWords::any($words, $vocabulary);
    }

    protected function matchesWholeName(string $table, array $vocabulary): bool
    {
        $words = $this->words($table);

        return count($words) === 1 && $this->hasWord($words, $vocabulary);
    }
}
