<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/** One owner-uploaded photo of a catalog product. See the creating migration's docblock. */
#[Fillable(['path', 'thumbnail_path', 'mime_type', 'size', 'position'])]
class ProductImage extends Model
{
    /** A single, deliberate delete (the owner removing one photo) also removes its file(s) — the bulk catalog paths use purgeFilesForAgent() instead. */
    protected static function booted(): void
    {
        static::deleting(function (ProductImage $image) {
            $disk = Storage::disk(config('agent.product_images.disk'));
            $disk->delete($image->path);

            if ($image->thumbnail_path !== null) {
                $disk->delete($image->thumbnail_path);
            }
        });
    }

    public function datasetProduct(): BelongsTo
    {
        return $this->belongsTo(DatasetProduct::class);
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    /** The public, directly fetchable, ABSOLUTE URL — what gets handed to a channel driver (Telegram etc.), which has no origin of its own to resolve a relative one against. */
    public function url(): string
    {
        return Storage::disk(config('agent.product_images.disk'))->url($this->path);
    }

    /**
     * The admin dashboard's OWN thumbnail URL — relative, never built from
     * APP_URL. url() above is absolute, which is correct for a channel
     * driver but wrong for the Каталог tab: APP_URL here is the ngrok
     * tunnel domain, so opening the dashboard at http://127.0.0.1:8000
     * made every `<img>` fetch cross-origin to ngrok, which serves its
     * own click-through interstitial page to an unrecognized visitor
     * instead of the file — broken thumbnails that "worked" only when
     * the dashboard itself happened to be opened through the ngrok
     * domain. A path-only URL instead resolves against whatever origin
     * the dashboard is actually being viewed from. Never hand this to a
     * channel driver — outside this request it resolves against nothing.
     *
     * Prefers thumbnail_path (see ProductImageThumbnailer) — a small
     * webp/jpeg made at upload time — over the full original, so the
     * Каталог tab's product grid loads dozens of small files instead of
     * dozens of full-size photos. A row uploaded before thumbnails existed
     * has none; previewUrl() falls back to the original path for those.
     */
    public function previewUrl(): string
    {
        return '/storage/'.ltrim($this->thumbnail_path ?? $this->path, '/');
    }

    /**
     * Removes every uploaded file for this agent's products in one pass, for
     * the bulk catalog replace/delete paths — those issue a single `DELETE`
     * query against `dataset_products` (50k rows is normal for a catalog),
     * which fires no model events and so would otherwise never clean up the
     * files a per-row delete() would have.
     */
    public static function purgeFilesForAgent(int $agentId): void
    {
        $disk = Storage::disk(config('agent.product_images.disk'));
        $images = static::where('agent_id', $agentId)->get(['path', 'thumbnail_path']);

        $paths = $images->pluck('path')
            ->merge($images->pluck('thumbnail_path')->filter())
            ->values();

        if ($paths->isNotEmpty()) {
            $disk->delete($paths->all());
        }
    }
}
