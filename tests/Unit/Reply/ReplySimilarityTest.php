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

    public function test_containment_is_zero_when_nothing_is_shared(): void
    {
        $this->assertSame(0.0, $this->similarity->containment('совершенно другой ответ', 'ноутбуки мыши клавиатуры'));
        $this->assertSame(0.0, $this->similarity->containment('', 'что-нибудь'));
    }
}
