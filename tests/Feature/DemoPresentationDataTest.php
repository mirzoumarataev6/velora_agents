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

        $legacy = $deals->skip(1)->first();
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
        $this->assertDatabaseMissing('deals', ['id' => $legacy->id]);
        $this->assertSame(3, Deal::where('user_id', $owner->id)->where('stage', 'payment')->count());
        $this->assertSame(0, $anotherOwnersShop->conversations()->count());
    }
}
