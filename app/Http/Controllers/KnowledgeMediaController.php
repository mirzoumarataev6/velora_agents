<?php

namespace App\Http\Controllers;

use App\Models\KnowledgeSource;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class KnowledgeMediaController extends Controller
{
    public function show(KnowledgeSource $source): StreamedResponse
    {
        abort_unless(in_array($source->mime_type, ['image/jpeg', 'image/png', 'image/webp'], true), 404);
        abort_unless($source->file_path && Storage::disk('local')->exists($source->file_path), 404);

        return Storage::disk('local')->response($source->file_path, null, [
            'Content-Type' => $source->mime_type,
            'Cache-Control' => 'private, max-age=120',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
