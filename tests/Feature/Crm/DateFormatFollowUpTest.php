<?php

namespace Tests\Feature\Crm;

use App\Mail\TeamInvitationMail;
use App\Models\Deal;
use App\Models\Invitation;
use App\Models\Role;
use App\Models\User;
use App\Services\Crm\DealBoard;
use App\Services\Crm\DealTrend;
use App\Services\Teams\CreatesTeam;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Follow-up to PHASE 3 item 20 (teams/partials/management.blade.php's
 * pending-invitation expiry date): the same translatedFormat('d MMM...')
 * bug — Carbon reuses PHP's own date() tokens, where 'M' is a single-letter
 * "short month" token; two, three or four in a row are that many
 * independent tokens, not one CLDR-style "MMM"/"MMMM" token, and render as
 * the month name repeated and mashed together — existed in four more
 * places, flagged as out of scope in PROGRESS.md at the time and fixed
 * here: the invitation email, DealTrend's two chart bucket labels, and
 * DealBoard's two deal-date labels.
 */
class DateFormatFollowUpTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_invitation_emails_expiry_date_renders_the_month_name_once(): void
    {
        $owner = User::factory()->create();
        app(CreatesTeam::class)->create($owner, 'Sales');
        $role = Role::where('team_id', $owner->current_team_id)->where('slug', 'viewer')->sole();
        $invitation = Invitation::factory()->create([
            'team_id' => $owner->current_team_id,
            'role_id' => $role->id,
            'invited_by' => $owner->id,
            'email' => 'pending@example.test',
            'expires_at' => now()->addDays(7),
        ]);

        $html = (new TeamInvitationMail($invitation))->render();

        $expected = now()->addDays(7)->translatedFormat('d F Y');
        $this->assertStringContainsString($expected, $html);
        $monthName = now()->addDays(7)->translatedFormat('F');
        $this->assertStringNotContainsString($monthName.$monthName, $html);
    }

    public function test_a_deal_cards_date_labels_render_the_month_name_once(): void
    {
        $owner = User::factory()->create();
        $deal = Deal::factory()->for($owner)->closed()->create([
            'deal_date' => now()->subDays(3),
            'closed_at' => now()->subDay(),
        ]);

        $card = app(DealBoard::class)->card($deal);

        $expectedDealDate = $deal->deal_date->translatedFormat('d M Y');
        $expectedClosed = $deal->closed_at->translatedFormat('d M Y');
        $this->assertSame($expectedDealDate, $card['deal_date_label']);
        $this->assertSame($expectedClosed, $card['closed_label']);

        $monthName = $deal->deal_date->translatedFormat('M');
        $this->assertStringNotContainsString($monthName.$monthName, $card['deal_date_label']);
    }

    public function test_the_trend_charts_bucket_labels_render_the_month_name_once(): void
    {
        $owner = User::factory()->create();

        $weekly = app(DealTrend::class)->series($owner, '8w', null);
        foreach ($weekly['buckets'] as $bucket) {
            $this->assertDoesNotMatchRegularExpression('/(\p{L}{2,})\1/u', $bucket['label']);
        }

        $sixMonth = app(DealTrend::class)->series($owner, '6m', null);
        foreach ($sixMonth['buckets'] as $bucket) {
            // A repeated-month-name bucket looked like "октябрьоктябрь10
            // 2026" — the month name immediately followed by itself.
            $this->assertDoesNotMatchRegularExpression('/(\p{L}{2,})\1/u', $bucket['label']);
        }
    }
}
