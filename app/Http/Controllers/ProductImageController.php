<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreProductImageRequest;
use App\Models\Agent;
use App\Models\DatasetProduct;
use App\Models\ProductImage;
use App\Services\Dataset\ProductImageThumbnailer;
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
    public function __construct(
        protected ProductImageThumbnailer $thumbnailer,
    ) {}

    /** Adds a photo, appended after this product's existing ones — the first ever uploaded stays primary. */
    public function store(StoreProductImageRequest $request, Agent $agent, int $product): JsonResponse
    {
        $product = $this->product($agent, $product);
        $file = $request->file('image');
        $disk = Storage::disk(config('agent.product_images.disk'));
        $path = $file->store('product-images/'.$agent->id, config('agent.product_images.disk'));

        // Shrinks the stored file in place if the owner's phone handed us a
        // 4000px original — every later channel send stays light. Resized
        // BEFORE the thumbnail is built, so the thumbnail's own source read
        // is of the already-smaller file, not the original upload.
        $this->thumbnailer->downscale($disk, $path);
        $thumbnailPath = $this->thumbnailer->thumbnail($disk, $path);

        $nextPosition = 1 + (int) $product->images()->max('position');

        $image = ProductImage::forceCreate([
            'agent_id' => $agent->id,
            'dataset_product_id' => $product->id,
            'path' => $path,
            'thumbnail_path' => $thumbnailPath,
            'mime_type' => $file->getMimeType(),
            // Re-read from disk rather than trusting the upload's own size:
            // downscale() may just have rewritten this file to something
            // considerably smaller.
            'size' => $disk->size($path),
            'position' => $nextPosition,
        ]);

        return response()->json(['image' => $this->present($image)], 201);
    }

    /**
     * Soft-ordering only: swaps this image's position with its immediate
     * predecessor, making it primary once it reaches position 0.
     *
     * `$image` is a plain int, not an implicitly-bound `ProductImage` —
     * route-model binding throws ModelNotFoundException straight out of the
     * router for a missing row, before this method ever runs, which is
     * exactly how a stale click used to leak "No query results for model
     * [...]" to the UI. Looking it up by hand here means a missing row gets
     * OUR answer (a clean, localized, non-leaking error) instead of
     * Laravel's default rendering of that exception.
     */
    public function moveUp(Request $request, Agent $agent, int $product, int $image): JsonResponse
    {
        $this->authorize('catalog.manage', $agent);
        $product = $this->product($agent, $product);

        $image = $this->findOwnedImage($agent, $product, $image);
        if ($image === null) {
            return response()->json(['message' => __('agents.catalog.product_images.errors.not_found')], 404);
        }

        $previous = $product->images()->where('position', '<', $image->position)->orderByDesc('position')->first();

        if ($previous !== null) {
            [$image->position, $previous->position] = [$previous->position, $image->position];
            $image->save();
            $previous->save();
        }

        return response()->json(['images' => $this->presentAll($product)]);
    }

    /**
     * Idempotent: a row that's already gone (a double click, a stale page,
     * the 5s undo window racing a second removal) for THIS agent's own
     * product is treated as success — the owner's goal, "this photo gone",
     * is already true, so there is nothing to retry and nothing to show an
     * error for. A row that exists but belongs to a different agent is
     * still refused (404) — that is a real authorization boundary, not a
     * double click.
     */
    public function destroy(Request $request, Agent $agent, int $product, int $image): JsonResponse
    {
        $this->authorize('catalog.manage', $agent);
        $product = $this->product($agent, $product);

        $imageId = $image;
        $image = $this->findOwnedImage($agent, $product, $imageId);
        if ($image === null) {
            if (ProductImage::where('id', $imageId)->exists()) {
                // Exists, but not under this agent/product — a real 404,
                // not an "already gone" one.
                abort(404);
            }

            return response()->json(['ok' => true, 'images' => $this->presentAll($product)]);
        }

        $image->delete();

        return response()->json(['ok' => true, 'images' => $this->presentAll($product)]);
    }

    /** The product, scoped to this agent — a product id belonging to someone else's catalog fails exactly like one that doesn't exist. */
    private function product(Agent $agent, int $productId): DatasetProduct
    {
        $this->authorize('catalog.manage', $agent);

        return DatasetProduct::where('agent_id', $agent->id)->findOrFail($productId);
    }

    /** Null for a row that's missing OR belongs to someone else's product — the caller decides what each of those means for its own action. */
    private function findOwnedImage(Agent $agent, DatasetProduct $product, int $imageId): ?ProductImage
    {
        return ProductImage::where('id', $imageId)
            ->where('agent_id', $agent->id)
            ->where('dataset_product_id', $product->id)
            ->first();
    }

    /** Relative URL — see ProductImage::previewUrl(): this response is for the owner's own browser, never a channel driver. */
    private function present(ProductImage $image): array
    {
        return ['id' => $image->id, 'url' => $image->previewUrl(), 'position' => $image->position];
    }

    private function presentAll(DatasetProduct $product): array
    {
        return $product->images()->get()->map(fn (ProductImage $image) => $this->present($image))->all();
    }
}
