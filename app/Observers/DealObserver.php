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

        // The won counterpart to the cancellation branch above: closing is
        // the owner attesting the sale is done, which deserves its own
        // moment in the timeline the same way cancelling does, rather than
        // falling into the generic "updated" bucket — see DealBoard::setClosed().
        if ($deal->wasChanged('closed_at') && $deal->closed_at !== null) {
            $this->record($deal, 'closed', ['reason' => $deal->won_reason]);
        }

        $changes = [];
        foreach (['title', 'amount', 'payment_status', 'cancelled_reason', 'won_reason'] as $field) {
            if ($field === 'cancelled_reason' && $deal->wasChanged('stage')) {
                continue;
            }
            // Already reported by the 'closed' activity above — recording it
            // again here would say the same thing twice.
            if ($field === 'won_reason' && $deal->wasChanged('closed_at')) {
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
