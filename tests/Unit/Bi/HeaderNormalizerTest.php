<?php

namespace Tests\Unit\Bi;

use App\Services\Bi\Ingestion\HeaderNormalizer;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

class HeaderNormalizerTest extends TestCase
{
    private HeaderNormalizer $headers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->headers = new HeaderNormalizer;
    }

    public function test_blank_headers_are_named_after_their_position(): void
    {
        $this->assertSame(
            ['Дата', 'Column 2', 'Выручка'],
            $this->headers->normalize(['Дата', '', 'Выручка'])
        );
    }

    /**
     * Two columns called "Сумма" is extremely common — a subtotal beside a total.
     * Collapsing them would lose a column and refusing the file would be absurd.
     */
    public function test_duplicate_headers_are_suffixed_rather_than_collapsed(): void
    {
        $this->assertSame(
            ['Сумма', 'Сумма (2)', 'Сумма (3)'],
            $this->headers->normalize(['Сумма', 'Сумма', 'Сумма'])
        );
    }

    public function test_duplicate_detection_ignores_case_and_surrounding_whitespace(): void
    {
        $this->assertSame(
            ['Сумма', 'сумма (2)'],
            $this->headers->normalize(['  Сумма  ', 'сумма'])
        );
    }

    public function test_trailing_blank_columns_are_dropped(): void
    {
        // A spreadsheet's "highest data column" often sits a few past the last real
        // one because something was typed there once and deleted. Carrying them
        // through would add empty `Column 41`, `Column 42` to every dataset.
        $this->assertSame(
            ['Дата', 'Выручка'],
            $this->headers->normalize(['Дата', 'Выручка', '', '', null])
        );
    }

    public function test_internal_whitespace_is_collapsed_and_non_breaking_spaces_normalised(): void
    {
        $this->assertSame(
            ['Дата заказа', 'Итого к оплате'],
            $this->headers->normalize(["Дата\n  заказа", "Итого\u{00A0}к\u{00A0}оплате"])
        );
    }

    public function test_a_date_used_as_a_column_heading_is_named_as_a_date(): void
    {
        // A pivoted report has dates for headings. Rendering the object rather than
        // an empty string at least names the column what the owner sees.
        $this->assertSame(
            ['Товар', '2026-03-01'],
            $this->headers->normalize(['Товар', new DateTimeImmutable('2026-03-01 00:00:00')])
        );
    }

    /**
     * The title-row case, which is what anything a human formatted looks like:
     * `A1: "Отчёт о продажах за март"`, a blank row, then the real headers.
     */
    public function test_a_single_cell_title_row_above_the_header_is_stepped_over(): void
    {
        $rows = [
            ['Отчёт о продажах за март', '', ''],
            ['', '', ''],
            ['Дата', 'Товар', 'Сумма'],
            ['01.03.2026', 'Куртка', '1500'],
        ];

        $this->assertSame(2, $this->headers->headerRowIndex($rows));
    }

    public function test_leading_blank_rows_are_stepped_over(): void
    {
        $rows = [
            ['', ''],
            ['Дата', 'Сумма'],
        ];

        $this->assertSame(1, $this->headers->headerRowIndex($rows));
    }

    /**
     * A genuinely single-column file — a list of SKUs or phone numbers — must not
     * have its header mistaken for a title and skipped.
     */
    public function test_a_single_column_file_keeps_its_own_header(): void
    {
        $rows = [
            ['Артикул'],
            ['SKU-001'],
            ['SKU-002'],
        ];

        $this->assertSame(0, $this->headers->headerRowIndex($rows));
    }

    public function test_an_entirely_blank_sheet_has_no_header(): void
    {
        $this->assertNull($this->headers->headerRowIndex([['', ''], [null, null]]));
        $this->assertNull($this->headers->headerRowIndex([]));
    }

    public function test_blank_row_detection_treats_whitespace_as_blank(): void
    {
        $this->assertTrue($this->headers->isBlankRow(['', '  ', null, "\u{00A0}"]));
        $this->assertFalse($this->headers->isBlankRow(['', '0']));
    }

    public function test_a_very_long_header_is_bounded(): void
    {
        // `bi_columns.source_name` is 512 characters and a merged cell can carry a
        // whole paragraph.
        $long = str_repeat('очень длинное название ', 60);

        $normalized = $this->headers->normalize([$long]);

        $this->assertLessThanOrEqual(480, mb_strlen($normalized[0]));
    }
}
