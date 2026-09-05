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

    /**
     * Rows fetched for the "nearest thing we do have" consolation search. Small
     * on purpose: it is a suggestion attached to a miss, not a second catalog.
     */
    public const NEAREST_LIMIT = 3;

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
                        'description' => 'Words to match against the product name, description, category and its other '
                            .'attributes (processor, memory, size, colour — whatever this catalog lists). Use the product '
                            .'words the customer actually said, without extra qualifiers; word endings do not have to match '
                            .'the catalog\'s. Omit to browse by filter alone.',
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
                        'description' => 'When true, return ONLY products with stock on hand right now. Use it just for '
                            .'"what can I pick up today", "what is left", "in stock only". Do NOT use it for "do you '
                            .'have X?", "есть ли у вас X", "у вас есть X" — those ask whether the shop SELLS the thing, '
                            .'which is an ordinary search with no stock filter. When in doubt leave it out: a catalog '
                            .'that does not track stock cannot answer it.',
                    ],
                    'category' => [
                        'type' => 'string',
                        'description' => 'Restrict to one product category, matched loosely against the catalog\'s own '
                            .'category names — every word you give must appear somewhere in the category name, in any '
                            .'order and in any grammatical form, so "игровые ноутбуки" finds "Ноутбук (игровой)".',
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
            '- Write EVERY reply, and a not-found reply above all, from the result you were just handed and from what '
                .'this conversation has already covered. Never send the same sentence twice: if you have already told '
                .'this customer you could not find something, the next one must be worded differently and must move '
                .'the conversation on — a new suggestion, a different question, the budget they named. Repeating '
                .'yourself verbatim is the single clearest sign to a customer that they are talking to a broken bot.',
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

        $dropped = $this->dropUnanswerableFilters($agent, $source, $filters);

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
            $nearest = $this->nearest($source, $filters);

            return [
                'count' => 0,
                'limit' => $limit,
                'filters' => $filters,
                'products' => [],
                'nearest' => $nearest['products'],
                'nearest_note' => $nearest['note'],
                'note' => trim($this->emptyResultNote($filters, $nearest).' '.($dropped['note'] ?? '')),
            ];
        }

        $result = [
            'count' => count($products),
            'limit' => $limit,
            'filters' => $filters,
            'products' => $products,
        ];

        if (isset($dropped['note'])) {
            $result['note'] = $dropped['note'];
        }

        return $result;
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

    /**
     * Remove filters this source physically cannot answer, and say so.
     *
     * THE BUG THIS EXISTS FOR. A catalog uploaded without a stock column stores
     * NULL stock on every row. `stock > 0` is false for NULL, so a single
     * in_stock_only argument excluded the entire catalog — every query, every
     * category, ten rows in and nothing out. And the model reaches for that
     * argument constantly, because "есть ли у вас игровые ноуты" means "do you
     * HAVE gaming laptops", which reads exactly like asking what is available.
     * The shop looked empty to its own assistant.
     *
     * Dropping the filter is right, but silently dropping it is not: the model
     * asked for in-stock products and would otherwise assume that is what came
     * back, and go on to tell the customer the item is in stock. So the note
     * says the catalog does not track stock and forbids the claim.
     *
     * @param  array<string, mixed>  $filters  Modified in place.
     * @return array{note?: string}
     */
    protected function dropUnanswerableFilters(Agent $agent, ?ProductSource $source, array &$filters): array
    {
        if (empty($filters['in_stock_only']) || $source === null || $source->tracksStock()) {
            return [];
        }

        unset($filters['in_stock_only']);

        Log::info('search_products dropped an in_stock_only filter the source cannot answer', [
            'agent_id' => $agent->id,
            'source' => $source->key(),
        ]);

        return ['note' => 'You asked for in-stock products only. This catalog does not record stock at all, so that '
            .'filter was ignored and these results are the whole range regardless of availability. Do NOT tell the '
            .'customer these items are in stock or in any way confirm availability — you were not given that. If they '
            .'need to know, offer to check.'];
    }

    /**
     * The nearest thing the catalog does have, found by dropping one filter.
     *
     * An empty result is where this feature used to fail the customer: the model
     * was handed nothing but a fixed sentence saying so, and wrote the same
     * apology every turn because that was genuinely all it had. Giving it the
     * products that were ONE filter away — the same laptops just over budget,
     * the same category without the price cap — means the not-found reply has
     * something real and different in it every time.
     *
     * Bounded, and tried in the order a customer would accept them: the budget
     * stretched first, then availability, then the category widened.
     *
     * @param  array<string, mixed>  $filters
     * @return array{products: array<int, array<string, mixed>>, note: string|null, relaxed: string|null}
     */
    protected function nearest(?ProductSource $source, array $filters): array
    {
        $none = ['products' => [], 'note' => null, 'relaxed' => null];

        if ($source === null) {
            return $none;
        }

        $relaxations = [];

        if (isset($filters['min_price']) || isset($filters['max_price'])) {
            $relaxations['price'] = array_diff_key($filters, array_flip(['min_price', 'max_price']));
        }

        if (! empty($filters['in_stock_only'])) {
            // Before the category, deliberately. Asked for a gaming laptop that
            // is in stock, "we have that one, it is just not in right now" is
            // the answer the customer wants; a monitor from a wider search is
            // not. Widening the category is the last resort, not the first.
            $relaxations['stock'] = array_diff_key($filters, array_flip(['in_stock_only']));
        }

        if (isset($filters['category'])) {
            $relaxations['category'] = array_diff_key($filters, array_flip(['category', 'min_price', 'max_price']));
        }

        foreach ($relaxations as $relaxed => $wider) {
            try {
                $products = $source->fetch($wider, self::NEAREST_LIMIT);
            } catch (\Throwable $e) {
                // The first query already failed loudly enough; a failed
                // consolation query is not worth a second log line or a turn.
                return $none;
            }

            if ($products !== []) {
                return [
                    'products' => $products,
                    'relaxed' => $relaxed,
                    'note' => match ($relaxed) {
                        'price' => 'These matched everything the customer asked for EXCEPT the price. Say plainly that '
                            .'nothing fits the budget they named, then mention what the nearest one actually costs — '
                            .'do not present these as being within budget.',
                        'stock' => 'These are what the customer asked for, but they are NOT currently in stock. Say '
                            .'that plainly and offer to let them know when it arrives — never imply availability.',
                        default => 'These are from the catalog more widely rather than the category asked about. Offer '
                            .'them as a suggestion, not as the thing that was asked for.',
                    },
                ];
            }
        }

        return $none;
    }

    /**
     * What the model is told about an empty result.
     *
     * Written per call, from the filters that were actually applied, and it says
     * so out loud. The static version of this string was the reason a customer
     * got the identical "к сожалению, ничего не нашлось" on every turn: given the
     * same sentence each time, the model repeated it each time. Naming this
     * search's own filters, and forbidding the reuse of an earlier reply, gives
     * it something specific to say and no template to fall back on.
     *
     * @param  array<string, mixed>  $filters
     * @param  array{products: array<int, array<string, mixed>>, note: string|null, relaxed: string|null}  $nearest
     */
    protected function emptyResultNote(array $filters, array $nearest): string
    {
        $note = $filters === []
            ? 'This search had no filters at all and still returned nothing, so the catalog is empty right now. '
            : 'Nothing in the catalog matched '.$this->describeFilters($filters).'. ';

        $note .= 'This is a fact about THIS search only — it does not mean the business sells nothing, and it does '
            .'not mean the product does not exist. ';

        $note .= $nearest['products'] !== []
            ? 'The "nearest" list holds real products from the catalog: '.$nearest['note'].' '
            : 'Say briefly that you could not find a match, then offer to check with a colleague or ask one '
                .'question that would widen the search (a different budget, a different type). ';

        return $note.'Write this reply from scratch for this turn. Answer in terms of what the customer actually '
            .'asked for here and what has already been discussed in this conversation — the budget, the type of '
            .'product, anything you have already offered them. Do NOT repeat, and do not lightly reword, anything '
            .'you have already said earlier in this conversation: they have read it, and hearing the same sentence '
            .'again reads as a broken bot rather than a person. Vary the wording every time.';
    }

    /**
     * The applied filters in words, so the model's reply can be about this
     * search rather than about searching in general.
     *
     * @param  array<string, mixed>  $filters
     */
    protected function describeFilters(array $filters): string
    {
        $parts = [];

        if (isset($filters['query'])) {
            $parts[] = '"'.$filters['query'].'"';
        }

        if (isset($filters['category'])) {
            $parts[] = 'category "'.$filters['category'].'"';
        }

        if (isset($filters['min_price']) && isset($filters['max_price'])) {
            $parts[] = 'a price between '.$this->amount($filters['min_price']).' and '.$this->amount($filters['max_price']);
        } elseif (isset($filters['min_price'])) {
            $parts[] = 'a price from '.$this->amount($filters['min_price']);
        } elseif (isset($filters['max_price'])) {
            $parts[] = 'a price up to '.$this->amount($filters['max_price']);
        }

        if (! empty($filters['in_stock_only'])) {
            $parts[] = 'in stock now';
        }

        return $parts === [] ? 'these filters' : implode(', ', $parts);
    }

    protected function amount(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.') ?: '0';
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
