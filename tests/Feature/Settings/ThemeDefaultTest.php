<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pre-paint theme boot scripts must never let the OS decide for someone who
 * has not made a choice. Before this test existed, "nothing stored" fell
 * back to `prefers-color-scheme`, so a brand-new account (or a guest who
 * never touched the toggle) opened dark on a dark OS. An explicit choice —
 * dark, light, or the "system" option in settings — still has to win.
 */
class ThemeDefaultTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The dashboard's boot script computes `isDark` from the account's saved
     * theme (embedded as `saved`) and, only for the explicit "system" value,
     * the OS media query. Asserting the exact expression — rather than just
     * the rendered class — is what lets this fail on the pre-fix commit: the
     * old expression consulted `prefersDark` whenever nothing was stored,
     * which is precisely the bug.
     */
    public function test_dashboard_boot_script_does_not_fall_back_to_the_os_when_nothing_is_stored(): void
    {
        $user = User::factory()->create(['theme' => null]);

        $html = $this->actingAs($user)->get('/dashboard')->getContent();

        $this->assertStringContainsString('var saved = null;', $html);
        $this->assertStringContainsString(
            "isDark = stored === 'dark' || (stored === 'system' && prefersDark)",
            $html
        );
        $this->assertStringNotContainsString(
            "isDark = stored ? stored === 'dark' : prefersDark",
            $html
        );
    }

    public function test_dashboard_boot_script_still_honours_an_explicit_dark_choice(): void
    {
        $user = User::factory()->create(['theme' => 'dark']);

        $html = $this->actingAs($user)->get('/dashboard')->getContent();

        $this->assertStringContainsString('var saved = "dark";', $html);
    }

    /**
     * Guest pages (login/register) have no "system" option — only an
     * explicit light/dark toggle cached in localStorage — so their boot
     * script must not call matchMedia at all once the OS fallback is gone.
     */
    public function test_guest_login_boot_script_does_not_consult_the_os_either(): void
    {
        $html = $this->get('/ru/login')->getContent();

        $script = $this->firstInlineScript($html);

        $this->assertStringNotContainsString('matchMedia', $script);
        $this->assertStringContainsString("isDark = stored === 'dark'", $script);
    }

    private function firstInlineScript(string $html): string
    {
        preg_match('/<script>(.*?)<\/script>/s', $html, $matches);

        return $matches[1] ?? '';
    }
}
