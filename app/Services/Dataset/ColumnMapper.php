<?php

namespace App\Services\Dataset;

class ColumnMapper
{
    public const FIELDS = ['name', 'price', 'stock', 'category', 'description', 'sku', 'url', 'extra'];

    public const REQUIRED_FIELDS = ['name'];

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

    // Возвращает список возможных названий столбцов для каждого поля
    public static function aliases(): array
    {
        return self::ALIASES;
    }

    // Возвращает алиасы полей в нормализованном (свёрнутом) виде для сравнения
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

    // Приводит заголовок столбца к нормализованному виду для сравнения
    public static function fold(string $header): string
    {
        $value = mb_strtolower(trim($header));
        $value = (string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value);

        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    // Подбирает соответствие столбцов таблицы полям товара по заголовкам и данным
    public function suggest(array $headers, array $rows = []): array
    {
        $normalized = array_map(fn ($h) => $this->normalize($h), $headers);

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

        if (! isset($mapping['name']) && $rows !== []) {
            $index = $this->columnThatLooksLikeAName($headers, $normalized, $rows, $takenColumns);

            if ($index !== null) {
                $mapping['name'] = $index;
                $takenColumns[$index] = true;
            }
        }

        $extra = [];

        foreach ($normalized as $index => $header) {
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

    // Ищет столбец, который по содержимому похож на название товара, если явного совпадения не нашлось
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

            if (count($values) > 2 && count(array_unique($values)) < 2) {
                continue;
            }

            return $index;
        }

        return null;
    }

    // Проверяет, есть ли в столбце хотя бы одно непустое значение
    protected function columnHasValues(array $rows, int $index): bool
    {
        return $this->columnValues($rows, $index) !== [];
    }

    // Возвращает непустые значения указанного столбца из всех строк
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

    // Проверяет, совпадает ли хотя бы одна из форм заголовка с алиасами поля
    protected function matchesAny(array $forms, array $aliases, bool $exact): bool
    {
        foreach ($forms as $form) {
            if ($form !== '' && $this->matches($form, $aliases, $exact)) {
                return true;
            }
        }

        return false;
    }

    // Сравнивает заголовок с алиасом точно или по вхождению подстроки
    protected function matches(string $header, array $aliases, bool $exact): bool
    {
        foreach ($aliases as $alias) {
            if ($exact ? $header === $alias : str_contains($header, $alias)) {
                return true;
            }
        }

        return false;
    }

    // Нормализует заголовок столбца для сравнения с алиасами
    protected function normalize(string $header): string
    {
        return self::fold($header);
    }
}
