<?php

namespace App\Models;

use App\Models\Concerns\StampsTeam;
use App\Services\Dataset\SearchSkeleton;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'name', 'price', 'stock', 'category', 'description', 'sku', 'url', 'image_url', 'extra', 'position',
    'search_text', 'category_key',
])]
class DatasetProduct extends Model
{
    use HasFactory;
    use StampsTeam;

    public static function searchText(
        ?string $name,
        ?string $description = null,
        ?string $sku = null,
        ?string $category = null,
        ?array $extra = null,
    ): string {
        $parts = [$name, $description, $sku, $category];

        foreach ($extra ?? [] as $key => $value) {
            if (is_scalar($value) && (string) $value !== '') {

                $parts[] = $key.' '.$value;
            }
        }

        $haystack = implode(' ', array_filter($parts, fn ($part) => $part !== null && $part !== ''));

        $haystack .= ' '.implode(' ', array_map(
            SearchSkeleton::fold(...),
            preg_split('/\s+/u', $haystack) ?: [],
        ));

        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $haystack)));
    }

    public static function categoryKey(?string $category): ?string
    {
        return $category === null ? null : mb_strtolower($category);
    }

    protected static function booted(): void
    {

        static::saving(function (DatasetProduct $product) {
            $product->search_text = static::searchText(
                $product->name,
                $product->description,
                $product->sku,
                $product->category,
                is_array($product->extra) ? $product->extra : null,
            );
            $product->category_key = static::categoryKey($product->category);
        });
    }

    protected function casts(): array
    {
        return [

            'price' => 'decimal:2',
            'stock' => 'integer',
            'extra' => 'array',
        ];
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function dataset(): BelongsTo
    {
        return $this->belongsTo(Dataset::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('position');
    }
}
