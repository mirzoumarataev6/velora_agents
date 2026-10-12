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
}
