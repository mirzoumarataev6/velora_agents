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

class ProductImageController extends Controller
{
    public function __construct(
        protected ProductImageThumbnailer $thumbnailer,
    ) {}

    public function store(StoreProductImageRequest $request, Agent $agent, int $product): JsonResponse
    {
        $product = $this->product($agent, $product);
        $file = $request->file('image');
        $disk = Storage::disk(config('agent.product_images.disk'));
        $path = $file->store('product-images/'.$agent->id, config('agent.product_images.disk'));

        $this->thumbnailer->downscale($disk, $path);
        $thumbnailPath = $this->thumbnailer->thumbnail($disk, $path);

        $nextPosition = 1 + (int) $product->images()->max('position');

        $image = ProductImage::forceCreate([
            'agent_id' => $agent->id,
            'dataset_product_id' => $product->id,
            'path' => $path,
            'thumbnail_path' => $thumbnailPath,
            'mime_type' => $file->getMimeType(),

            'size' => $disk->size($path),
            'position' => $nextPosition,
        ]);

        return response()->json(['image' => $this->present($image)], 201);
    }

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

    public function destroy(Request $request, Agent $agent, int $product, int $image): JsonResponse
    {
        $this->authorize('catalog.manage', $agent);
        $product = $this->product($agent, $product);

        $imageId = $image;
        $image = $this->findOwnedImage($agent, $product, $imageId);
        if ($image === null) {
            if (ProductImage::where('id', $imageId)->exists()) {

                abort(404);
            }

            return response()->json(['ok' => true, 'images' => $this->presentAll($product)]);
        }

        $image->delete();

        return response()->json(['ok' => true, 'images' => $this->presentAll($product)]);
    }

    private function product(Agent $agent, int $productId): DatasetProduct
    {
        $this->authorize('catalog.manage', $agent);

        return DatasetProduct::where('agent_id', $agent->id)->findOrFail($productId);
    }

    private function findOwnedImage(Agent $agent, DatasetProduct $product, int $imageId): ?ProductImage
    {
        return ProductImage::where('id', $imageId)
            ->where('agent_id', $agent->id)
            ->where('dataset_product_id', $product->id)
            ->first();
    }

    private function present(ProductImage $image): array
    {
        return ['id' => $image->id, 'url' => $image->previewUrl(), 'position' => $image->position];
    }

    private function presentAll(DatasetProduct $product): array
    {
        return $product->images()->get()->map(fn (ProductImage $image) => $this->present($image))->all();
    }
}
