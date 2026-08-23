<?php

namespace App\Services\Dataset;

/**
 * Turns the strings a spreadsheet actually contains into the typed values the
 * products table stores.
 *
 * Every method returns null rather than throwing or guessing: a price cell
 * reading "по запросу" is a product without a price, not a broken import, and
 * nulling it keeps the row searchable by name and category while correctly
 * excluding it from a "under 500" filter.
 */
class ValueParser
{
    /** Cells that mean "there is no value here", whatever the column. */
    protected const BLANKS = ['', '-', '—', '–', 'n/a', 'na', 'null', 'нет данных', 'не указано'];

    /** Cell values that mean the item IS available, without saying how many. */
    protected const IN_STOCK = [
        'yes', 'y', 'true', 'in stock', 'instock', 'available', '+',
        'да', 'есть', 'в наличии', 'наличие', 'имеется',
        'ҳаст', 'хаст', 'мавҷуд', 'мавчуд',
    ];

    /** Cell values that mean the item is NOT available. */
    protected const OUT_OF_STOCK = [
        'no', 'n', 'false', 'out of stock', 'outofstock', 'unavailable', 'sold out',
        'нет', 'нет в наличии', 'отсутствует', 'под заказ', 'закончился',
        'нест', 'мавҷуд нест', 'мавчуд нест',
    ];

    /**
     * A price as a decimal string (matching the column's decimal:2 cast), or
     * null when the cell holds no usable number.
     *
     * Handles what real exports contain: currency symbols and codes ("1 200 ₽",
     * "$1,299.00", "450 сомони"), thousands separators in either convention,
     * and a comma decimal point ("1 234,56").
     */
    public function price(?string $raw): ?string
    {
        $value = $this->number($raw);

        // A negative price is a data error, not a discount — importing it would
        // let it satisfy any max_price filter and be quoted to a customer.
        if ($value === null || $value < 0) {
            return null;
        }

        return number_format($value, 2, '.', '');
    }

    /**
     * Stock as a whole number of units, or null when unknown.
     *
     * A column that says "в наличии"/"yes" rather than a count still answers the
     * question in_stock_only asks, so it maps to 1 — treating it as unknown
     * would drop exactly the products the customer is asking to see.
     */
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

        // Fractional stock ("2.5 м") floors rather than rounding up: claiming
        // 3 metres are on the shelf when 2.5 are is the expensive direction.
        return max(0, (int) floor($number));
    }

    /**
     * Any short text field (name, category, sku), trimmed, collapsed and capped
     * to what the string column can hold.
     */
    public function text(?string $raw, int $limit = 255): ?string
    {
        $value = $this->clean($raw);

        if ($value === null) {
            return null;
        }

        $value = trim((string) preg_replace('/\s+/u', ' ', $value));

        return $value === '' ? null : mb_substr($value, 0, $limit);
    }

    /**
     * Long text (description). Internal newlines are preserved — a description
     * is read by a human via the model, not matched as a key.
     */
    public function longText(?string $raw, int $limit = 5000): ?string
    {
        $value = $this->clean($raw);

        return $value === null ? null : mb_substr($value, 0, $limit);
    }

    /**
     * A product URL, or null unless it is plausibly one. Anything that isn't
     * http(s) is dropped rather than passed through: the agent may hand this
     * link to a customer, so a mis-mapped column must not become a link.
     */
    public function url(?string $raw): ?string
    {
        $value = $this->text($raw, 1024);

        if ($value === null) {
            return null;
        }

        // A bare "example.com/item" is a URL an owner would expect to work.
        if (! preg_match('#^https?://#i', $value) && preg_match('#^[\w-]+(\.[\w-]+)+/#', $value)) {
            $value = 'https://'.$value;
        }

        return filter_var($value, FILTER_VALIDATE_URL) !== false
            && preg_match('#^https?://#i', $value)
                ? $value
                : null;
    }

    /**
     * The numeric content of a cell as a float, or null.
     */
    protected function number(?string $raw): ?float
    {
        $value = $this->clean($raw);

        if ($value === null) {
            return null;
        }

        // Strip everything that isn't part of a number: currency symbols and
        // codes, unit suffixes, spaces used as thousands separators.
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

    /**
     * Resolve "," and "." into a single decimal point.
     *
     * Both present: the rightmost one is the decimal point and the other is
     * grouping, so "1.234,56" and "1,234.56" both mean 1234.56.
     *
     * Only one, repeated: it is grouping ("1.234.567").
     *
     * Only one, once: three trailing digits after a non-empty head reads as
     * grouping ("1 234" written "1,234"), anything else as a decimal point
     * ("1,5", "12.75"). This case is genuinely ambiguous — "1.500" is fifteen
     * hundred to a Russian export and one and a half to an American one — and
     * grouping is the reading that matches the price columns owners upload.
     */
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

    /**
     * Trim a cell and collapse the many ways a spreadsheet writes "empty" into
     * a real null.
     */
    protected function clean(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $value = trim(str_replace(["\u{00A0}", "\u{202F}"], ' ', $raw));

        return in_array(mb_strtolower($value), self::BLANKS, true) ? null : ($value === '' ? null : $value);
    }
}
