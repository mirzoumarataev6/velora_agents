<?php

namespace Tests\Feature\Quality;

use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Quality\NonAnswerDetector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The backfill that cleans up rows the old unanswered rule got wrong.
 *
 * Every fixture here is a row as it would ACTUALLY have been written before the
 * fix: flagged `knowledge_miss` because retrieval scored nothing, with the
 * agent's real reply sitting in `content` — which is the evidence the old rule
 * never looked at.
 */
class ReclassifyUnansweredCommandTest extends TestCase
{
    use RefreshDatabase;

    protected Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        $agent = Agent::factory()->create();
        $this->conversation = $agent->conversations()->create(['status' => 'ai']);
    }

    /**
     * A row exactly as the pre-fix classifier wrote it.
     *
     * @param  array<string, mixed>  $extraMeta
     */
    protected function flagged(string $reply, string $question = 'А где вы находитесь', string $reason = 'knowledge_miss', array $extraMeta = []): Message
    {
        return $this->conversation->messages()->create([
            'role' => 'assistant',
            'content' => $reply,
            'meta' => [
                'unanswered' => true,
                'unanswered_reason' => $reason,
                'unanswered_question' => $question,
                'unanswered_detected_by' => 'retrieval',
                ...$extraMeta,
            ],
        ]);
    }

    // -----------------------------------------------------------------
    // Dry run
    // -----------------------------------------------------------------

    public function test_a_dry_run_reports_the_change_without_making_it(): void
    {
        $answered = $this->flagged('Мы находимся в Худжанде, ул. Ленина 45.');

        $this->artisan('reclassify:unanswered', ['--dry-run' => true])
            ->expectsOutputToContain('DRY RUN')
            ->assertSuccessful();

        $this->assertTrue($answered->fresh()->meta['unanswered'], 'A dry run must not write.');
    }

    // -----------------------------------------------------------------
    // Applying
    // -----------------------------------------------------------------

    /** The reported transcript, as it sits in the database today. */
    public function test_the_settings_sourced_answers_are_reclassified(): void
    {
        $address = $this->flagged('Мы находимся в Худжанде, ул. Ленина 45.');
        $hours = $this->flagged('Мо ҳар рӯз аз соати 8:30 то 19:30 кушодаем.', 'А соати чан Шумо кушода');

        $this->artisan('reclassify:unanswered')->assertSuccessful();

        foreach ([$address, $hours] as $message) {
            $meta = $message->fresh()->meta;

            $this->assertFalse($meta['unanswered']);
            $this->assertNull($meta['unanswered_reason']);
            $this->assertNull($meta['unanswered_question']);
        }
    }

    /** A real gap must survive the cleanup — that is the whole risk here. */
    public function test_a_genuine_gap_stays_flagged(): void
    {
        $gap = $this->flagged('К сожалению, такой информации у меня нет.', 'Доставка в Бохтар есть?');

        $this->artisan('reclassify:unanswered')->assertSuccessful();

        $meta = $gap->fresh()->meta;

        $this->assertTrue($meta['unanswered']);
        $this->assertSame('knowledge_miss', $meta['unanswered_reason']);
        $this->assertSame('Доставка в Бохтар есть?', $meta['unanswered_question']);
    }

    /**
     * The two other reasons are facts about what the pipeline DID — searches it
     * ran, an outage it hit — not readings of the reply. The fix did not touch
     * them, so neither may this.
     */
    public function test_catalog_and_provider_rows_are_never_touched(): void
    {
        $catalog = $this->flagged('Такого товара у нас нет в наличии.', 'Есть красный габардин?', 'catalog_miss');
        $provider = $this->flagged('Извините, técnическая ошибка.', 'Сколько стоит?', 'provider_down');

        $this->artisan('reclassify:unanswered')->assertSuccessful();

        $this->assertTrue($catalog->fresh()->meta['unanswered']);
        $this->assertSame('catalog_miss', $catalog->fresh()->meta['unanswered_reason']);
        $this->assertTrue($provider->fresh()->meta['unanswered']);
    }

    /** Guards the corrected classifier applies, applied to history as well. */
    public function test_rows_that_closed_something_or_escalated_are_reclassified(): void
    {
        $ordered = $this->flagged('Не знаю точно, но заказ оформил.', extraMeta: ['lead_captured' => true]);
        $handed = $this->flagged('Не знаю, передаю менеджеру.', extraMeta: ['escalated' => true]);
        $priced = $this->flagged('Не знаю, уточню цену.', extraMeta: ['price_approval_requested' => true]);

        $this->artisan('reclassify:unanswered')->assertSuccessful();

        foreach ([$ordered, $handed, $priced] as $message) {
            $this->assertFalse($message->fresh()->meta['unanswered']);
        }
    }

    // -----------------------------------------------------------------
    // Bookkeeping
    // -----------------------------------------------------------------

    /** A mass edit of historical data has to leave a trail. */
    public function test_a_corrected_row_records_what_it_used_to_say(): void
    {
        $message = $this->flagged('Мы находимся в Худжанде, ул. Ленина 45.');

        $this->artisan('reclassify:unanswered')->assertSuccessful();

        $meta = $message->fresh()->meta;

        $this->assertSame('knowledge_miss', $meta['unanswered_was']);
        $this->assertNotNull($meta['unanswered_reclassified_at']);
    }

    public function test_running_it_twice_changes_nothing_the_second_time(): void
    {
        $this->flagged('Мы находимся в Худжанде, ул. Ленина 45.');
        $this->flagged('К сожалению, такой информации у меня нет.');

        $this->artisan('reclassify:unanswered')->assertSuccessful();
        $first = Message::where('meta->unanswered', true)->count();

        $this->artisan('reclassify:unanswered')->assertSuccessful();

        $this->assertSame($first, Message::where('meta->unanswered', true)->count());
        $this->assertSame(1, $first, 'Only the genuine gap should remain.');
    }

    public function test_the_before_option_limits_the_scope(): void
    {
        $old = $this->flagged('Мы находимся в Худжанде, ул. Ленина 45.');
        $old->forceFill(['created_at' => now()->subMonth()])->save();

        $recent = $this->flagged('Мы находимся в Худжанде, ул. Ленина 45.');

        $this->artisan('reclassify:unanswered', ['--before' => now()->subWeek()->toDateString()])
            ->assertSuccessful();

        $this->assertFalse($old->fresh()->meta['unanswered']);
        $this->assertTrue($recent->fresh()->meta['unanswered'], 'A row outside the window must be left alone.');
    }

    /**
     * The command and the live pipeline must never disagree about what counts
     * as a non-answer — they read the same object, and this is the test that
     * says so out loud.
     */
    public function test_the_command_uses_the_same_detector_as_the_pipeline(): void
    {
        $detector = new NonAnswerDetector;

        $this->assertTrue($detector->matches('К сожалению, такой информации у меня нет.'));
        $this->assertFalse($detector->matches('Мы находимся в Худжанде, ул. Ленина 45.'));
        $this->assertFalse($detector->matches('Мо ҳар рӯз аз соати 8:30 то 19:30 кушодаем.'));
    }
}
