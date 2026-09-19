<?php

namespace App\Services\Dataset;

trait PresentsProducts
{
    protected function presentRow(array $fields): array
    {
        $row = ['name' => (string) ($fields['name'] ?? '')];

        if (($fields['price'] ?? null) !== null && $fields['price'] !== '') {
            $row['price'] = $this->formatPrice($fields['price']);
        }

        if (($fields['stock'] ?? null) !== null && $fields['stock'] !== '') {
            $stock = $fields['stock'];

            if (is_numeric($stock)) {
                $row['stock'] = 0 + $stock;
                $row['in_stock'] = $row['stock'] > 0;
            } else {
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
