<?php

namespace App\Models;

use App\Services\Dataset\SearchSkeleton;
use Database\Factories\DatasetProductFactory;
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
    /** @use HasFactory<DatasetProductFactory> */
    use HasFactory;

    /**
     * The haystack a text query is matched against, folded to lowercase.
     *
     * Built here rather than in SQL so MySQL and SQLite agree: see the migration
     * for why leaving case folding to the database does not work across both.
     *
     * The CATEGORY and the EXTRA attributes are part of the haystack, and that
     * is not cosmetic. A customer asking for "игровые ноутбуки" is using the
     * words the shop wrote in its Категория column, not in its Модель column,
     * and one asking for "ноутбук с 16 ГБ" is using the words from its ОЗУ
     * column. With only name, description and sku in here, both of those found
     * nothing in a catalog that plainly contained the product.
     *
     * @param  array<string, mixed>|null  $extra  Attribute columns kept under their own header names.
     */
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
                // The header name goes in as well as the value: "озу 16 гб"
                // answers a question phrased with either.
                $parts[] = $key.' '.$value;
            }
        }

        $haystack = implode(' ', array_filter($parts, fn ($part) => $part !== null && $part !== ''));

        // A skeleton form of every word, appended to the haystack: a Latin
        // brand name ("MacBook", "VICTUS") and its Cyrillic loanword spelling
        // ("макбук", "виктус") fold to the same skeleton — see SearchSkeleton.
        // Without this, a catalog written in Latin is invisible to a customer
        // typing the Cyrillic spelling, which is how these brand names are
        // normally written in Russian and Tajik.
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
        // Covers every write that goes through the model (factories, tinker,
        // future edit-in-place UI). The bulk importer sets these itself, since
        // it inserts rows without instantiating them.
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
            // decimal:2 keeps prices as exact strings rather than floats — a
            // price is money, and the model is going to quote it verbatim.
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

    /** Owner-uploaded photos, smallest position (primary) first. */
    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('position');
    }
}
