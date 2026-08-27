<?php

namespace App\Services\Dataset;

use App\Models\Agent;
use Illuminate\Support\Facades\Log;

/**
 * The `search_products` function-calling tool: everything the model is allowed
 * to do to an agent's products.
 *
 * The model never writes SQL. It fills in a fixed set of parameters, and this
 * class validates them and hands them to whichever ProductSource the agent is
 * using — the uploaded catalog or a live connection to the business's own
 * database. That is the whole security model, and it holds three lines in
 * particular:
 *
 *  - The tenant scope is applied by the resolver, from the Agent the reply
 *    pipeline is already holding — never from anything the model said. There is
 *    no tool parameter that names an agent or a data source, so there is nothing
 *    for a prompt injection to point at another tenant's products.
 *
 *  - MAX_LIMIT is enforced after the model's own limit, not before. A model that
 *    asks for 500 rows gets 20, which keeps a "list everything you have" both
 *    fast and non-exhaustive.
 *
 *  - The tool definition, the argument validation and the result shape are the
 *    same whatever the source. An agent behaves identically whether its prices
 *    come from a spreadsheet or from the shop's live table; only where the
 *    numbers are read from changes.
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

    public function __construct(protected ProductSourceResolver $sources) {}

    /**
     * Whether this agent has products worth offering the tool for. An empty
     * catalog, or a live connection that is not finished being set up, must not
     * put the tool in the prompt: the model would call it, get nothing, and tell
     * the customer the shop sells nothing.
     */
    public function enabledFor(Agent $agent): bool
    {
        return $this->sources->for($agent)?->hasProducts() === true;
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
     * Guidance appended to the system prompt when the agent has products.
     *
     * The freshness block below is the load-bearing part, and it is written the
     * way it is because of a real failure: asked about a laptop it had already
     * quoted earlier in the same conversation, the model answered from its own
     * previous message and repeated a price that had since changed in the shop's
     * database. It only re-checked when the customer asked it to.
     *
     * That is the worst thing this feature can do — a confidently quoted stale
     * price is worse than no price — and it is not a tool bug: the model simply
     * believed it already knew. So "I saw this above" is named and forbidden
     * explicitly, rather than being left to follow from "the tool is the only
     * source", which it evidently does not.
     */
    public function systemInstruction(): string
    {
        return implode("\n", [
            'PRODUCT CATALOG:',
            '- This business has a structured product catalog, and you have a tool called "'.self::TOOL_NAME.'" to query it.',
            '- It is the ONLY authoritative source for prices, discounts, stock, availability, and which products exist. '
                .'The knowledge base is never current on any of those, and neither is your own memory.',
            '',
            'ALWAYS CALL IT FIRST — this rule has no exceptions:',
            '- Call "'.self::TOOL_NAME.'" BEFORE saying anything about a price, a discount, stock, availability, '
                .'a size, a colour, an article number, a product link, or whether a product exists at all. Every single time.',
            '- A price or stock figure that appeared EARLIER IN THIS CONVERSATION is NOT a source. Not your own earlier '
                .'reply, not an earlier tool result, not a number the customer quoted back at you. Prices and stock change '
                .'between one message and the next, and the catalog is queried live.',
            '- "I already looked this up above", "I just said it costs X", "we discussed this product a moment ago" and '
                .'"the customer already knows the price" are NOT acceptable grounding. They are exactly the cases where '
                .'you must call the tool again.',
            '- This applies in full to follow-ups about a product already discussed: "and in blue?", "how much was it '
                .'again?", "is that one still available?", "what about the cheaper one?", or the same question repeated. '
                .'Search again before answering.',
            '- Never wait to be asked to double-check. If the customer has to say "are you sure" or "please re-check", '
                .'you have already failed — the answer should have been fresh the first time.',
            '- Never announce that you are re-checking, and never say a figure is "still" or "as before" what it was. '
                .'Just answer with what the tool returned this time.',
            '',
            '- Call it with no filters to see a sample of the catalog.',
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
        $source = $this->sources->for($agent);

        try {
            if ($source === null) {
                throw new \RuntimeException('No product source is configured for this agent.');
            }

            $products = $source->fetch($filters, $limit);
        } catch (\Throwable $e) {
            // A failed lookup must read to the customer as "I could not find
            // that", never as an error. This is also the path a customer's own
            // database being down, slow or firewalled ends up on: the reply
            // pipeline has no better recovery available than an honest empty
            // result, and the customer must never see a connection error.
            Log::error('search_products query failed', [
                'agent_id' => $agent->id,
                'source' => $source?->key(),
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

        if ($products === []) {
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
            'count' => count($products),
            'limit' => $limit,
            'filters' => $filters,
            'products' => $products,
        ];
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
}
