<?php

namespace Tests\Feature\Teams;

use App\Models\Agent;
use App\Models\BiDataset;
use App\Models\BiSource;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Dataset;
use App\Models\DatasetProduct;
use App\Models\Deal;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MigrateUsersToTeamsCommandTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Agent $agent;

    private Channel $channel;

    private Conversation $conversation;

    private DatasetProduct $product;

    private Customer $customer;

    private Deal $deal;

    private BiDataset $biDataset;

    protected function setUp(): void
    {
        parent::setUp();

        // A "legacy" account: every row below is created the way it is
        // today, with no team_id at all — exactly the shape the backfill
        // command has to repair.
        $this->user = User::factory()->create(['name' => 'Legacy Owner']);
        $this->agent = Agent::factory()->for($this->user)->create();
        $this->channel = Channel::factory()->for($this->agent)->create();
        $this->conversation = Conversation::factory()->for($this->agent)->create(['channel_id' => $this->channel->id]);

        $dataset = Dataset::factory()->for($this->agent)->create();
        $this->product = DatasetProduct::factory()->for($dataset)->create();

        $this->customer = Customer::factory()->for($this->user)->create();
        $this->deal = Deal::factory()->for($this->user)->create(['customer_id' => $this->customer->id]);

        $biSource = BiSource::factory()->for($this->user)->create();
        $this->biDataset = BiDataset::factory()->create(['bi_source_id' => $biSource->id]);

        DB::table('usage_logs')->insert([
            'user_id' => $this->user->id,
            'agent_id' => $this->agent->id,
            'type' => 'generation',
            'input_tokens' => 10,
            'output_tokens' => 20,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_dry_run_reports_what_it_would_do_and_writes_nothing(): void
    {
        $this->artisan('team:migrate-users', ['--dry-run' => true])
            ->expectsOutputToContain('1 user(s) have no team yet')
            ->assertExitCode(0);

        $this->user->refresh();
        $this->assertNull($this->user->current_team_id);
        $this->assertSame(0, Team::count());
        $this->assertNull($this->agent->refresh()->team_id);
        $this->assertNull($this->deal->refresh()->team_id);
        $this->assertSame(0, DB::table('usage_logs')->whereNotNull('team_id')->count());
    }

    public function test_it_creates_a_personal_team_and_backfills_every_owned_row(): void
    {
        $this->artisan('team:migrate-users')->assertExitCode(0);

        $this->user->refresh();
        $this->assertNotNull($this->user->current_team_id);

        $team = Team::find($this->user->current_team_id);
        $this->assertNotNull($team);
        $this->assertSame($this->user->id, $team->created_by);

        // agents.id -> team_id, the root of the agent-scoped chain.
        $this->assertSame($team->id, $this->agent->refresh()->team_id);

        // Agent-scoped tables, backfilled by joining through agents.user_id.
        $this->assertSame($team->id, $this->channel->refresh()->team_id);
        $this->assertSame($team->id, $this->conversation->refresh()->team_id);
        $this->assertSame($team->id, $this->product->refresh()->team_id);

        // Direct user_id tables.
        $this->assertSame($team->id, $this->customer->refresh()->team_id);
        $this->assertSame($team->id, $this->deal->refresh()->team_id);
        $this->assertSame($team->id, $this->biDataset->refresh()->team_id);

        $usageLog = DB::table('usage_logs')->where('user_id', $this->user->id)->first();
        $this->assertSame($team->id, $usageLog->team_id);
    }

    public function test_it_is_idempotent(): void
    {
        $this->artisan('team:migrate-users')->assertExitCode(0);
        $teamId = $this->user->refresh()->current_team_id;
        $teamCountAfterFirstRun = Team::count();

        $this->artisan('team:migrate-users')
            ->expectsOutputToContain('Every user already has a team')
            ->assertExitCode(0);

        $this->assertSame($teamId, $this->user->refresh()->current_team_id);
        $this->assertSame($teamCountAfterFirstRun, Team::count());
    }

    public function test_a_second_user_created_after_the_first_run_is_picked_up_on_the_next_run(): void
    {
        $this->artisan('team:migrate-users')->assertExitCode(0);

        $second = User::factory()->create();
        $secondAgent = Agent::factory()->for($second)->create();

        $this->artisan('team:migrate-users')->assertExitCode(0);

        $this->assertNotNull($second->refresh()->current_team_id);
        $this->assertSame($second->current_team_id, $secondAgent->refresh()->team_id);

        // The first user's team and backfilled rows are untouched by the second run.
        $this->assertNotNull($this->agent->refresh()->team_id);
        $this->assertNotEquals($second->current_team_id, $this->agent->team_id);
    }

    /**
     * Until a later stage wires every write path to stamp team_id itself, a
     * row created after a user's first migration (a new conversation, a new
     * deal, ...) is born with team_id = NULL. The backfill command has to
     * be able to come back and fill those in, or team:verify-migration can
     * never pass again.
     */
    public function test_rows_created_after_the_first_migration_are_filled_in_on_a_later_run(): void
    {
        $this->artisan('team:migrate-users')->assertExitCode(0);
        $team = Team::find($this->user->refresh()->current_team_id);

        $newConversation = Conversation::factory()->for($this->agent)->create(['channel_id' => $this->channel->id]);
        $newCustomer = Customer::factory()->for($this->user)->create();
        $newDeal = Deal::factory()->for($this->user)->create(['customer_id' => $newCustomer->id]);

        $this->assertNull($newConversation->team_id);
        $this->assertNull($newDeal->team_id);

        $this->artisan('team:migrate-users')
            ->expectsOutputToContain('Topped up 1 user(s)')
            ->assertExitCode(0);

        $this->assertSame($team->id, $newConversation->refresh()->team_id);
        $this->assertSame($team->id, $newCustomer->refresh()->team_id);
        $this->assertSame($team->id, $newDeal->refresh()->team_id);

        // And the already-filled rows from the first run are untouched.
        $this->assertSame($team->id, $this->deal->refresh()->team_id);

        $this->artisan('team:verify-migration')
            ->expectsOutputToContain('Verification passed')
            ->assertExitCode(0);
    }

    /** The top-up pass must never clobber a row that already carries a (different) team's id. */
    public function test_top_up_never_overwrites_a_row_that_already_belongs_to_a_different_team(): void
    {
        $this->artisan('team:migrate-users')->assertExitCode(0);

        $otherTeam = Team::factory()->create();
        $this->deal->forceFill(['team_id' => $otherTeam->id])->save();

        $newDeal = Deal::factory()->for($this->user)->create(['customer_id' => $this->customer->id]);
        $this->assertNull($newDeal->team_id);

        $this->artisan('team:migrate-users')->assertExitCode(0);

        // Left exactly as it was — never reset back to the user's own team.
        $this->assertSame($otherTeam->id, $this->deal->refresh()->team_id);

        // The genuinely-missing row was still filled, with the user's own team.
        $this->assertSame($this->user->refresh()->current_team_id, $newDeal->refresh()->team_id);
    }

    public function test_dry_run_reports_top_up_counts_and_writes_nothing(): void
    {
        $this->artisan('team:migrate-users')->assertExitCode(0);
        $team = Team::find($this->user->refresh()->current_team_id);

        // Creating one Deal also creates one DealActivity (DealObserver's audit
        // row), so the genuinely missing count for this one new row is 2, not 1.
        $newDeal = Deal::factory()->for($this->user)->create(['customer_id' => $this->customer->id]);

        // A plain Artisan::call rather than $this->artisan()->expectsOutputToContain()
        // here on purpose: chaining a second expectsOutputToContain() assertion
        // onto a command already run once earlier in the same test does not
        // reliably capture output in this Laravel testing helper — asserting on
        // Artisan::output() directly does.
        $exitCode = \Illuminate\Support\Facades\Artisan::call('team:migrate-users', ['--dry-run' => true]);
        $output = \Illuminate\Support\Facades\Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('0 user(s) have no team yet; 1 user(s) already have a team', $output);
        $this->assertStringContainsString('would top up user #'.$this->user->id, $output);
        $this->assertStringContainsString('fill 2 row(s)', $output);

        $this->assertNull($newDeal->refresh()->team_id);
        $this->assertSame($team->id, $this->deal->refresh()->team_id);
    }
}
