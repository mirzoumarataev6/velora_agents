<?php

namespace App\Observers;

use App\Models\Lead;
use App\Services\Crm\CustomerResolver;
use App\Services\Crm\DealStageAutomation;
use Illuminate\Support\Facades\Log;
use Throwable;

class LeadObserver
{
    public function __construct(
        protected CustomerResolver $customers,
        protected DealStageAutomation $deals,
    ) {}

    public function created(Lead $lead): void
    {
        $this->resolve($lead);

        $this->drive(function () use ($lead) {

            $this->deals->negotiationOpened($lead);
            $this->deals->orderPlaced($lead);
        }, $lead);
    }

    public function updated(Lead $lead): void
    {
        if ($lead->wasChanged('phone')) {
            $this->resolve($lead);
        }

        if ($lead->wasChanged('approved_price')) {
            $this->drive(fn () => $this->deals->priceAgreed($lead), $lead);
        }
    }

    private function resolve(Lead $lead): void
    {
        try {
            $this->customers->resolveForLead($lead);
        } catch (Throwable $e) {
            Log::warning('Could not file a lead against a CRM customer', [
                'lead_id' => $lead->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function drive(callable $work, Lead $lead): void
    {
        try {
            $work();
        } catch (Throwable $e) {
            Log::warning('Could not advance a deal from a lead', [
                'lead_id' => $lead->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
