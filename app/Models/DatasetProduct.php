<?php

namespace App\Models;

use Database\Factories\DatasetProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'name', 'price', 'stock', 'category', 'description', 'sku', 'url', 'extra', 'position',
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
     */
    public static function searchText(?string $name, ?string $description = null, ?string $sku = null): string
    {
        return mb_strtolower(trim(implode(' ', array_filter([$name, $description, $sku]))));
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
            $product->search_text = static::searchText($product->name, $product->description, $product->sku);
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
}
