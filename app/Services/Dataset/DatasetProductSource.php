<?php

namespace App\Services\Dataset;

use App\Models\Agent;
use App\Models\DatasetProduct;
use Illuminate\Database\Eloquent\Builder;

class DatasetProductSource implements ProductSource
{
    use NormalizesSearchTerms;
    use PresentsProducts;

    // Создаёт источник товаров из загруженного датасета агента
    public function __construct(protected Agent $agent) {}

    // Возвращает ключ, идентифицирующий этот источник товаров
    public function key(): string
    {
        return 'dataset';
    }

    // Сообщает, есть ли у агента загруженные товары
    public function hasProducts(): bool
    {
        return DatasetProduct::query()->where('agent_id', $this->agent->id)->exists();
    }

    // Сообщает, ведётся ли в датасете учёт остатков на складе
    public function tracksStock(): bool
    {
        return DatasetProduct::query()
            ->where('agent_id', $this->agent->id)
            ->whereNotNull('stock')
            ->exists();
    }

    // Возвращает товары, подходящие под фильтры, с ограничением по количеству
    public function fetch(array $filters, int $limit): array
    {
        return $this->query($filters)
            ->limit($limit)
            ->get()
            ->map($this->present(...))
            ->all();
    }

    // Строит запрос к товарам датасета с учётом фильтров поиска, категории, цены и сортировки
    protected function query(array $filters): Builder
    {
        $query = DatasetProduct::query()->where('agent_id', $this->agent->id);

        if (isset($filters['query'])) {
            $terms = $this->terms($filters['query']);

            if ($terms === []) {
                $query->whereRaw('1 = 0');
            }

            foreach ($terms as $term) {
                $query->where('search_text', 'like', '%'.$term.'%');
            }
        }

        if (isset($filters['category'])) {
            foreach ($this->categoryTerms($filters['category']) as $term) {
                $query->where('category_key', 'like', '%'.$term.'%');
            }
        }

        if (isset($filters['min_price'])) {
            $query->where('price', '>=', $filters['min_price']);
        }

        if (isset($filters['max_price'])) {
            $query->where('price', '<=', $filters['max_price']);
        }

        if (! empty($filters['in_stock_only'])) {
            $query->where('stock', '>', 0);
        }

        return match ($filters['sort'] ?? null) {
            'price_asc' => $query->orderByRaw('price is null')->orderBy('price')->orderBy('position'),
            'price_desc' => $query->orderByRaw('price is null')->orderByDesc('price')->orderBy('position'),
            default => $query->orderBy('position'),
        };
    }

    // Преобразует модель товара датасета в представление для ответа
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
