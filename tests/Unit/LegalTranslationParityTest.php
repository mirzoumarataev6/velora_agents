<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * Same convention as CommonTranslationParityTest, extended to legal.php — the
 * Terms of Service and Privacy Policy pages linked from registration.
 */
class LegalTranslationParityTest extends TestCase
{
    public function test_legal_strings_have_the_same_keys_in_all_three_locales(): void
    {
        $russian = $this->keys(require resource_path('lang/ru/legal.php'));

        foreach (['tg', 'en'] as $locale) {
            $this->assertSame($russian, $this->keys(require resource_path("lang/{$locale}/legal.php")), $locale);
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
