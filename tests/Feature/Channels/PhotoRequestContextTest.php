<?php

namespace Tests\Feature\Channels;

use App\Models\Conversation;
use App\Services\Channels\PhotoRequestContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PhotoRequestContextTest extends TestCase
{
    use RefreshDatabase;

    private function listedConversation(): Conversation
    {
        $conversation = Conversation::factory()->create();
        $conversation->messages()->create(['role' => 'assistant', 'content' => "1. Macbook Neo 13 — 8199 сомони\n2. Macbook Neo 13 — 8900 сомони\n3. Macbook Air 13.6 — 10499 сомони"]);
        $conversation->messages()->create(['role' => 'assistant', 'content' => 'Какую модель показать?', 'meta' => ['photo_status' => 'clarify']]);

        return $conversation;
    }

    public function test_ordinal_and_price_selections_preserve_display_order_and_decimal_model_names(): void
    {
        $conversation = $this->listedConversation();
        foreach (['фото третьего', '3', '10499 сомони', 'Macbook Air 13.6'] as $text) {
            $user = $conversation->messages()->create(['role' => 'user', 'content' => $text]);
            $resolved = app(PhotoRequestContext::class)->resolve($conversation, $user);
            $this->assertTrue((bool) $resolved['requested']);
            $this->assertSame('Macbook Air 13.6', $resolved['model']);
            $this->assertSame('ru', $resolved['locale']);
        }
    }

    public function test_a_new_commercial_question_is_not_swallowed_by_pending_photo_selection(): void
    {
        $conversation = $this->listedConversation();
        foreach (['Сколько стоит первый?', 'Есть доставка Macbook Neo 13?', 'Хочу заказать второй'] as $text) {
            $user = $conversation->messages()->create(['role' => 'user', 'content' => $text]);
            $this->assertFalse((bool) app(PhotoRequestContext::class)->resolve($conversation, $user)['requested']);
        }
    }

    public function test_expired_clarification_does_not_capture_a_bare_model(): void
    {
        $conversation = $this->listedConversation();
        $this->travel(31)->minutes();
        $user = $conversation->messages()->create(['role' => 'user', 'content' => 'Macbook Neo 13']);
        $this->assertFalse((bool) app(PhotoRequestContext::class)->resolve($conversation, $user)['requested']);
    }

    public function test_a_different_conversation_cannot_supply_the_product_list(): void
    {
        $this->listedConversation();
        $other = Conversation::factory()->create();
        $user = $other->messages()->create(['role' => 'user', 'content' => 'фото первого']);
        $resolved = app(PhotoRequestContext::class)->resolve($other, $user);
        $this->assertSame('', $resolved['model']);
        $this->assertSame([], $resolved['choices']);
    }

    public function test_start_resets_the_previous_product_list(): void
    {
        $conversation = $this->listedConversation();
        $conversation->messages()->create(['role' => 'user', 'content' => '/start']);
        $conversation->messages()->create(['role' => 'assistant', 'content' => 'Здравствуйте']);
        $user = $conversation->messages()->create(['role' => 'user', 'content' => 'фото первого']);
        $resolved = app(PhotoRequestContext::class)->resolve($conversation, $user);
        $this->assertSame('', $resolved['model']);
        $this->assertSame([], $resolved['choices']);
    }
}
