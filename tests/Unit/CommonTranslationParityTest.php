<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * Same convention as SettingsTranslationParityTest/AgentsTranslationParityTest/
 * Bi\TranslationParityTest, extended to common.php — the file the no-access
 * page (HomeDestination's dead end) draws its strings from.
 */
class CommonTranslationParityTest extends TestCase
{
    public function test_common_strings_have_the_same_keys_in_all_three_locales(): void
    {
        $russian = $this->keys(require resource_path('lang/ru/common.php'));

        foreach (['tg', 'en'] as $locale) {
            $this->assertSame($russian, $this->keys(require resource_path("lang/{$locale}/common.php")), $locale);
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
