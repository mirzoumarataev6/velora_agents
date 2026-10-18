<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * Same convention as AgentsTranslationParityTest/CommonTranslationParityTest/
 * etc., for shell.php — split out of agents.php's old 'nav' key in PHASE 2
 * item 13 (see shell.php's own docblock for why).
 */
class ShellTranslationParityTest extends TestCase
{
    public function test_shell_strings_have_the_same_keys_in_all_three_locales(): void
    {
        $russian = $this->keys(require resource_path('lang/ru/shell.php'));

        foreach (['tg', 'en'] as $locale) {
            $this->assertSame($russian, $this->keys(require resource_path("lang/{$locale}/shell.php")), $locale);
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
