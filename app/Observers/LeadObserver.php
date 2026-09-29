<?php

namespace App\Observers;

use App\Models\Lead;
use App\Services\Crm\CustomerResolver;
use App\Services\Crm\DealStageAutomation;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * A captured lead is where a phone number usually enters the system, and a
 * phone number is the only thing that can identify one person across two apps.
 * So this is the hook that makes the retroactive merge happen.
 */
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
            // Two mutually exclusive signals: a price-approval request IS the
            // negotiation, anything else is an order that has been placed.
            $this->deals->negotiationOpened($lead);
            $this->deals->orderPlaced($lead);
        }, $lead);
    }

    /** A number added to an existing lead identifies the customer just as well. */
    public function updated(Lead $lead): void
    {
        if ($lead->wasChanged('phone')) {
            $this->resolve($lead);
        }

        // The owner has granted a price: agreed number, so the deal is waiting
        // on money just as a placed order is.
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

    /** Wrapped like the rest: a board that failed to move must not cost a sale. */
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
