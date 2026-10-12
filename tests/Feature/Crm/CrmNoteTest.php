<?php

namespace Tests\Feature\Crm;

use App\Models\CrmNote;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The notes/activity thread that replaced the single freeform `note` field
 * on deals and customers.
 */
class CrmNoteTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_add_a_note_to_a_deal_via_json(): void
    {
        $owner = User::factory()->create();
        $customer = Customer::factory()->for($owner)->create();
        $deal = Deal::factory()->for($owner)->for($customer)->create();

        $response = $this->actingAs($owner)->postJson(route('crm.deals.notes.store', $deal), [
            'kind' => 'call',
            'body' => 'Позвонил, договорились на завтра.',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('crm_notes', [
            'notable_type' => Deal::class,
            'notable_id' => $deal->id,
            'user_id' => $owner->id,
            'kind' => 'call',
            'body' => 'Позвонил, договорились на завтра.',
        ]);
    }

    public function test_owner_can_add_a_note_to_a_customer_via_plain_post(): void
    {
        $owner = User::factory()->create();
        $customer = Customer::factory()->for($owner)->create();

        $response = $this->actingAs($owner)->post(route('crm.customers.notes.store', $customer), [
            'kind' => 'visit',
            'body' => 'Заехал в офис, обсудили условия.',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('crm_notes', [
            'notable_type' => Customer::class,
            'notable_id' => $customer->id,
            'kind' => 'visit',
            'body' => 'Заехал в офис, обсудили условия.',
        ]);
    }

    public function test_adding_a_note_kind_syncs_the_legacy_note_column(): void
    {
        $owner = User::factory()->create();
        $customer = Customer::factory()->for($owner)->create(['note' => null]);

        $this->actingAs($owner)->post(route('crm.customers.notes.store', $customer), [
            'kind' => 'note',
            'body' => 'Важная заметка.',
        ])->assertRedirect();

        $this->assertSame('Важная заметка.', $customer->fresh()->note);
    }

    public function test_deleting_the_latest_note_resyncs_the_legacy_column_to_the_next_one(): void
    {
        $owner = User::factory()->create();
        $customer = Customer::factory()->for($owner)->create();

        $this->travelTo(now()->subMinute());
        $this->actingAs($owner)->post(route('crm.customers.notes.store', $customer), [
            'kind' => 'note',
            'body' => 'Первая заметка.',
        ]);
        $this->travelBack();

        $this->actingAs($owner)->post(route('crm.customers.notes.store', $customer), [
            'kind' => 'note',
            'body' => 'Вторая заметка.',
        ]);

        $this->assertSame('Вторая заметка.', $customer->fresh()->note);

        $latest = CrmNote::where('body', 'Вторая заметка.')->first();
        $this->actingAs($owner)->delete(route('crm.notes.destroy', $latest))->assertRedirect();

        $this->assertSame('Первая заметка.', $customer->fresh()->note);
        $this->assertSoftDeleted($latest);
    }

    public function test_author_can_delete_their_own_note(): void
    {
        $owner = User::factory()->create();
        $deal = Deal::factory()->for($owner)->create();
        $note = CrmNote::forceCreate([
            'notable_type' => Deal::class,
            'notable_id' => $deal->id,
            'user_id' => $owner->id,
            'kind' => 'note',
            'body' => 'Note body',
            'happened_at' => now(),
        ]);

        $this->actingAs($owner)->delete(route('crm.notes.destroy', $note))->assertRedirect();

        $this->assertSoftDeleted($note);
    }

    public function test_another_owner_cannot_delete_someone_elses_note(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $deal = Deal::factory()->for($owner)->create();
        $note = CrmNote::forceCreate([
            'notable_type' => Deal::class,
            'notable_id' => $deal->id,
            'user_id' => $owner->id,
            'kind' => 'note',
            'body' => 'Note body',
            'happened_at' => now(),
        ]);

        $this->actingAs($other)->delete(route('crm.notes.destroy', $note))->assertForbidden();
        $this->assertDatabaseHas('crm_notes', ['id' => $note->id, 'deleted_at' => null]);
    }

    public function test_another_owner_cannot_add_a_note_to_someone_elses_deal(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $deal = Deal::factory()->for($owner)->create();

        $this->actingAs($other)->postJson(route('crm.deals.notes.store', $deal), [
            'kind' => 'note',
            'body' => 'Not allowed',
        ])->assertForbidden();
    }

    public function test_body_is_required(): void
    {
        $owner = User::factory()->create();
        $deal = Deal::factory()->for($owner)->create();

        $this->actingAs($owner)->postJson(route('crm.deals.notes.store', $deal), [
            'kind' => 'note',
            'body' => '',
        ])->assertJsonValidationErrors('body');
    }

    public function test_body_cannot_exceed_the_max_length(): void
    {
        $owner = User::factory()->create();
        $deal = Deal::factory()->for($owner)->create();

        $this->actingAs($owner)->postJson(route('crm.deals.notes.store', $deal), [
            'kind' => 'note',
            'body' => str_repeat('a', 5001),
        ])->assertJsonValidationErrors('body');
    }

    public function test_kind_must_be_in_the_allowed_set(): void
    {
        $owner = User::factory()->create();
        $deal = Deal::factory()->for($owner)->create();

        $this->actingAs($owner)->postJson(route('crm.deals.notes.store', $deal), [
            'kind' => 'bogus',
            'body' => 'Something',
        ])->assertJsonValidationErrors('kind');
    }

    public function test_happened_at_cannot_be_in_the_far_future(): void
    {
        $owner = User::factory()->create();
        $deal = Deal::factory()->for($owner)->create();

        $this->actingAs($owner)->postJson(route('crm.deals.notes.store', $deal), [
            'kind' => 'note',
            'body' => 'Something',
            'happened_at' => now()->addYear()->toDateString(),
        ])->assertJsonValidationErrors('happened_at');
    }

    public function test_the_deal_edit_form_can_still_be_saved_without_sending_a_note_field(): void
    {
        $owner = User::factory()->create();
        $customer = Customer::factory()->for($owner)->create();
        $deal = Deal::factory()->for($owner)->for($customer)->create(['note' => 'Legacy note']);

        $this->actingAs($owner)->patchJson(route('crm.deals.update', $deal), [
            'title' => $deal->title,
            'amount' => 100,
            'payment_status' => 'unpaid',
        ])->assertOk();

        // The 'sometimes' fix: omitting `note` entirely must not null it out.
        $this->assertSame('Legacy note', $deal->fresh()->note);
    }

    public function test_the_migration_backfills_crm_notes_from_legacy_note_columns(): void
    {
        $owner = User::factory()->create();
        $customer = Customer::factory()->for($owner)->create(['note' => null]);
        $customerWithNote = Customer::factory()->for($owner)->create(['note' => 'Старая заметка клиента']);
        $deal = Deal::factory()->for($owner)->for($customer)->create(['note' => 'Старая заметка по сделке']);

        // RefreshDatabase already ran every migration, including the seed —
        // re-run just that one file's up() against the rows created above,
        // the same way a real deploy would run it once against existing
        // data. Targeted by path rather than `migrate:rollback --step=1`:
        // that rolls back whichever migration happens to be LAST overall,
        // which stopped being this one the moment a later migration (deals
        // tags) was added.
        //
        // Stage 2d-2 made crm_notes.team_id NOT NULL, but this migration
        // predates team_id entirely — its raw insert() never set one. That
        // is exactly what it is being tested against (a real historical
        // migration's actual behavior), so the column is relaxed back to
        // nullable for the duration of this one re-run rather than editing
        // the already-shipped migration itself.
        Schema::table('crm_notes', fn ($table) => $table->unsignedBigInteger('team_id')->nullable()->change());

        $migration = require database_path('migrations/2026_10_12_000002_seed_crm_notes_from_legacy_note_columns.php');
        $migration->down();
        $migration->up();

        $this->assertDatabaseHas('crm_notes', [
            'notable_type' => Customer::class,
            'notable_id' => $customerWithNote->id,
            'kind' => 'note',
            'body' => 'Старая заметка клиента',
        ]);
        $this->assertDatabaseHas('crm_notes', [
            'notable_type' => Deal::class,
            'notable_id' => $deal->id,
            'kind' => 'note',
            'body' => 'Старая заметка по сделке',
        ]);
        $this->assertDatabaseMissing('crm_notes', [
            'notable_type' => Customer::class,
            'notable_id' => $customer->id,
        ]);
    }

    public function test_the_migration_down_removes_exactly_the_rows_it_seeded(): void
    {
        $owner = User::factory()->create();
        Customer::factory()->for($owner)->create(['note' => 'Will be backfilled']);

        // See the sibling test above for why: this migration predates
        // team_id, so its raw insert() needs the column relaxed back to
        // nullable for this one re-run.
        Schema::table('crm_notes', fn ($table) => $table->unsignedBigInteger('team_id')->nullable()->change());

        $migration = require database_path('migrations/2026_10_12_000002_seed_crm_notes_from_legacy_note_columns.php');
        $migration->up();
        $this->assertGreaterThan(0, DB::table('crm_notes')->count());

        $migration->down();

        $this->assertSame(0, DB::table('crm_notes')->count());
    }
}
