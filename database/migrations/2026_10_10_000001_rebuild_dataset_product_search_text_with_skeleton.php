<?php

use App\Models\DatasetProduct;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Rebuild search_text for catalogs imported before it carried a skeleton form
 * of every word (see SearchSkeleton and DatasetProduct::searchText()).
 *
 * Without this, a catalog already in the system keeps being invisible to a
 * Cyrillic query against a Latin-named product ("макбук" never finding
 * "MacBook") until its owner happens to re-upload the file. The column is
 * derived data, so recomputing it is safe to repeat and needs no schema
 * change.
 */
return new class extends Migration
{
    public function up(): void
    {
        DatasetProduct::query()
            ->select(['id', 'name', 'description', 'sku', 'category', 'extra'])
            ->chunkById(500, function ($products) {
                foreach ($products as $product) {
                    // Written directly rather than through save(): this is a
                    // derived column being repaired, not an edit to the product,
                    // and updated_at should not move because of a migration.
                    DB::table('dataset_products')
                        ->where('id', $product->id)
                        ->update([
                            'search_text' => DatasetProduct::searchText(
                                $product->name,
                                $product->description,
                                $product->sku,
                                $product->category,
                                is_array($product->extra) ? $product->extra : null,
                            ),
                        ]);
                }
            });
    }

    public function down(): void
    {
        // Nothing to undo: search_text is derived, and the previous, narrower
        // value is not worth restoring.
    }
};
