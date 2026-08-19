<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Message;
use App\Services\Channels\ChannelDriverManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Throwable;

/**
 * The unified inbox: every conversation across every agent and channel this
 * owner has, in one place.
 *
 * This is a view layer over the existing Conversation/Message models — the
 * same rows the webhook pipeline writes. Nothing here duplicates that state;
 * it filters, aggregates and (for operator replies) writes back through the
 * same channel drivers the agent itself uses.
 */
class ConversationController extends Controller
{
    /** How the inbox can be sliced. `all` is the absence of a filter. */
    protected const STATUS_FILTERS = ['all', 'unread', 'needs_human', 'has_lead', 'closed'];

    protected const PER_PAGE = 25;

    public function index(Request $request): View
    {
        $user = $request->user();

        return view('conversations.index', [
            'i18nExtraNamespaces' => ['conversations'],
            'agents' => $user->agents()->orderBy('name')->get(['id', 'name']),
            'channelTypes' => Conversation::ownedBy($user)
                ->join('channels', 'channels.id', '=', 'conversations.channel_id')
                ->distinct()
                ->orderBy('channels.type')
                ->pluck('channels.type'),
            'filters' => $this->filters($request),
        ]);
    }

    /**
     * The conversation list itself, as JSON. Used for the first paint, for
     * infinite scroll, and for the poll that surfaces new messages without a
     * reload — one endpoint, so the three can never disagree.
     */
    public function list(Request $request): JsonResponse
    {
        $filters = $this->filters($request);
        $page = $this->query($request, $filters)->paginate(self::PER_PAGE);

        return response()->json([
            'data' => collect($page->items())->map(fn (Conversation $c) => $this->summarize($c))->all(),
            'current_page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
            'total' => $page->total(),
            // Always the unfiltered count: it drives the "unread" badge, which
            // would be nonsense if it only counted what the current filter shows.
            'unread_total' => Conversation::ownedBy($request->user())
                ->whereNotNull('channel_id')
                ->unread()
                ->count(),
        ]);
    }

    /**
     * One conversation with its full transcript. Opening it is what marks it
     * read, mirroring how any mail client behaves.
     */
    public function show(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('view', $conversation);

        $conversation->load(['agent:id,name,slug', 'channel:id,type', 'leads']);

        $messages = $conversation->messages()->orderBy('id')->get();

        $conversation->forceFill(['operator_read_at' => now()])->save();

        return response()->json([
            'conversation' => array_merge($this->summarize($conversation, unread: false), [
                'external_user_id' => $conversation->external_user_id,
                'can_reply' => $conversation->channel !== null,
                'started_at' => $conversation->created_at?->toIso8601String(),
            ]),
            'messages' => $messages->map(fn (Message $m) => $this->presentMessage($m))->all(),
            'leads' => $conversation->leads->map(fn (Lead $lead) => [
                'id' => $lead->id,
                'customer_name' => $lead->customer_name,
                'contact' => $lead->contact,
                'items' => $lead->items,
                'status' => $lead->status,
                'created_at' => $lead->created_at?->toIso8601String(),
            ])->all(),
        ]);
    }

    /**
     * Send a message to the customer as the human operator.
     *
     * Delivery happens BEFORE the message is recorded, on purpose: a transcript
     * that shows a reply the customer never received is worse than no reply at
     * all, because the owner would stop waiting for it.
     */
    public function reply(Request $request, Conversation $conversation, ChannelDriverManager $drivers): JsonResponse
    {
        $this->authorize('update', $conversation);

        $data = $request->validate([
            'content' => ['required', 'string', 'max:4000'],
        ]);

        $channel = $conversation->channel;

        if ($channel === null || $conversation->external_user_id === null) {
            return response()->json(['message' => __('conversations.errors.no_channel')], 422);
        }

        try {
            $drivers->for($channel)->sendMessage($channel, $conversation->external_user_id, $data['content']);
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

        // Answering personally IS taking over: leaving the agent live would have
        // it reply again underneath the operator, to the same customer.
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

    /**
     * Move a conversation between AI handling, human takeover and closed.
     */
    public function updateStatus(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('update', $conversation);

        $data = $request->validate([
            'status' => ['required', 'string', 'in:ai,human_takeover,closed'],
        ]);

        $conversation->forceFill(['status' => $data['status']])->save();

        return response()->json(['status' => $conversation->status]);
    }

    /**
     * The filter set an inbox starts from.
     *
     * Public and static because an agent's own conversations tab renders the
     * same inbox partial and has to start from an identical shape — a
     * hand-written copy over there would drift the first time a filter is
     * added here.
     *
     * @return array<string, mixed>
     */
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

    /**
     * @return array<string, mixed>
     */
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

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Conversation>
     */
    protected function query(Request $request, array $filters): Builder
    {
        $user = $request->user();

        return Conversation::ownedBy($user)
            // Playground threads have no channel: they are the owner testing
            // their own agent, not a customer, and cannot be replied to.
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
            // Preview text without loading whole transcripts into memory.
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
            // "All" sits next to its own "Closed" chip in the UI, so it means
            // all ACTIVE conversations — an inbox hides archived mail until
            // asked for it explicitly.
            ->when(
                $filters['status'] !== 'closed',
                fn ($q) => $q->where('conversations.status', '!=', 'closed')
            )
            // Message-content search, Telegram-style: the term is looked for in
            // every message body as well as in who the customer is. Alongside
            // the filter we carry back WHICH message matched and WHEN, so the
            // list can be ordered by match recency and the UI can jump straight
            // to that message instead of dropping the reader at the bottom.
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
            // While searching, "most recent" means the most recent MATCH — a
            // conversation whose hit is two months old belongs below one that
            // was hit yesterday, whatever their last messages say. Conversations
            // matched only by customer name have no match date and fall back.
            ->when(
                $filters['q'],
                fn ($q) => $q->orderByRaw('COALESCE(match_at, conversations.last_message_at) DESC'),
                fn ($q) => $q->orderByDesc('conversations.last_message_at')
            )
            ->orderByDesc('conversations.id');
    }

    /**
     * @return array<string, mixed>
     */
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
            // Only present while searching. The row shows the matched message
            // rather than the last one — during a search the last message is
            // rarely the reason the row is on screen.
            'match_count' => (int) ($conversation->match_count ?? 0),
            'match_excerpt' => $conversation->match_excerpt === null
                ? null
                : Str::limit((string) $conversation->match_excerpt, 140),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function presentMessage(Message $message): array
    {
        // Which knowledge the agent leaned on for this answer, when the reply
        // pipeline recorded it. Shown in the thread so the owner can see why
        // the agent said what it said.
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
            // So the operator can see the customer spoke rather than typed —
            // the content is the transcript either way.
            'source_type' => $message->source_type ?? 'text',
            'voice_duration' => $message->voice_duration,
        ];
    }
}
