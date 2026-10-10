<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/** One owner-uploaded photo of a catalog product. See the creating migration's docblock. */
#[Fillable(['path', 'mime_type', 'size', 'position'])]
class ProductImage extends Model
{
    /** A single, deliberate delete (the owner removing one photo) also removes its file — the bulk catalog paths use purgeFilesForAgent() instead. */
    protected static function booted(): void
    {
        static::deleting(function (ProductImage $image) {
            Storage::disk(config('agent.product_images.disk'))->delete($image->path);
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

    /** The public, directly fetchable URL — what gets handed to the channel driver and the owner's own preview. */
    public function url(): string
    {
        return Storage::disk(config('agent.product_images.disk'))->url($this->path);
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
        $paths = static::where('agent_id', $agentId)->pluck('path');

        if ($paths->isNotEmpty()) {
            $disk->delete($paths->all());
        }
    }
}
