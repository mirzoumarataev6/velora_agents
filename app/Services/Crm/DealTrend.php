<?php

namespace App\Services\Crm;

use App\Models\Deal;
use App\Models\User;
use Carbon\CarbonImmutable;

class DealTrend
{
    public const WINDOWS = ['8w', '6m'];

    public function series(User $user, string $window, ?int $workspaceId): array
    {
        $window = in_array($window, self::WINDOWS, true) ? $window : '8w';
        $timezone = config('agent.analytics.timezone') ?: config('app.timezone');

        $buckets = $this->buckets($window, $timezone);
        $index = array_flip(array_column($buckets, 'key'));

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

            if ($deal->payment_status === 'paid' && $deal->stage_role === 'won') {

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

                'label' => $window === '6m' ? $bucketStart->translatedFormat('M Y') : $bucketStart->translatedFormat('d M'),
                'new_deals' => 0,
                'won' => 0,
                'revenue' => 0.0,
            ];
        }

        return $buckets;
    }

    private function bucketKey(string $window, string $timezone, \DateTimeInterface $at): string
    {
        $local = CarbonImmutable::instance($at)->setTimezone($timezone);

        return $window === '6m' ? $local->startOfMonth()->toDateString() : $local->startOfWeek(1)->toDateString();
    }

    private function localBucketKey(string $window, \DateTimeInterface $date): string
    {
        $local = CarbonImmutable::instance($date);

        return $window === '6m' ? $local->startOfMonth()->toDateString() : $local->startOfWeek(1)->toDateString();
    }
}
