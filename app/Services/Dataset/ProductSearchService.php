<?php

namespace App\Services\Dataset;

use App\Models\Agent;
use App\Models\DatasetProduct;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

/**
 * The `search_products` function-calling tool: everything the model is allowed
 * to do to an agent's catalog.
 *
 * The model never writes SQL. It fills in a fixed set of parameters, and this
 * class turns them into one parameterized, agent-scoped query. That is the whole
 * security model, and it holds two lines in particular:
 *
 *  - The tenant scope is applied here, from the Agent the reply pipeline is
 *    already holding — never from anything the model said. There is no tool
 *    parameter that names an agent, so there is nothing for a prompt injection
 *    to point at another tenant's catalog.
 *
 *  - MAX_LIMIT is enforced after the model's own limit, not before. A model that
 *    asks for 500 rows gets 20, which keeps a "list everything you have" both
 *    fast and non-exhaustive.
 */
class ProductSearchService
{
    public const TOOL_NAME = 'search_products';

    /**
     * Hard ceiling on rows returned, whatever the model asks for. Protects the
     * query, the token budget, and the customer from being handed the catalog.
     */
    public const MAX_LIMIT = 20;

    /** Used when the model names no limit — a chat reply quotes a few items, not twenty. */
    public const DEFAULT_LIMIT = 5;

    /** Longest text query accepted; anything beyond is noise for a LIKE match. */
    protected const MAX_QUERY_LENGTH = 120;

    /**
     * Whether this agent has a catalog worth offering the tool for. An empty
     * dataset must not put the tool in the prompt: the model would call it,
     * get nothing, and tell the customer the shop sells nothing.
     */
    public function enabledFor(Agent $agent): bool
    {
        return DatasetProduct::query()->where('agent_id', $agent->id)->exists();
    }

    /**
     * The tool definition in the neutral shape LlmProviderInterface::generate()
     * accepts under $options['tools'].
     *
     * @return array{name: string, description: string, parameters: array}
     */
    public function toolDefinition(): array
    {
        return [
            'name' => self::TOOL_NAME,
            'description' => 'Search this business\'s product catalog for exact, up-to-date facts: which products '
                .'exist, what they cost, what is in stock, and what categories there are. Call this whenever the '
                .'customer asks about a specific product, a price, availability, a budget ("something under 500"), '
                .'or wants a recommendation from the range. Prefer it over the knowledge base for anything involving '
                .'price or stock — it is the authoritative source for both. Call it with no filters to see a sample '
                .'of the catalog. It returns at most '.self::MAX_LIMIT.' products.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'query' => [
                        'type' => 'string',
                        'description' => 'Words to match against the product name and description. Use the product '
                            .'words the customer actually said, without extra qualifiers. Omit to browse by filter alone.',
                    ],
                    'min_price' => [
                        'type' => 'number',
                        'description' => 'Only products priced at or above this amount.',
                    ],
                    'max_price' => [
                        'type' => 'number',
                        'description' => 'Only products priced at or below this amount. Use this for "cheaper than", "up to", "budget of".',
                    ],
                    'in_stock_only' => [
                        'type' => 'boolean',
                        'description' => 'When true, return only products currently in stock. Use it when the customer '
                            .'asks what is available or wants to buy now.',
                    ],
                    'category' => [
                        'type' => 'string',
                        'description' => 'Restrict to one product category, matched loosely against the catalog\'s own category names.',
                    ],
                    'sort' => [
                        'type' => 'string',
                        'enum' => ['price_asc', 'price_desc'],
                        'description' => 'price_asc for cheapest first, price_desc for most expensive first. Omit to keep catalog order.',
                    ],
                    'limit' => [
                        'type' => 'integer',
                        'description' => 'How many products to return, 1 to '.self::MAX_LIMIT.'. Defaults to '
                            .self::DEFAULT_LIMIT.'. Ask for more only when the customer explicitly wants a list.',
                    ],
                ],
                // Nothing is required: "what do you sell?" is a legitimate call
                // with no arguments at all.
                'required' => [],
            ],
        ];
    }

    /**
     * Guidance appended to the system prompt when the catalog is non-empty.
     */
    public function systemInstruction(): string
    {
        return implode("\n", [
            'PRODUCT CATALOG:',
            '- This business has a structured product catalog, and you have a tool called "'.self::TOOL_NAME.'" to query it.',
            '- Prices, stock and availability come from that tool ONLY. Never quote a price or claim something is in '
                .'stock from memory, from the knowledge base, or from earlier in this conversation — call the tool and '
                .'report what it returns.',
            '- Call it before answering any question about what exists, what it costs, what is available, or what fits a budget.',
            '- It returns at most '.self::MAX_LIMIT.' products and may not be the whole range. Never claim it is everything '
                .'the business sells, and never say a product does not exist just because this search missed it — offer to check instead.',
            '- Report the results as normal conversation. Mention at most one or two products unless the customer asked '
                .'for a list, and never mention the tool, the search, the catalog file, or any internal system.',
            '- If it returns nothing, say briefly that you could not find a match, then offer the nearest alternative you '
                .'did see or to check with a colleague. Never present an empty result as "we have no products".',
        ]);
    }

    /**
     * Run one tool call. Returns a small structured payload for the model to
     * phrase, never a raw model or an exception.
     *
     * @param  array<string, mixed>  $args  Raw search_products arguments.
     * @return array{count: int, limit: int, filters: array<string, mixed>, products: array<int, array<string, mixed>>, note?: string}
     */
    public function search(Agent $agent, array $args): array
    {
        $limit = $this->limit($args['limit'] ?? null);
        $filters = $this->filters($args);

        try {
            $products = $this->query($agent, $filters)->limit($limit)->get();
        } catch (\Throwable $e) {
            // A failed lookup must read to the customer as "I could not find
            // that", never as an error. The reply pipeline has no better
            // recovery available at this point than an honest empty result.
            Log::error('search_products query failed', [
                'agent_id' => $agent->id,
                'filters' => $filters,
                'error' => $e->getMessage(),
            ]);

            return [
                'count' => 0,
                'limit' => $limit,
                'filters' => $filters,
                'products' => [],
                'note' => 'The catalog could not be searched just now. Do not tell the customer about this — '
                    .'answer from what you already know, or offer to check with a colleague.',
            ];
        }

        if ($products->isEmpty()) {
            return [
                'count' => 0,
                'limit' => $limit,
                'filters' => $filters,
                'products' => [],
                'note' => 'No products matched these filters. This does NOT mean the business sells nothing — the '
                    .'catalog may simply not have this item, or the filters were too narrow. Say you could not find a '
                    .'match, then offer an alternative or to check with a colleague. Do not apologize at length.',
            ];
        }

        return [
            'count' => $products->count(),
            'limit' => $limit,
            'filters' => $filters,
            'products' => $products->map($this->present(...))->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<DatasetProduct>
     */
    protected function query(Agent $agent, array $filters): Builder
    {
        // The tenant scope leads the query and comes from the Agent, not from
        // the arguments. Everything below only ever narrows it further.
        $query = DatasetProduct::query()->where('agent_id', $agent->id);

        if (isset($filters['query'])) {
            // Every term must appear in the row's search text (name, description
            // and sku, folded to lowercase at import), so "red winter jacket"
            // doesn't match everything merely red — and matching is
            // case-insensitive for Cyrillic on MySQL and SQLite alike.
            $terms = $this->terms($filters['query']);

            if ($terms === []) {
                $query->whereRaw('1 = 0');
            }

            foreach ($terms as $term) {
                $query->where('search_text', 'like', '%'.$term.'%');
            }
        }

        if (isset($filters['category'])) {
            $category = $this->escapeLike(mb_strtolower($filters['category']));

            if ($category !== '') {
                $query->where('category_key', 'like', '%'.$category.'%');
            }
        }

        if (isset($filters['min_price'])) {
            $query->where('price', '>=', $filters['min_price']);
        }

        if (isset($filters['max_price'])) {
            $query->where('price', '<=', $filters['max_price']);
        }

        if (! empty($filters['in_stock_only'])) {
            // Unknown stock (null) is deliberately excluded: the customer asked
            // for what they can buy now, and "we don't know" isn't that.
            $query->where('stock', '>', 0);
        }

        return match ($filters['sort'] ?? null) {
            // Nulls last in both directions — a product with no price is never
            // the answer to "what's your cheapest".
            'price_asc' => $query->orderByRaw('price is null')->orderBy('price')->orderBy('position'),
            'price_desc' => $query->orderByRaw('price is null')->orderByDesc('price')->orderBy('position'),
            default => $query->orderBy('position'),
        };
    }

    /**
     * Normalize the model's arguments into the filters actually applied,
     * dropping anything unusable. Returned to the model alongside the results so
     * it can see what was really searched for.
     *
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected function filters(array $args): array
    {
        $filters = [];

        $query = $this->string($args['query'] ?? null, self::MAX_QUERY_LENGTH);
        if ($query !== null) {
            $filters['query'] = $query;
        }

        $category = $this->string($args['category'] ?? null, 100);
        if ($category !== null) {
            $filters['category'] = $category;
        }

        $min = $this->money($args['min_price'] ?? null);
        $max = $this->money($args['max_price'] ?? null);

        // A model that fills both in the wrong order ("between 500 and 100")
        // would otherwise get a guaranteed-empty result and tell the customer
        // there is nothing in their budget.
        if ($min !== null && $max !== null && $min > $max) {
            [$min, $max] = [$max, $min];
        }

        if ($min !== null) {
            $filters['min_price'] = $min;
        }

        if ($max !== null) {
            $filters['max_price'] = $max;
        }

        if (filter_var($args['in_stock_only'] ?? null, FILTER_VALIDATE_BOOLEAN)) {
            $filters['in_stock_only'] = true;
        }

        if (in_array($args['sort'] ?? null, ['price_asc', 'price_desc'], true)) {
            $filters['sort'] = $args['sort'];
        }

        return $filters;
    }

    /**
     * The row count actually used: the model's request clamped into
     * [1, MAX_LIMIT], defaulting when it asked for nothing sensible.
     */
    protected function limit(mixed $requested): int
    {
        if (! is_numeric($requested)) {
            return self::DEFAULT_LIMIT;
        }

        $limit = (int) $requested;

        if ($limit < 1) {
            return self::DEFAULT_LIMIT;
        }

        return min($limit, self::MAX_LIMIT);
    }

    /**
     * Split a text query into the lowercased terms that must all match.
     *
     * Returns an empty list when nothing usable survives — a query of "%" folds
     * to nothing once wildcards are stripped, and the caller turns that into a
     * no-match rather than the LIKE '%%' that would return the whole catalog.
     *
     * @return string[]
     */
    protected function terms(string $query): array
    {
        $parts = preg_split('/\s+/u', mb_strtolower($query)) ?: [];

        $terms = [];
        foreach ($parts as $part) {
            $term = $this->escapeLike($part);
            if ($term !== '') {
                $terms[] = $term;
            }
        }

        return array_slice($terms, 0, 6);
    }

    /**
     * Strip LIKE wildcards out of a value rather than escaping them.
     *
     * Escaping would work, but it needs an explicit ESCAPE clause to behave the
     * same on MySQL and SQLite, and none of '%', '_' or '\' is a character a
     * product query legitimately needs. Removing them makes a model-supplied
     * '%' — which would otherwise match the entire catalog — inert everywhere.
     */
    protected function escapeLike(string $value): string
    {
        return str_replace(['%', '_', '\\'], '', $value);
    }

    protected function string(mixed $value, int $limit): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim((string) preg_replace('/\s+/u', ' ', $value));

        return $value === '' ? null : mb_substr($value, 0, $limit);
    }

    protected function money(mixed $value): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }

        $value = (float) $value;

        return $value < 0 ? null : $value;
    }

    /**
     * One product as the model should see it: the facts, no ids, no internals.
     *
     * @return array<string, mixed>
     */
    protected function present(DatasetProduct $product): array
    {
        $row = ['name' => $product->name];

        if ($product->price !== null) {
            // Trailing ".00" on a whole-number price reads as machine output
            // when the model echoes it back to a customer.
            $row['price'] = rtrim(rtrim((string) $product->price, '0'), '.') ?: '0';
        }

        if ($product->stock !== null) {
            $row['stock'] = $product->stock;
            $row['in_stock'] = $product->stock > 0;
        }

        foreach (['category', 'sku', 'url'] as $field) {
            if ($product->{$field} !== null) {
                $row[$field] = $product->{$field};
            }
        }

        if ($product->description !== null) {
            // Enough for the model to describe the item; not so much that twenty
            // rows crowd out the conversation.
            $row['description'] = mb_substr($product->description, 0, 300);
        }

        if (! empty($product->extra)) {
            $row['details'] = $product->extra;
        }

        return $row;
    }
}
