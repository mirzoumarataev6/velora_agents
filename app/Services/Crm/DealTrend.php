<?php

namespace App\Services\Crm;

use App\Models\Deal;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * The CRM's own time-series: new deals, deals won, and paid revenue over a
 * selectable window — separate from DashboardMetrics (that one reports on
 * the AI agents' conversations, this reports on the pipeline itself).
 *
 * Every bucket is computed in the owner's timezone, not the database's.
 * `created_at`/`closed_at` are stored in UTC — grouping by their raw date
 * would put late-evening Dushanbe activity (UTC+5) into the next UTC day's
 * bucket, which is exactly the bug DashboardMetrics::series() and
 * responseTrend() still have. Every timestamp here is converted to local
 * time BEFORE its bucket key is computed; `deal_date` is the one exception,
 * because it is a plain date the owner typed directly and was never a UTC
 * instant to begin with.
 */
class DealTrend
{
    public const WINDOWS = ['8w', '6m'];

    /** New deals, deals won, and paid revenue, bucketed weekly (8w) or monthly (6m) in the owner's timezone. */
    public function series(User $user, string $window, ?int $workspaceId): array
    {
        $window = in_array($window, self::WINDOWS, true) ? $window : '8w';
        $timezone = config('agent.analytics.timezone') ?: config('app.timezone');

        $buckets = $this->buckets($window, $timezone);
        $index = array_flip(array_column($buckets, 'key'));

        // Three different lower bounds for three differently-anchored
        // measures sharing one query: a deal can enter this window by being
        // created, closed, or logged (deal_date) inside it, even if the
        // other two dates sit outside.
        $localFloor = CarbonImmutable::parse($buckets[0]['key'], $timezone);
        $utcFloor = $localFloor->setTimezone('UTC');

        $query = Deal::ownedBy($user)
            ->where(fn ($q) => $q
                ->where('created_at', '>=', $utcFloor)
                ->orWhere('closed_at', '>=', $utcFloor)
                ->orWhere('deal_date', '>=', $localFloor->toDateString()));

        if ($workspaceId !== null) {
            $query->where('crm_workspace_id', $workspaceId);
        }

        $rows = $query->get(['created_at', 'closed_at', 'deal_date', 'stage_role', 'payment_status', 'amount']);

        foreach ($rows as $deal) {
            $createdKey = $this->bucketKey($window, $timezone, $deal->created_at);
            if (isset($index[$createdKey])) {
                $buckets[$index[$createdKey]]['new_deals']++;
            }

            if ($deal->closed_at !== null) {
                $closedKey = $this->bucketKey($window, $timezone, $deal->closed_at);
                if (isset($index[$closedKey])) {
                    $buckets[$index[$closedKey]]['won']++;
                }
            }

            // Both conditions, not payment_status alone — see
            // DealBoard::paidInWonStage(), the same "paid" definition every
            // other purchase/revenue count in the CRM uses now.
            if ($deal->payment_status === 'paid' && $deal->stage_role === 'won') {
                // closed_at when the owner explicitly confirmed it, deal_date
                // (already local — see the class docblock) otherwise,
                // created_at as the last resort for a deal with neither.
                if ($deal->closed_at !== null) {
                    $revenueKey = $this->bucketKey($window, $timezone, $deal->closed_at);
                } elseif ($deal->deal_date !== null) {
                    $revenueKey = $this->localBucketKey($window, $deal->deal_date);
                } else {
                    $revenueKey = $this->bucketKey($window, $timezone, $deal->created_at);
                }

                if (isset($index[$revenueKey])) {
                    $buckets[$index[$revenueKey]]['revenue'] += (float) $deal->amount;
                }
            }
        }

        return [
            'window' => $window,
            'timezone' => $timezone,
            'buckets' => $buckets,
            'totals' => [
                'new_deals' => array_sum(array_column($buckets, 'new_deals')),
                'won' => array_sum(array_column($buckets, 'won')),
                'revenue' => array_sum(array_column($buckets, 'revenue')),
            ],
        ];
    }

    /** The empty, dated buckets a window is made of — zero-filled until the query above adds to them. */
    private function buckets(string $window, string $timezone): array
    {
        $now = CarbonImmutable::now($timezone);

        if ($window === '6m') {
            $start = $now->startOfMonth()->subMonths(5);
            $count = 6;
        } else {
            $start = $now->startOfWeek(1)->subWeeks(7);
            $count = 8;
        }

        $buckets = [];
        for ($i = 0; $i < $count; $i++) {
            $bucketStart = $window === '6m' ? $start->addMonths($i) : $start->addWeeks($i);
            $buckets[] = [
                'key' => $bucketStart->toDateString(),
                'label' => $window === '6m' ? $bucketStart->translatedFormat('MMM Y') : $bucketStart->translatedFormat('d MMM'),
                'new_deals' => 0,
                'won' => 0,
                'revenue' => 0.0,
            ];
        }

        return $buckets;
    }

    /**
     * A stored instant's bucket key, after converting it to the owner's
     * timezone first. `$at` is already a Carbon instance here — Eloquent's
     * own datetime/date casts produced it — so this only ever translates an
     * already-known timezone, never guesses one.
     */
    private function bucketKey(string $window, string $timezone, \DateTimeInterface $at): string
    {
        $local = CarbonImmutable::instance($at)->setTimezone($timezone);

        return $window === '6m' ? $local->startOfMonth()->toDateString() : $local->startOfWeek(1)->toDateString();
    }

    /** A plain date's bucket key — no timezone conversion, because it was never a UTC instant. */
    private function localBucketKey(string $window, \DateTimeInterface $date): string
    {
        $local = CarbonImmutable::instance($date);

        return $window === '6m' ? $local->startOfMonth()->toDateString() : $local->startOfWeek(1)->toDateString();
    }
}
