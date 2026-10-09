<?php

namespace Tests\Unit\Bi;

use App\Services\Bi\Ingestion\TypeCaster;
use App\Services\Bi\Schema\ColumnType;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The caster is used twice with the same rules — once by inference to decide a
 * column's type, once by materialization to fill it — so every case here is
 * simultaneously a test of what a column WILL be and of what it will then hold.
 */
class TypeCasterTest extends TestCase
{
    private TypeCaster $caster;

    protected function setUp(): void
    {
        parent::setUp();
        $this->caster = new TypeCaster;
    }

    /**
     * The separator problem: `1,500` is a thousand five hundred in one locale and
     * one and a half in another, and the file never says which.
     */
    #[DataProvider('numberProvider')]
    public function test_it_parses_numbers_written_the_way_people_write_them(string $raw, ?string $expected): void
    {
        $result = $this->caster->cast($raw, ColumnType::Decimal);

        if ($expected === null) {
            $this->assertTrue($result->failed, "[{$raw}] should not have parsed as a number.");

            return;
        }

        $this->assertFalse($result->failed, "[{$raw}] should have parsed as a number.");
        $this->assertSame($expected, $result->value);
    }

    public static function numberProvider(): array
    {
        return [
            'plain' => ['1500', '1500.000000'],
            'decimal point' => ['1500.25', '1500.250000'],
            'decimal comma' => ['1500,25', '1500.250000'],
            // Both separators present: the LAST one is the decimal point. This is
            // always unambiguous and both spellings must land on the same number.
            'anglo grouped' => ['1,500.25', '1500.250000'],
            'euro grouped' => ['1.500,25', '1500.250000'],
            // One separator followed by exactly three digits reads as grouping,
            // which is the reading that is right far more often.
            'comma thousands' => ['1,500', '1500.000000'],
            'dot thousands' => ['1.500', '1500.000000'],
            'repeated grouping' => ['1.234.567', '1234567.000000'],
            // One separator with one or two digits after it is a decimal point.
            'short fraction' => ['1,5', '1.500000'],
            'two decimals' => ['0.25', '0.250000'],
            'four decimals' => ['3,1416', '3.141600'],
            // Space-grouped, in all the spaces spreadsheets and PDFs use.
            'space grouped' => ['1 500,50', '1500.500000'],
            'nbsp grouped' => ["1\u{00A0}500,50", '1500.500000'],
            'narrow nbsp' => ["1\u{202F}500", '1500.000000'],
            'apostrophe grouped' => ["1'500", '1500.000000'],
            // Currency, top and tailed.
            'trailing currency' => ['1500 сомони', '1500.000000'],
            'leading currency' => ['$1,500.00', '1500.000000'],
            'rouble' => ['1500₽', '1500.000000'],
            // Accounting negatives, which every финансовый export produces.
            'parentheses negative' => ['(1 500)', '-1500.000000'],
            'minus' => ['-250,50', '-250.500000'],
            'unicode minus' => ['−250', '-250.000000'],
            // Not numbers, and must not become 0 — a silent zero in a revenue
            // column is the exact failure this whole class exists to prevent.
            'text' => ['по запросу', null],
            'trailing text' => ['1500 units sold', null],
            'empty separators' => ['1..2', null],
            'bare separator' => ['.', null],
            'phone' => ['+992 90 011 22 33', null],
        ];
    }

    public function test_recognised_placeholders_are_blank_rather_than_failures(): void
    {
        // The distinction matters: a blank is a fact about the file, a failure is a
        // value we could not keep. Only the second is counted and shown back.
        foreach (['', '-', '—', 'н/д', 'нд', 'не указано', 'N/A', '#DIV/0!', 'нет данных'] as $placeholder) {
            $result = $this->caster->cast($placeholder, ColumnType::Decimal);

            $this->assertTrue($result->blank, "[{$placeholder}] should be treated as blank.");
            $this->assertFalse($result->failed);
            $this->assertNull($result->value);
        }

        // Something that is NOT a recognized placeholder stays a counted failure,
        // or the count would stop meaning anything.
        $this->assertTrue($this->caster->cast('уточнить у менеджера', ColumnType::Decimal)->failed);
    }

    public function test_a_fractional_value_is_refused_by_an_integer_column_rather_than_truncated(): void
    {
        // Truncating would make a column quietly under-report. It is counted
        // instead, so the owner is told the column did not fit.
        $this->assertTrue($this->caster->cast('12,7', ColumnType::Integer)->failed);

        // A float that is really a whole number is fine — spreadsheets store 3 as
        // 3.0 constantly.
        $this->assertSame(3, $this->caster->cast(3.0, ColumnType::Integer)->value);
        $this->assertSame(12, $this->caster->cast('12', ColumnType::Integer)->value);
    }

    public function test_an_oversized_number_is_refused_rather_than_silently_changed(): void
    {
        // A 25-digit "number" is a barcode or an account, and storing it in a
        // BIGINT would change it.
        $this->assertTrue($this->caster->cast('1234567890123456789012345', ColumnType::Integer)->failed);
        // Past DECIMAL(20,6)'s 14 integer digits.
        $this->assertTrue($this->caster->cast('999999999999999', ColumnType::Decimal)->failed);
    }

    /**
     * 0 and 1 must NOT make a column boolean — but once an owner has said a column
     * is a flag, its zeroes and ones are what they meant.
     */
    public function test_boolean_accepts_words_and_digits_but_only_words_are_evidence(): void
    {
        foreach (['да', 'ДА', 'yes', 'true', 'ҳа', 'есть'] as $truthy) {
            $this->assertSame(1, $this->caster->cast($truthy, ColumnType::Boolean)->value, $truthy);
        }

        foreach (['нет', 'no', 'false', 'НЕТ'] as $falsy) {
            $this->assertSame(0, $this->caster->cast($falsy, ColumnType::Boolean)->value, $falsy);
        }

        $this->assertSame(1, $this->caster->cast('1', ColumnType::Boolean)->value);
        $this->assertSame(0, $this->caster->cast('0', ColumnType::Boolean)->value);
        $this->assertTrue($this->caster->cast('возможно', ColumnType::Boolean)->failed);

        // 'нест' is a recognized BLANK rather than a false: in Tajik it carries
        // both "no" and "there is none", and storing an unknown as a definite "no"
        // is the worse of the two mistakes.
        $this->assertTrue($this->caster->cast('нест', ColumnType::Boolean)->blank);
    }

    /**
     * Identifier-shaped values must stay text. Both of these would parse as
     * numbers under a naive reading, and both would be CHANGED by doing so — the
     * phone loses its "+", the account number loses its leading zero.
     */
    public function test_identifier_shaped_values_are_not_numbers(): void
    {
        foreach (['+992 90 011 22 33', '0912345678', '00123', '+1500'] as $identifier) {
            $this->assertTrue(
                $this->caster->cast($identifier, ColumnType::Decimal)->failed,
                "[{$identifier}] must not be read as a number."
            );
        }

        // A leading zero before a decimal point is still an ordinary number.
        $this->assertSame('0.500000', $this->caster->cast('0,5', ColumnType::Decimal)->value);
        $this->assertSame('0.000000', $this->caster->cast('0', ColumnType::Decimal)->value);
    }

    /**
     * A spreadsheet date cell arrives already parsed. Keeping the object rather
     * than a formatted string is what makes an Excel date unambiguous.
     */
    public function test_a_real_date_object_casts_without_any_format_guessing(): void
    {
        $date = new DateTimeImmutable('2026-03-15 14:30:00');

        $this->assertSame('2026-03-15', $this->caster->cast($date, ColumnType::Date)->value);
        $this->assertSame('2026-03-15 14:30:00', $this->caster->cast($date, ColumnType::DateTime)->value);
        // A date in a column the owner re-typed as a number is a real rejection.
        $this->assertTrue($this->caster->cast($date, ColumnType::Decimal)->failed);
    }

    /**
     * The same three numbers, read two ways. Which one you get is decided by the
     * STORED pattern and by nothing about the value — which is the property that
     * makes a whole column consistent instead of parsed row by row.
     */
    public function test_a_text_date_casts_through_its_stored_format(): void
    {
        $this->assertSame(
            '2026-04-03',
            $this->caster->cast('03.04.2026', ColumnType::Date, ['date_format' => 'd.m.Y'])->value
        );

        $this->assertSame(
            '2026-03-04',
            $this->caster->cast('03/04/2026', ColumnType::Date, ['date_format' => 'm/d/Y'])->value
        );
    }

    /**
     * A written-out month must cast through the same folding the voter used to
     * RECOGNIZE the column. If the two disagreed, inference would promise a date
     * column whose every row then failed to cast — the most confusing outcome
     * ingestion can produce, because the type looks right and the data is empty.
     */
    public function test_a_written_out_month_casts_through_the_shared_folding(): void
    {
        $format = ['date_format' => 'd.m.Y'];

        $this->assertSame('2026-03-15', $this->caster->cast('15 марта 2026', ColumnType::Date, $format)->value);
        $this->assertSame('2026-03-15', $this->caster->cast('15 марти 2026', ColumnType::Date, $format)->value);
        $this->assertSame('2026-03-15', $this->caster->cast('15 March 2026', ColumnType::Date, $format)->value);
    }

    /**
     * The ISO fallback must try the time-bearing spellings FIRST. `Y-m-d` matches
     * the first ten characters of an ISO timestamp, so the wrong order here would
     * silently throw away the clock on every .xlsx import.
     */
    public function test_the_iso_fallback_keeps_the_time(): void
    {
        $this->assertSame(
            '2026-03-15 14:30:00',
            $this->caster->cast('2026-03-15T14:30:00', ColumnType::DateTime)->value
        );
        $this->assertSame(
            '2026-03-15 14:30:00',
            $this->caster->cast('2026-03-15 14:30:00', ColumnType::DateTime)->value
        );
        $this->assertSame('2026-03-15', $this->caster->cast('2026-03-15', ColumnType::Date)->value);
    }

    public function test_an_impossible_date_is_refused_rather_than_rolled_forward(): void
    {
        // PHP would happily turn the 31st of February into the 3rd of March. A
        // column of malformed dates must fail loudly instead of being shifted.
        $this->assertTrue($this->caster->cast('31.02.2026', ColumnType::Date, ['date_format' => 'd.m.Y'])->failed);
    }

    public function test_a_number_in_a_text_column_is_not_rendered_in_exponent_notation(): void
    {
        $this->assertSame('1500', $this->caster->cast(1500.0, ColumnType::Text)->value);
        $this->assertSame('1000000000000000000', $this->caster->cast(1000000000000000000, ColumnType::Text)->value);
        $this->assertSame('0.5', $this->caster->cast(0.5, ColumnType::Text)->value);
    }
}
