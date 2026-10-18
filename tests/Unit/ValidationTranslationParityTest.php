<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * PHASE 2 item 9: a validation rule with no matching key in this file's
 * `attributes` array falls back to Laravel's default — either the raw
 * field name verbatim ("The customer_id field is required.") or, worse,
 * whatever English the rule TEMPLATE itself defaults to if the whole
 * locale's rule string is missing. Every Projects field (title,
 * description, priority, due_at, wip_limit, column_id, assignee_ids,
 * label_ids, customer_id, deal_id, body, name, color) now has an entry
 * here. Same convention as CommonTranslationParityTest/
 * ProjectsTranslationParityTest/etc., applied to validation.php, which had
 * no parity test at all before this.
 */
class ValidationTranslationParityTest extends TestCase
{
    public function test_validation_strings_have_the_same_keys_in_all_three_locales(): void
    {
        $russian = $this->keys(require resource_path('lang/ru/validation.php'));

        foreach (['tg', 'en'] as $locale) {
            $this->assertSame($russian, $this->keys(require resource_path("lang/{$locale}/validation.php")), $locale);
        }
    }

    /**
     * The exact field list PHASE 2 item 9 named, each of which some
     * Projects controller actually validates — reads the live attribute
     * map rather than hardcoding it twice, so a key renamed in the lang
     * file fails here instead of silently going stale.
     */
    public function test_every_named_projects_field_has_an_attribute_name_in_all_three_locales(): void
    {
        $fields = [
            'title', 'description', 'priority', 'due_at', 'wip_limit', 'column_id',
            'assignee_ids', 'assignee_ids.*', 'label_ids', 'label_ids.*',
            'customer_id', 'deal_id', 'body', 'name', 'color',
        ];

        foreach (['ru', 'tg', 'en'] as $locale) {
            $attributes = (require resource_path("lang/{$locale}/validation.php"))['attributes'];
            foreach ($fields as $field) {
                $this->assertArrayHasKey($field, $attributes, "{$locale}: missing validation attribute name for [{$field}]");
                $this->assertNotSame('', trim($attributes[$field]), "{$locale}: blank validation attribute name for [{$field}]");
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
