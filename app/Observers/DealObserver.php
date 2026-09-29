<?php

namespace App\Observers;

use App\Models\Deal;
use App\Models\DealActivity;

class DealObserver
{
    public function created(Deal $deal): void
    {
        $this->record($deal, 'created');
    }

    public function updated(Deal $deal): void
    {
        if ($deal->wasChanged('stage')) {
            $this->record($deal, 'stage_changed', [
                'from' => $deal->getPrevious()['stage'] ?? null,
                'to' => $deal->stage,
                'reason' => $deal->getPrevious()['cancelled_reason'] ?? $deal->cancelled_reason,
            ]);
        }

        $changes = [];
        foreach (['title', 'amount', 'payment_status', 'cancelled_reason'] as $field) {
            if ($field === 'cancelled_reason' && $deal->wasChanged('stage')) {
                continue;
            }
            if ($deal->wasChanged($field)) {
                $changes[$field] = ['from' => $deal->getPrevious()[$field] ?? null, 'to' => $deal->{$field}];
            }
        }

        if ($changes !== []) {
            $this->record($deal, 'updated', ['changes' => $changes]);
        }
    }

    private function record(Deal $deal, string $type, array $data = []): void
    {
        DealActivity::create([
            'user_id' => $deal->user_id,
            'deal_id' => $deal->id,
            'type' => $type,
            'data' => $data ?: null,
        ]);
    }
}
