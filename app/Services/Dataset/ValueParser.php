<?php

namespace App\Services\Dataset;

class ValueParser
{
    protected const BLANKS = ['', '-', '—', '–', 'n/a', 'na', 'null', 'нет данных', 'не указано'];

    protected const IN_STOCK = [
        'yes', 'y', 'true', 'in stock', 'instock', 'available', '+',
        'да', 'есть', 'в наличии', 'наличие', 'имеется',
        'ҳаст', 'хаст', 'мавҷуд', 'мавчуд',
    ];

    protected const OUT_OF_STOCK = [
        'no', 'n', 'false', 'out of stock', 'outofstock', 'unavailable', 'sold out',
        'нет', 'нет в наличии', 'отсутствует', 'под заказ', 'закончился',
        'нест', 'мавҷуд нест', 'мавчуд нест',
    ];

    // Разбирает строку с ценой и возвращает её в нормализованном числовом виде
    public function price(?string $raw): ?string
    {
        $value = $this->number($raw);

        if ($value === null || $value < 0) {
            return null;
        }

        return number_format($value, 2, '.', '');
    }

    // Разбирает строку с остатком (число или текстовую отметку наличия) в количество
    public function stock(?string $raw): ?int
    {
        $value = $this->clean($raw);

        if ($value === null) {
            return null;
        }

        $folded = mb_strtolower($value);

        if (in_array($folded, self::IN_STOCK, true)) {
            return 1;
        }

        if (in_array($folded, self::OUT_OF_STOCK, true)) {
            return 0;
        }

        $number = $this->number($value);

        if ($number === null) {
            return null;
        }

        return max(0, (int) floor($number));
    }

    // Очищает и обрезает текстовое значение ячейки до заданной длины
    public function text(?string $raw, int $limit = 255): ?string
    {
        $value = $this->clean($raw);

        if ($value === null) {
            return null;
        }

        $value = trim((string) preg_replace('/\s+/u', ' ', $value));

        return $value === '' ? null : mb_substr($value, 0, $limit);
    }

    // Очищает и обрезает длинный текст (например, описание товара)
    public function longText(?string $raw, int $limit = 5000): ?string
    {
        $value = $this->clean($raw);

        return $value === null ? null : mb_substr($value, 0, $limit);
    }

    // Разбирает строку и возвращает валидный URL, при необходимости добавляя схему
    public function url(?string $raw): ?string
    {
        $value = $this->text($raw, 1024);

        if ($value === null) {
            return null;
        }

        if (! preg_match('#^https?://#i', $value) && preg_match('#^[\w-]+(\.[\w-]+)+/#', $value)) {
            $value = 'https://'.$value;
        }

        return filter_var($value, FILTER_VALIDATE_URL) !== false
            && preg_match('#^https?://#i', $value)
                ? $value
                : null;
    }

    // Разбирает строку в число с плавающей точкой, учитывая знак и разделители
    protected function number(?string $raw): ?float
    {
        $value = $this->clean($raw);

        if ($value === null) {
            return null;
        }

        $value = (string) preg_replace('/[^\d,.\-]/u', '', $value);
        $value = trim($value);

        if ($value === '' || ! preg_match('/\d/', $value)) {
            return null;
        }

        $negative = str_starts_with($value, '-');
        $value = str_replace('-', '', $value);

        $value = $this->normalizeSeparators($value);

        if (! is_numeric($value)) {
            return null;
        }

        return $negative ? -(float) $value : (float) $value;
    }

    // Определяет, какой из разделителей (запятая или точка) десятичный, а какой — разряды тысяч
    protected function normalizeSeparators(string $value): string
    {
        $lastComma = strrpos($value, ',');
        $lastDot = strrpos($value, '.');

        if ($lastComma === false && $lastDot === false) {
            return $value;
        }

        if ($lastComma !== false && $lastDot !== false) {
            $decimalAt = max($lastComma, $lastDot);

            return str_replace([',', '.'], '', substr($value, 0, $decimalAt))
                .'.'.substr($value, $decimalAt + 1);
        }

        $separator = $lastComma !== false ? ',' : '.';
        $position = $lastComma !== false ? $lastComma : $lastDot;

        if (substr_count($value, $separator) > 1) {
            return str_replace($separator, '', $value);
        }

        $head = substr($value, 0, $position);
        $tail = substr($value, $position + 1);

        if ($head !== '' && strlen($tail) === 3) {
            return $head.$tail;
        }

        return $head.'.'.$tail;
    }

    // Очищает значение от неразрывных пробелов и распознаёт признаки "пусто"
    protected function clean(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $value = trim(str_replace(["\u{00A0}", "\u{202F}"], ' ', $raw));

        return in_array(mb_strtolower($value), self::BLANKS, true) ? null : ($value === '' ? null : $value);
    }
}
