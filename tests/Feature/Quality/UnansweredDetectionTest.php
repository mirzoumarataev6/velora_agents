<?php

namespace Tests\Feature\Quality;

use App\Models\Agent;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeSource;
use App\Services\AgentReplyService;
use App\Services\Llm\LlmProviderInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Does the "вопросы без ответа" widget actually catch a real gap?
 *
 * The scenario every one of these builds is the same one an owner would
 * recognise: the knowledge base has content, but not the content this customer
 * asked for, and the agent says so politely instead of inventing an answer.
 */
class UnansweredDetectionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * An agent whose knowledge base is populated — with something OTHER than
     * what the customer is about to ask for.
     *
     * The embedding decides which of the two detection signals this exercises.
     * The query vector is always [1, 0, 0] (see the mock below), so a chunk
     * sitting off-axis scores below the relevance bar and comes back marked
     * unconfident, while [1, 0, 0] itself comes back a confident match. Both
     * cases are a knowledge gap; only one of them is visible in the scores.
     *
     * @param  array<int, float>  $embedding
     */
    protected function agentWithKnowledge(array $embedding = [0.05, 0.9987, 0]): Agent
    {
        $agent = Agent::factory()->create(['behavior' => ['on_not_found' => 'apologize']]);
        $source = KnowledgeSource::factory()->for($agent)->create(['name' => 'О магазине']);

        $chunk = new KnowledgeChunk([
            'content' => 'Магазин в Душанбе, проспект Рудаки 15. Работаем с 9:00 до 20:00.',
            'embedding' => $embedding,
            'embedding_model' => 'test-embedding-model',
            'chunk_index' => 0,
        ]);
        $chunk->agent_id = $agent->id;
        $chunk->knowledge_source_id = $source->id;
        $chunk->save();

        return $agent;
    }

    /**
     * @return array<string, mixed> the assistant message meta
     */
    protected function askAndGetMeta(Agent $agent, string $question, string $reply): array
    {
        $this->mock(LlmProviderInterface::class, function ($mock) use ($reply) {
            $mock->shouldReceive('embed')->andReturn(['vector' => [1, 0, 0], 'input_tokens' => 3]);
            $mock->shouldReceive('embeddingModel')->andReturn('test-embedding-model');
            $mock->shouldReceive('generate')->andReturn([
                'text' => $reply,
                'input_tokens' => 10,
                'output_tokens' => 5,
                'finish_reason' => 'STOP',
            ]);
        });

        $conversation = $agent->conversations()->create(['status' => 'ai']);
        $result = $this->app->make(AgentReplyService::class)->reply($conversation, $question);

        return $result['assistant']->meta;
    }

    /**
     * THE REPRO. A store address in a city the knowledge base says nothing
     * about, answered with an apology rather than a wrong address.
     */
    public function test_an_apologetic_i_dont_know_about_an_unlisted_city_is_flagged(): void
    {
        $meta = $this->askAndGetMeta(
            $this->agentWithKnowledge(),
            'Подскажите, где находится ваш магазин в Худжанде?',
            'К сожалению, такой информации у меня нет. Уточните, пожалуйста, у менеджера.',
        );

        $this->assertTrue($meta['unanswered'], 'A polite "I do not know" left the gap invisible to the owner.');
        $this->assertSame('knowledge_miss', $meta['unanswered_reason']);
        $this->assertSame('Подскажите, где находится ваш магазин в Худжанде?', $meta['unanswered_question']);
    }

    /**
     * The half the old condition could never reach.
     *
     * `$retrieved !== []` was the test, and the vector store never returns
     * empty for a non-empty knowledge base — it hands back the best few marked
     * unconfident instead. So this exact turn, the commonest kind of gap there
     * is, scored a miss and was recorded as answered.
     */
    public function test_a_gap_is_caught_from_the_retrieval_scores_when_nothing_is_confident(): void
    {
        $meta = $this->askAndGetMeta(
            $this->agentWithKnowledge(),
            'Есть ли у вас доставка в Худжанд?',
            'К сожалению, такой информации у меня нет.',
        );

        $this->assertTrue($meta['unanswered']);
        $this->assertSame('retrieval', $meta['unanswered_detected_by']);
    }

    /**
     * And the half no score can reach: retrieval was confident, the chunk was
     * simply about a different city. Only the reply gives this away.
     */
    public function test_a_gap_is_caught_from_the_reply_when_retrieval_was_confident(): void
    {
        $meta = $this->askAndGetMeta(
            $this->agentWithKnowledge([1, 0, 0]),
            'Подскажите адрес вашего магазина в Худжанде?',
            'К сожалению, такой информации у меня нет — есть только магазин в Душанбе.',
        );

        $this->assertTrue($meta['unanswered']);
        $this->assertSame('reply_text', $meta['unanswered_detected_by']);
    }

    /**
     * The detector has to survive the model choosing its own words, which is
     * the whole reason it cannot key off the configured fallback string.
     *
     * @param  string  $reply  A natural paraphrase of "I don't know".
     */
    #[DataProvider('nonAnswerPhrasings')]
    public function test_natural_paraphrases_of_i_dont_know_are_all_caught(string $reply): void
    {
        $meta = $this->askAndGetMeta(
            $this->agentWithKnowledge([1, 0, 0]),
            'Подскажите адрес вашего магазина в Худжанде?',
            $reply,
        );

        $this->assertTrue($meta['unanswered'], "Not flagged: {$reply}");
    }

    /**
     * @return array<string, array{string}>
     */
    public static function nonAnswerPhrasings(): array
    {
        return [
            'plain ru' => ['К сожалению, я не знаю.'],
            'ru cannot say' => ['Извините, не могу сказать точно.'],
            'ru no data' => ['У меня нет данных по этому вопросу.'],
            'ru do not hold' => ['Не располагаю такой информацией.'],
            'ru struggle' => ['Затрудняюсь ответить на этот вопрос.'],
            'ru not specified' => ['Адрес не указан в моих материалах.'],
            'tajik' => ['Бубахшед, ман инро намедонам.'],
            'tajik no info' => ['Дар ин бора маълумот надорам.'],
            'english' => ["I'm sorry, I don't know the answer to that."],
            'english no info' => ["I don't have that information available."],
            'english not sure' => ["I'm not sure about that one."],
        ];
    }

    /**
     * The other half of the promise. A detector that fires on real answers is
     * worse than one that fires on nothing, because the owner stops reading the
     * widget either way and this one costs them the good rows too.
     *
     * @param  string  $reply  A genuine answer that must NOT be flagged.
     */
    #[DataProvider('genuineAnswers')]
    public function test_a_real_answer_is_never_flagged(string $reply): void
    {
        $meta = $this->askAndGetMeta(
            $this->agentWithKnowledge([1, 0, 0]),
            'Подскажите адрес вашего магазина?',
            $reply,
        );

        $this->assertFalse($meta['unanswered'], "Wrongly flagged: {$reply}");
    }

    /**
     * @return array<string, array{string}>
     */
    public static function genuineAnswers(): array
    {
        return [
            'address' => ['Наш магазин на проспекте Рудаки 15 в Душанбе, работаем с 9:00 до 20:00.'],
            'hedged but answered' => ['Обычно доставка занимает 2-3 дня, зависит от района.'],
            'english answer' => ['We are at 15 Rudaki Avenue, open from 9am to 8pm.'],
            'contains a negation' => ['В воскресенье мы не работаем, приходите с понедельника.'],
            // Says something is missing SOMEWHERE ELSE, then answers anyway.
            'missing there, answered here' => ['Точный адрес не указан на сайте, но мы в Худжанде на Ленина 45.'],
        ];
    }

    // -----------------------------------------------------------------
    // Answers that did not come from the knowledge base
    // -----------------------------------------------------------------

    /**
     * The knowledge base is not the agent's only source of truth.
     *
     * An owner types their address and opening hours into the agent's own
     * settings — `custom_system_prompt`, `rules_instructions`, `description` —
     * and those reach the model directly, never through the vector store. So a
     * perfectly correct answer to "где вы находитесь" scores nothing against
     * the uploaded documents BY DESIGN, and low retrieval confidence says
     * nothing at all about whether the customer was answered.
     *
     * This agent is the shape that produced the false positives: real business
     * facts in settings, a knowledge base holding something unrelated.
     */
    protected function agentAnsweringFromSettings(): Agent
    {
        $agent = Agent::factory()->create([
            'behavior' => ['on_not_found' => 'apologize'],
            'custom_system_prompt' => 'Мы находимся в Худжанде, ул. Ленина 45. '
                .'Часы работы: с 8:30 до 19:30, без выходных.',
        ]);

        $source = KnowledgeSource::factory()->for($agent)->create(['name' => 'Каталог тканей']);

        // Off-axis against the [1, 0, 0] query vector, so retrieval comes back
        // with nothing confident — exactly as it does in production when the
        // question is about business info the documents never covered.
        $chunk = new KnowledgeChunk([
            'content' => 'Ткани: габардин, бязь, сатин. Ширина рулона 150 см.',
            'embedding' => [0.05, 0.9987, 0],
            'embedding_model' => 'test-embedding-model',
            'chunk_index' => 0,
        ]);
        $chunk->agent_id = $agent->id;
        $chunk->knowledge_source_id = $source->id;
        $chunk->save();

        return $agent;
    }

    /** From the reported transcript, verbatim. */
    public function test_a_correct_address_answered_from_settings_is_not_flagged(): void
    {
        $meta = $this->askAndGetMeta(
            $this->agentAnsweringFromSettings(),
            'А где вы находитесь',
            'Мы находимся в Худжанде, ул. Ленина 45. Будем рады вас видеть!',
        );

        $this->assertFalse(
            $meta['unanswered'],
            'A correct, specific address was reported to the owner as a knowledge gap.',
        );
    }

    /** The other half of the transcript — asked in Tajik, answered correctly. */
    public function test_correct_working_hours_answered_from_settings_are_not_flagged(): void
    {
        $meta = $this->askAndGetMeta(
            $this->agentAnsweringFromSettings(),
            'А соати чан Шумо кушода',
            'Мо ҳар рӯз аз соати 8:30 то 19:30 кушодаем.',
        );

        $this->assertFalse(
            $meta['unanswered'],
            'Correct opening hours were reported to the owner as a knowledge gap.',
        );
    }

    /**
     * The fix must not be "stop flagging things". The same agent, the same
     * empty retrieval, asked something its settings genuinely do not cover —
     * and this one IS a gap the owner needs to see.
     */
    public function test_a_real_gap_on_the_same_agent_is_still_caught(): void
    {
        $meta = $this->askAndGetMeta(
            $this->agentAnsweringFromSettings(),
            'А доставка в Бохтар у вас есть?',
            'К сожалению, такой информации у меня нет.',
        );

        $this->assertTrue($meta['unanswered'], 'A real gap stopped being reported.');
        $this->assertSame('knowledge_miss', $meta['unanswered_reason']);
        $this->assertSame('retrieval', $meta['unanswered_detected_by']);
    }

    /**
     * The distinction the whole widget rests on, stated as one test: what
     * decides is whether the CUSTOMER got an answer, not where the answer came
     * from. Same agent, same unconfident retrieval, two different replies.
     */
    public function test_only_the_reply_decides_when_retrieval_found_nothing(): void
    {
        $answered = $this->askAndGetMeta(
            $this->agentAnsweringFromSettings(),
            'Подскажите, вы во сколько открываетесь?',
            'Открываемся в 8:30, закрываемся в 19:30.',
        );

        $notAnswered = $this->askAndGetMeta(
            $this->agentAnsweringFromSettings(),
            'Подскажите, вы во сколько открываетесь?',
            'Затрудняюсь ответить, уточните у менеджера.',
        );

        $this->assertFalse($answered['unanswered']);
        $this->assertTrue($notAnswered['unanswered']);
    }
}
