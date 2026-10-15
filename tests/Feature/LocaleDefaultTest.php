<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tajik is the product's default for anyone who has never chosen a language
 * — a brand-new visitor, or a brand-new account. Russian remains fully
 * supported and is still what an explicit choice gets you, at any of the
 * three places that record one: the URL's {locale} segment, the session,
 * and the user row.
 */
class LocaleDefaultTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_hitting_root_is_redirected_to_tajik_by_default(): void
    {
        $this->get('/')->assertRedirect('/tg');
    }

    /**
     * The natural flow, end to end: a visitor who never chose a language
     * hits "/", follows wherever the root redirect sends them (that is the
     * default), opens registration from there, and signs up. Deriving the
     * register URL from the redirect itself — rather than hardcoding
     * "/tg/register" — is what makes this actually exercise the default
     * instead of just an explicit visit to Tajik.
     */
    public function test_a_new_registered_user_with_no_chosen_locale_gets_tajik(): void
    {
        $landing = $this->get('/')->headers->get('Location');
        $this->get($landing.'/register')->assertOk();

        $this->post('/register', [
            'name' => 'New Visitor',
            'email' => 'new-visitor@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertRedirect();

        $this->assertSame('tg', User::where('email', 'new-visitor@example.com')->first()->locale);
    }

    public function test_a_user_who_already_chose_russian_keeps_it(): void
    {
        $this->get('/ru/register')->assertOk();

        $this->post('/register', [
            'name' => 'Russian Visitor',
            'email' => 'ru-visitor@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertRedirect();

        $this->assertSame('ru', User::where('email', 'ru-visitor@example.com')->first()->locale);

        $this->post('/logout');

        // And a returning user whose row already says `ru` keeps rendering in
        // it — the default only ever applies to someone who never chose.
        $user = User::factory()->create(['locale' => 'ru']);
        $this->actingAs($user)->get('/agents')
            ->assertOk()
            ->assertSee('<html lang="ru">', false);
    }

    public function test_lang_query_still_switches_to_russian(): void
    {
        $user = User::factory()->create(['locale' => null]);

        $this->actingAs($user)->get('/agents?lang=ru')
            ->assertOk()
            ->assertSee('<html lang="ru">', false);

        $this->assertSame('ru', $user->fresh()->locale);
    }
}
