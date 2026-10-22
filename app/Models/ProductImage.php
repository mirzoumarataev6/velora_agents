<?php

namespace App\Models;

use App\Models\Concerns\StampsTeam;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

#[Fillable(['path', 'thumbnail_path', 'mime_type', 'size', 'position'])]
class ProductImage extends Model
{
    use StampsTeam;

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

    public function url(): string
    {
        return Storage::disk(config('agent.product_images.disk'))->url($this->path);
    }

    public function previewUrl(): string
    {
        return '/storage/'.ltrim($this->thumbnail_path ?? $this->path, '/');
    }

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
