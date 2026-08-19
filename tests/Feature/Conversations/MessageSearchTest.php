<?php

namespace Tests\Feature\Conversations;

use App\Models\Agent;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Telegram-style search: the term is looked for inside message bodies, the list
 * is ordered by the most recent MATCH, and each row carries enough to jump
 * straight to the message that matched.
 */
class MessageSearchTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected Agent $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
        $this->agent = Agent::factory()->for($this->owner)->create(['name' => 'Shop bot']);
    }

    /**
     * @param  array<int, array{0: string, 1: string}>  $messages  [role, content]
     */
    protected function conversation(array $messages, array $attributes = [], ?Agent $agent = null): Conversation
    {
        $agent ??= $this->agent;
        $channel = $agent->channels()->where('type', 'telegram')->first()
            ?? Channel::factory()->for($agent)->create();

        $conversation = Conversation::factory()->for($agent)->for($channel)->create($attributes + [
            'last_message_at' => now(),
        ]);

        foreach ($messages as $i => [$role, $content]) {
            Message::factory()->for($conversation)->create([
                'role' => $role,
                'content' => $content,
                'created_at' => now()->addSeconds($i),
            ]);
        }

        return $conversation;
    }

    protected function search(string $term, array $extra = [])
    {
        return $this->actingAs($this->owner)
            ->getJson(route('conversations.list', $extra + ['q' => $term]));
    }

    public function test_search_finds_a_conversation_by_message_content_alone(): void
    {
        $match = $this->conversation([['user', 'Сколько стоит доставка ковра?']], ['external_user_name' => 'Madina']);
        $miss = $this->conversation([['user', 'Здравствуйте']], ['external_user_name' => 'Anvar']);

        $ids = collect($this->search('ковра')->assertOk()->json('data'))->pluck('id');

        $this->assertTrue($ids->contains($match->id));
        $this->assertFalse($ids->contains($miss->id));
    }

    public function test_search_still_matches_the_customer_name_and_handle(): void
    {
        $byName = $this->conversation([['user', 'Привет']], ['external_user_name' => 'Мадина Рахимова']);
        $byHandle = $this->conversation([['user', 'Привет']], ['external_user_id' => 'madina_r']);

        $this->assertTrue(
            collect($this->search('Мадина')->json('data'))->pluck('id')->contains($byName->id)
        );
        $this->assertTrue(
            collect($this->search('madina_r')->json('data'))->pluck('id')->contains($byHandle->id)
        );
    }

    public function test_the_agents_own_replies_are_searchable_too(): void
    {
        $conversation = $this->conversation([
            ['user', 'Здравствуйте'],
            ['assistant', 'Длительность урока составляет 2.5 часа.'],
        ]);

        $this->assertTrue(
            collect($this->search('Длительность')->json('data'))->pluck('id')->contains($conversation->id)
        );
    }

    /**
     * The heart of it: a conversation whose hit is recent outranks one that has
     * merely been active recently.
     */
    public function test_results_are_ordered_by_the_most_recent_matching_message(): void
    {
        $oldHit = $this->conversation(
            [['user', 'Нужен ковёр']],
            ['external_user_name' => 'Old hit', 'last_message_at' => now()->subDays(1)]
        );
        $oldHit->messages()->update(['created_at' => now()->subDays(10)]);

        $recentHit = $this->conversation(
            [['user', 'Тоже нужен ковёр']],
            ['external_user_name' => 'Recent hit', 'last_message_at' => now()->subDays(5)]
        );
        $recentHit->messages()->update(['created_at' => now()->subHour()]);

        $ids = collect($this->search('ковёр')->json('data'))->pluck('id')->all();

        // Recent match first, even though the other conversation was more
        // recently active overall.
        $this->assertSame([$recentHit->id, $oldHit->id], $ids);
    }

    public function test_each_row_carries_its_match_count_and_excerpt(): void
    {
        $conversation = $this->conversation([
            ['user', 'Есть ковёр?'],
            ['assistant', 'Да, ковёр есть в наличии'],
            ['user', 'Спасибо'],
        ]);

        $row = collect($this->search('ковёр')->json('data'))->firstWhere('id', $conversation->id);

        $this->assertSame(2, $row['match_count']);
        // The excerpt is the most recent matching message, not the last message.
        $this->assertStringContainsString('в наличии', $row['match_excerpt']);
    }

    public function test_rows_carry_no_match_data_when_not_searching(): void
    {
        $this->conversation([['user', 'Привет']]);

        $row = collect($this->actingAs($this->owner)->getJson(route('conversations.list'))->json('data'))->first();

        $this->assertSame(0, $row['match_count']);
        $this->assertNull($row['match_excerpt']);
    }

    /**
     * A conversation matched only by its customer's name has no matching
     * message, so it falls back to ordering by activity rather than vanishing.
     */
    public function test_a_name_only_match_still_appears(): void
    {
        $conversation = $this->conversation([['user', 'Привет']], ['external_user_name' => 'Ковёр Иванов']);

        $row = collect($this->search('Ковёр')->json('data'))->firstWhere('id', $conversation->id);

        $this->assertNotNull($row);
        $this->assertSame(0, $row['match_count']);
        $this->assertNull($row['match_excerpt']);
    }

    /**
     * Search must never become a way to read another owner's messages.
     */
    public function test_search_never_crosses_into_another_owners_conversations(): void
    {
        $stranger = Agent::factory()->create();
        $theirs = $this->conversation([['user', 'секретный ковёр']], ['external_user_name' => 'Not mine'], $stranger);

        $ids = collect($this->search('ковёр')->json('data'))->pluck('id');

        $this->assertFalse($ids->contains($theirs->id));
    }

    /**
     * The per-agent tab passes the same agent filter its lock sets.
     */
    public function test_search_respects_the_agent_scope(): void
    {
        $other = Agent::factory()->for($this->owner)->create(['name' => 'Clinic bot']);
        $mine = $this->conversation([['user', 'нужен ковёр']], ['external_user_name' => 'Mine']);
        $theirs = $this->conversation([['user', 'тоже ковёр']], ['external_user_name' => 'Other agent'], $other);

        $ids = collect($this->search('ковёр', ['agent' => $this->agent->id])->json('data'))->pluck('id');

        $this->assertTrue($ids->contains($mine->id));
        $this->assertFalse($ids->contains($theirs->id));
    }

    public function test_search_combines_with_the_status_filter(): void
    {
        $open = $this->conversation([['user', 'ковёр']], ['external_user_name' => 'Open', 'status' => 'ai']);
        $closed = $this->conversation([['user', 'ковёр']], ['external_user_name' => 'Closed', 'status' => 'closed']);

        $ids = collect($this->search('ковёр')->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($open->id));
        $this->assertFalse($ids->contains($closed->id));

        $closedIds = collect($this->search('ковёр', ['status' => 'closed'])->json('data'))->pluck('id');
        $this->assertTrue($closedIds->contains($closed->id));
    }

    public function test_a_search_that_matches_nothing_returns_an_empty_list(): void
    {
        $this->conversation([['user', 'Привет']]);

        $response = $this->search('нет-такого-слова');

        $response->assertOk();
        $this->assertSame([], $response->json('data'));
        $this->assertSame(0, $response->json('total'));
    }

    /**
     * The unread badge counts the whole inbox, so a search must not shrink it.
     */
    public function test_searching_does_not_change_the_unread_total(): void
    {
        $this->conversation([['user', 'ковёр']]);
        $this->conversation([['user', 'что-то другое']]);

        $all = $this->actingAs($this->owner)->getJson(route('conversations.list'))->json('unread_total');
        $filtered = $this->search('ковёр')->json('unread_total');

        $this->assertSame($all, $filtered);
    }
}
