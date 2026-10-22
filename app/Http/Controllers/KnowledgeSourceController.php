<?php

namespace App\Http\Controllers;

use App\Http\Requests\Knowledge\StoreKnowledgeFileRequest;
use App\Http\Requests\Knowledge\StoreKnowledgeTextRequest;
use App\Http\Requests\Knowledge\StoreKnowledgeUrlRequest;
use App\Jobs\ExtractTextJob;
use App\Models\Agent;
use App\Models\KnowledgeSource;
use App\Services\Rag\MySqlCosineVectorStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class KnowledgeSourceController extends Controller
{
    public function status(Agent $agent): JsonResponse
    {
        $this->authorize('view', $agent);

        $sources = $agent->knowledgeSources()
            ->withCount('chunks')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (KnowledgeSource $source) => $this->serialize($source));

        return response()->json(['sources' => $sources]);
    }

    public function storeFile(StoreKnowledgeFileRequest $request, Agent $agent): RedirectResponse|JsonResponse
    {
        $created = [];

        foreach ($request->file('files') as $file) {
            $storedPath = $file->store("knowledge/{$agent->id}/sources", 'local');

            $source = new KnowledgeSource([
                'type' => 'file',
                'name' => $file->getClientOriginalName(),
                'original_filename' => $file->getClientOriginalName(),
                'file_path' => $storedPath,
                'mime_type' => $file->getClientMimeType(),
                'status' => 'pending',
            ]);
            $source->agent_id = $agent->id;
            $source->save();

            ExtractTextJob::dispatch($source);
            $created[] = $source;
        }

        if ($request->wantsJson()) {
            return response()->json([
                'sources' => array_map(fn ($s) => $this->serialize($s), $created),
            ], 201);
        }

        return back()->with('status', 'Файлы добавлены и поставлены в очередь на обработку.');
    }

    public function storeText(StoreKnowledgeTextRequest $request, Agent $agent): RedirectResponse|JsonResponse
    {
        $path = "knowledge/{$agent->id}/sources/".Str::uuid().'.txt';
        Storage::disk('local')->put($path, $request->string('content'));

        $source = new KnowledgeSource([
            'type' => 'text',
            'name' => $request->string('name'),
            'file_path' => $path,
            'mime_type' => 'text/plain',
            'status' => 'pending',
        ]);
        $source->agent_id = $agent->id;
        $source->save();

        ExtractTextJob::dispatch($source);

        if ($request->wantsJson()) {
            return response()->json(['source' => $this->serialize($source)], 201);
        }

        return back()->with('status', 'Текст добавлен и поставлен в очередь на обработку.');
    }

    public function storeUrl(StoreKnowledgeUrlRequest $request, Agent $agent): RedirectResponse|JsonResponse
    {
        $source = new KnowledgeSource([
            'type' => 'url',
            'name' => $request->string('url'),
            'url' => $request->string('url'),
            'status' => 'pending',
        ]);
        $source->agent_id = $agent->id;
        $source->save();

        ExtractTextJob::dispatch($source);

        if ($request->wantsJson()) {
            return response()->json(['source' => $this->serialize($source)], 201);
        }

        return back()->with('status', 'Ссылка добавлена и поставлена в очередь на обработку.');
    }

    public function refetch(Request $request, Agent $agent, KnowledgeSource $knowledgeSource): RedirectResponse|JsonResponse
    {
        $this->authorize('update', $agent);

        abort_unless($knowledgeSource->type === 'url', 422);

        $knowledgeSource->chunks()->delete();

        if (Storage::disk('local')->exists($knowledgeSource->extractedPath())) {
            Storage::disk('local')->delete($knowledgeSource->extractedPath());
        }

        $knowledgeSource->update(['status' => 'pending', 'error_message' => null]);
        MySqlCosineVectorStore::forgetCache($agent->id);

        ExtractTextJob::dispatch($knowledgeSource);

        $knowledgeSource->loadCount('chunks');

        if ($request->wantsJson()) {
            return response()->json(['source' => $this->serialize($knowledgeSource)]);
        }

        return back()->with('status', __('agents.knowledge.toasts.refetch_queued'));
    }

    public function destroy(Request $request, Agent $agent, KnowledgeSource $knowledgeSource): RedirectResponse|JsonResponse
    {
        $this->authorize('delete', $knowledgeSource);

        if ($knowledgeSource->file_path) {
            Storage::disk('local')->delete($knowledgeSource->file_path);
        }

        if (Storage::disk('local')->exists($knowledgeSource->extractedPath())) {
            Storage::disk('local')->delete($knowledgeSource->extractedPath());
        }

        $knowledgeSource->delete();
        MySqlCosineVectorStore::forgetCache($agent->id);

        if ($request->wantsJson()) {
            return response()->json(['deleted' => true]);
        }

        return back()->with('status', 'Источник удалён.');
    }

    protected function serialize(KnowledgeSource $source): array
    {
        return [
            'id' => $source->id,
            'name' => $source->name,
            'type' => $source->type,
            'status' => $source->status,
            'error_message' => $source->error_message,
            'chunks_count' => $source->chunks_count ?? 0,
            'product_count' => $source->meta['product_count'] ?? null,
            'created_at' => $source->created_at->diffForHumans(),
        ];
    }
}
