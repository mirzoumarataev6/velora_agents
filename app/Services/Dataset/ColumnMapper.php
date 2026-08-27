<?php

namespace App\Services\Dataset;

/**
 * The catalog's field vocabulary, and the guess that saves the owner from
 * mapping seven columns by hand.
 *
 * The guess is only ever a suggestion: DatasetController shows it back with the
 * sampled values beside it and imports nothing until the owner confirms. That
 * confirmation step is the whole reason this feature can be trusted to answer
 * a price question — an auto-mapper that silently reads "закупка" as the retail
 * price would have the agent quoting wholesale to customers.
 */
class ColumnMapper
{
    /**
     * Fields a column can be mapped to. 'extra' is the escape hatch: the column
     * is kept verbatim in the products' JSON under its own header name.
     */
    public const FIELDS = ['name', 'price', 'stock', 'category', 'description', 'sku', 'url', 'extra'];

    /** Without a product name a row is not a product, so this one is required. */
    public const REQUIRED_FIELDS = ['name'];

    /**
     * Header aliases per field, lowercased. Matched exactly first, then as a
     * substring — exact-first is what stops "цена закупки" being claimed by the
     * loose 'цена' rule when a plain 'Цена' column also exists.
     *
     * @var array<string, string[]>
     */
    protected const ALIASES = [
        'name' => [
            'name', 'title', 'product', 'product name', 'item', 'goods',
            'название', 'наименование', 'товар', 'продукт', 'имя',
            'ном', 'номи мол', 'мол',
        ],
        'price' => [
            'price', 'cost', 'amount', 'retail price', 'sale price', 'unit price',
            'цена', 'стоимость', 'цена продажи', 'розничная цена', 'прайс',
            'нарх', 'арзиш',
        ],
        'stock' => [
            'stock', 'quantity', 'qty', 'in stock', 'availability', 'available', 'balance',
            'остаток', 'остатки', 'количество', 'кол-во', 'наличие', 'в наличии', 'запас',
            'миқдор', 'микдор', 'мавҷудӣ', 'мавчуди',
        ],
        'category' => [
            'category', 'group', 'type', 'section', 'collection',
            'категория', 'группа', 'раздел', 'тип', 'коллекция',
            'категория', 'гурӯҳ', 'гурух',
        ],
        'description' => [
            'description', 'details', 'about', 'comment', 'notes', 'characteristics',
            'описание', 'описание товара', 'подробности', 'комментарий', 'характеристики',
            'тавсиф', 'шарҳ', 'шарх',
        ],
        'sku' => [
            'sku', 'article', 'code', 'id', 'vendor code', 'barcode', 'part number', 'mpn',
            'артикул', 'код', 'код товара', 'штрихкод', 'номенклатура',
            'рамз', 'рақам', 'ракам',
        ],
        'url' => [
            'url', 'link', 'page', 'product url', 'permalink', 'website',
            'ссылка', 'адрес', 'страница', 'сайт',
            'пайванд', 'истинод',
        ],
    ];

    /**
     * The alias table itself, for callers that match on more than a header name.
     *
     * Live database discovery needs the same vocabulary but a different rule:
     * it also knows each column's TYPE, so it can prefer a numeric "цена" over a
     * textual one and refuse a numeric column as the product name. Exposing the
     * words keeps that one vocabulary — an owner who mapped a spreadsheet here
     * meets the same guesses on their database — while letting the type-aware
     * matching live where the types are.
     *
     * @return array<string, string[]>
     */
    public static function aliases(): array
    {
        return self::ALIASES;
    }

    /**
     * Fold a header to its comparable form. Public for the same reason as
     * aliases(): the two matchers must agree on what "the same name" means.
     */
    public static function fold(string $header): string
    {
        $value = mb_strtolower(trim($header));
        $value = (string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value);

        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    /**
     * Suggest field => column index for a header row.
     *
     * Every field is claimed by at most one column, and every column by at most
     * one field — a spreadsheet with both "Цена" and "Цена со скидкой" must not
     * end up with two prices fighting over the same key.
     *
     * @param  string[]  $headers
     * @return array<string, int>
     */
    public function suggest(array $headers): array
    {
        $normalized = array_map(fn ($h) => $this->normalize($h), $headers);

        $mapping = [];
        $takenColumns = [];

        // Exact matches across every field first, so a precise header always
        // wins its column before any substring rule gets a chance at it.
        foreach ([true, false] as $exactPass) {
            foreach (self::ALIASES as $field => $aliases) {
                if (isset($mapping[$field])) {
                    continue;
                }

                foreach ($normalized as $index => $header) {
                    if ($header === '' || isset($takenColumns[$index])) {
                        continue;
                    }

                    if ($this->matches($header, $aliases, $exactPass)) {
                        $mapping[$field] = $index;
                        $takenColumns[$index] = true;
                        break;
                    }
                }
            }
        }

        return $mapping;
    }

    /**
     * @param  string[]  $aliases
     */
    protected function matches(string $header, array $aliases, bool $exact): bool
    {
        foreach ($aliases as $alias) {
            if ($exact ? $header === $alias : str_contains($header, $alias)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Fold a header to its comparable form: lowercase, punctuation and repeated
     * whitespace collapsed, so "Цена, руб." and "цена руб" compare equal.
     */
    protected function normalize(string $header): string
    {
        return self::fold($header);
    }
}
