<?php

namespace App\Services\Dataset;

/**
 * One product as the model should see it: the facts, no ids, no internals.
 *
 * Shared by both sources so a row read from a shop's live table reaches the
 * model in exactly the shape an imported one does — otherwise the model would
 * have to be told about two result formats, and the reply would read differently
 * depending on which source the owner happened to pick.
 */
trait PresentsProducts
{
    /**
     * @param  array<string, mixed>  $fields  Raw values keyed by the field vocabulary.
     * @return array<string, mixed>
     */
    protected function presentRow(array $fields): array
    {
        $row = ['name' => (string) ($fields['name'] ?? '')];

        if (($fields['price'] ?? null) !== null && $fields['price'] !== '') {
            // Trailing ".00" on a whole-number price reads as machine output
            // when the model echoes it back to a customer.
            $row['price'] = $this->formatPrice($fields['price']);
        }

        if (($fields['stock'] ?? null) !== null && $fields['stock'] !== '') {
            $stock = $fields['stock'];

            if (is_numeric($stock)) {
                $row['stock'] = 0 + $stock;
                $row['in_stock'] = $row['stock'] > 0;
            } else {
                // A live table may hold "в наличии" or a boolean rather than a
                // count. Pass it through verbatim rather than guessing a number
                // the model would then quote as a quantity.
                $row['stock'] = (string) $stock;
                $row['in_stock'] = filter_var($stock, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true;
            }
        }

        foreach (['category', 'sku', 'url'] as $field) {
            if (($fields[$field] ?? null) !== null && $fields[$field] !== '') {
                $row[$field] = (string) $fields[$field];
            }
        }

        if (($fields['description'] ?? null) !== null && $fields['description'] !== '') {
            // Enough for the model to describe the item; not so much that twenty
            // rows crowd out the conversation.
            $row['description'] = mb_substr((string) $fields['description'], 0, 300);
        }

        if (! empty($fields['details'])) {
            $row['details'] = $fields['details'];
        }

        return $row;
    }

    protected function formatPrice(mixed $price): string
    {
        if (! is_numeric($price)) {
            return (string) $price;
        }

        $formatted = number_format((float) $price, 2, '.', '');

        return rtrim(rtrim($formatted, '0'), '.') ?: '0';
    }
}
