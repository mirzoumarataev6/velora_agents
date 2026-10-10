<?php

namespace Tests\Feature\Dataset;

use App\Models\Agent;
use App\Models\Dataset;
use App\Models\DatasetProduct;
use App\Models\ProductImage;
use App\Models\User;
use App\Services\Dataset\DatasetImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * DatasetImporter::update() — matches existing rows to the new file by sku
 * then by name (see matchRows()), updates matched rows in place without
 * touching their photos, marks anything the file no longer has as out of
 * stock instead of deleting it, and never guesses at an ambiguous match.
 */
class CatalogUpdateImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');
    }

    protected function agentWithDataset(User $user): Agent
    {
        $agent = Agent::factory()->for($user)->create();
        Dataset::factory()->for($agent)->create();

        return $agent;
    }

    protected function photoFor(DatasetProduct $product): ProductImage
    {
        Storage::disk('public')->put("product-images/{$product->id}.jpg", 'fixture');

        return ProductImage::forceCreate([
            'agent_id' => $product->agent_id, 'dataset_product_id' => $product->id,
            'path' => "product-images/{$product->id}.jpg", 'mime_type' => 'image/jpeg', 'size' => 7, 'position' => 0,
        ]);
    }

    public function test_a_sku_matched_product_is_updated_in_place_and_keeps_its_photo(): void
    {
        $user = User::factory()->create();
        $agent = $this->agentWithDataset($user);
        $product = DatasetProduct::factory()->create([
            'agent_id' => $agent->id, 'dataset_id' => $agent->dataset->id,
            'name' => 'HP OMEN 16', 'sku' => 'OMEN-16', 'price' => 14999, 'stock' => 3,
        ]);
        $image = $this->photoFor($product);

        $this->importer()->update($agent, 'new.csv', ['Название', 'Артикул', 'Цена'], [
            ['HP OMEN 16', 'OMEN-16', '15999'],
        ], ['name' => 0, 'sku' => 1, 'price' => 2]);

        $product->refresh();
        $this->assertSame('15999.00', $product->price);
        $this->assertDatabaseHas('product_images', ['id' => $image->id, 'dataset_product_id' => $product->id]);
        Storage::disk('public')->assertExists($image->path);
        $this->assertSame(1, DatasetProduct::where('agent_id', $agent->id)->count());
    }

    public function test_a_uniquely_named_product_with_no_sku_is_matched_by_name_and_keeps_its_photo(): void
    {
        $user = User::factory()->create();
        $agent = $this->agentWithDataset($user);
        $product = DatasetProduct::factory()->create([
            'agent_id' => $agent->id, 'dataset_id' => $agent->dataset->id,
            'name' => 'Чехол для телефона', 'sku' => null, 'price' => 50, 'stock' => 10,
        ]);
        $image = $this->photoFor($product);

        $this->importer()->update($agent, 'new.csv', ['Название', 'Цена'], [
            ['Чехол для телефона', '65'],
        ], ['name' => 0, 'price' => 1]);

        $product->refresh();
        $this->assertSame('65.00', $product->price);
        Storage::disk('public')->assertExists($image->path);
    }

    /** A file with no stock column must not zero out — or otherwise touch — a matched row's stock. */
    public function test_an_unmapped_field_is_left_untouched_on_a_matched_row(): void
    {
        $user = User::factory()->create();
        $agent = $this->agentWithDataset($user);
        $product = DatasetProduct::factory()->create([
            'agent_id' => $agent->id, 'dataset_id' => $agent->dataset->id,
            'name' => 'Монитор 27"', 'sku' => 'MON-27', 'price' => 2199, 'stock' => 10, 'category' => 'Мониторы',
        ]);

        $this->importer()->update($agent, 'new.csv', ['Название', 'Артикул', 'Цена'], [
            ['Монитор 27"', 'MON-27', '1999'],
        ], ['name' => 0, 'sku' => 1, 'price' => 2]);

        $product->refresh();
        $this->assertSame('1999.00', $product->price);
        // Neither stock nor category was in this file's mapping.
        $this->assertSame(10, $product->stock);
        $this->assertSame('Мониторы', $product->category);
    }

    public function test_a_product_missing_from_the_new_file_is_marked_out_of_stock_not_deleted(): void
    {
        $user = User::factory()->create();
        $agent = $this->agentWithDataset($user);
        $kept = DatasetProduct::factory()->create([
            'agent_id' => $agent->id, 'dataset_id' => $agent->dataset->id,
            'name' => 'HP OMEN 16', 'sku' => 'OMEN-16', 'stock' => 3,
        ]);
        $missing = DatasetProduct::factory()->create([
            'agent_id' => $agent->id, 'dataset_id' => $agent->dataset->id,
            'name' => 'Discontinued Laptop', 'sku' => 'OLD-1', 'stock' => 5,
        ]);
        $photo = $this->photoFor($missing);

        $summary = $this->importer()->update($agent, 'new.csv', ['Название', 'Артикул', 'Остаток'], [
            ['HP OMEN 16', 'OMEN-16', '3'],
        ], ['name' => 0, 'sku' => 1, 'stock' => 2]);

        $this->assertSame(1, $summary['updated']);
        $this->assertSame(1, $summary['out_of_stock']);
        $missing->refresh();
        $this->assertSame(0, $missing->stock);
        $this->assertSame('Discontinued Laptop', $missing->name);
        $this->assertDatabaseHas('product_images', ['id' => $photo->id]);
        // The matched row is untouched by the out-of-stock pass.
        $this->assertSame(3, $kept->refresh()->stock);
    }

    /** Name matching ignores case and collapses internal whitespace; sku matching does not need to, since it never ran this path to begin with. */
    public function test_name_matching_is_case_and_whitespace_insensitive(): void
    {
        $user = User::factory()->create();
        $agent = $this->agentWithDataset($user);
        $product = DatasetProduct::factory()->create([
            'agent_id' => $agent->id, 'dataset_id' => $agent->dataset->id,
            'name' => 'HP  Omen 16', 'sku' => null, 'price' => 100,
        ]);
        $image = $this->photoFor($product);

        $summary = $this->importer()->update($agent, 'new.csv', ['Название', 'Цена'], [
            ['hp omen 16', '150'],
        ], ['name' => 0, 'price' => 1]);

        $this->assertSame(1, $summary['updated']);
        $this->assertSame(0, $summary['added']);
        $this->assertSame('150.00', $product->refresh()->price);
        Storage::disk('public')->assertExists($image->path);
    }

    /** A blank cell for a mapped field must leave the existing value alone, not null it out. */
    public function test_a_blank_cell_on_a_mapped_field_does_not_overwrite_the_existing_value(): void
    {
        $user = User::factory()->create();
        $agent = $this->agentWithDataset($user);
        $product = DatasetProduct::factory()->create([
            'agent_id' => $agent->id, 'dataset_id' => $agent->dataset->id,
            'name' => 'Клавиатура', 'sku' => 'KB-1', 'price' => 1500, 'stock' => 20,
        ]);

        $this->importer()->update($agent, 'new.csv', ['Название', 'Артикул', 'Цена', 'Остаток'], [
            ['Клавиатура', 'KB-1', '', ''],
        ], ['name' => 0, 'sku' => 1, 'price' => 2, 'stock' => 3]);

        $product->refresh();
        $this->assertSame('1500.00', $product->price);
        $this->assertSame(20, $product->stock);
    }

    /** The same blank-cell rule applies to text fields, not just numeric ones: ValueParser::text()/longText() already return null for a blank cell, so this is really a test of applyMatchedRow's null guard, same as the price/stock test above. */
    public function test_a_blank_cell_on_a_mapped_text_field_does_not_overwrite_the_existing_value(): void
    {
        $user = User::factory()->create();
        $agent = $this->agentWithDataset($user);
        $product = DatasetProduct::factory()->create([
            'agent_id' => $agent->id, 'dataset_id' => $agent->dataset->id,
            'name' => 'Клавиатура', 'sku' => 'KB-1', 'category' => 'Периферия', 'description' => 'Механическая, подсветка RGB',
        ]);

        $this->importer()->update($agent, 'new.csv', ['Название', 'Артикул', 'Категория', 'Описание'], [
            ['Клавиатура', 'KB-1', '', ''],
        ], ['name' => 0, 'sku' => 1, 'category' => 2, 'description' => 3]);

        $product->refresh();
        $this->assertSame('Периферия', $product->category);
        $this->assertSame('Механическая, подсветка RGB', $product->description);
    }

    /** Partial file, checkbox on (default): rows the file doesn't list are still marked out of stock. */
    public function test_partial_file_with_mark_missing_enabled_zeroes_the_stock_of_missing_rows(): void
    {
        $user = User::factory()->create();
        $agent = $this->agentWithDataset($user);
        $kept = DatasetProduct::factory()->create(['agent_id' => $agent->id, 'dataset_id' => $agent->dataset->id, 'name' => 'A', 'sku' => 'A-1', 'stock' => 3]);
        $missing = DatasetProduct::factory()->create(['agent_id' => $agent->id, 'dataset_id' => $agent->dataset->id, 'name' => 'B', 'sku' => 'B-1', 'stock' => 5]);

        $summary = $this->importer()->update($agent, 'partial.csv', ['Название', 'Артикул'], [
            ['A', 'A-1'],
        ], ['name' => 0, 'sku' => 1], markMissingOutOfStock: true);

        $this->assertSame(1, $summary['out_of_stock']);
        $this->assertSame(0, $missing->refresh()->stock);
        $this->assertSame(3, $kept->refresh()->stock);
    }

    /** Partial file, checkbox off: a row the file doesn't list is left completely untouched. */
    public function test_partial_file_with_mark_missing_disabled_leaves_missing_rows_untouched(): void
    {
        $user = User::factory()->create();
        $agent = $this->agentWithDataset($user);
        $missing = DatasetProduct::factory()->create(['agent_id' => $agent->id, 'dataset_id' => $agent->dataset->id, 'name' => 'B', 'sku' => 'B-1', 'stock' => 5]);

        $summary = $this->importer()->update($agent, 'partial.csv', ['Название', 'Артикул'], [
            ['A', 'A-1'],
        ], ['name' => 0, 'sku' => 1], markMissingOutOfStock: false);

        $this->assertSame(0, $summary['out_of_stock']);
        $this->assertSame(5, $missing->refresh()->stock);
    }

    /** The HTTP layer threads the owner's checkbox choice through to the importer. */
    public function test_the_import_endpoint_honors_mark_missing_out_of_stock_flag(): void
    {
        $user = User::factory()->create();
        $agent = $this->agentWithDataset($user);
        DatasetProduct::factory()->create(['agent_id' => $agent->id, 'dataset_id' => $agent->dataset->id, 'name' => 'Old Row', 'stock' => 5]);

        $analyze = $this->actingAs($user)->post(
            route('agents.catalog.analyze', $agent),
            ['file' => UploadedFile::fake()->createWithContent('new.csv', "Название\nNew Row\n")],
            ['Accept' => 'application/json'],
        );

        $response = $this->actingAs($user)->postJson(route('agents.catalog.import', $agent), [
            'token' => $analyze->json('token'),
            'mapping' => $analyze->json('mapping'),
            'mode' => 'update',
            'mark_missing_out_of_stock' => false,
        ]);

        $response->assertOk()->assertJsonPath('out_of_stock', 0);
        $this->assertSame(5, DatasetProduct::where('name', 'Old Row')->sole()->stock);
    }

    /** The preview endpoint reports the count the mapping step shows, without writing anything. */
    public function test_the_preview_update_endpoint_reports_the_missing_count_without_writing(): void
    {
        $user = User::factory()->create();
        $agent = $this->agentWithDataset($user);
        DatasetProduct::factory()->create(['agent_id' => $agent->id, 'dataset_id' => $agent->dataset->id, 'name' => 'Old Row', 'stock' => 5]);

        $analyze = $this->actingAs($user)->post(
            route('agents.catalog.analyze', $agent),
            ['file' => UploadedFile::fake()->createWithContent('new.csv', "Название\nNew Row\n")],
            ['Accept' => 'application/json'],
        );

        $response = $this->actingAs($user)->postJson(route('agents.catalog.preview_update', $agent), [
            'token' => $analyze->json('token'),
            'mapping' => $analyze->json('mapping'),
        ]);

        $response->assertOk()->assertJsonPath('missing', 1)->assertJsonPath('added', 1);
        $this->assertSame(5, DatasetProduct::where('name', 'Old Row')->sole()->stock);
        $this->assertSame(1, DatasetProduct::where('agent_id', $agent->id)->count());
    }

    public function test_a_reappearing_product_has_its_stock_restored_from_the_file(): void
    {
        $user = User::factory()->create();
        $agent = $this->agentWithDataset($user);
        $product = DatasetProduct::factory()->create([
            'agent_id' => $agent->id, 'dataset_id' => $agent->dataset->id,
            'name' => 'Seasonal Jacket', 'sku' => 'JKT-1', 'stock' => 5,
        ]);

        // First update: this file no longer lists it.
        $this->importer()->update($agent, 'gone.csv', ['Название', 'Артикул', 'Остаток'], [
            ['Something Else', 'OTHER-1', '2'],
        ], ['name' => 0, 'sku' => 1, 'stock' => 2]);
        $this->assertSame(0, $product->refresh()->stock);

        // Second update: it's back.
        $this->importer()->update($agent, 'back.csv', ['Название', 'Артикул', 'Остаток'], [
            ['Seasonal Jacket', 'JKT-1', '12'],
        ], ['name' => 0, 'sku' => 1, 'stock' => 2]);

        $this->assertSame(12, $product->refresh()->stock);
        // Still the same row — matched, not recreated.
        $this->assertSame($product->id, DatasetProduct::where('sku', 'JKT-1')->sole()->id);
    }

    public function test_same_name_duplicates_with_equal_counts_match_in_order_and_create_no_duplicates(): void
    {
        $user = User::factory()->create();
        $agent = $this->agentWithDataset($user);
        $first = DatasetProduct::factory()->create([
            'agent_id' => $agent->id, 'dataset_id' => $agent->dataset->id,
            'name' => 'Macbook Neo 13', 'sku' => null, 'price' => 8199, 'position' => 0,
        ]);
        $second = DatasetProduct::factory()->create([
            'agent_id' => $agent->id, 'dataset_id' => $agent->dataset->id,
            'name' => 'Macbook Neo 13', 'sku' => null, 'price' => 8900, 'position' => 1,
        ]);

        $summary = $this->importer()->update($agent, 'new.csv', ['Название', 'Цена'], [
            ['Macbook Neo 13', '8300'],
            ['Macbook Neo 13', '9100'],
        ], ['name' => 0, 'price' => 1]);

        $this->assertSame(2, $summary['updated']);
        $this->assertSame(0, $summary['added']);
        $this->assertSame(0, $summary['ambiguous']);
        $this->assertSame(2, DatasetProduct::where('agent_id', $agent->id)->count());
        // File order pairs with existing file order: first existing row (the
        // one that was cheaper, created first) gets the first new price.
        $this->assertSame('8300.00', $first->refresh()->price);
        $this->assertSame('9100.00', $second->refresh()->price);
    }

    public function test_same_name_duplicates_with_unequal_counts_are_added_as_new_and_reported_ambiguous(): void
    {
        $user = User::factory()->create();
        $agent = $this->agentWithDataset($user);
        $existing = DatasetProduct::factory()->create([
            'agent_id' => $agent->id, 'dataset_id' => $agent->dataset->id,
            'name' => 'Macbook Neo 13', 'sku' => null, 'stock' => 4,
        ]);

        $summary = $this->importer()->update($agent, 'new.csv', ['Название', 'Цена'], [
            ['Macbook Neo 13', '8300'],
            ['Macbook Neo 13', '9100'],
        ], ['name' => 0, 'price' => 1]);

        $this->assertSame(0, $summary['updated']);
        $this->assertSame(2, $summary['ambiguous']);
        $this->assertSame(0, $summary['added']);
        $this->assertSame(1, $summary['out_of_stock']);
        // Never guessed: the existing row is untouched apart from stock=0,
        // and both file rows became new, distinct products.
        $this->assertSame(0, $existing->refresh()->stock);
        $this->assertSame(3, DatasetProduct::where('agent_id', $agent->id)->count());
    }

    public function test_replace_still_purges_every_photo(): void
    {
        $user = User::factory()->create();
        $agent = $this->agentWithDataset($user);
        $product = DatasetProduct::factory()->create(['agent_id' => $agent->id, 'dataset_id' => $agent->dataset->id]);
        $image = $this->photoFor($product);

        $this->importer()->import($agent, 'new.csv', ['Название'], [['Другой товар']], ['name' => 0]);

        Storage::disk('public')->assertMissing($image->path);
        $this->assertDatabaseCount('product_images', 0);
    }

    // --- the HTTP layer: mode selection -------------------------------------

    public function test_the_import_endpoint_defaults_to_replace_when_mode_is_absent(): void
    {
        $user = User::factory()->create();
        $agent = $this->agentWithDataset($user);
        DatasetProduct::factory()->create(['agent_id' => $agent->id, 'dataset_id' => $agent->dataset->id, 'name' => 'Old Row']);

        $analyze = $this->actingAs($user)->post(
            route('agents.catalog.analyze', $agent),
            ['file' => UploadedFile::fake()->createWithContent('new.csv', "Название\nNew Row\n")],
            ['Accept' => 'application/json'],
        );

        $response = $this->actingAs($user)->postJson(route('agents.catalog.import', $agent), [
            'token' => $analyze->json('token'),
            'mapping' => $analyze->json('mapping'),
        ]);

        $response->assertOk();
        $this->assertSame(1, DatasetProduct::where('agent_id', $agent->id)->count());
        $this->assertDatabaseMissing('dataset_products', ['name' => 'Old Row']);
    }

    public function test_the_import_endpoint_runs_update_mode_when_requested(): void
    {
        $user = User::factory()->create();
        $agent = $this->agentWithDataset($user);
        DatasetProduct::factory()->create(['agent_id' => $agent->id, 'dataset_id' => $agent->dataset->id, 'name' => 'Old Row', 'stock' => 5]);

        $analyze = $this->actingAs($user)->post(
            route('agents.catalog.analyze', $agent),
            ['file' => UploadedFile::fake()->createWithContent('new.csv', "Название\nNew Row\n")],
            ['Accept' => 'application/json'],
        );

        $response = $this->actingAs($user)->postJson(route('agents.catalog.import', $agent), [
            'token' => $analyze->json('token'),
            'mapping' => $analyze->json('mapping'),
            'mode' => 'update',
        ]);

        $response->assertOk()->assertJsonPath('added', 1)->assertJsonPath('out_of_stock', 1);
        $this->assertSame(0, DatasetProduct::where('name', 'Old Row')->sole()->stock);
        $this->assertDatabaseHas('dataset_products', ['name' => 'New Row']);
    }

    // --- stale warning -------------------------------------------------------

    public function test_the_catalog_is_not_flagged_stale_before_the_threshold(): void
    {
        $user = User::factory()->create();
        $agent = Agent::factory()->for($user)->create();
        Dataset::factory()->for($agent)->create([
            'imported_at' => now()->subDays((int) config('agent.dataset.stale_after_days') - 1),
        ]);

        $this->actingAs($user)->getJson(route('agents.catalog.show', $agent))
            ->assertOk()->assertJsonPath('dataset.stale', false);
    }

    public function test_the_catalog_is_flagged_stale_past_the_threshold(): void
    {
        $user = User::factory()->create();
        $agent = Agent::factory()->for($user)->create();
        Dataset::factory()->for($agent)->create([
            'imported_at' => now()->subDays((int) config('agent.dataset.stale_after_days') + 1),
        ]);

        $this->actingAs($user)->getJson(route('agents.catalog.show', $agent))
            ->assertOk()->assertJsonPath('dataset.stale', true);
    }

    private function importer(): DatasetImporter
    {
        return app(DatasetImporter::class);
    }
}
