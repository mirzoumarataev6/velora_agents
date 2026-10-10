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

    /** ProductImageThumbnailer runs on every upload; previewUrl() is built to prefer whatever it produced. */
    public function test_a_thumbnail_is_generated_on_upload_and_previewurl_points_to_it(): void
    {
        $user = User::factory()->create();
        [$agent, $product] = $this->product($user);

        $this->actingAs($user)->postJson(
            route('agents.catalog.products.images.store', [$agent, $product->id]),
            ['image' => UploadedFile::fake()->image('omen.jpg', 800, 600)],
        )->assertCreated();

        $image = ProductImage::sole();
        $this->assertNotNull($image->thumbnail_path);
        $this->assertNotSame($image->path, $image->thumbnail_path);
        Storage::disk('public')->assertExists($image->thumbnail_path);

        [$width, $height] = getimagesize(Storage::disk('public')->path($image->thumbnail_path));
        $this->assertLessThanOrEqual((int) config('agent.product_images.thumbnail_max_dimension_px'), max($width, $height));

        $this->assertSame('/storage/'.$image->thumbnail_path, $image->previewUrl());
    }

    /** A phone-sized original gets shrunk in place so every later channel send stays light — see ProductImageThumbnailer::downscale(). */
    public function test_an_oversized_original_is_downscaled_in_place_on_upload(): void
    {
        $user = User::factory()->create();
        [$agent, $product] = $this->product($user);
        $maxDimension = (int) config('agent.product_images.max_dimension_px');

        $this->actingAs($user)->postJson(
            route('agents.catalog.products.images.store', [$agent, $product->id]),
            ['image' => UploadedFile::fake()->image('big.jpg', $maxDimension + 500, (int) (($maxDimension + 500) * 0.75))],
        )->assertCreated();

        $image = ProductImage::sole();
        [$width, $height] = getimagesize(Storage::disk('public')->path($image->path));
        $this->assertLessThanOrEqual($maxDimension, max($width, $height));
    }

    /** Deleting a photo must not leave its thumbnail file orphaned on disk. */
    public function test_deleting_an_image_also_deletes_its_thumbnail_file(): void
    {
        $user = User::factory()->create();
        [$agent, $product] = $this->product($user);

        $store = $this->actingAs($user)->postJson(
            route('agents.catalog.products.images.store', [$agent, $product->id]),
            ['image' => UploadedFile::fake()->image('omen.jpg', 800, 600)],
        );
        $image = ProductImage::sole();
        $thumbnailPath = $image->thumbnail_path;
        $this->assertNotNull($thumbnailPath);

        $this->actingAs($user)->deleteJson(
            route('agents.catalog.products.images.destroy', [$agent, $product->id, $image->id]),
        )->assertOk();

        Storage::disk('public')->assertMissing($image->path);
        Storage::disk('public')->assertMissing($thumbnailPath);
    }

    /** A row from before thumbnails existed has no thumbnail_path — previewUrl() must still resolve to something. */
    public function test_preview_url_falls_back_to_the_original_when_there_is_no_thumbnail(): void
    {
        $user = User::factory()->create();
        [$agent, $product] = $this->product($user);
        Storage::disk('public')->put('product-images/legacy.jpg', 'fixture');

        $image = ProductImage::forceCreate([
            'agent_id' => $agent->id, 'dataset_product_id' => $product->id,
            'path' => 'product-images/legacy.jpg', 'thumbnail_path' => null,
            'mime_type' => 'image/jpeg', 'size' => 7, 'position' => 0,
        ]);

        $this->assertSame('/storage/product-images/legacy.jpg', $image->previewUrl());
    }

    /**
     * APP_URL in this environment is a tunnel domain the dashboard isn't
     * necessarily opened through — see ProductImage::previewUrl(). The
     * admin UI's own upload response must stay relative so the browser
     * resolves it against whatever origin it is actually viewing from.
     */
    public function test_the_upload_response_url_is_relative_not_built_from_app_url(): void
    {
        $user = User::factory()->create();
        [$agent, $product] = $this->product($user);

        // Storage::fake() drops the disk's own 'url' config by default,
        // which would make url() look relative too and so make this
        // assertion pass for the wrong reason — see
        // ProductPhotoPipelineTest's equivalent note. Carrying it forward
        // here is what proves previewUrl() (not url()) answered this
        // request.
        Storage::fake('public', ['url' => config('filesystems.disks.public.url')]);

        $response = $this->actingAs($user)->postJson(
            route('agents.catalog.products.images.store', [$agent, $product->id]),
            ['image' => UploadedFile::fake()->image('omen.jpg')],
        );

        $url = $response->json('image.url');
        $this->assertStringStartsWith('/storage/', $url);
        $this->assertStringNotContainsString(config('app.url'), $url);
    }

    /** The Каталог tab's own product listing is the other admin-facing payload that must stay relative. */
    public function test_the_catalog_products_listing_also_returns_a_relative_photo_url(): void
    {
        $user = User::factory()->create();
        [$agent, $product] = $this->product($user);
        // Same reasoning as the upload test above: keep the disk's real
        // 'url' in play so a relative result actually means previewUrl().
        Storage::fake('public', ['url' => config('filesystems.disks.public.url')]);
        Storage::disk('public')->put('product-images/x.jpg', 'fixture');
        ProductImage::forceCreate([
            'agent_id' => $agent->id, 'dataset_product_id' => $product->id,
            'path' => 'product-images/x.jpg', 'mime_type' => 'image/jpeg', 'size' => 7, 'position' => 0,
        ]);

        $response = $this->actingAs($user)->getJson(route('agents.catalog.products', $agent));

        $url = $response->json('preview.0.images.0.url');
        $this->assertStringStartsWith('/storage/', $url);
        $this->assertStringNotContainsString(config('app.url'), $url);
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

    /**
     * The bug this test guards against: a rapid double click used to send
     * two DELETEs for the same photo — the first removed it, the second
     * hit a missing row and (via Laravel's default implicit-route-model-
     * binding rendering) leaked "No query results for model
     * [App\Models\ProductImage] N" straight to the UI as a raw error
     * toast. destroy() now looks the row up by hand and treats "already
     * gone, same agent/product" as success, not a 404.
     */
    public function test_deleting_the_same_photo_twice_returns_success_both_times(): void
    {
        $user = User::factory()->create();
        [$agent, $product] = $this->product($user);
        $image = ProductImage::forceCreate([
            'agent_id' => $agent->id, 'dataset_product_id' => $product->id,
            'path' => 'product-images/a.jpg', 'mime_type' => 'image/jpeg', 'size' => 10, 'position' => 0,
        ]);
        Storage::disk('public')->put($image->path, 'fixture');

        $this->actingAs($user)->deleteJson(
            route('agents.catalog.products.images.destroy', [$agent, $product->id, $image->id]),
        )->assertOk()->assertJsonPath('ok', true);

        // Same request again, against a row that is now genuinely gone.
        $second = $this->actingAs($user)->deleteJson(
            route('agents.catalog.products.images.destroy', [$agent, $product->id, $image->id]),
        );

        $second->assertOk()->assertJsonPath('ok', true);
        $this->assertStringNotContainsString('No query results', $second->getContent());
        $this->assertStringNotContainsString('ProductImage', $second->getContent());
        $this->assertDatabaseMissing('product_images', ['id' => $image->id]);
    }

    /**
     * Idempotent deletion must not become a loophole: an image id that is
     * genuinely someone else's — not merely "already deleted" — is still
     * refused. The acting user owns BOTH agents here, so this isolates the
     * cross-agent row check itself from the authorize('update', $agent)
     * gate the "stranger" tests above already cover.
     */
    public function test_deleting_an_image_belonging_to_a_different_agent_is_refused_not_treated_as_already_gone(): void
    {
        $user = User::factory()->create();
        [$agentA, $productA] = $this->product($user);
        [$agentB, $productB] = $this->product($user);
        $imageOnA = ProductImage::forceCreate([
            'agent_id' => $agentA->id, 'dataset_product_id' => $productA->id,
            'path' => 'product-images/a.jpg', 'mime_type' => 'image/jpeg', 'size' => 10, 'position' => 0,
        ]);

        $this->actingAs($user)->deleteJson(
            route('agents.catalog.products.images.destroy', [$agentB, $productB->id, $imageOnA->id]),
        )->assertNotFound();

        $this->assertDatabaseHas('product_images', ['id' => $imageOnA->id]);
    }

    /** A missing image on move-up gets a clean, localized JSON error — never the raw Eloquent exception text. */
    public function test_moving_up_a_missing_image_returns_a_friendly_json_error(): void
    {
        $user = User::factory()->create();
        [$agent, $product] = $this->product($user);

        $response = $this->actingAs($user)->postJson(
            route('agents.catalog.products.images.move_up', [$agent, $product->id, 999999]),
        );

        $response->assertNotFound()->assertJsonPath('message', __('agents.catalog.product_images.errors.not_found'));
        $this->assertStringNotContainsString('No query results', $response->getContent());
        $this->assertStringNotContainsString('ProductImage', $response->getContent());
    }

    /**
     * The global fix, not just the two ProductImageController endpoints:
     * ANY implicitly-bound route parameter (here {agent}, typed Agent
     * $agent) that fails to resolve throws ModelNotFoundException straight
     * out of the router, before any controller runs — bootstrap/app.php's
     * exception renderer is what keeps THAT from leaking a raw model class
     * name too.
     */
    public function test_a_json_request_for_a_nonexistent_agent_gets_a_friendly_message_not_a_raw_exception(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/agents/this-slug-does-not-exist/catalog');

        $response->assertNotFound()->assertJsonPath('message', __('common.error_not_found'));
        $this->assertStringNotContainsString('No query results', $response->getContent());
        $this->assertStringNotContainsString('App\\Models\\Agent', $response->getContent());
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
