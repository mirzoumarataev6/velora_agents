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

    /**
     * $canViewCrm is passed in, never resolved here via Gate/can(): this
     * class has no Request to authorize against and no business deciding
     * who gets to see what — DashboardController already knows (crm.view),
     * and threading the answer through the constructor is what lets every
     * method below skip a Lead query outright instead of running it and
     * discarding the result. H4: /dashboard itself stays gated by
     * analytics.view alone (unchanged) — this is what stops a role holding
     * ONLY analytics.view from seeing lead figures that belong to crm.view.
     */
    public function __construct(protected User $user, protected bool $canViewCrm) {}

    /**
     * Собирает все метрики дашборда за указанный период, опционально по
     * одному агенту.
     *
     * $agents, when the caller already has the owner's agent list (the page
     * does, for its own selector), is threaded into agentComparison() rather
     * than let it fetch the identical ownedBy() list a second time.
     */
    public function forRange(int $days, ?int $agentId = null, ?Collection $agents = null): array
    {
        $days = in_array($days, self::RANGES, true) ? $days : self::RANGES[0];
        $since = CarbonImmutable::now()->subDays($days - 1)->startOfDay();
        $this->agentId = $agentId;

        $timeline = $this->messageTimeline($since);
        $conversationStats = $this->conversationStats();

        // Both grouped by the one status column, so funnel()'s "leads" and
        // "confirmed" stages are sums over these counts rather than two more
        // round trips against a table leadsByStatus() just finished reading.
        $leadStatusCounts = $this->canViewCrm ? $this->leadStatusCounts() : collect();

        // Same WHERE (created_at >= $since, this owner's leads), read once and
        // shared: daily() groups it by day, afterHours() filters it by hour.
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

    // Сообщает, есть ли у пользователя вообще какая-либо история переписки
    public function hasAnyHistory(): bool
    {
        return $this->conversations()->exists();
    }

    /**
     * Собирает верхнеуровневые сводные показатели: активные диалоги, лиды, конверсия
     *
     * @param  array{total: int, active: int}  $conversationStats
     */
    protected function summary(array $conversationStats): array
    {
        $now = CarbonImmutable::now();

        // H4: leads_today/leads_week/conversion_rate are crm.view's data, not
        // analytics.view's — a role holding only the latter gets 0/null here
        // rather than a query against the leads table at all. One query with
        // two conditional sums rather than two: both read the same rows,
        // scoped to the same agent(s), just bucketed by a different cutoff.
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

    /**
     * Общее и «активное» число диалогов за один проход — funnel() reuses the
     * total instead of counting the same rows again.
     *
     * @return array{total: int, active: int}
     */
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

    // Вычисляет среднее время ответа ассистента в миллисекундах
    protected function averageResponseMs(): ?float
    {
        $value = $this->messages()
            ->where('messages.role', 'assistant')
            ->whereNotNull(DB::raw("json_extract(messages.meta, '$.total_ms')"))
            ->avg(DB::raw("json_extract(messages.meta, '$.total_ms') + 0"));

        return $value === null ? null : round((float) $value, 0);
    }

    // Собирает временную шкалу сообщений с каналом для каждой записи
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

    // Группирует временную шкалу по дням для построения графика сообщений
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

    /**
     * Дневные ряды для спарклайнов и трендов на карточках KPI.
     *
     * Читает и группирует — ничего не решает. Существует только для того,
     * чтобы плитка, показывающая «10», могла показать и то, как эти 10
     * набрались: без этого три из пяти карточек остались бы без линии, а
     * рисовать её из выдуманных чисел нельзя.
     *
     * Даты достаются одним столбцом и раскладываются по дням в PHP.
     * Группировка по дате в SQL потребовала бы диалекто-зависимой функции
     * (date() против DATE_FORMAT()), а этот проект ходит и в SQLite (тесты),
     * и в MySQL (прод) — так что день считается там, где часовой пояс
     * аналитики уже известен.
     *
     * The leads column is a parameter, not a query here: afterHours() reads
     * the exact same `created_at >= $since` rows for its own purpose, so
     * forRange() fetches them once and hands the result to both.
     *
     * Ряд конверсии — производный, не третий запрос: доля диалогов за день,
     * из которых вышла заявка. Дни без диалогов дают null, а не ноль: день без
     * трафика — это не день с нулевой конверсией.
     */
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

    // Строит тепловую карту активности по дням недели и часам
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

    // Считает долю сообщений и лидов, пришедших в нерабочее время
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

    // Определяет, приходится ли момент времени на нерабочие часы
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

    // Переводит момент времени в часовой пояс аналитики
    protected function local(CarbonImmutable $at): CarbonImmutable
    {
        return $at->setTimezone(config('agent.analytics.timezone') ?: config('app.timezone'));
    }

    /**
     * Строит воронку от диалогов к лидам и подтверждённым сделкам.
     *
     * Takes the conversation total and the per-status lead counts rather than
     * counting again: both are already a full pass over exactly these rows,
     * done once in forRange() for the summary card and the status breakdown.
     *
     * @param  array{total: int, active: int}  $conversationStats
     */
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

    // Считает открытые заказы и заявки на согласование цены, ожидающие владельца
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

    // Группирует вопросы без ответа, чтобы показать самые частые пробелы в знаниях
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

    // Считает, о каких товарах чаще всего спрашивали и как часто поиск не находил их
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

    // Собирает статистику оценок ответов (лайки/дизлайки) и худшие по оценке ответы
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

    // Строит динамику среднего времени ответа по дням
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

    // Считает количество лидов по каждому статусу — один сгруппированный запрос
    protected function leadStatusCounts(): Collection
    {
        return $this->leads()
            ->select('leads.status', DB::raw('count(*) as aggregate'))
            ->groupBy('leads.status')
            ->pluck('aggregate', 'leads.status');
    }

    // Раскладывает уже посчитанные по статусам лиды на строки с долями
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

    /**
     * Сравнивает агентов пользователя по числу сообщений и лидов (если агентов больше одного)
     *
     * $agents, when given, is the controller's own ownedBy() list for its
     * agent selector — the identical query, so reusing it skips a second
     * round trip for the same rows.
     */
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

    /**
     * Выбирает самый важный сигнал для баннера дашборда по приоритету.
     *
     * $pending is a parameter, not a call: forRange() already computes it
     * for the 'pending' key, and this used to call pending() again — a
     * second pair of lead queries for a value already sitting in a local.
     */
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

    // Возвращает диалоги, доступные пользователю (с учётом фильтра по агенту)
    protected function conversations()
    {
        return Conversation::query()->whereIn('conversations.agent_id', $this->agentIds());
    }

    // Возвращает лиды, доступные пользователю (с учётом фильтра по агенту)
    protected function leads()
    {
        return Lead::query()->whereIn('leads.agent_id', $this->agentIds());
    }

    // Возвращает сообщения, доступные пользователю (с учётом фильтра по агенту)
    protected function messages()
    {
        return Message::query()
            ->join('conversations', 'conversations.id', '=', 'messages.conversation_id')
            ->whereIn('conversations.agent_id', $this->agentIds());
    }

    // Возвращает идентификаторы агентов пользователя, отфильтрованные по выбранному агенту
    protected function agentIds()
    {
        return Agent::ownedBy($this->user)
            ->select('agents.id')
            ->when($this->agentId !== null, fn ($q) => $q->where('agents.id', $this->agentId));
    }
}
