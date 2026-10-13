<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * Same convention as AgentsTranslationParityTest (Stage 3a) and
 * Bi\TranslationParityTest, extended to settings.php — the file Stage 3b's
 * new "Team" tab and invitation flow draw their strings from.
 */
class SettingsTranslationParityTest extends TestCase
{
    public function test_settings_strings_have_the_same_keys_in_all_three_locales(): void
    {
        $russian = $this->keys(require resource_path('lang/ru/settings.php'));

        foreach (['tg', 'en'] as $locale) {
            $this->assertSame($russian, $this->keys(require resource_path("lang/{$locale}/settings.php")), $locale);
        }
    }

    /** @return array<int, string> */
    private function keys(array $dictionary, string $prefix = ''): array
    {
        $keys = [];
        foreach ($dictionary as $key => $value) {
            $path = $prefix.$key;
            if (is_array($value)) {
                array_push($keys, ...$this->keys($value, $path.'.'));
            } else {
                $keys[] = $path;
            }
        }

        sort($keys);

        return $keys;
    }
}
