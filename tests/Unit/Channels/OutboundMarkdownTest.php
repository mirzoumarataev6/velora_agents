<?php

namespace Tests\Unit\Channels;

use App\Services\Channels\OutboundMarkdown;
use PHPUnit\Framework\TestCase;

/**
 * The conversion itself, per dialect.
 */
class OutboundMarkdownTest extends TestCase
{
    protected OutboundMarkdown $markdown;

    protected function setUp(): void
    {
        parent::setUp();

        $this->markdown = new OutboundMarkdown;
    }

    /** The reply from the bug report. */
    protected const REPLY = '**OMEN 16** — 14999 сомони, это *лучший* вариант для игр.';

    public function test_bold_becomes_a_tag_for_telegram(): void
    {
        $this->assertSame(
            '<b>OMEN 16</b> — 14999 сомони, это <i>лучший</i> вариант для игр.',
            $this->markdown->toTelegramHtml(self::REPLY),
        );
    }

    /**
     * WhatsApp bold is ONE asterisk, and the conversion must not then read its
     * own output as italic — which is exactly what a naive ordering does.
     */
    public function test_bold_becomes_a_single_asterisk_for_whatsapp(): void
    {
        $this->assertSame(
            '*OMEN 16* — 14999 сомони, это _лучший_ вариант для игр.',
            $this->markdown->toWhatsApp(self::REPLY),
        );
    }

    public function test_every_marker_is_removed_for_instagram(): void
    {
        $this->assertSame(
            'OMEN 16 — 14999 сомони, это лучший вариант для игр.',
            $this->markdown->toPlainText(self::REPLY),
        );
    }

    /**
     * The reason Telegram gets HTML rather than MarkdownV2: a product name is
     * full of characters MarkdownV2 would demand be escaped, and one missed
     * escape costs the whole message. HTML needs exactly three, always.
     */
    public function test_html_special_characters_in_product_data_are_escaped(): void
    {
        // Exactly the three Telegram's parser cares about. A double quote is
        // legal in HTML text content, so it is left readable — it only needs
        // escaping inside an attribute, which the link rule handles.
        $this->assertSame(
            '<b>15 15.6" FHD</b> &lt;i3&gt; за 4699 &amp; в наличии',
            $this->markdown->toTelegramHtml('**15 15.6" FHD** <i3> за 4699 & в наличии'),
        );
    }

    public function test_links_become_the_right_thing_per_channel(): void
    {
        $text = 'Смотрите [каталог](https://shop.tj/c?a=1&b=2)';

        $this->assertSame(
            'Смотрите <a href="https://shop.tj/c?a=1&amp;b=2">каталог</a>',
            $this->markdown->toTelegramHtml($text),
        );
        $this->assertSame('Смотрите каталог (https://shop.tj/c?a=1&b=2)', $this->markdown->toWhatsApp($text));
        $this->assertSame('Смотрите каталог (https://shop.tj/c?a=1&b=2)', $this->markdown->toPlainText($text));
    }

    /**
     * A list marker left as "* " is an unmatched italic marker on WhatsApp and
     * a literal asterisk everywhere else.
     */
    public function test_list_markers_and_headings_are_converted(): void
    {
        $text = "### Ноутбуки\n- **OMEN 16** — 14999\n* Macbook Pro 14 — 17999";

        $this->assertSame(
            "<b>Ноутбуки</b>\n• <b>OMEN 16</b> — 14999\n• Macbook Pro 14 — 17999",
            $this->markdown->toTelegramHtml($text),
        );
        $this->assertSame(
            "*Ноутбуки*\n• *OMEN 16* — 14999\n• Macbook Pro 14 — 17999",
            $this->markdown->toWhatsApp($text),
        );
        $this->assertSame(
            "Ноутбуки\n• OMEN 16 — 14999\n• Macbook Pro 14 — 17999",
            $this->markdown->toPlainText($text),
        );
    }

    /**
     * Underscores inside a word are part of the word. A product code must not
     * turn half a sentence italic.
     */
    public function test_underscores_inside_a_word_are_left_alone(): void
    {
        $text = 'Артикул OMEN_16_2024 и файл report_final_v2.pdf';

        $this->assertSame($text, $this->markdown->toTelegramHtml($text));
        $this->assertSame($text, $this->markdown->toWhatsApp($text));
    }

    /**
     * Formatting is a nicety; a control character reaching a customer is not.
     * Anything left unpaired resolves to nothing rather than to a sentinel.
     */
    public function test_unbalanced_markers_never_leak_sentinels(): void
    {
        foreach (['**OMEN 16 — 14999', 'цена ** и *', "\x02bold\x03 текст"] as $text) {
            foreach (['toTelegramHtml', 'toWhatsApp', 'toPlainText'] as $method) {
                $out = $this->markdown->{$method}($text);

                $this->assertStringNotContainsString("\x02", $out);
                $this->assertStringNotContainsString("\x03", $out);
            }
        }
    }

    public function test_plain_text_passes_through_untouched(): void
    {
        $text = 'Здравствуйте! У нас есть OMEN 16 за 14999 сомони.';

        $this->assertSame($text, $this->markdown->toTelegramHtml($text));
        $this->assertSame($text, $this->markdown->toWhatsApp($text));
        $this->assertSame($text, $this->markdown->toPlainText($text));
    }
}
