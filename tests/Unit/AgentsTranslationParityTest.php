<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * Same convention as Bi\TranslationParityTest, extended to agents.php — the
 * file the dashboard shell (sidebar, header, team switcher) draws its
 * strings from. Nothing enforced this for the file as a whole before Stage
 * 3a added `nav.teams.*`; worth having going forward per CLAUDE.md's own
 * note that this is "worth doing... for any new namespace".
 */
class AgentsTranslationParityTest extends TestCase
{
    public function test_agents_strings_have_the_same_keys_in_all_three_locales(): void
    {
        $russian = $this->keys(require resource_path('lang/ru/agents.php'));

        foreach (['tg', 'en'] as $locale) {
            $this->assertSame($russian, $this->keys(require resource_path("lang/{$locale}/agents.php")), $locale);
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
