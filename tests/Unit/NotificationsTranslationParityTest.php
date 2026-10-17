<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * Same convention as CommonTranslationParityTest/SettingsTranslationParityTest/
 * AgentsTranslationParityTest/Bi\TranslationParityTest, for the task-bell's own
 * namespace (resources/lang/{ru,tg,en}/notifications.php). Matters more than
 * usual here: the bell builds every sentence CLIENT-SIDE from this bundle (see
 * partials/task-bell.blade.php and the shell store's i18nText()), so a key
 * missing in one locale wouldn't 500 anywhere — it would just render blank
 * text in the dropdown, silently, with nothing to fail loudly.
 */
class NotificationsTranslationParityTest extends TestCase
{
    public function test_notifications_strings_have_the_same_keys_in_all_three_locales(): void
    {
        $russian = $this->keys(require resource_path('lang/ru/notifications.php'));

        foreach (['tg', 'en'] as $locale) {
            $this->assertSame($russian, $this->keys(require resource_path("lang/{$locale}/notifications.php")), $locale);
        }
    }

    /**
     * Every string must actually say something — a present-but-empty key
     * would pass a key-parity check while still rendering as nothing.
     */
    public function test_notifications_strings_are_all_non_empty(): void
    {
        foreach (['ru', 'tg', 'en'] as $locale) {
            foreach ($this->keys(require resource_path("lang/{$locale}/notifications.php")) as $key) {
                $this->assertNotSame('', trim((string) __("notifications.{$key}", [], $locale)), "{$locale}: notifications.{$key} is empty");
            }
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
