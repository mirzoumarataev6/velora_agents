<?php

namespace App\Observers;

use App\Models\BiSource;
use Illuminate\Support\Facades\Storage;

class BiSourceObserver
{
    public function deleting(BiSource $source): void
    {

        $source->datasets()->get()->each(fn ($dataset) => $dataset->delete());
    }

    public function deleted(BiSource $source): void
    {
        $directory = $source->storageDirectory();

        if (str_starts_with($directory, 'bi/')) {
            Storage::disk('local')->deleteDirectory($directory);
        }
    }
}
