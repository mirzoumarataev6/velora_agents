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
            'название', 'название товара', 'наименование', 'наименование товара',
            'товар', 'продукт', 'имя', 'модель',
            'ном', 'номи мол', 'мол',
        ],
        'price' => [
            'price', 'cost', 'amount', 'retail price', 'sale price', 'unit price',
            'цена', 'стоимость', 'цена продажи', 'розничная цена', 'прайс',
            'сомони',
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
     * The same aliases, folded the way a header is folded before comparison.
     *
     * A header is always folded before it is matched, so an alias that is not
     * folded can never match one: `кол-во` becomes "кол во" on the header side
     * and stays "кол-во" on the alias side, and the two never meet. Folding both
     * ends is what makes the comparison symmetric — and it matters more now that
     * transliteration feeds candidates like "kol vo" into the same lookup.
     *
     * @return array<string, string[]>
     */
    public static function foldedAliases(): array
    {
        static $folded = null;

        if ($folded !== null) {
            return $folded;
        }

        $folded = [];

        foreach (self::ALIASES as $field => $aliases) {
            $seen = [];

            foreach ($aliases as $alias) {
                $value = self::fold($alias);

                if ($value !== '' && ! isset($seen[$value])) {
                    $seen[$value] = true;
                    $folded[$field][] = $value;
                }
            }
        }

        return $folded;
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
     * A header spelled in latin — `nazvanie`, `cena`, `narkh` — is read by
     * transliterating it back into the vocabulary's own alphabet, the same way
     * a live database's columns are. Exports out of a CMS or a hosting panel
     * carry those headers constantly, and the alternative is listing every
     * latin spelling of every word in three languages.
     *
     * The layers are ordered so certainty wins over cleverness: an exact match
     * in the vocabulary's own languages, then an exact transliterated one, then
     * the loose passes in the same order. A transliterated guess can never take
     * a column a real word already claimed.
     *
     * Whatever is left over after the core fields have been claimed becomes an
     * `extra` column rather than being dropped. Shops describe their goods with
     * whatever attributes matter to them — Процессор, ОЗУ, Память on a laptop
     * export, Размер and Цвет on a clothing one — and there is no fixed set of
     * fields those could ever be forced into. Kept as extras they are stored
     * verbatim under the owner's own header names, shown to the model with the
     * product, and searchable; dropped, they were the reason the agent could not
     * answer "which one has 16GB".
     *
     * @param  string[]  $headers
     * @param  array<int, array<int, string>>  $rows  Sample data rows, if available.
     * @return array<string, int|int[]>
     */
    public function suggest(array $headers, array $rows = []): array
    {
        $normalized = array_map(fn ($h) => $this->normalize($h), $headers);

        // Every reading of each header, computed once: this is a header row, so
        // there is no point transliterating the same word per field per pass.
        $latin = array_map(fn ($h) => Transliterator::candidates($h), $normalized);

        $aliases = self::foldedAliases();

        $mapping = [];
        $takenColumns = [];

        foreach ([[true, false], [true, true], [false, false], [false, true]] as [$exactPass, $translitPass]) {
            foreach ($aliases as $field => $words) {
                if (isset($mapping[$field])) {
                    continue;
                }

                foreach ($normalized as $index => $header) {
                    if ($header === '' || isset($takenColumns[$index])) {
                        continue;
                    }

                    $forms = $translitPass ? $latin[$index] : [$header];

                    if ($this->matchesAny($forms, $words, $exactPass)) {
                        $mapping[$field] = $index;
                        $takenColumns[$index] = true;
                        break;
                    }
                }
            }
        }

        // A file whose name column is headed something the vocabulary has never
        // seen is not an unusable file — it is the common case, because a shop
        // headed it after what it sells ("Модель", "Позиция", "Бренд"). The
        // name is the one field the import cannot proceed without, so when no
        // header claimed it the values decide instead.
        if (! isset($mapping['name']) && $rows !== []) {
            $index = $this->columnThatLooksLikeAName($headers, $normalized, $rows, $takenColumns);

            if ($index !== null) {
                $mapping['name'] = $index;
                $takenColumns[$index] = true;
            }
        }

        $extra = [];

        foreach ($normalized as $index => $header) {
            // An unnamed column is a row number or a spreadsheet artefact, not
            // an attribute — "№" folds to nothing and stays out.
            if ($header === '' || isset($takenColumns[$index])) {
                continue;
            }

            if ($rows === [] || $this->columnHasValues($rows, $index)) {
                $extra[] = $index;
            }
        }

        if ($extra !== []) {
            $mapping['extra'] = $extra;
        }

        return $mapping;
    }

    /**
     * The leftmost unclaimed column whose values read like product names.
     *
     * Deliberately conservative: a column of numbers is a quantity or a code, a
     * column of near-identical values is a category, and a column that is mostly
     * empty is neither. Anything that survives all three is text that varies per
     * row — which is what a product name is.
     *
     * @param  string[]  $headers
     * @param  string[]  $normalized
     * @param  array<int, array<int, string>>  $rows
     * @param  array<int, bool>  $taken
     */
    protected function columnThatLooksLikeAName(array $headers, array $normalized, array $rows, array $taken): ?int
    {
        foreach (array_keys($headers) as $index) {
            if (($normalized[$index] ?? '') === '' || isset($taken[$index])) {
                continue;
            }

            $values = $this->columnValues($rows, $index);

            if (count($values) < max(1, (int) ceil(count($rows) / 2))) {
                continue;
            }

            $textual = array_filter($values, fn (string $value) => ! is_numeric(str_replace([' ', ',', "\u{00A0}"], '', $value)));

            if (count($textual) < count($values)) {
                continue;
            }

            // One repeated value across several rows is a category column.
            if (count($values) > 2 && count(array_unique($values)) < 2) {
                continue;
            }

            return $index;
        }

        return null;
    }

    /**
     * @param  array<int, array<int, string>>  $rows
     */
    protected function columnHasValues(array $rows, int $index): bool
    {
        return $this->columnValues($rows, $index) !== [];
    }

    /**
     * The non-blank values a column holds across the sampled rows.
     *
     * @param  array<int, array<int, string>>  $rows
     * @return string[]
     */
    protected function columnValues(array $rows, int $index): array
    {
        $values = [];

        foreach ($rows as $row) {
            $value = trim((string) ($row[$index] ?? ''));

            if ($value !== '') {
                $values[] = $value;
            }
        }

        return $values;
    }

    /**
     * @param  string[]  $forms  Readings of one header. Any of them matching is a match:
     *                           they are alternative spellings of one word, not several words.
     * @param  string[]  $aliases
     */
    protected function matchesAny(array $forms, array $aliases, bool $exact): bool
    {
        foreach ($forms as $form) {
            if ($form !== '' && $this->matches($form, $aliases, $exact)) {
                return true;
            }
        }

        return false;
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
