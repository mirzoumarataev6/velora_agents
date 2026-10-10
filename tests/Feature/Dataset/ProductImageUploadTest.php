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

class ProductImageUploadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    /** @return array{0: Agent, 1: DatasetProduct} */
    protected function product(User $owner): array
    {
        $agent = Agent::factory()->for($owner)->create();
        $dataset = Dataset::factory()->for($agent)->create();
        $product = DatasetProduct::factory()->create(['agent_id' => $agent->id, 'dataset_id' => $dataset->id]);

        return [$agent, $product];
    }

    public function test_the_owner_can_upload_a_photo_for_their_own_product(): void
    {
        $user = User::factory()->create();
        [$agent, $product] = $this->product($user);

        $response = $this->actingAs($user)->postJson(
            route('agents.catalog.products.images.store', [$agent, $product->id]),
            ['image' => UploadedFile::fake()->image('omen.jpg')],
        );

        $response->assertCreated();
        $this->assertDatabaseCount('product_images', 1);
        $image = ProductImage::sole();
        $this->assertSame($product->id, $image->dataset_product_id);
        Storage::disk('public')->assertExists($image->path);
    }

    public function test_a_non_image_file_is_rejected(): void
    {
        $user = User::factory()->create();
        [$agent, $product] = $this->product($user);

        $this->actingAs($user)->postJson(
            route('agents.catalog.products.images.store', [$agent, $product->id]),
            ['image' => UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf')],
        )->assertStatus(422)->assertJsonValidationErrors('image');

        $this->assertDatabaseCount('product_images', 0);
    }

    public function test_a_file_larger_than_the_configured_limit_is_rejected(): void
    {
        $user = User::factory()->create();
        [$agent, $product] = $this->product($user);
        $tooLargeKb = (int) config('agent.product_images.max_file_size_kb') + 100;

        $this->actingAs($user)->postJson(
            route('agents.catalog.products.images.store', [$agent, $product->id]),
            ['image' => UploadedFile::fake()->create('big.jpg', $tooLargeKb, 'image/jpeg')],
        )->assertStatus(422)->assertJsonValidationErrors('image');

        $this->assertDatabaseCount('product_images', 0);
    }

    public function test_a_stranger_cannot_upload_a_photo_to_someone_elses_product(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        [$agent, $product] = $this->product($owner);

        $this->actingAs($stranger)->postJson(
            route('agents.catalog.products.images.store', [$agent, $product->id]),
            ['image' => UploadedFile::fake()->image('omen.jpg')],
        )->assertForbidden();
    }

    public function test_a_product_id_belonging_to_another_agent_is_not_found(): void
    {
        $user = User::factory()->create();
        $otherAgent = Agent::factory()->for($user)->create();
        [, $product] = $this->product($user);

        $this->actingAs($user)->postJson(
            route('agents.catalog.products.images.store', [$otherAgent, $product->id]),
            ['image' => UploadedFile::fake()->image('omen.jpg')],
        )->assertNotFound();
    }

    public function test_a_stranger_cannot_move_up_a_photo_on_someone_elses_product(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        [$agent, $product] = $this->product($owner);
        $image = ProductImage::forceCreate([
            'agent_id' => $agent->id, 'dataset_product_id' => $product->id,
            'path' => 'product-images/a.jpg', 'mime_type' => 'image/jpeg', 'size' => 10, 'position' => 0,
        ]);

        $this->actingAs($stranger)->postJson(
            route('agents.catalog.products.images.move_up', [$agent, $product->id, $image->id]),
        )->assertForbidden();
        $this->assertSame(0, $image->fresh()->position);
    }

    public function test_a_stranger_cannot_delete_a_photo_on_someone_elses_product(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        [$agent, $product] = $this->product($owner);
        $image = ProductImage::forceCreate([
            'agent_id' => $agent->id, 'dataset_product_id' => $product->id,
            'path' => 'product-images/a.jpg', 'mime_type' => 'image/jpeg', 'size' => 10, 'position' => 0,
        ]);
        Storage::disk('public')->put($image->path, 'fixture');

        $this->actingAs($stranger)->deleteJson(
            route('agents.catalog.products.images.destroy', [$agent, $product->id, $image->id]),
        )->assertForbidden();
        $this->assertDatabaseHas('product_images', ['id' => $image->id]);
        Storage::disk('public')->assertExists($image->path);
    }

    /** A product id belonging to a different agent 404s on move-up and delete too, not only on upload. */
    public function test_a_product_id_belonging_to_another_agent_is_not_found_on_move_up_and_delete(): void
    {
        $user = User::factory()->create();
        $otherAgent = Agent::factory()->for($user)->create();
        [$agent, $product] = $this->product($user);
        $image = ProductImage::forceCreate([
            'agent_id' => $agent->id, 'dataset_product_id' => $product->id,
            'path' => 'product-images/a.jpg', 'mime_type' => 'image/jpeg', 'size' => 10, 'position' => 0,
        ]);

        $this->actingAs($user)->postJson(
            route('agents.catalog.products.images.move_up', [$otherAgent, $product->id, $image->id]),
        )->assertNotFound();
        $this->actingAs($user)->deleteJson(
            route('agents.catalog.products.images.destroy', [$otherAgent, $product->id, $image->id]),
        )->assertNotFound();
    }

    /** An image id that genuinely belongs to a different product under the SAME agent must not be moved/deleted through this one. */
    public function test_an_image_belonging_to_a_different_product_under_the_same_agent_is_not_found(): void
    {
        $user = User::factory()->create();
        [$agent, $productA] = $this->product($user);
        $productB = DatasetProduct::factory()->create(['agent_id' => $agent->id, 'dataset_id' => $productA->dataset_id]);
        $imageOnB = ProductImage::forceCreate([
            'agent_id' => $agent->id, 'dataset_product_id' => $productB->id,
            'path' => 'product-images/b.jpg', 'mime_type' => 'image/jpeg', 'size' => 10, 'position' => 0,
        ]);

        $this->actingAs($user)->deleteJson(
            route('agents.catalog.products.images.destroy', [$agent, $productA->id, $imageOnB->id]),
        )->assertNotFound();
        $this->assertDatabaseHas('product_images', ['id' => $imageOnB->id]);
    }

    public function test_deleting_one_photo_removes_its_file_but_not_its_siblings(): void
    {
        $user = User::factory()->create();
        [$agent, $product] = $this->product($user);
        $first = ProductImage::forceCreate([
            'agent_id' => $agent->id, 'dataset_product_id' => $product->id,
            'path' => 'product-images/a.jpg', 'mime_type' => 'image/jpeg', 'size' => 10, 'position' => 0,
        ]);
        $second = ProductImage::forceCreate([
            'agent_id' => $agent->id, 'dataset_product_id' => $product->id,
            'path' => 'product-images/b.jpg', 'mime_type' => 'image/jpeg', 'size' => 10, 'position' => 1,
        ]);
        Storage::disk('public')->put($first->path, 'fixture');
        Storage::disk('public')->put($second->path, 'fixture');

        $this->actingAs($user)->deleteJson(
            route('agents.catalog.products.images.destroy', [$agent, $product->id, $first->id]),
        )->assertOk();

        Storage::disk('public')->assertMissing($first->path);
        Storage::disk('public')->assertExists($second->path);
        $this->assertDatabaseMissing('product_images', ['id' => $first->id]);
        $this->assertDatabaseHas('product_images', ['id' => $second->id]);
    }

    public function test_moving_a_second_photo_up_makes_it_primary(): void
    {
        $user = User::factory()->create();
        [$agent, $product] = $this->product($user);
        $primary = ProductImage::forceCreate([
            'agent_id' => $agent->id, 'dataset_product_id' => $product->id,
            'path' => 'product-images/a.jpg', 'mime_type' => 'image/jpeg', 'size' => 10, 'position' => 0,
        ]);
        $second = ProductImage::forceCreate([
            'agent_id' => $agent->id, 'dataset_product_id' => $product->id,
            'path' => 'product-images/b.jpg', 'mime_type' => 'image/jpeg', 'size' => 10, 'position' => 1,
        ]);

        $this->actingAs($user)->postJson(
            route('agents.catalog.products.images.move_up', [$agent, $product->id, $second->id]),
        )->assertOk();

        $this->assertSame(0, $second->fresh()->position);
        $this->assertSame(1, $primary->fresh()->position);
    }

    /** Replacing the whole catalog retires every old product id — their files must not be orphaned on disk. */
    public function test_replacing_the_catalog_deletes_every_old_products_photo_files(): void
    {
        $user = User::factory()->create();
        [$agent, $product] = $this->product($user);
        $image = ProductImage::forceCreate([
            'agent_id' => $agent->id, 'dataset_product_id' => $product->id,
            'path' => 'product-images/old.jpg', 'mime_type' => 'image/jpeg', 'size' => 10, 'position' => 0,
        ]);
        Storage::disk('public')->put($image->path, 'fixture');

        app(DatasetImporter::class)->import(
            $agent, 'new.csv', ['Название'], [['Новый товар']], ['name' => 0],
        );

        Storage::disk('public')->assertMissing($image->path);
        $this->assertDatabaseCount('product_images', 0);
    }
}
