<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Message;
use App\Services\Channels\ChannelDriverManager;
use App\Services\Channels\OutsideMessagingWindowException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Throwable;

class ConversationController extends Controller
{
    protected const STATUS_FILTERS = ['all', 'unread', 'needs_human', 'has_lead', 'rated_down', 'rated_up', 'closed'];

    protected const PER_PAGE = 25;

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Conversation::class);

        $user = $request->user();

        return view('conversations.index', [
            'i18nExtraNamespaces' => ['conversations'],
            'agents' => Agent::ownedBy($user)->orderBy('name')->get(['id', 'name']),
            'channelTypes' => Conversation::ownedBy($user)
                ->join('channels', 'channels.id', '=', 'conversations.channel_id')
                ->distinct()
                ->orderBy('channels.type')
                ->pluck('channels.type'),
            'filters' => $this->filters($request),
        ]);
    }

    public function list(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Conversation::class);

        $filters = $this->filters($request);
        $page = $this->query($request, $filters)->paginate(self::PER_PAGE);

        return response()->json([
            'data' => collect($page->items())->map(fn (Conversation $c) => $this->summarize($c))->all(),
            'current_page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
            'total' => $page->total(),

            'unread_total' => Conversation::ownedBy($request->user())
                ->whereNotNull('channel_id')
                ->unread()
                ->count(),
        ]);
    }

    public function show(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('view', $conversation);

        $conversation->load(['agent:id,name,slug', 'channel:id,type', 'leads']);

        $messages = $conversation->messages()->orderBy('id')->get();

        $conversation->forceFill(['operator_read_at' => now()])->save();

        return response()->json([
            'conversation' => array_merge($this->summarize($conversation, unread: false), [
                'external_user_id' => $conversation->external_user_id,
                'can_reply' => $conversation->channel !== null && $request->user()->can('reply', $conversation),
                'can_takeover' => $request->user()->can('update', $conversation),
                'started_at' => $conversation->created_at?->toIso8601String(),
            ]),
            'messages' => $messages->map(fn (Message $m) => $this->presentMessage($m))->all(),
            'leads' => ($request->user()->can('crm.view', $conversation) ? $conversation->leads : collect())->map(fn (Lead $lead) => [
                'id' => $lead->id,
                'customer_name' => $lead->customer_name,
                'contact' => $lead->contact,
                'items' => $lead->items,
                'status' => $lead->status,
                'created_at' => $lead->created_at?->toIso8601String(),
            ])->all(),
        ]);
    }

    public function reply(Request $request, Conversation $conversation, ChannelDriverManager $drivers): JsonResponse
    {
        $this->authorize('reply', $conversation);

        $data = $request->validate([
            'content' => ['required', 'string', 'max:4000'],
        ]);

        $channel = $conversation->channel;

        if ($channel === null || $conversation->external_user_id === null) {
            return response()->json(['message' => __('conversations.errors.no_channel')], 422);
        }

        try {
            $drivers->for($channel)->sendMessage($channel, $conversation->external_user_id, $data['content']);
        } catch (OutsideMessagingWindowException $e) {

            Log::info('Operator reply blocked by the platform messaging window', [
                'conversation_id' => $conversation->id,
                'channel_id' => $channel->id,
            ]);

            return response()->json(['message' => __('conversations.errors.outside_window')], 422);
        } catch (Throwable $e) {
            Log::error('Operator reply could not be delivered', [
                'conversation_id' => $conversation->id,
                'channel_id' => $channel->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['message' => __('conversations.errors.delivery_failed')], 502);
        }

        $message = $conversation->messages()->create([
            'role' => 'operator',
            'content' => $data['content'],
        ]);

        $conversation->forceFill([
            'status' => $conversation->status === 'closed' ? 'closed' : 'human_takeover',
            'last_message_at' => now(),
            'operator_read_at' => now(),
        ])->save();

        return response()->json([
            'message' => $this->presentMessage($message),
            'status' => $conversation->status,
        ], 201);
    }

    public function updateStatus(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('update', $conversation);

        $data = $request->validate([
            'status' => ['required', 'string', 'in:ai,human_takeover,closed'],
        ]);

        $conversation->forceFill(['status' => $data['status']])->save();

        return response()->json(['status' => $conversation->status]);
    }

    public static function defaultFilters(?int $agentId = null): array
    {
        return [
            'status' => 'all',
            'agent' => $agentId,
            'channel' => null,
            'q' => null,
            'from' => null,
            'to' => null,
        ];
    }

    protected function filters(Request $request): array
    {
        $status = (string) $request->query('status', 'all');

        return array_merge(self::defaultFilters(), [
            'status' => in_array($status, self::STATUS_FILTERS, true) ? $status : 'all',
            'agent' => $request->query('agent') ? (int) $request->query('agent') : null,
            'channel' => $request->query('channel') ?: null,
            'q' => trim((string) $request->query('q')) ?: null,
            'from' => $request->query('from') ?: null,
            'to' => $request->query('to') ?: null,
        ]);
    }

    protected function query(Request $request, array $filters): Builder
    {
        $user = $request->user();

        return Conversation::ownedBy($user)

            ->whereNotNull('conversations.channel_id')
            ->with(['agent:id,name,slug', 'channel:id,type'])
            ->withCount([
                'leads',
                'messages as unread_count' => fn ($q) => $q
                    ->where('messages.role', 'user')
                    ->where(fn ($w) => $w
                        ->whereNull('conversations.operator_read_at')
                        ->orWhereColumn('messages.created_at', '>', 'conversations.operator_read_at')
                    ),
            ])

            ->addSelect(['last_message_content' => Message::select('content')
                ->whereColumn('messages.conversation_id', 'conversations.id')
                ->latest('messages.id')
                ->limit(1),
            ])
            ->addSelect(['last_message_role' => Message::select('role')
                ->whereColumn('messages.conversation_id', 'conversations.id')
                ->latest('messages.id')
                ->limit(1),
            ])
            ->when($filters['agent'], fn ($q, $id) => $q->where('conversations.agent_id', $id))
            ->when($filters['channel'], fn ($q, $type) => $q->whereHas('channel', fn ($c) => $c->where('type', $type)))
            ->when($filters['from'], fn ($q, $from) => $q->whereDate('conversations.last_message_at', '>=', $from))
            ->when($filters['to'], fn ($q, $to) => $q->whereDate('conversations.last_message_at', '<=', $to))
            ->when($filters['status'] === 'unread', fn ($q) => $q->unread())
            ->when($filters['status'] === 'needs_human', fn ($q) => $q->where('conversations.status', 'human_takeover'))
            ->when($filters['status'] === 'closed', fn ($q) => $q->where('conversations.status', 'closed'))
            ->when($filters['status'] === 'has_lead', fn ($q) => $q->whereHas('leads'))

            ->when($filters['status'] === 'rated_down', fn ($q) => $q->whereHas(
                'messages',
                fn ($m) => $m->where('messages.rating', Message::RATING_DOWN)
            ))

            ->when($filters['status'] === 'rated_up', fn ($q) => $q->whereHas(
                'messages',
                fn ($m) => $m->where('messages.rating', Message::RATING_UP)
            ))

            ->when(
                ! in_array($filters['status'], ['closed', 'rated_down', 'rated_up'], true),
                fn ($q) => $q->where('conversations.status', '!=', 'closed')
            )

            ->when($filters['q'], function (Builder $q, string $term) {
                $like = '%'.$term.'%';

                $q->where(fn ($w) => $w
                    ->where('conversations.external_user_name', 'like', $like)
                    ->orWhere('conversations.external_user_id', 'like', $like)
                    ->orWhereHas('messages', fn ($m) => $m->where('messages.content', 'like', $like))
                )
                    ->withCount(['messages as match_count' => fn ($m) => $m->where('messages.content', 'like', $like)])
                    ->addSelect(['match_excerpt' => Message::select('content')
                        ->whereColumn('messages.conversation_id', 'conversations.id')
                        ->where('messages.content', 'like', $like)
                        ->latest('messages.id')
                        ->limit(1),
                    ])
                    ->addSelect(['match_at' => Message::select('created_at')
                        ->whereColumn('messages.conversation_id', 'conversations.id')
                        ->where('messages.content', 'like', $like)
                        ->latest('messages.id')
                        ->limit(1),
                    ]);
            })

            ->when(
                $filters['q'],
                fn ($q) => $q->orderByRaw('COALESCE(match_at, conversations.last_message_at) DESC'),
                fn ($q) => $q->orderByDesc('conversations.last_message_at')
            )
            ->orderByDesc('conversations.id');
    }

    protected function summarize(Conversation $conversation, ?bool $unread = null): array
    {
        return [
            'id' => $conversation->id,
            'customer' => $conversation->external_user_name ?: ($conversation->external_user_id ?? '—'),
            'agent' => [
                'id' => $conversation->agent?->id,
                'name' => $conversation->agent?->name,
                'slug' => $conversation->agent?->slug,
            ],
            'channel' => $conversation->channel?->type,
            'status' => $conversation->status,
            'preview' => Str::limit((string) ($conversation->last_message_content ?? ''), 120),
            'preview_role' => $conversation->last_message_role,
            'last_message_at' => $conversation->last_message_at?->toIso8601String()
                ?? $conversation->updated_at?->toIso8601String(),
            'unread' => $unread ?? ((int) ($conversation->unread_count ?? 0) > 0),
            'leads_count' => (int) ($conversation->leads_count ?? $conversation->leads()->count()),

            'match_count' => (int) ($conversation->match_count ?? 0),
            'match_excerpt' => $conversation->match_excerpt === null
                ? null
                : Str::limit((string) $conversation->match_excerpt, 140),
        ];
    }

    protected function presentMessage(Message $message): array
    {

        $chunks = collect($message->meta['retrieved_chunks'] ?? [])
            ->map(fn ($chunk) => [
                'source' => $chunk['source_name'] ?? $chunk['source'] ?? null,
                'score' => isset($chunk['score']) ? round((float) $chunk['score'], 3) : null,
                'confident' => (bool) ($chunk['confident'] ?? true),
                'excerpt' => isset($chunk['content']) ? Str::limit((string) $chunk['content'], 220) : null,
            ])
            ->filter(fn ($chunk) => $chunk['source'] !== null || $chunk['excerpt'] !== null)
            ->values()
            ->all();

        return [
            'id' => $message->id,
            'role' => $message->role,
            'content' => $message->content,
            'created_at' => $message->created_at?->toIso8601String(),
            'chunks' => $chunks,

            'source_type' => $message->source_type ?? 'text',
            'voice_duration' => $message->voice_duration,

            'rating' => $message->rating,
            'rated_at' => $message->rated_at?->toIso8601String(),

            'delivery_status' => $message->meta['delivery_status'] ?? null,
        ];
    }
}
