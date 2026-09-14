<?php

namespace Tests\Unit\Reply;

use App\Services\Reply\ReplySimilarity;
use PHPUnit\Framework\TestCase;

/**
 * The measurement behind "the bot said that already".
 */
class ReplySimilarityTest extends TestCase
{
    protected ReplySimilarity $similarity;

    protected function setUp(): void
    {
        parent::setUp();

        $this->similarity = new ReplySimilarity;
    }

    public function test_the_same_answer_reworded_still_reads_as_a_repeat(): void
    {
        $first = 'У нас есть Lenovo LOQ 15 за 14999 сомони, HP Victus 16 за 18500 и Acer Aspire 3 за 6200. '
            .'Если вас интересует какая-то определённая модель, напишите.';
        $second = 'Есть Lenovo LOQ 15 за 14999 сомони, HP Victus 16 за 18500, а также Acer Aspire 3 за 6200. '
            .'Напишите, если интересует определённая модель.';

        $this->assertTrue($this->similarity->isNearDuplicate($second, $first));
    }

    public function test_an_answer_to_the_actual_question_is_not_a_repeat(): void
    {
        $first = 'У нас есть Lenovo LOQ 15 за 14999 сомони, HP Victus 16 за 18500 и Acer Aspire 3 за 6200. '
            .'Если вас интересует какая-то определённая модель, напишите.';
        $second = 'Да, это всё, что подходит под ваш бюджет. Хотите, подберу что-нибудь чуть дороже?';

        $this->assertFalse($this->similarity->isNearDuplicate($second, $first));
    }

    /**
     * Containment, not symmetric similarity: the new reply is judged by how much
     * of IT is recycled, so adding one sentence to a repeat does not excuse it.
     */
    public function test_a_repeat_with_one_sentence_added_is_still_a_repeat(): void
    {
        $first = 'Есть три модели: Lenovo за 14999, HP за 18500 и Acer за 6200 сомони, все в наличии.';
        $second = 'Есть три модели: Lenovo за 14999, HP за 18500 и Acer за 6200 сомони, все в наличии. Спасибо.';

        $this->assertTrue($this->similarity->isNearDuplicate($second, $first));
    }

    public function test_short_replies_are_never_compared(): void
    {
        // Two people agreeing is not a bot looping, and short texts collide by
        // coincidence.
        $this->assertFalse($this->similarity->isNearDuplicate('Да, конечно.', 'Да, конечно.'));
    }

    /**
     * The reported loop: the framing reworded in Tajik, the three MacBooks
     * identical. Word containment scored it 0.57 — a miss.
     */
    public function test_the_same_listing_reworded_is_a_repeat_even_when_the_words_differ(): void
    {
        $first = "Маъзед, ман ноутбукҳое бо нархи 6000 то 7000 сомони наёфтам. Бо вуҷуди ин, ман баъзе модели хуби MacBook-ро мебинам:\n"
            ."1. **Macbook Neo 13** — 8199 сомонӣ (8GB RAM, 256GB).\n2. **Macbook Neo 13** — 8900 сомонӣ (8GB RAM, 512GB).\n"
            .'3. **Macbook Air 15** — 11999 сомонӣ (16GB RAM, 256GB).';
        $second = "Маъзед, дар нарх range васият (6000 то 7000 сомонӣ) барои ноутбукҳо ман моделе наёфтам. Ба сифати варианти наздик:\n"
            ."1. **Macbook Neo 13** — 8199 сомонӣ (8GB RAM, 256GB).\n2. **Macbook Neo 13** — 8900 сомонӣ (8GB RAM, 512GB).\n"
            .'3. **Macbook Air 15** — 11999 сомонӣ (16GB RAM, 256GB).';
        $customer = ['Ман хамин а 6000 то 7000 ноутбуки нагз мехарилакги'];

        $this->assertFalse($this->similarity->isNearDuplicate($second, $first));
        $this->assertTrue($this->similarity->repeatsListing($second, $first, $customer));
        $this->assertTrue($this->similarity->isRepeatOf($second, $first, $customer));
    }

    /** Quoting the customer's own budget back is not the assistant repeating itself. */
    public function test_the_customers_own_figures_do_not_make_a_repeat(): void
    {
        $first = 'В диапазоне 6000–7000 ничего нет, ближе всего 8199, 8900 и 11999 сомони.';
        $second = 'Между 6000 и 7000 сомони, к сожалению, ничего нет. Бюджет строгий или можно чуть больше, скажем 7500?';

        $this->assertFalse($this->similarity->repeatsListing($second, $first, ['от 6000 до 7000']));
    }

    /** "Which of these two is better?" answered about both is not a loop. */
    public function test_two_repeated_prices_are_a_comparison_not_a_listing(): void
    {
        $first = 'Есть Neo за 8199, Neo за 8900 и Air за 11999 сомони.';
        $second = 'Neo за 8900 лучше, чем за 8199: вдвое больше памяти.';

        $this->assertFalse($this->similarity->repeatsListing($second, $first));
    }

    public function test_containment_is_zero_when_nothing_is_shared(): void
    {
        $this->assertSame(0.0, $this->similarity->containment('совершенно другой ответ', 'ноутбуки мыши клавиатуры'));
        $this->assertSame(0.0, $this->similarity->containment('', 'что-нибудь'));
    }
}
