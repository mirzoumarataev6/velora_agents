<?php

namespace App\Services\Analytics;

use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Message;
use App\Models\User;
use App\Services\Haggling\HagglingService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Every number on the owner's dashboard, read from rows that already exist.
 *
 * Two rules shaped this class:
 *
 *  - Nothing is invented. A widget with no history returns an empty structure
 *    and the view says so; there is no sample data, no "—" standing in for a
 *    number that was never measured, and no extrapolation from one day to a
 *    week. An owner who trusts a fabricated chart once will not trust a real
 *    one later.
 *
 *  - The heavy widgets share one pass. The time series, the heatmap and the
 *    out-of-hours count are all functions of the same list of message
 *    timestamps, so they are derived in PHP from a single query rather than
 *    from three GROUP BYs — which also sidesteps the fact that day-of-week and
 *    hour extraction are spelled differently in MySQL and in the SQLite the
 *    tests run on.
 */
class DashboardMetrics
{
    /** A conversation counts as live if it has moved within this window. */
    public const ACTIVE_WITHIN_HOURS = 24;

    /** Ranges the messages chart can be shown over. */
    public const RANGES = [7, 30];

    /**
     * When set (via forRange's $agentId), every widget on the page is scoped
     * to this one agent instead of all of the owner's agents — set once per
     * request and read by agentIds(), so a single filter change here reaches
     * every widget without threading a parameter through each of them.
     */
    protected ?int $agentId = null;

    public function __construct(protected User $user) {}

    /**
     * @return array<string, mixed>
     */
    public function forRange(int $days, ?int $agentId = null): array
    {
        $days = in_array($days, self::RANGES, true) ? $days : self::RANGES[0];
        $since = CarbonImmutable::now()->subDays($days - 1)->startOfDay();
        $this->agentId = $agentId;

        $timeline = $this->messageTimeline($since);

        return [
            'range' => $days,
            // Echoed back so the view can build links (range toggle, etc.)
            // that keep the selected agent rather than silently dropping it.
            'agent_id' => $this->agentId,
            'has_history' => $this->hasAnyHistory(),
            'summary' => $this->summary(),
            'series' => $this->series($timeline, $since, $days),
            'channels' => $timeline->pluck('channel')->unique()->filter()->sort()->values()->all(),
            'heatmap' => $this->heatmap($timeline),
            'after_hours' => $this->afterHours($timeline, $since),
            'funnel' => $this->funnel(),
            'pending' => $this->pending(),
            'unanswered' => $unanswered = $this->unanswered($since),
            'products' => $products = $this->askedProducts($since),
            'ratings' => $ratings = $this->ratings($since, $days),
            'response_trend' => $this->responseTrend($since, $days),
            'leads_by_status' => $this->leadsByStatus(),
            'agent_comparison' => $this->agentComparison($since),
            // Derived from the four above rather than from its own queries: the
            // highlight is a RANKING of things already on this page, not a new
            // measurement, and computing it separately would let the card and
            // the widget under it disagree about the same number.
            'highlight' => $this->highlight($unanswered, $products, $ratings),
        ];
    }

    /**
     * Whether this account has enough of a past for any of this to mean
     * anything. Drives one honest empty state instead of eight lonely zeroes.
     */
    public function hasAnyHistory(): bool
    {
        return $this->conversations()->exists();
    }

    /**
     * @return array<string, int|float|null>
     */
    protected function summary(): array
    {
        $now = CarbonImmutable::now();

        $activeConversations = $this->conversations()
            ->where('conversations.status', '!=', 'closed')
            ->where('conversations.last_message_at', '>=', $now->subHours(self::ACTIVE_WITHIN_HOURS))
            ->count();

        $leadsToday = $this->leads()->where('leads.created_at', '>=', $now->startOfDay())->count();
        $leadsWeek = $this->leads()->where('leads.created_at', '>=', $now->startOfWeek())->count();

        $totalConversations = $this->conversations()->count();
        $convertedConversations = $this->conversations()
            ->whereIn('conversations.id', Lead::query()->select('conversation_id')->whereNotNull('conversation_id'))
            ->count();

        return [
            'active_conversations' => $activeConversations,
            'leads_today' => $leadsToday,
            'leads_week' => $leadsWeek,
            'conversations_total' => $totalConversations,
            'conversion_rate' => $totalConversations > 0
                ? round($convertedConversations / $totalConversations * 100, 1)
                : null,
            'avg_response_ms' => $this->averageResponseMs(),
        ];
    }

    /**
     * How long the agent takes to answer, in milliseconds.
     *
     * Read from the timing the reply pipeline already stamps on every assistant
     * message rather than from the gap between two rows: `total_ms` is the work
     * the agent did, while a timestamp difference would also count the customer
     * walking away mid-conversation.
     */
    protected function averageResponseMs(): ?float
    {
        $value = $this->messages()
            ->where('messages.role', 'assistant')
            ->whereNotNull(DB::raw("json_extract(messages.meta, '$.total_ms')"))
            ->avg(DB::raw("json_extract(messages.meta, '$.total_ms') + 0"));

        return $value === null ? null : round((float) $value, 0);
    }

    /**
     * Every message in the window as {at, channel}. The one query three widgets
     * are built from.
     *
     * @return Collection<int, object{at: CarbonImmutable, channel: ?string}>
     */
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

    /**
     * Daily message counts, per channel and in total, with every day in the
     * range present — including the silent ones. A chart that skips empty days
     * turns a quiet weekend into a straight line and hides it.
     *
     * @param  Collection<int, object>  $timeline
     * @return array<int, array<string, mixed>>
     */
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
     * Day-of-week × hour-of-day message volume.
     *
     * Monday-first (ISO), because the audience is Russian- and Tajik-speaking
     * and a Sunday-first grid reads as someone else's calendar.
     *
     * @param  Collection<int, object>  $timeline
     * @return array{grid: array<int, array<int, int>>, max: int}
     */
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

    /**
     * The product's whole argument, as a number: how much of the work happened
     * when nobody was at the shop.
     *
     * Agents carry no configured opening hours, so the window is the documented
     * default — nights plus the whole weekend. Named in config rather than
     * inlined so it is one edit away from becoming per-agent later.
     *
     * @param  Collection<int, object>  $timeline
     * @return array<string, int|float|null>
     */
    protected function afterHours(Collection $timeline, CarbonImmutable $since): array
    {
        $messages = $timeline->filter(fn ($row) => $this->isAfterHours($row->at))->count();
        $total = $timeline->count();

        $leads = $this->leads()
            ->where('leads.created_at', '>=', $since)
            ->pluck('leads.created_at')
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

        // The window wraps midnight, so it is "at or after 22" OR "before 8",
        // not a range between two numbers.
        return $hour >= $from || $hour < $to;
    }

    /**
     * A stored (UTC) moment on the shop's wall clock. "Busy at 10" and "after
     * 22:00" are questions about Khujand time, not about the server's.
     */
    protected function local(CarbonImmutable $at): CarbonImmutable
    {
        return $at->setTimezone(config('agent.analytics.timezone') ?: config('app.timezone'));
    }

    /**
     * Conversations → заявки → confirmed, with the drop-off between each pair.
     *
     * "Confirmed" is a lead the owner has actually moved on (in progress or
     * done). A lead still sitting at `new` is a lead, not yet an order, and
     * counting it as one would make the funnel flatter than the business is.
     *
     * Each stage after the first carries `conversion` — the share of the
     * PREVIOUS stage that reached this one — rather than the drop-off. Not
     * finding a lead in every conversation is not a failure on the business's
     * part, it is a customer who did not convert at that step; a SaaS funnel
     * (Amplitude, Mixpanel) reports that as a conversion rate, not a loss, and
     * so does this one now.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function funnel(): array
    {
        $conversations = $this->conversations()->count();
        $leads = $this->leads()->count();
        $confirmed = $this->leads()->whereIn('leads.status', ['in_progress', 'done'])->count();

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

    /**
     * @return array<string, int>
     */
    protected function pending(): array
    {
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

    /**
     * Questions the agent could not answer, grouped by what was asked.
     *
     * Grouped on a normalised form of the question — lowercased, stripped of
     * punctuation — so "есть ли доставка в Худжанд?" and "Доставка в Худжанд"
     * land in one row instead of two rows of one. The normalised key is for
     * grouping, never for reading: what the owner sees is a verbatim phrasing,
     * specifically the MOST RECENT one, which falls out of the newest-first
     * ordering the row cap needs anyway and is the better choice regardless —
     * it is how customers are asking now.
     *
     * @return array<int, array<string, mixed>>
     */
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

    /**
     * What customers asked the catalog for, converted or not.
     *
     * Counted per SEARCH rather than per sale on purpose: a product asked about
     * nine times and bought once is the most interesting row on this page, and
     * every sales-side report would show it as a single unit.
     *
     * Terms are folded case-insensitively; the label shown is the most recent
     * spelling, for the same reason the unanswered list shows the most recent
     * phrasing.
     *
     * @return array<int, array<string, mixed>>
     */
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
                    continue; // A bare "what do you sell?" browse — no demand signal in it.
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
                // How often the catalog had something to show for it. A term
                // asked about repeatedly with nothing found is a stocking
                // decision waiting to be made.
                'miss_rate' => $row['count'] > 0 ? (int) round(($row['count'] - $row['found']) / $row['count'] * 100) : 0,
            ])
            ->values()
            ->all();
    }

    /**
     * What customers thought of the answers, in total and day by day.
     *
     * This is the only signal on the page that catches a CONFIDENTLY WRONG
     * answer. Everything else here reports what the agent noticed about itself:
     * the unanswered list needs the agent to have failed to find something, and
     * an agent that quotes last year's price finds plenty. Only the customer
     * knows that one was wrong, and 👎 is the only way they can say so.
     *
     * @return array{up: int, down: int, total: int, score: float|null, series: array<int, array<string, mixed>>, worst: array<int, array<string, mixed>>}
     */
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
            // Null, not 100, when nobody has voted. An agent nobody has rated
            // has not scored perfectly; it has not been scored.
            'score' => $total > 0 ? round($up / $total * 100, 1) : null,
            'series' => $series,
            // The replies to actually go and read. Newest first, because a
            // wrong price quoted this morning matters more than one last week.
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

    /**
     * Average reply time per day — is the agent getting faster or slower?
     *
     * Same `total_ms` the headline number uses, so the trend and the average
     * above it can never tell different stories. Days with no measured reply
     * carry null rather than zero: a quiet day is not an instant one.
     *
     * @return array{series: array<int, array<string, mixed>>, peak: float, has_data: bool}
     */
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

    /**
     * Where the заявки are sitting.
     *
     * Every status the schema can hold is listed, including the ones at zero —
     * an empty "awaiting_price_approval" row is information (nobody is waiting
     * on you), while a missing one just looks like a shorter list.
     *
     * @return array{rows: array<int, array<string, mixed>>, total: int}
     */
    protected function leadsByStatus(): array
    {
        $counts = $this->leads()
            ->select('leads.status', DB::raw('count(*) as aggregate'))
            ->groupBy('leads.status')
            ->pluck('aggregate', 'leads.status');

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
     * Which agent is carrying the work, when the owner runs more than one.
     *
     * Returns an empty array for a single-agent account rather than a
     * one-bar chart: a comparison of one thing is not a comparison, and the
     * view drops the whole widget on empty. Also empty once the page itself
     * is scoped to one agent (the per-agent filter) — comparing an agent
     * against itself is not a comparison either.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function agentComparison(CarbonImmutable $since): array
    {
        if ($this->agentId !== null) {
            return [];
        }

        $agents = $this->user->agents()->orderBy('name')->get(['id', 'name']);

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

        $leads = Lead::query()
            ->whereIn('leads.agent_id', $agents->pluck('id'))
            ->where('leads.created_at', '>=', $since)
            ->select('leads.agent_id', DB::raw('count(*) as aggregate'))
            ->groupBy('leads.agent_id')
            ->pluck('aggregate', 'leads.agent_id');

        $totalMessages = (int) $messages->sum();

        return $agents
            ->map(fn (Agent $agent) => [
                'id' => $agent->id,
                'name' => $agent->name,
                'messages' => $agentMessages = (int) ($messages[$agent->id] ?? 0),
                'leads' => (int) ($leads[$agent->id] ?? 0),
                // Share of the TOTAL, not of the busiest agent: "who is
                // carrying how much of the whole" is a different question
                // from "who is busiest", and the bar answers the first one.
                'share' => $totalMessages > 0 ? round($agentMessages / $totalMessages * 100, 1) : 0.0,
            ])
            ->sortByDesc('messages')
            ->values()
            ->all();
    }

    /**
     * The one thing worth doing right now.
     *
     * Replaces the old "работает, пока вы спите" card, which reported a number
     * nobody could act on. This ranks the page's own findings by how much a
     * single decision would change them, and shows the top one — so the card
     * says "three people asked about X and got nothing, add it" rather than
     * "42".
     *
     * Ordering is by URGENCY FIRST, then by reach: someone actively waiting on
     * the owner outranks a pattern in last week's data, however large. Ties are
     * broken by count inside each kind.
     *
     * @param  array<int, array<string, mixed>>  $unanswered
     * @param  array<int, array<string, mixed>>  $products
     * @param  array<string, mixed>  $ratings
     * @return array{kind: string, count: int, subject: ?string, route: ?string}|null
     */
    protected function highlight(array $unanswered, array $products, array $ratings): ?array
    {
        $pending = $this->pending();

        // 1. Someone is waiting on a decision only the owner can make.
        if ($pending['price_approvals'] > 0) {
            return ['kind' => 'price_approvals', 'count' => $pending['price_approvals'], 'subject' => null, 'route' => 'leads.index'];
        }

        // 2. The agent answered confidently and the customer said it was wrong.
        //    Nothing else on this page can see this.
        if ($ratings['down'] > 0) {
            return ['kind' => 'thumbs_down', 'count' => $ratings['down'], 'subject' => null, 'route' => 'conversations.index'];
        }

        // 3. A question asked repeatedly with nothing to answer it — one
        //    knowledge-base entry away from being solved for good.
        if ($unanswered !== [] && $unanswered[0]['count'] > 1) {
            return ['kind' => 'unanswered', 'count' => $unanswered[0]['count'], 'subject' => $unanswered[0]['question'], 'route' => 'agents.index'];
        }

        // 4. Demand the catalog cannot meet — a stocking decision.
        $missed = array_values(array_filter($products, fn ($row) => $row['miss_rate'] >= 50 && $row['count'] > 1));

        if ($missed !== []) {
            return ['kind' => 'product_gap', 'count' => $missed[0]['count'], 'subject' => $missed[0]['term'], 'route' => 'agents.index'];
        }

        // 5. Ordinary backlog.
        if ($pending['open_orders'] > 0) {
            return ['kind' => 'open_orders', 'count' => $pending['open_orders'], 'subject' => null, 'route' => 'leads.index'];
        }

        // 6. A single unanswered question is still worth naming — it is just
        //    not yet a pattern.
        if ($unanswered !== []) {
            return ['kind' => 'unanswered', 'count' => $unanswered[0]['count'], 'subject' => $unanswered[0]['question'], 'route' => 'agents.index'];
        }

        return null;
    }

    /** @return Builder<Conversation> */
    protected function conversations()
    {
        return Conversation::query()->whereIn('conversations.agent_id', $this->agentIds());
    }

    /** @return Builder<Lead> */
    protected function leads()
    {
        return Lead::query()->whereIn('leads.agent_id', $this->agentIds());
    }

    /** @return Builder<Message> */
    protected function messages()
    {
        return Message::query()
            ->join('conversations', 'conversations.id', '=', 'messages.conversation_id')
            ->whereIn('conversations.agent_id', $this->agentIds());
    }

    /** @return Builder<Agent> */
    protected function agentIds()
    {
        return $this->user->agents()
            ->select('agents.id')
            ->when($this->agentId !== null, fn ($q) => $q->where('agents.id', $this->agentId));
    }
}
