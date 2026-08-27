<?php

namespace Tests\Unit\Rag;

use App\Services\Rag\ProductQuestionDetector;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The detector decides whether the knowledge base can be skipped, so its errors
 * are asymmetric: a missed skip costs a few hundred milliseconds, a wrong skip
 * costs an answer. Both directions are covered, and the "must not skip" list is
 * deliberately the longer one.
 */
class ProductQuestionDetectorTest extends TestCase
{
    protected ProductQuestionDetector $detector;

    protected function setUp(): void
    {
        parent::setUp();

        $this->detector = new ProductQuestionDetector;
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function pureProductQuestions(): array
    {
        return [
            'ru price' => ['Сколько стоит Lenovo IdeaPad?'],
            'ru price short' => ['Какая цена на ноутбук?'],
            'ru budget' => ['Какие ноутбуки есть до 4000?'],
            'ru stock' => ['Ноутбук есть в наличии?'],
            'ru catalog' => ['Покажите ассортимент'],
            'en price' => ['How much is the Lenovo IdeaPad?'],
            'en stock' => ['Is it in stock?'],
            'en catalog' => ['Do you sell laptops?'],
            'tg price' => ['Ин чанд пул аст?'],
            'tg stock' => ['Ноутбук мавҷуд аст?'],
        ];
    }

    /**
     * Every one of these has a product or price word in it, and every one needs
     * the knowledge base anyway. This is the list that matters.
     *
     * @return array<string, array{0: string}>
     */
    public static function questionsThatStillNeedTheKnowledgeBase(): array
    {
        return [
            // The canonical way a naive keyword check goes wrong.
            'delivery cost' => ['Сколько стоит доставка?'],
            'delivery cost en' => ['How much is delivery?'],
            'warranty on a product' => ['Есть ли гарантия на ноутбук?'],
            'installments' => ['Можно купить ноутбук в рассрочку?'],
            'discount policy' => ['Какие у вас скидки на ноутбуки?'],
            'payment methods' => ['Ноутбук есть в наличии и можно оплатить картой?'],
            'opening hours' => ['Во сколько вы работаете?'],
            'address' => ['Где находится магазин?'],
            'returns' => ['Можно вернуть ноутбук?'],
            'course price' => ['Сколько стоит курс по программированию?'],
            'warranty en' => ['Is there a warranty on this laptop?'],
            'shipping en' => ['Do you ship to Dushanbe and how much?'],
            'tg delivery' => ['Расониш чанд пул аст?'],
            // Not a product question at all.
            'greeting' => ['Здравствуйте!'],
            'general knowledge' => ['А что такое SSD и чем он лучше HDD?'],
            'about the business' => ['Расскажите о вашей компании'],
            'empty' => ['   '],
        ];
    }

    #[DataProvider('pureProductQuestions')]
    public function test_a_pure_product_question_can_skip_retrieval(string $message): void
    {
        $this->assertTrue(
            $this->detector->isPureProductQuestion($message),
            "Expected [{$message}] to be skippable.",
        );
    }

    #[DataProvider('questionsThatStillNeedTheKnowledgeBase')]
    public function test_anything_that_might_need_the_knowledge_base_keeps_it(string $message): void
    {
        $this->assertFalse(
            $this->detector->isPureProductQuestion($message),
            "Expected [{$message}] to KEEP retrieval.",
        );
    }

    public function test_one_veto_word_anywhere_is_enough(): void
    {
        // Product-shaped from start to finish, right up to the last two words.
        $this->assertTrue($this->detector->isPureProductQuestion('Сколько стоит Lenovo IdeaPad?'));
        $this->assertFalse($this->detector->isPureProductQuestion('Сколько стоит Lenovo IdeaPad с доставкой?'));
    }

    public function test_a_long_message_is_assumed_to_ask_more_than_one_thing(): void
    {
        $long = 'Сколько стоит Lenovo IdeaPad, и ещё расскажите пожалуйста подробнее про то, '
            .'как у вас всё устроено и что вообще можно у вас приобрести прямо сейчас';

        $this->assertGreaterThan(120, mb_strlen($long));
        $this->assertFalse($this->detector->isPureProductQuestion($long));
    }

    public function test_matching_is_case_and_whitespace_insensitive(): void
    {
        $this->assertTrue($this->detector->isPureProductQuestion("  СКОЛЬКО   СТОИТ\nLenovo?  "));
    }
}
