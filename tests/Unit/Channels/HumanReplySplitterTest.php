<?php

namespace Tests\Unit\Channels;

use App\Services\Channels\HumanReplySplitter;
use PHPUnit\Framework\TestCase;

class HumanReplySplitterTest extends TestCase
{
    protected HumanReplySplitter $splitter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->splitter = new HumanReplySplitter;
    }

    protected function split(string $text, int $minLength = 200, int $maxMessages = 3): array
    {
        return $this->splitter->split($text, $minLength, $maxMessages);
    }

    public function test_short_reply_stays_a_single_message(): void
    {
        $this->assertSame(['Да, есть в наличии!'], $this->split('Да, есть в наличии!'));
    }

    public function test_reply_at_the_threshold_is_not_split(): void
    {
        $text = str_repeat('a. ', 60); // 180 chars, plenty of sentence boundaries
        $this->assertCount(1, $this->split(trim($text), minLength: 200));
    }

    public function test_long_reply_is_split_into_at_most_max_messages(): void
    {
        $paragraphs = [
            'Здравствуйте! Да, это платье есть в наличии в размерах S, M и L.',
            'Цена — 450 сомони. Доставка по Душанбе бесплатная при заказе от 300 сомони.',
            'Оплатить можно картой или наличными при получении курьеру.',
            'Скажите, какой размер вам подойдёт, и я оформлю заказ.',
        ];

        $messages = $this->split(implode("\n\n", $paragraphs));

        $this->assertCount(3, $messages);
        $this->assertSame(implode(' ', $paragraphs), implode(' ', array_map(
            fn ($m) => str_replace("\n\n", ' ', $m), $messages
        )));
    }

    public function test_never_splits_mid_sentence_or_mid_word(): void
    {
        $text = 'Первое предложение здесь. Второе предложение немного длиннее первого. '
            .'Третье предложение тоже достаточно длинное, чтобы набрать порог. '
            .'Четвёртое предложение завершает ответ полностью.';

        foreach ($this->split($text) as $message) {
            $this->assertMatchesRegularExpression('/[.!?…]$/u', $message, "Message ended mid-sentence: {$message}");
        }
    }

    public function test_a_single_long_sentence_is_never_split(): void
    {
        $text = str_repeat('очень длинное предложение без единой точки и переноса ', 12);

        $this->assertCount(1, $this->split(trim($text)));
    }

    public function test_splits_on_line_breaks_when_there_are_no_paragraphs(): void
    {
        $text = "Вот что у нас есть:\n- Платье синее — 450 сомони\n- Платье красное — 470 сомони\n"
            ."- Платье чёрное — 500 сомони\n- Платье белое — 480 сомони\n- Платье зелёное — 460 сомони\n"
            ."- Платье жёлтое — 440 сомони\nСкажите, какое из них отправить, и я оформлю заказ?";

        $messages = $this->split($text);

        $this->assertGreaterThan(1, count($messages));
        $this->assertLessThanOrEqual(3, count($messages));
        $this->assertStringContainsString('Платье синее', $messages[0]);
    }

    public function test_no_text_is_lost_or_duplicated(): void
    {
        $text = "Первый абзац с содержанием.\n\nВторой абзац подлиннее первого, с деталями.\n\n"
            ."Третий абзац про доставку и оплату заказа.\n\nЧетвёртый абзац с вопросом?";

        $messages = $this->split($text);
        $normalize = fn (string $s) => preg_replace('/\s+/u', ' ', trim($s));

        $this->assertSame($normalize($text), $normalize(implode(' ', $messages)));
    }

    public function test_messages_are_roughly_balanced_in_length(): void
    {
        // A short trailing "ok!" must not become its own message while the
        // first one carries everything else.
        $text = str_repeat("Довольно длинный абзац с полезной информацией о товаре.\n\n", 5).'Ок!';

        $messages = $this->split($text);
        $lengths = array_map('mb_strlen', $messages);

        $this->assertCount(3, $messages);
        $this->assertGreaterThan(max($lengths) * 0.3, min($lengths));
    }

    public function test_max_messages_of_one_disables_splitting(): void
    {
        $text = str_repeat("Абзац.\n\n", 40);

        $this->assertCount(1, $this->split($text, maxMessages: 1));
    }

    public function test_max_messages_of_two_is_respected(): void
    {
        $text = str_repeat("Абзац с некоторым содержанием внутри.\n\n", 8);

        $this->assertCount(2, $this->split(trim($text), maxMessages: 2));
    }

    public function test_decimals_and_abbreviations_do_not_create_a_boundary(): void
    {
        $text = 'Цена 1.5 тыс. сомони за штуку, это наша лучшая цена на сегодня и она держится '
            .'до конца недели, дальше поставщик поднимает стоимость примерно на десять процентов.';

        // No sentence-ending punctuation followed by whitespace except after "тыс." —
        // whatever the splitter does, it must not break inside "1.5".
        foreach ($this->split($text) as $message) {
            $this->assertStringNotContainsString('1.', mb_substr($message, -2));
        }
    }
}
