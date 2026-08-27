<?php

namespace App\Services\Dataset;

use App\Models\Agent;
use App\Models\DatasetProduct;
use Illuminate\Database\Eloquent\Builder;

/**
 * Products from the agent's own imported catalog — the default source, and the
 * behaviour this feature had before live connections existed.
 *
 * The tenant scope leads every query and comes from the Agent the reply pipeline
 * is already holding, never from anything the model said. There is no tool
 * parameter that names an agent, so there is nothing for a prompt injection to
 * point at another tenant's catalog.
 */
class DatasetProductSource implements ProductSource
{
    use NormalizesSearchTerms;
    use PresentsProducts;

    public function __construct(protected Agent $agent) {}

    public function key(): string
    {
        return 'dataset';
    }

    /**
     * An empty dataset must not put the tool in the prompt: the model would call
     * it, get nothing, and tell the customer the shop sells nothing.
     */
    public function hasProducts(): bool
    {
        return DatasetProduct::query()->where('agent_id', $this->agent->id)->exists();
    }

    public function fetch(array $filters, int $limit): array
    {
        return $this->query($filters)
            ->limit($limit)
            ->get()
            ->map($this->present(...))
            ->all();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<DatasetProduct>
     */
    protected function query(array $filters): Builder
    {
        $query = DatasetProduct::query()->where('agent_id', $this->agent->id);

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
     * @return array<string, mixed>
     */
    protected function present(DatasetProduct $product): array
    {
        return $this->presentRow([
            'name' => $product->name,
            'price' => $product->price,
            'stock' => $product->stock,
            'category' => $product->category,
            'sku' => $product->sku,
            'url' => $product->url,
            'description' => $product->description,
            'details' => $product->extra,
        ]);
    }
}
