<?php

namespace App\Services\Database\Discovery;

use App\Services\Database\LiveProductSource;
use App\Services\Dataset\ColumnMapper;

/**
 * What each column of a table means, judged by its NAME and its TYPE.
 *
 * The uploaded catalog guesses this from header names alone, because a
 * spreadsheet has no types. A live database does, and the types settle cases the
 * names cannot:
 *
 *  - a shop with both `price` (decimal) and `price_text` (varchar) gets the one
 *    the search can actually compare against a number;
 *  - a `nomer` integer column is never offered as the product NAME, whatever the
 *    vocabulary says, because a product name is text;
 *  - a `qty` column that turns out to hold "в наличии" is still mapped, and
 *    LiveProductSource already knows to drop the numeric filter for it.
 *
 * The vocabulary itself is ColumnMapper's, unchanged and shared, so an owner who
 * has mapped a spreadsheet before meets the same words here.
 */
class ColumnRoleResolver
{
    /**
     * How well a column's type suits a field. Higher wins when several columns
     * match the same word; it never lets a column match a word it does not.
     */
    protected const TYPE_FIT = [
        'price' => ['numeric' => 2, 'text' => 0],
        'stock' => ['numeric' => 2, 'text' => 1],
        // A name, a description and a URL are text by nature. A numeric column
        // matching one of those words is a coincidence, not a meaning.
        'name' => ['numeric' => -1, 'text' => 2],
        'description' => ['numeric' => -1, 'text' => 2],
        'url' => ['numeric' => -1, 'text' => 2],
        // An SKU is legitimately either: an integer article number is as real as
        // a printed code.
        'sku' => ['numeric' => 1, 'text' => 1],
        'category' => ['numeric' => -1, 'text' => 2],
    ];

    /**
     * Fields that must be text. A numeric column is never offered for these,
     * even as a last resort — see TYPE_FIT's negative scores.
     *
     * `category` is here for a reason worth stating: a numeric `category_id` is
     * a FOREIGN KEY, not a category. Reading it as the label would have the
     * agent telling a customer their category is "7" — and, worse, would make
     * the field look filled, so the join that fetches the real name from
     * `categories` would never be planned.
     */
    protected const TEXT_ONLY = ['name', 'description', 'url', 'category'];

    /**
     * Read one table's columns as product fields.
     *
     * Every field is claimed by at most one column and every column by at most
     * one field, the same rule the spreadsheet mapper applies: a table with both
     * "Цена" and "Цена закупки" must not end up with two prices for the search
     * to arbitrate between.
     */
    public function resolve(DiscoveredSchema $schema, string $table): ColumnRoles
    {
        $columns = $schema->columns($table);

        if ($columns === []) {
            return new ColumnRoles;
        }

        $folded = [];
        foreach ($columns as $index => $column) {
            $folded[$index] = ColumnMapper::fold($column['name']);
        }

        $aliases = ColumnMapper::aliases();
        $roles = [];
        $numeric = [];
        $taken = [];

        // Exact matches across every field first, so a precise column name always
        // wins before any substring rule gets a chance at it — "цена" claims
        // `Цена` before the loose pass hands it `Цена закупки`.
        foreach ([true, false] as $exactPass) {
            foreach (LiveProductSource::FIELDS as $field) {
                if (isset($roles[$field]) || ! isset($aliases[$field])) {
                    continue;
                }

                $best = null;
                $bestFit = PHP_INT_MIN;

                foreach ($columns as $index => $column) {
                    if (isset($taken[$index]) || $folded[$index] === '') {
                        continue;
                    }

                    if (! $this->matches($folded[$index], $aliases[$field], $exactPass)) {
                        continue;
                    }

                    if ($this->isSurrogateKey($field, $folded[$index], (bool) $column['numeric'])) {
                        continue;
                    }

                    $fit = $this->typeFit($field, (bool) $column['numeric']);

                    if ($fit < 0) {
                        continue;
                    }

                    // Ties go to the earlier column: a table's own ordering puts
                    // the primary name before the secondary one far more often
                    // than not.
                    if ($fit > $bestFit) {
                        $bestFit = $fit;
                        $best = $index;
                    }
                }

                if ($best !== null) {
                    $roles[$field] = $columns[$best]['name'];
                    $numeric[$field] = (bool) $columns[$best]['numeric'];
                    $taken[$best] = true;
                }
            }
        }

        return new ColumnRoles($roles, $numeric);
    }

    /**
     * Whether this table could supply the given field for SOMEONE ELSE — used
     * when a product table keeps its name or its stock in a related table.
     */
    public function supplies(DiscoveredSchema $schema, string $table, string $field): bool
    {
        return $this->resolve($schema, $table)->has($field);
    }

    /**
     * @param  string[]  $aliases
     */
    protected function matches(string $column, array $aliases, bool $exact): bool
    {
        foreach ($aliases as $alias) {
            if ($exact ? $column === $alias : str_contains($column, $alias)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A numeric `id` or `<something>_id` is a KEY, not an article code.
     *
     * The catalog vocabulary lists "id" among the SKU aliases, which is right
     * for a spreadsheet — someone who typed "ID" in a header meant an article
     * number. In a database it is wrong almost every time: essentially every
     * table has a surrogate `id`, most have foreign keys beside it, and matching
     * "id" as a substring claims all of them. Offering one as the SKU would have
     * the agent reading internal row numbers out to customers on nearly every
     * catalog we discover — and `category_id` would be read out as the article
     * number of the product.
     *
     * A real article number keeps its own name — `article`, `sku`, `code`,
     * `артикул`, `штрихкод`, `product_code` — and none of those are affected.
     */
    protected function isSurrogateKey(string $field, string $folded, bool $numeric): bool
    {
        return $field === 'sku'
            && $numeric
            && ($folded === 'id' || str_ends_with($folded, ' id'));
    }

    /**
     * A negative fit means "never", not "worse": a text-only field refuses a
     * numeric column outright rather than accepting it for want of anything
     * better.
     */
    protected function typeFit(string $field, bool $numeric): int
    {
        if ($numeric && in_array($field, self::TEXT_ONLY, true)) {
            return -1;
        }

        $fit = self::TYPE_FIT[$field] ?? ['numeric' => 1, 'text' => 1];

        return $numeric ? $fit['numeric'] : $fit['text'];
    }
}
