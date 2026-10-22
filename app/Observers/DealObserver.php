<?php

namespace App\Observers;

use App\Models\CrmStage;
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

            $previousStageId = $deal->getPrevious()['crm_stage_id'] ?? null;
            $previousStage = $previousStageId !== null ? CrmStage::find($previousStageId) : null;

            $this->record($deal, 'stage_changed', [
                'from' => $deal->getPrevious()['stage'] ?? null,
                'to' => $deal->stage,
                'from_role' => $previousStage?->role,
                'to_role' => $deal->stage_role,
                'from_label' => $previousStage?->displayName(),
                'to_label' => $deal->crmStage?->displayName(),
                'reason' => $deal->getPrevious()['cancelled_reason'] ?? $deal->cancelled_reason,
            ]);
        }

        if ($deal->wasChanged('closed_at') && $deal->closed_at !== null) {
            $this->record($deal, 'closed', ['reason' => $deal->won_reason]);
        }

        $changes = [];
        foreach (['title', 'amount', 'payment_status', 'cancelled_reason', 'won_reason'] as $field) {
            if ($field === 'cancelled_reason' && $deal->wasChanged('stage')) {
                continue;
            }

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

            'team_id' => $deal->team_id,
            'deal_id' => $deal->id,
            'type' => $type,
            'data' => $data ?: null,
        ]);
    }
}
