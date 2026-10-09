<?php

namespace Tests\Feature\Bi;

use App\Models\BiDashboard;
use App\Models\BiWidget;
use App\Models\User;
use App\Services\Bi\Ingestion\DatasetMaterializer;
use App\Services\Bi\Ingestion\SourceNormalizer;
use App\Services\Llm\LlmProviderInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Mockery;
use Tests\Support\BuildsBiFixtures;
use Tests\Support\DropsAnalyticalTables;
use Tests\TestCase;

class AnalystChatTest extends TestCase
{
    use BuildsBiFixtures, DropsAnalyticalTables, RefreshDatabase;

    private User $owner;

    private BiDashboard $board;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->owner = User::factory()->create();
        $source = $this->csvSource($this->owner, [
            ['Дата', 'Регион', 'Выручка'], ['01.03.2026', 'Душанбе', '1000'], ['05.03.2026', 'Худжанд', '2000'],
        ]);
        foreach (app(SourceNormalizer::class)->normalize($source) as $dataset) {
            app(DatasetMaterializer::class)->materialize($dataset);
        }
        $this->actingAs($this->owner)->post(route('bi.sources.analyze', $source))->assertRedirect();
        $this->board = BiDashboard::firstOrFail();
    }

    private function fakeTool(string $name, array $arguments): void
    {
        $fake = Mockery::mock(LlmProviderInterface::class);
        $fake->shouldReceive('generate')->once()->andReturn([
            'text' => '', 'input_tokens' => 1, 'output_tokens' => 1, 'finish_reason' => 'tool_calls',
            'tool_calls' => [['id' => 'call-1', 'name' => $name, 'arguments' => $arguments]],
        ]);
        $this->app->instance(LlmProviderInterface::class, $fake);
    }

    private function ask(string $question = 'Покажи данные'): TestResponse
    {
        return $this->actingAs($this->owner)->postJson(route('bi.chat.store', $this->board), ['message' => $question]);
    }

    public function test_answer_is_computed_by_query_runner_and_describes_the_calculation(): void
    {
        $this->fakeTool('answer_question', ['spec' => ['measures' => [['aggregate' => 'sum', 'column' => 'c2']]]]);
        $response = $this->ask('Сколько выручка?');
        $response->assertOk()->assertJsonPath('effects.0.kind', 'answer');
        $this->assertEquals(3000, $response->json('effects.0.rows.0.m0'));
        $this->assertStringContainsString('Выручка', $response->json('text'));
        $this->assertDatabaseHas('bi_chat_messages', ['user_id' => $this->owner->id, 'bi_dashboard_id' => $this->board->id, 'role' => 'assistant']);
    }

    public function test_add_update_remove_and_undo_are_scoped_to_this_board(): void
    {
        $count = BiWidget::count();
        $this->fakeTool('add_widget', ['title' => 'Регионы', 'type' => 'bar', 'spec' => [
            'dimensions' => [['column' => 'c1']], 'measures' => [['aggregate' => 'sum', 'column' => 'c2']],
        ]]);
        $added = $this->ask();
        $added->assertOk()->assertJsonPath('effects.0.kind', 'widget');
        $this->assertSame($count + 1, BiWidget::count());
        $widgetId = $added->json('effects.0.widget_id');

        $this->fakeTool('update_widget', ['widget_id' => $widgetId, 'title' => 'Новый график', 'type' => 'column']);
        $updated = $this->ask('Переименуй график');
        $updated->assertOk()->assertJsonPath('effects.0.widget.title', 'Новый график');
        $this->actingAs($this->owner)->postJson(route('bi.chat.undo', [$this->board, $updated->json('id')]))->assertOk();
        $this->assertSame('Регионы', BiWidget::findOrFail($widgetId)->title);

        $this->fakeTool('remove_widget', ['widget_id' => $widgetId]);
        $removed = $this->ask('Убери график');
        $removed->assertOk()->assertJsonPath('effects.0.kind', 'remove');
        $this->assertDatabaseMissing('bi_widgets', ['id' => $widgetId]);
        $this->actingAs($this->owner)->postJson(route('bi.chat.undo', [$this->board, $removed->json('id')]))->assertOk();
        $this->assertSame($count + 1, BiWidget::count());
    }

    public function test_filters_clarification_refusal_and_tenant_boundary(): void
    {
        $this->fakeTool('set_dashboard_filter', ['filters' => [['column' => 'c1', 'operator' => 'eq', 'value' => 'Душанбе']]]);
        $filtered = $this->ask('Оставь Душанбе');
        $filtered->assertOk()->assertJsonPath('effects.0.kind', 'filter');
        $this->assertSame('Душанбе', $this->board->fresh()->filters[(string) $this->board->bi_dataset_id][0]['value']);

        $other = User::factory()->create();
        $this->actingAs($other)->postJson(route('bi.chat.store', $this->board), ['message' => 'Чужие данные'])->assertForbidden();
        $this->actingAs($other)->postJson(route('bi.chat.undo', [$this->board, $filtered->json('id')]))->assertForbidden();

        $this->fakeTool('clear_filters', []);
        $this->ask('Очисти фильтры')->assertOk();
        $this->assertSame([], $this->board->fresh()->filters[(string) $this->board->bi_dataset_id]);

        $this->fakeTool('clarify', ['column_ids' => ['c0', 'c2']]);
        $this->ask('Какой столбец?')->assertOk()->assertJsonCount(2, 'effects.0.options');
        $this->fakeTool('refuse', []);
        $this->ask('Сколько на Марсе?')->assertOk()->assertJsonPath('text', __('bi.chat.cannot_answer'));
    }

    public function test_empty_tool_schemas_encode_properties_as_json_objects(): void
    {
        $fake = Mockery::mock(LlmProviderInterface::class);
        $fake->shouldReceive('generate')->once()->withArgs(function ($instruction, $turns, $options) {
            $tools = collect($options['tools'])->keyBy('name');

            return collect(['clear_filters', 'refuse'])->every(fn ($name) => is_object($tools[$name]['parameters']['properties'])
                && (array) $tools[$name]['parameters']['properties'] === []
            );
        })->andReturn([
            'text' => '', 'input_tokens' => 1, 'output_tokens' => 1, 'finish_reason' => 'tool_calls',
            'tool_calls' => [['id' => 'call-1', 'name' => 'clear_filters', 'arguments' => []]],
        ]);
        $this->app->instance(LlmProviderInterface::class, $fake);

        $this->ask('Очисти фильтры')->assertOk();
    }

    public function test_invalid_model_column_never_becomes_a_widget_or_an_answer(): void
    {
        $before = BiWidget::count();
        $this->fakeTool('add_widget', ['title' => 'Чужое', 'type' => 'bar', 'spec' => [
            'dimensions' => [['column' => 'c999']], 'measures' => [['aggregate' => 'count']],
        ]]);
        $this->ask()->assertOk()->assertJsonPath('text', __('bi.errors.unknown_column'));
        $this->assertSame($before, BiWidget::count());
    }
}
