<?php

namespace Tests\Feature\Crm;

use App\Models\CrmWorkspace;
use App\Models\Deal;
use App\Models\User;
use App\Services\Crm\DealTrend;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The CRM trend report. The timezone test is the one that matters: a deal
 * logged right after local midnight in Asia/Dushanbe (UTC+5) is still the
 * previous UTC calendar day — grouping by raw UTC, the bug this report must
 * NOT repeat, would put it a whole week earlier than it belongs.
 */
class DealTrendTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_trend_page_requires_authentication(): void
    {
        $this->get(route('crm.trend'))->assertRedirect(route('login'));
    }

    public function test_the_trend_page_renders_for_a_signed_in_owner(): void
    {
        $owner = User::factory()->create();

        $this->actingAs($owner)->get(route('crm.trend'))->assertOk();
    }

    public function test_buckets_are_computed_in_the_owners_timezone_not_raw_utc(): void
    {
        $owner = User::factory()->create();
        $timezone = 'Asia/Dushanbe';

        // This week's Monday, 00:00 local — and 02:00 local just after it,
        // which is 21:00 the PREVIOUS (UTC) Sunday. A raw-UTC grouping would
        // file this deal under the week ending that Sunday; correct local
        // bucketing must file it under the week that starts this Monday.
        $mondayStart = CarbonImmutable::now($timezone)->startOfWeek(CarbonInterface::MONDAY);
        $mondayEarlyMorningLocal = $mondayStart->addHours(2);
        $createdAtUtc = $mondayEarlyMorningLocal->setTimezone('UTC');

        $this->assertNotSame($mondayStart->toDateString(), $createdAtUtc->toDateString());

        // Keep "now" a few weeks ahead so $mondayStart sits safely inside
        // the 8-week lookback window, not at either edge of it.
        $this->travelTo($mondayStart->addWeeks(3));

        Deal::factory()->for($owner)->create(['created_at' => $createdAtUtc]);

        $series = app(DealTrend::class)->series($owner, '8w', null);
        $buckets = collect($series['buckets'])->keyBy('key');

        $this->assertSame(1, $buckets[$mondayStart->toDateString()]['new_deals']);
        $this->assertSame(0, $buckets[$mondayStart->subWeek()->toDateString()]['new_deals']);
    }

    public function test_won_deals_are_counted_by_closed_at(): void
    {
        $owner = User::factory()->create();
        $timezone = 'Asia/Dushanbe';
        $monday = CarbonImmutable::now($timezone)->startOfWeek(CarbonInterface::MONDAY);
        $this->travelTo($monday->addWeeks(3));

        Deal::factory()->for($owner)->create([
            'stage' => 'payment',
            'payment_status' => 'paid',
            'closed_at' => $monday->addDays(2),
            'created_at' => $monday->subWeek(),
        ]);

        $series = app(DealTrend::class)->series($owner, '8w', null);
        $buckets = collect($series['buckets'])->keyBy('key');

        $this->assertSame(1, $buckets[$monday->toDateString()]['won']);
    }

    /** Both conditions, not payment_status alone — the same definition DealBoard::list() uses. */
    public function test_revenue_requires_both_paid_status_and_the_payment_stage(): void
    {
        $owner = User::factory()->create();
        $timezone = 'Asia/Dushanbe';
        $monday = CarbonImmutable::now($timezone)->startOfWeek(CarbonInterface::MONDAY);
        $this->travelTo($monday->addWeeks(3));

        // Paid status without ever reaching the stage — must not count.
        Deal::factory()->for($owner)->create([
            'stage' => 'haggling',
            'payment_status' => 'paid',
            'amount' => 500,
            'deal_date' => $monday->toDateString(),
        ]);

        // In the stage, but not actually paid — must not count either.
        Deal::factory()->for($owner)->create([
            'stage' => 'payment',
            'payment_status' => 'unpaid',
            'amount' => 500,
            'deal_date' => $monday->toDateString(),
        ]);

        // Both — the only one that should count.
        Deal::factory()->for($owner)->create([
            'stage' => 'payment',
            'payment_status' => 'paid',
            'amount' => 300,
            'deal_date' => $monday->toDateString(),
        ]);

        $series = app(DealTrend::class)->series($owner, '8w', null);
        $buckets = collect($series['buckets'])->keyBy('key');

        $this->assertEqualsWithDelta(300.0, $buckets[$monday->toDateString()]['revenue'], 0.001);
    }

    public function test_the_trend_respects_the_workspace_filter(): void
    {
        $owner = User::factory()->create();
        $workspaceA = CrmWorkspace::forceCreate(['user_id' => $owner->id, 'name' => 'Store A', 'color' => 'blue']);
        $workspaceB = CrmWorkspace::forceCreate(['user_id' => $owner->id, 'name' => 'Store B', 'color' => 'green']);

        Deal::factory()->for($owner)->create(['crm_workspace_id' => $workspaceA->id]);
        Deal::factory()->for($owner)->create(['crm_workspace_id' => $workspaceB->id]);

        $series = app(DealTrend::class)->series($owner, '8w', $workspaceA->id);

        $this->assertSame(1, $series['totals']['new_deals']);
    }

    public function test_an_unknown_range_falls_back_to_8_weeks(): void
    {
        $owner = User::factory()->create();

        $series = app(DealTrend::class)->series($owner, 'bogus', null);

        $this->assertSame('8w', $series['window']);
        $this->assertCount(8, $series['buckets']);
    }

    public function test_the_6_month_window_has_six_monthly_buckets(): void
    {
        $owner = User::factory()->create();

        $series = app(DealTrend::class)->series($owner, '6m', null);

        $this->assertCount(6, $series['buckets']);
    }
}
