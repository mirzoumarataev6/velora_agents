<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreProductImageRequest;
use App\Models\Agent;
use App\Models\DatasetProduct;
use App\Models\ProductImage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Per-product photos in the Каталог tab — the trusted source the agent's
 * reply pipeline checks before the catalog's own image_url, the knowledge
 * base, or Wikimedia. See ImageReplyService.
 *
 * `{product}` is a plain id resolved and scoped by hand below rather than a
 * route-bound model: DatasetController's own product queries already follow
 * this convention (filtering by agent_id, never binding a product by itself),
 * and reusing it here avoids a mismatched scopeBindings() relation name.
 */
class ProductImageController extends Controller
{
    /** Adds a photo, appended after this product's existing ones — the first ever uploaded stays primary. */
    public function store(StoreProductImageRequest $request, Agent $agent, int $product): JsonResponse
    {
        $product = $this->product($agent, $product);
        $file = $request->file('image');
        $disk = Storage::disk(config('agent.product_images.disk'));
        $path = $file->store('product-images/'.$agent->id, config('agent.product_images.disk'));

        $nextPosition = 1 + (int) $product->images()->max('position');

        $image = ProductImage::forceCreate([
            'agent_id' => $agent->id,
            'dataset_product_id' => $product->id,
            'path' => $path,
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
            'position' => $nextPosition,
        ]);

        return response()->json(['image' => $this->present($image)], 201);
    }

    /** Soft-ordering only: swaps this image's position with its immediate predecessor, making it primary once it reaches position 0. */
    public function moveUp(Request $request, Agent $agent, int $product, ProductImage $image): JsonResponse
    {
        $this->authorize('update', $agent);
        $product = $this->product($agent, $product);
        $this->assertBelongsToProduct($image, $product);

        $previous = $product->images()->where('position', '<', $image->position)->orderByDesc('position')->first();

        if ($previous !== null) {
            [$image->position, $previous->position] = [$previous->position, $image->position];
            $image->save();
            $previous->save();
        }

        return response()->json(['images' => $this->presentAll($product)]);
    }

    public function destroy(Request $request, Agent $agent, int $product, ProductImage $image): JsonResponse
    {
        $this->authorize('update', $agent);
        $product = $this->product($agent, $product);
        $this->assertBelongsToProduct($image, $product);

        $image->delete();

        return response()->json(['images' => $this->presentAll($product)]);
    }

    /** The product, scoped to this agent — a product id belonging to someone else's catalog fails exactly like one that doesn't exist. */
    private function product(Agent $agent, int $productId): DatasetProduct
    {
        $this->authorize('update', $agent);

        return DatasetProduct::where('agent_id', $agent->id)->findOrFail($productId);
    }

    private function assertBelongsToProduct(ProductImage $image, DatasetProduct $product): void
    {
        abort_unless($image->dataset_product_id === $product->id, 404);
    }

    private function present(ProductImage $image): array
    {
        return ['id' => $image->id, 'url' => $image->url(), 'position' => $image->position];
    }

    private function presentAll(DatasetProduct $product): array
    {
        return $product->images()->get()->map(fn (ProductImage $image) => $this->present($image))->all();
    }
}
