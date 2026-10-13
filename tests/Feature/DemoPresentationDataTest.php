<?php

namespace Tests\Feature;

use App\Jobs\ExtractTextJob;
use App\Models\Agent;
use App\Models\Deal;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DemoPresentationDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_presentation_data_is_coherent_and_a_rebuild_preserves_owner_edits(): void
    {
        Bus::fake([ExtractTextJob::class]);
        Storage::fake('local');
        $owner = User::factory()->create();
        $anotherOwner = User::factory()->create();
        $anotherOwnersShop = Agent::factory()->for($anotherOwner)->create(['slug' => 'olami-kompiuterxo']);

        $seeder = new DemoSeeder;
        $seeder->ownerEmail = $owner->email;
        $seeder->run();

        $deals = Deal::where('user_id', $owner->id)
            ->where('note', 'Демонстрационные данные')->get();

        $this->assertGreaterThanOrEqual(12, $deals->count());
        $this->assertSame(3, $deals->where('stage', 'payment')->count());
        $this->assertSame(2, $deals->where('stage', 'cancelled')->count());
        $this->assertTrue($deals->every(fn (Deal $deal) => $deal->customer_id !== null));

        $edited = $deals->first();
        $edited->title = 'Изменено владельцем';
        $edited->save();

        // The legacy purge protects cards with tasks as possible owner work.
        // Pick a generated card without one, or this test asks the seeder to
        // delete a card it deliberately preserves.
        $legacy = $deals->first(fn (Deal $deal) => $deal->id !== $edited->id
            && $deal->conversation_id !== null
            && $deal->tasks()->doesntExist());
        $this->assertNotNull($legacy);
        $legacy->forceFill(['note' => null])->saveQuietly();
        foreach ($legacy->activities as $activity) {
            $activity->forceFill(['data' => null])->save();
        }

        $seeder = new DemoSeeder;
        $seeder->ownerEmail = $owner->email;
        $seeder->run();

        $this->assertDatabaseHas('deals', [
            'id' => $edited->id,
            'title' => 'Изменено владельцем',
        ]);
        // Soft-deleted now, not gone — see the Deal migration. A later reseed
        // still treats it as absent (the global scope hides trashed rows from
        // the queries DemoSeeder matches against), so the purge's own effect
        // — this card disappears and a fresh one can take its place — is
        // unchanged; only how "gone" is asserted here changes.
        $this->assertSoftDeleted('deals', ['id' => $legacy->id]);
        // The deliberately owner-edited demo deal is retained even if it is
        // already paid; count the three reproducible presentation sales apart
        // from that retained owner work.
        $this->assertSame(3, Deal::where('user_id', $owner->id)->where('stage', 'payment')->whereKeyNot($edited->id)->count());
        $this->assertSame(0, $anotherOwnersShop->conversations()->count());
    }
}
