<?php

namespace App\Services\Analytics;

use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Message;
use App\Models\User;
use App\Services\Haggling\HagglingService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DashboardMetrics
{
    public const ACTIVE_WITHIN_HOURS = 24;

    public const RANGES = [7, 30];

    protected ?int $agentId = null;

    public function __construct(protected User $user, protected bool $canViewCrm) {}

    public function forRange(int $days, ?int $agentId = null, ?Collection $agents = null): array
    {
        $days = in_array($days, self::RANGES, true) ? $days : self::RANGES[0];
        $since = CarbonImmutable::now()->subDays($days - 1)->startOfDay();
        $this->agentId = $agentId;

        $timeline = $this->messageTimeline($since);
        $conversationStats = $this->conversationStats();

        $leadStatusCounts = $this->canViewCrm ? $this->leadStatusCounts() : collect();

        $leadsCreatedAt = $this->canViewCrm
            ? $this->leads()->where('leads.created_at', '>=', $since)->pluck('leads.created_at')
            : collect();

        $pending = $this->pending();

        return [
            'range' => $days,
            'agent_id' => $this->agentId,
            'has_history' => $this->hasAnyHistory(),
            'summary' => $this->summary($conversationStats),
            'series' => $this->series($timeline, $since, $days),
            'channels' => $timeline->pluck('channel')->unique()->filter()->sort()->values()->all(),
            'heatmap' => $this->heatmap($timeline),
            'after_hours' => $this->afterHours($timeline, $leadsCreatedAt),
            'funnel' => $this->funnel($conversationStats, $leadStatusCounts),
            'pending' => $pending,
            'unanswered' => $unanswered = $this->unanswered($since),
            'products' => $products = $this->askedProducts($since),
            'ratings' => $ratings = $this->ratings($since, $days),
            'response_trend' => $this->responseTrend($since, $days),
            'leads_by_status' => $this->leadsByStatus($leadStatusCounts),
            'daily' => $this->daily($since, $days, $leadsCreatedAt),
            'agent_comparison' => $this->agentComparison($since, $agents),
            'highlight' => $this->highlight($unanswered, $products, $ratings, $pending),
        ];
    }

    public function hasAnyHistory(): bool
    {
        return $this->conversations()->exists();
    }

    protected function summary(array $conversationStats): array
    {
        $now = CarbonImmutable::now();

        $leadCounters = $this->canViewCrm
            ? $this->leads()->selectRaw(
                'sum(case when leads.created_at >= ? then 1 else 0 end) as today, '.
                'sum(case when leads.created_at >= ? then 1 else 0 end) as week',
                [$now->startOfDay(), $now->startOfWeek()]
            )->first()
            : null;

        $totalConversations = $conversationStats['total'];
        $convertedConversations = $this->canViewCrm
            ? $this->conversations()
                ->whereIn('conversations.id', Lead::query()->select('conversation_id')->whereNotNull('conversation_id'))
                ->count()
            : 0;

        return [
            'active_conversations' => $conversationStats['active'],
            'leads_today' => (int) ($leadCounters->today ?? 0),
            'leads_week' => (int) ($leadCounters->week ?? 0),
            'conversations_total' => $totalConversations,
            'conversion_rate' => $this->canViewCrm && $totalConversations > 0
                ? round($convertedConversations / $totalConversations * 100, 1)
                : null,
            'avg_response_ms' => $this->averageResponseMs(),
        ];
    }

    protected function conversationStats(): array
    {
        $row = $this->conversations()
            ->selectRaw(
                'count(*) as total, '.
                'sum(case when status != ? and last_message_at >= ? then 1 else 0 end) as active',
                ['closed', CarbonImmutable::now()->subHours(self::ACTIVE_WITHIN_HOURS)]
            )
            ->first();

        return [
            'total' => (int) $row->total,
            'active' => (int) ($row->active ?? 0),
        ];
    }

    protected function averageResponseMs(): ?float
    {
        $value = $this->messages()
            ->where('messages.role', 'assistant')
            ->whereNotNull(DB::raw("json_extract(messages.meta, '$.total_ms')"))
            ->avg(DB::raw("json_extract(messages.meta, '$.total_ms') + 0"));

        return $value === null ? null : round((float) $value, 0);
    }

    protected function messageTimeline(CarbonImmutable $since): Collection
    {
        return $this->messages()
            ->leftJoin('channels', 'channels.id', '=', 'conversations.channel_id')
            ->where('messages.created_at', '>=', $since)
            ->orderBy('messages.id')
            ->get(['messages.created_at as at', 'channels.type as channel'])
            ->map(fn ($row) => (object) [
                'at' => CarbonImmutable::parse($row->at),
                'channel' => $row->channel,
            ]);
    }

    protected function series(Collection $timeline, CarbonImmutable $since, int $days): array
    {
        $byDay = $timeline->groupBy(fn ($row) => $row->at->toDateString());
        $series = [];

        for ($i = 0; $i < $days; $i++) {
            $day = $since->addDays($i);
            $rows = $byDay->get($day->toDateString(), collect());

            $series[] = [
                'date' => $day->toDateString(),
                'label' => $day->format('d.m'),
                'total' => $rows->count(),
                'by_channel' => $rows->groupBy(fn ($row) => $row->channel ?? 'playground')
                    ->map->count()
                    ->all(),
            ];
        }

        return $series;
    }

    protected function daily(CarbonImmutable $since, int $days, Collection $leadsCreatedAt): array
    {
        $leadsByDay = $leadsCreatedAt
            ->groupBy(fn ($at) => $this->local(CarbonImmutable::parse($at))->toDateString())
            ->map->count();

        $conversationsByDay = $this->conversations()
            ->where('conversations.created_at', '>=', $since)
            ->pluck('conversations.created_at')
            ->groupBy(fn ($at) => $this->local(CarbonImmutable::parse($at))->toDateString())
            ->map->count();

        $leads = [];
        $conversations = [];
        $conversion = [];

        for ($i = 0; $i < $days; $i++) {
            $key = $since->addDays($i)->toDateString();

            $leadCount = (int) ($leadsByDay[$key] ?? 0);
            $conversationCount = (int) ($conversationsByDay[$key] ?? 0);

            $leads[] = $leadCount;
            $conversations[] = $conversationCount;
            $conversion[] = $this->canViewCrm && $conversationCount > 0
                ? round($leadCount / $conversationCount * 100, 1)
                : null;
        }

        return [
            'leads' => $leads,
            'conversations' => $conversations,
            'conversion' => $conversion,
        ];
    }

    protected function heatmap(Collection $timeline): array
    {
        $grid = array_fill(0, 7, array_fill(0, 24, 0));

        foreach ($timeline as $row) {
            $local = $this->local($row->at);
            $grid[$local->dayOfWeekIso - 1][(int) $local->format('G')]++;
        }

        return [
            'grid' => $grid,
            'max' => max(1, max(array_map('max', $grid))),
        ];
    }

    protected function afterHours(Collection $timeline, Collection $leadsCreatedAt): array
    {
        $messages = $timeline->filter(fn ($row) => $this->isAfterHours($row->at))->count();
        $total = $timeline->count();

        $leads = $leadsCreatedAt
            ->filter(fn ($at) => $this->isAfterHours(CarbonImmutable::parse($at)))
            ->count();

        return [
            'messages' => $messages,
            'leads' => $leads,
            'share' => $total > 0 ? round($messages / $total * 100, 1) : null,
        ];
    }

    public function isAfterHours(CarbonImmutable $at): bool
    {
        $config = config('agent.analytics.after_hours');
        $at = $this->local($at);

        if ($at->isWeekend() && ($config['weekends'] ?? true)) {
            return true;
        }

        $hour = (int) $at->format('G');
        $from = (int) ($config['from_hour'] ?? 22);
        $to = (int) ($config['to_hour'] ?? 8);

        return $hour >= $from || $hour < $to;
    }

    protected function local(CarbonImmutable $at): CarbonImmutable
    {
        return $at->setTimezone(config('agent.analytics.timezone') ?: config('app.timezone'));
    }

    protected function funnel(array $conversationStats, Collection $leadStatusCounts): array
    {
        $conversations = $conversationStats['total'];
        $leads = $this->canViewCrm ? (int) $leadStatusCounts->sum() : 0;
        $confirmed = $this->canViewCrm
            ? (int) ($leadStatusCounts['in_progress'] ?? 0) + (int) ($leadStatusCounts['done'] ?? 0)
            : 0;

        $stages = [
            ['key' => 'conversations', 'value' => $conversations],
            ['key' => 'leads', 'value' => $leads],
            ['key' => 'confirmed', 'value' => $confirmed],
        ];

        foreach ($stages as $i => $stage) {
            $previous = $i === 0 ? null : $stages[$i - 1]['value'];

            $stages[$i]['share'] = $conversations > 0 ? round($stage['value'] / $conversations * 100, 1) : 0.0;
            $stages[$i]['conversion'] = $previous === null || $previous === 0
                ? null
                : round($stage['value'] / $previous * 100, 1);
        }

        return $stages;
    }

    protected function pending(): array
    {
        if (! $this->canViewCrm) {
            return ['open_orders' => 0, 'price_approvals' => 0];
        }

        return [
            'open_orders' => $this->leads()
                ->where('leads.type', 'order')
                ->whereIn('leads.status', ['new', 'in_progress'])
                ->count(),
            'price_approvals' => $this->leads()
                ->where('leads.type', HagglingService::TYPE)
                ->where('leads.status', HagglingService::STATUS_PENDING)
                ->count(),
        ];
    }

    protected function unanswered(CarbonImmutable $since): array
    {
        $rows = $this->messages()
            ->where('messages.role', 'assistant')
            ->where('messages.created_at', '>=', $since)
            ->where('messages.meta->unanswered', true)
            ->orderByDesc('messages.id')
            ->limit(500)
            ->get(['messages.meta']);

        return $rows
            ->map(fn (Message $message) => [
                'question' => trim((string) ($message->meta['unanswered_question'] ?? '')),
                'reason' => $message->meta['unanswered_reason'] ?? null,
            ])
            ->filter(fn ($row) => $row['question'] !== '')
            ->groupBy(fn ($row) => Str::of($row['question'])->lower()->replaceMatches('/[^\p{L}\p{N}\s]/u', '')->squish()->toString())
            ->map(fn (Collection $group) => [
                'question' => $group->first()['question'],
                'reason' => $group->first()['reason'],
                'count' => $group->count(),
            ])
            ->sortByDesc('count')
            ->values()
            ->take(8)
            ->all();
    }

    protected function askedProducts(CarbonImmutable $since): array
    {
        $rows = $this->messages()
            ->where('messages.role', 'assistant')
            ->where('messages.created_at', '>=', $since)
            ->whereNotNull(DB::raw("json_extract(messages.meta, '$.product_queries')"))
            ->orderByDesc('messages.id')
            ->limit(500)
            ->get(['messages.meta']);

        $terms = [];

        foreach ($rows as $message) {
            foreach ($message->meta['product_queries'] ?? [] as $query) {
                $term = trim((string) ($query['query'] ?? $query['category'] ?? ''));

                if ($term === '') {
                    continue;
                }

                $key = Str::of($term)->lower()->squish()->toString();

                $terms[$key] ??= ['term' => $term, 'count' => 0, 'found' => 0];
                $terms[$key]['count']++;
                $terms[$key]['found'] += (int) ($query['count'] ?? 0) > 0 ? 1 : 0;
            }
        }

        return collect($terms)
            ->sortByDesc('count')
            ->take(8)
            ->map(fn ($row) => $row + [
                'miss_rate' => $row['count'] > 0 ? (int) round(($row['count'] - $row['found']) / $row['count'] * 100) : 0,
            ])
            ->values()
            ->all();
    }

    protected function ratings(CarbonImmutable $since, int $days): array
    {
        $rows = $this->messages()
            ->whereNotNull('messages.rating')
            ->where('messages.created_at', '>=', $since)
            ->get(['messages.id', 'messages.conversation_id', 'messages.content', 'messages.rating', 'messages.created_at']);

        $up = $rows->where('rating', Message::RATING_UP)->count();
        $down = $rows->where('rating', Message::RATING_DOWN)->count();
        $total = $up + $down;

        $byDay = $rows->groupBy(fn (Message $m) => CarbonImmutable::parse($m->created_at)->toDateString());
        $series = [];

        for ($i = 0; $i < $days; $i++) {
            $day = $since->addDays($i);
            $rated = $byDay->get($day->toDateString(), collect());

            $series[] = [
                'date' => $day->toDateString(),
                'label' => $day->format('d.m'),
                'up' => $rated->where('rating', Message::RATING_UP)->count(),
                'down' => $rated->where('rating', Message::RATING_DOWN)->count(),
            ];
        }

        return [
            'up' => $up,
            'down' => $down,
            'total' => $total,
            'score' => $total > 0 ? round($up / $total * 100, 1) : null,
            'series' => $series,
            'worst' => $rows->where('rating', Message::RATING_DOWN)
                ->sortByDesc('id')
                ->take(5)
                ->map(fn (Message $m) => [
                    'id' => $m->id,
                    'conversation_id' => $m->conversation_id,
                    'excerpt' => Str::limit((string) $m->content, 120),
                    'at' => CarbonImmutable::parse($m->created_at)->format('d.m H:i'),
                ])
                ->values()
                ->all(),
        ];
    }

    protected function responseTrend(CarbonImmutable $since, int $days): array
    {
        $rows = $this->messages()
            ->where('messages.role', 'assistant')
            ->where('messages.created_at', '>=', $since)
            ->whereNotNull(DB::raw("json_extract(messages.meta, '$.total_ms')"))
            ->get([
                'messages.created_at as at',
                DB::raw("json_extract(messages.meta, '$.total_ms') + 0 as ms"),
            ]);

        $byDay = $rows->groupBy(fn ($row) => CarbonImmutable::parse($row->at)->toDateString());
        $series = [];
        $peak = 0.0;

        for ($i = 0; $i < $days; $i++) {
            $day = $since->addDays($i);
            $dayRows = $byDay->get($day->toDateString(), collect());
            $average = $dayRows->isEmpty() ? null : round($dayRows->avg('ms'), 0);

            $peak = max($peak, (float) ($average ?? 0));

            $series[] = [
                'date' => $day->toDateString(),
                'label' => $day->format('d.m'),
                'ms' => $average,
                'replies' => $dayRows->count(),
            ];
        }

        return [
            'series' => $series,
            'peak' => max(1.0, $peak),
            'has_data' => $rows->isNotEmpty(),
        ];
    }

    protected function leadStatusCounts(): Collection
    {
        return $this->leads()
            ->select('leads.status', DB::raw('count(*) as aggregate'))
            ->groupBy('leads.status')
            ->pluck('aggregate', 'leads.status');
    }

    protected function leadsByStatus(Collection $counts): array
    {
        $statuses = ['new', 'in_progress', HagglingService::STATUS_PENDING, 'done'];
        $total = (int) $counts->sum();

        return [
            'rows' => array_map(fn (string $status) => [
                'status' => $status,
                'count' => (int) ($counts[$status] ?? 0),
                'share' => $total > 0 ? round(((int) ($counts[$status] ?? 0)) / $total * 100, 1) : 0.0,
            ], $statuses),
            'total' => $total,
        ];
    }

    protected function agentComparison(CarbonImmutable $since, ?Collection $agents = null): array
    {
        if ($this->agentId !== null) {
            return [];
        }

        $agents ??= Agent::ownedBy($this->user)->orderBy('name')->get(['id', 'name']);

        if ($agents->count() < 2) {
            return [];
        }

        $messages = Message::query()
            ->join('conversations', 'conversations.id', '=', 'messages.conversation_id')
            ->whereIn('conversations.agent_id', $agents->pluck('id'))
            ->where('messages.created_at', '>=', $since)
            ->select('conversations.agent_id', DB::raw('count(*) as aggregate'))
            ->groupBy('conversations.agent_id')
            ->pluck('aggregate', 'conversations.agent_id');

        $leads = $this->canViewCrm
            ? Lead::query()
                ->whereIn('leads.agent_id', $agents->pluck('id'))
                ->where('leads.created_at', '>=', $since)
                ->select('leads.agent_id', DB::raw('count(*) as aggregate'))
                ->groupBy('leads.agent_id')
                ->pluck('aggregate', 'leads.agent_id')
            : collect();

        $totalMessages = (int) $messages->sum();

        return $agents
            ->map(fn (Agent $agent) => [
                'id' => $agent->id,
                'name' => $agent->name,
                'messages' => $agentMessages = (int) ($messages[$agent->id] ?? 0),
                'leads' => (int) ($leads[$agent->id] ?? 0),
                'share' => $totalMessages > 0 ? round($agentMessages / $totalMessages * 100, 1) : 0.0,
            ])
            ->sortByDesc('messages')
            ->values()
            ->all();
    }

    protected function highlight(array $unanswered, array $products, array $ratings, array $pending): ?array
    {
        if ($pending['price_approvals'] > 0) {
            return ['kind' => 'price_approvals', 'count' => $pending['price_approvals'], 'subject' => null, 'route' => 'leads.index'];
        }

        if ($ratings['down'] > 0) {
            return ['kind' => 'thumbs_down', 'count' => $ratings['down'], 'subject' => null, 'route' => 'conversations.index'];
        }

        if ($unanswered !== [] && $unanswered[0]['count'] > 1) {
            return ['kind' => 'unanswered', 'count' => $unanswered[0]['count'], 'subject' => $unanswered[0]['question'], 'route' => 'agents.index'];
        }

        $missed = array_values(array_filter($products, fn ($row) => $row['miss_rate'] >= 50 && $row['count'] > 1));

        if ($missed !== []) {
            return ['kind' => 'product_gap', 'count' => $missed[0]['count'], 'subject' => $missed[0]['term'], 'route' => 'agents.index'];
        }

        if ($pending['open_orders'] > 0) {
            return ['kind' => 'open_orders', 'count' => $pending['open_orders'], 'subject' => null, 'route' => 'leads.index'];
        }

        if ($unanswered !== []) {
            return ['kind' => 'unanswered', 'count' => $unanswered[0]['count'], 'subject' => $unanswered[0]['question'], 'route' => 'agents.index'];
        }

        return null;
    }

    protected function conversations()
    {
        return Conversation::query()->whereIn('conversations.agent_id', $this->agentIds());
    }

    protected function leads()
    {
        return Lead::query()->whereIn('leads.agent_id', $this->agentIds());
    }

    protected function messages()
    {
        return Message::query()
            ->join('conversations', 'conversations.id', '=', 'messages.conversation_id')
            ->whereIn('conversations.agent_id', $this->agentIds());
    }

    protected function agentIds()
    {
        return Agent::ownedBy($this->user)
            ->select('agents.id')
            ->when($this->agentId !== null, fn ($q) => $q->where('agents.id', $this->agentId));
    }
}
