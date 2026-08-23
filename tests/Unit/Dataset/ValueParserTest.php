<?php

namespace Tests\Unit\Dataset;

use App\Services\Dataset\ValueParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The price and stock columns owners actually upload — currency symbols,
 * thousands separators in both conventions, and words where a number was
 * expected. Everything unparseable must become null rather than a wrong number:
 * a price the agent quotes to a customer is worse wrong than absent.
 */
class ValueParserTest extends TestCase
{
    protected ValueParser $parser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->parser = new ValueParser;
    }

    /**
     * @return array<string, array{0: ?string, 1: ?string}>
     */
    public static function prices(): array
    {
        return [
            'plain integer' => ['1200', '1200.00'],
            'decimal point' => ['1200.50', '1200.50'],
            'decimal comma' => ['1200,50', '1200.50'],
            'space grouping' => ['1 200', '1200.00'],
            'space grouping with decimal comma' => ['1 200,50', '1200.50'],
            'non-breaking space grouping' => ["1\u{00A0}200,50", '1200.50'],
            'ru currency suffix' => ['1 200 ₽', '1200.00'],
            'currency code suffix' => ['1200 руб.', '1200.00'],
            'usd prefix' => ['$1,299.00', '1299.00'],
            'en grouping only' => ['1,234', '1234.00'],
            'ru grouping only' => ['1.234', '1234.00'],
            'both separators, comma decimal' => ['1.234,56', '1234.56'],
            'both separators, dot decimal' => ['1,234.56', '1234.56'],
            'repeated grouping' => ['1.234.567', '1234567.00'],
            'small decimal' => ['0,5', '0.50'],
            'zero' => ['0', '0.00'],

            'empty' => ['', null],
            'null' => [null, null],
            'dash placeholder' => ['—', null],
            'words' => ['по запросу', null],
            'negative' => ['-50', null],
            'no digits at all' => ['₽', null],
        ];
    }

    #[DataProvider('prices')]
    public function test_price_parsing(?string $input, ?string $expected): void
    {
        $this->assertSame($expected, $this->parser->price($input));
    }

    /**
     * @return array<string, array{0: ?string, 1: ?int}>
     */
    public static function stocks(): array
    {
        return [
            'count' => ['5', 5],
            'zero' => ['0', 0],
            'grouped count' => ['1 200', 1200],
            'fractional floors' => ['2,5', 2],
            'yes' => ['да', 1],
            'in stock ru' => ['в наличии', 1],
            'in stock en' => ['In Stock', 1],
            'plus' => ['+', 1],
            'no' => ['нет', 0],
            'out of stock' => ['out of stock', 0],
            'unknown word' => ['скоро', null],
            'empty' => ['', null],
            'null' => [null, null],
            'negative clamps to zero' => ['-3', 0],
        ];
    }

    #[DataProvider('stocks')]
    public function test_stock_parsing(?string $input, ?int $expected): void
    {
        $this->assertSame($expected, $this->parser->stock($input));
    }

    public function test_text_is_trimmed_collapsed_and_capped(): void
    {
        $this->assertSame('Куртка зимняя', $this->parser->text("  Куртка   зимняя \n"));
        $this->assertNull($this->parser->text('   '));
        $this->assertNull($this->parser->text('-'));
        $this->assertSame(10, mb_strlen((string) $this->parser->text(str_repeat('я', 50), 10)));
    }

    public function test_only_plausible_urls_survive(): void
    {
        $this->assertSame('https://shop.test/item/1', $this->parser->url('https://shop.test/item/1'));
        $this->assertSame('http://shop.test/x', $this->parser->url('http://shop.test/x'));
        // A bare domain-with-path is what owners paste; it is completed, not dropped.
        $this->assertSame('https://shop.test/item', $this->parser->url('shop.test/item'));

        // A mis-mapped column must not become a link the agent hands a customer.
        $this->assertNull($this->parser->url('Куртка зимняя'));
        $this->assertNull($this->parser->url('javascript:alert(1)'));
        $this->assertNull($this->parser->url(''));
    }
}
