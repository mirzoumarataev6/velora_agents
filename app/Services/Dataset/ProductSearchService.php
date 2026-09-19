<?php

namespace App\Services\Dataset;

use App\Models\Agent;
use Illuminate\Support\Facades\Log;

class ProductSearchService
{
    public const TOOL_NAME = 'search_products';

    public const MAX_LIMIT = 20;

    public const DEFAULT_LIMIT = 5;

    public const NEAREST_LIMIT = 3;

    protected const MAX_QUERY_LENGTH = 120;

    protected const MAX_EXCLUSIONS = 10;

    protected const CYRILLIC_TO_LATIN = [
        'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'ғ' => 'g', 'д' => 'd', 'е' => 'e', 'ё' => 'e',
        'ж' => 'zh', 'з' => 'z', 'и' => 'i', 'ӣ' => 'i', 'й' => 'i', 'к' => 'k', 'қ' => 'k', 'л' => 'l',
        'м' => 'm', 'н' => 'n', 'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't', 'у' => 'u',
        'ӯ' => 'u', 'ф' => 'f', 'х' => 'h', 'ҳ' => 'h', 'ц' => 'ts', 'ч' => 'ch', 'ҷ' => 'j', 'ш' => 'sh',
        'щ' => 'sh', 'ъ' => '', 'ы' => 'i', 'ь' => '', 'э' => 'e', 'ю' => 'yu', 'я' => 'ya',
    ];

    // Создаёт сервис поиска товаров с резолвером источников каталога
    public function __construct(protected ProductSourceResolver $sources) {}

    // Сообщает, включён ли для агента поиск по каталогу товаров
    public function enabledFor(Agent $agent): bool
    {
        return $this->sources->for($agent)?->hasProducts() === true;
    }

    // Возвращает описание инструмента поиска товаров для LLM
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
                            .'order and in any grammatical form, so "игровые ноутбуки" finds "Ноутбук (игровой)". Set it '
                            .'whenever the customer names a kind of product ("ноутбук"), budget searches included — '
                            .'otherwise the nearest alternatives can be a different kind of product.',
                    ],
                    'exclude' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => 'Brands, product families or words the customer said they do NOT want ("не '
                            .'хочу MacBook", "МакБук макул не", "not Apple"). Products whose name, category or '
                            .'description contains any of them are left out — the nearest-alternative list included. '
                            .'Once the customer has rejected something, pass it on EVERY later search in the '
                            .'conversation, not just the next one. Write each the way the catalog spells it (usually '
                            .'the Latin brand name, e.g. "MacBook").',
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
                'required' => [],
            ],
        ];
    }

    // Возвращает системную инструкцию о правилах использования поиска по каталогу
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
            '',
            'WHAT THE CUSTOMER HAS REJECTED — carry it forward:',
            '- When the customer says they do not want a product, brand or family ("МакБук макул не", "не хочу Apple", '
                .'"not a gaming one"), that stays in force for the rest of the conversation until they say otherwise.',
            '- Pass every rejected item in "exclude" on EVERY later "'.self::TOOL_NAME.'" call, not only the one right '
                .'after they said it.',
            '- Never offer, list or mention as a suggestion anything they rejected — not as the nearest option, not as '
                .'a fallback. "Something else" means something you have not already shown them.',
            '',
            'WHEN NOTHING FITS THE BUDGET — answer like a good salesperson:',
            '- Say plainly that nothing falls in the range they named.',
            '- Lead with the nearest option and state the real gap in money ("5999 — just 1 under your range", "7999 — '
                .'999 over"). Every "nearest" product carries a "budget_gap" for exactly this.',
            '- Then ask whether the budget is firm or could move a little.',
            '- Never present an over-budget product as if it were a suggestion within budget.',
        ]);
    }

    // Выполняет поиск товаров по каталогу агента с учётом фильтров из аргументов
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

            $products = $this->fetch($source, $filters, $limit);
        } catch (\Throwable $e) {
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

        $exclude = $this->exclusions($args['exclude'] ?? null);
        if ($exclude !== []) {
            $filters['exclude'] = $exclude;
        }

        return $filters;
    }

    protected function exclusions(mixed $value): array
    {
        $items = is_string($value) ? explode(',', $value) : (is_array($value) ? $value : []);
        $clean = [];

        foreach ($items as $item) {
            $item = $this->string($item, 60);

            if ($item !== null && $this->skeleton($item) !== '') {
                $clean[] = $item;
            }
        }

        return array_slice(array_values(array_unique($clean)), 0, self::MAX_EXCLUSIONS);
    }

    protected function fetch(ProductSource $source, array $filters, int $limit): array
    {
        $exclude = $filters['exclude'] ?? [];

        if ($exclude === []) {
            return $source->fetch($filters, $limit);
        }

        $rows = array_filter(
            $source->fetch($filters, self::MAX_LIMIT),
            fn (array $row) => ! $this->isExcluded($row, $exclude),
        );

        return array_slice(array_values($rows), 0, $limit);
    }

    protected function isExcluded(array $row, array $exclude): bool
    {
        $haystack = $this->skeleton(implode(' ', array_map(
            fn ($field) => is_scalar($row[$field] ?? null) ? (string) $row[$field] : '',
            ['name', 'category', 'description', 'sku'],
        )));

        foreach ($exclude as $term) {
            $needle = $this->skeleton($term);

            if ($needle !== '' && str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    protected function skeleton(string $text): string
    {
        $text = strtr(mb_strtolower($text), self::CYRILLIC_TO_LATIN);
        $text = (string) preg_replace('/[^a-z0-9]+/', '', $text);
        $text = strtr($text, ['ck' => 'k', 'c' => 'k', 'q' => 'k', 'oo' => 'u', 'ph' => 'f', 'w' => 'v', 'y' => 'i', 'x' => 'ks']);

        return (string) preg_replace('/(.)\1+/', '$1', $text);
    }

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
            $relaxations['stock'] = array_diff_key($filters, array_flip(['in_stock_only']));
        }

        if (isset($filters['category'])) {
            $relaxations['category'] = array_diff_key($filters, array_flip(['category', 'min_price', 'max_price']));
        }

        foreach ($relaxations as $relaxed => $wider) {
            try {
                $products = $relaxed === 'price'
                    ? $this->nearestByPrice($source, $wider, $filters)
                    : $this->fetch($source, $wider, self::NEAREST_LIMIT);
            } catch (\Throwable $e) {
                return $none;
            }

            if ($products !== []) {
                return [
                    'products' => $products,
                    'relaxed' => $relaxed,
                    'note' => match ($relaxed) {
                        'price' => 'These matched everything the customer asked for EXCEPT the price. They are sorted '
                            .'NEAREST FIRST, and each one\'s "budget_gap" says which side of the range it falls on '
                            .'(below_min / above_max) and by how much. Say plainly that nothing fits the budget they '
                            .'named, lead with the nearest one and state the real gap in money ("5999 — just 1 '
                            .'under your range", "7999 — 999 over"), then ask whether the budget is firm. Do not '
                            .'present these as being within budget.',
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

    protected function nearestByPrice(ProductSource $source, array $wider, array $filters): array
    {
        $min = $filters['min_price'] ?? null;
        $max = $filters['max_price'] ?? null;
        $candidates = [];

        if ($min !== null) {
            $below = [...$wider, 'max_price' => $min, 'sort' => 'price_desc'];

            foreach ($this->fetch($source, $below, self::NEAREST_LIMIT) as $row) {
                $candidates[] = $this->withBudgetGap($row, $min, $max);
            }
        }

        if ($max !== null) {
            $above = [...$wider, 'min_price' => $max, 'sort' => 'price_asc'];

            foreach ($this->fetch($source, $above, self::NEAREST_LIMIT) as $row) {
                $candidates[] = $this->withBudgetGap($row, $min, $max);
            }
        }

        $candidates = array_values(array_filter($candidates, fn (array $row) => isset($row['budget_gap'])));

        usort($candidates, fn (array $a, array $b) => $a['budget_gap']['amount'] <=> $b['budget_gap']['amount']);

        return array_slice($candidates, 0, self::NEAREST_LIMIT);
    }

    protected function withBudgetGap(array $row, ?float $min, ?float $max): array
    {
        $price = is_numeric($row['price'] ?? null)
            ? (float) $row['price']
            : $this->money(preg_replace('/[^\d.]/', '', (string) ($row['price'] ?? '')));

        if ($price === null) {
            return $row;
        }

        if ($min !== null && $price < $min) {
            $row['budget_gap'] = ['side' => 'below_min', 'amount' => $this->gap($min - $price)];
        } elseif ($max !== null && $price > $max) {
            $row['budget_gap'] = ['side' => 'above_max', 'amount' => $this->gap($price - $max)];
        }

        return $row;
    }

    protected function gap(float $amount): int|float
    {
        $amount = round($amount, 2);

        return $amount == floor($amount) ? (int) $amount : $amount;
    }

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

        if (! empty($filters['exclude'])) {
            $parts[] = 'excluding '.implode(', ', $filters['exclude']);
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
