<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * Same convention as CommonTranslationParityTest/SettingsTranslationParityTest/
 * AgentsTranslationParityTest/Bi\TranslationParityTest, extended to
 * projects.php — no such test existed yet (PHASE 1 item 3 is the first time
 * a new key, deal_untitled, was added to this namespace since it was
 * created). Later phases add validation-attribute keys to this same file;
 * this test guards every one of them, not just deal_untitled.
 */
class ProjectsTranslationParityTest extends TestCase
{
    public function test_projects_strings_have_the_same_keys_in_all_three_locales(): void
    {
        $russian = $this->keys(require resource_path('lang/ru/projects.php'));

        foreach (['tg', 'en'] as $locale) {
            $this->assertSame($russian, $this->keys(require resource_path("lang/{$locale}/projects.php")), $locale);
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
