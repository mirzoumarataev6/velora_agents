<?php

namespace App\Services\Bi\Dashboard;

use App\Models\BiChatMessage;
use App\Models\BiDashboard;
use App\Models\BiDataset;
use App\Models\BiWidget;
use App\Models\User;
use App\Services\Bi\Query\BiQueryException;
use App\Services\Bi\Query\QueryRunner;
use App\Services\Bi\Query\SpecValidator;
use App\Services\Llm\LlmProviderInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The LLM chooses an operation, never a table or a SQL string. Tool arguments
 * cross the same registry and shape validators as a human editor. The model's
 * prose is not trusted as an answer about numbers: only QueryRunner output is.
 */
class AnalystChat
{
    public function __construct(
        private readonly LlmProviderInterface $llm,
        private readonly SpecValidator $validator,
        private readonly QueryRunner $runner,
        private readonly WidgetSpecBuilder $specs,
        private readonly WidgetRenderer $renderer,
    ) {}

    public function reply(User $owner, BiDashboard $dashboard, string $question): BiChatMessage
    {
        $dataset = BiDataset::ownedBy($owner)->whereKey($dashboard->bi_dataset_id)->with('columns')->first();
        if (! $dataset) {
            throw ValidationException::withMessages(['message' => __('bi.chat.no_dataset')]);
        }

        $history = $dashboard->chatMessages()->where('user_id', $owner->id)->latest('id')->limit(12)->get()->reverse()->values();
        $turns = $history->map(fn (BiChatMessage $message) => ['role' => $message->role === 'assistant' ? 'model' : 'user', 'text' => $message->content])->all();
        $turns[] = ['role' => 'user', 'text' => $question];
        $userMessage = new BiChatMessage(['role' => 'user', 'content' => $question]);
        $userMessage->user_id = $owner->id;
        $userMessage->bi_dashboard_id = $dashboard->id;
        $userMessage->save();

        $columns = $dataset->columns->map(fn ($column) => [
            'id' => $column->physical_name, 'label' => $column->displayName(),
            'type' => $column->type->value, 'role' => $column->role->value,
            'sample_values' => array_slice($column->sample_values ?? [], 0, 3),
        ])->all();
        $instruction = 'You are an analyst for the owner. Reply in the language of the user. '
            .'Choose a tool for every numerical answer or dashboard change. Never produce SQL or invent values. '
            .'If a column is ambiguous, call clarify with candidate column ids. If impossible, call refuse. '
            .'The only dataset schema available is: '.json_encode($columns, JSON_UNESCAPED_UNICODE).'. '
            .'Current widgets: '.json_encode($dashboard->widgets()->get(['id', 'title', 'type', 'spec'])->toArray(), JSON_UNESCAPED_UNICODE).'. '
            .'QuerySpec uses dimensions:[{column,grain?}], measures:[{aggregate,column?}], filters:[{column,operator,value?}], sorts, limit. '
            .'Do not infer facts about other datasets.';

        try {
            $response = $this->llm->generate($instruction, $turns, [
                'temperature' => 0, 'max_output_tokens' => 700, 'tools' => $this->tools(),
            ]);
            $calls = array_slice($response['tool_calls'] ?? [], 0, 3);
            $effects = [];
            $undo = [];
            foreach ($calls as $call) {
                $effect = $this->execute($owner, $dashboard, $dataset, (string) ($call['name'] ?? ''), (array) ($call['arguments'] ?? []));
                $effects[] = $effect;
                if (isset($effect['undo'])) {
                    $undo[] = $effect['undo'];
                }
            }
            $content = $effects === [] ? __('bi.chat.cannot_answer') : implode("\n", array_column($effects, 'text'));
            $payload = ['effects' => $effects, 'undo' => $undo];
        } catch (BiQueryException $e) {
            $content = __($e->translationKey());
            $payload = ['effects' => []];
        } catch (ValidationException $e) {
            $content = collect($e->errors())->flatten()->first() ?? __('bi.chat.cannot_answer');
            $payload = ['effects' => []];
        } catch (Throwable $e) {
            report($e);
            $content = __('bi.chat.unavailable');
            $payload = ['effects' => []];
        }

        $message = new BiChatMessage(['role' => 'assistant', 'content' => $content, 'payload' => $payload]);
        $message->user_id = $owner->id;
        $message->bi_dashboard_id = $dashboard->id;
        $message->save();

        return $message;
    }

    /** @return array<int, array{name:string,description:string,parameters:array}> */
    private function tools(): array
    {
        // JSON Schema requires an object for `properties`; PHP's empty array
        // would serialize as `[]`, which OpenAI rejects with HTTP 400 before
        // the model can answer any BI question.
        $shape = fn (array $properties, array $required = []) => [
            'type' => 'object',
            'properties' => $properties === [] ? (object) [] : $properties,
            'required' => $required,
        ];
        $string = ['type' => 'string'];
        $integer = ['type' => 'integer'];
        $object = ['type' => 'object'];

        return [
            ['name' => 'answer_question', 'description' => 'Compute an answer with a validated QuerySpec. Never supply SQL.', 'parameters' => $shape(['spec' => $object], ['spec'])],
            ['name' => 'add_widget', 'description' => 'Add a valid chart to the current analysis.', 'parameters' => $shape(['title' => $string, 'type' => $string, 'spec' => $object], ['title', 'type', 'spec'])],
            ['name' => 'update_widget', 'description' => 'Change an existing widget chart type, measures, dimensions, filters or title.', 'parameters' => $shape(['widget_id' => $integer, 'title' => $string, 'type' => $string, 'spec' => $object, 'filters' => ['type' => 'array', 'items' => $object]], ['widget_id'])],
            ['name' => 'remove_widget', 'description' => 'Remove a widget from the current dashboard.', 'parameters' => $shape(['widget_id' => $integer], ['widget_id'])],
            ['name' => 'set_dashboard_filter', 'description' => 'Apply a category or date filter to all widgets.', 'parameters' => $shape(['filters' => ['type' => 'array', 'items' => $object]], ['filters'])],
            ['name' => 'clear_filters', 'description' => 'Clear all dashboard filters.', 'parameters' => $shape([])],
            ['name' => 'clarify', 'description' => 'Ask the user to choose among ambiguous columns. Supply column ids.', 'parameters' => $shape(['column_ids' => ['type' => 'array', 'items' => $string]], ['column_ids'])],
            ['name' => 'refuse', 'description' => 'Use if this cannot be answered from the available schema.', 'parameters' => $shape([])],
        ];
    }

    /** @return array<string, mixed> */
    private function execute(User $owner, BiDashboard $dashboard, BiDataset $dataset, string $name, array $args): array
    {
        if ($name === 'answer_question') {
            $spec = $this->validator->validate($dataset, (array) ($args['spec'] ?? []));
            $result = $this->runner->run($spec);
            $fields = collect($result->columns)->map(fn ($field) => trim(($field['aggregate'] ? __('bi.aggregates.'.$field['aggregate']).' ' : '').$field['label']))->implode(', ');
            $filters = collect($spec->filters)->map(fn ($filter) => $filter->column->displayName().' '.$filter->operator->value.' '.implode(', ', $filter->values))->implode('; ');
            $description = __('bi.chat.computed', ['fields' => $fields, 'filters' => $filters ?: __('bi.chat.all_rows')]);

            return ['kind' => 'answer', 'text' => $description, 'rows' => $result->rows, 'columns' => $result->columns, 'has_more' => $result->hasMore];
        }

        if ($name === 'clarify') {
            $ids = array_slice((array) ($args['column_ids'] ?? []), 0, 5);
            $options = $dataset->columns->whereIn('physical_name', $ids)->map(fn ($c) => ['id' => $c->physical_name, 'label' => $c->displayName()])->values()->all();
            if (count($options) < 2) {
                return ['kind' => 'refusal', 'text' => __('bi.chat.cannot_answer')];
            }

            return ['kind' => 'clarification', 'text' => __('bi.chat.clarify'), 'options' => $options];
        }

        if ($name === 'refuse') {
            return ['kind' => 'refusal', 'text' => __('bi.chat.cannot_answer')];
        }

        if ($name === 'set_dashboard_filter' || $name === 'clear_filters') {
            $filters = $name === 'clear_filters' ? [] : array_values((array) ($args['filters'] ?? []));
            $this->validator->validate($dataset, ['measures' => [['aggregate' => 'count']], 'filters' => $filters, 'limit' => 1]);
            $before = $dashboard->filters ?? [];
            $all = $before;
            $all[(string) $dataset->id] = $filters;
            $dashboard->forceFill(['filters' => $all])->save();

            return ['kind' => 'filter', 'text' => $name === 'clear_filters' ? __('bi.chat.filters_cleared') : __('bi.chat.filter_applied'), 'undo' => ['kind' => 'filters', 'filters' => $before]];
        }

        if ($name === 'add_widget') {
            if ($dashboard->isFull()) {
                throw ValidationException::withMessages(['widget' => __('bi.errors.too_many_widgets', ['limit' => BiDashboard::MAX_WIDGETS])]);
            }
            $widget = new BiWidget([
                'title' => mb_substr((string) ($args['title'] ?? __('bi.widgets.add')), 0, 120),
                'type' => WidgetType::tryFrom((string) ($args['type'] ?? '')) ?? throw ValidationException::withMessages(['type' => __('bi.validation.widget_type_unknown')]),
                'spec' => (array) ($args['spec'] ?? []), 'filters' => (array) ($args['filters'] ?? []),
            ]);
            $widget->user_id = $owner->id;
            $widget->bi_dashboard_id = $dashboard->id;
            $widget->bi_dataset_id = $dataset->id;
            $widget->forceFill(BiWidget::defaultSlot($dashboard, $widget->type));
            $widget->setRelation('dataset', $dataset);
            $this->specs->build($widget, $dashboard);
            $widget->save();

            return $this->widgetEffect($widget, $dashboard, __('bi.chat.widget_added'), ['kind' => 'delete', 'id' => $widget->id]);
        }

        if (in_array($name, ['update_widget', 'remove_widget'], true)) {
            $widget = $dashboard->widgets()->where('user_id', $owner->id)->whereKey((int) ($args['widget_id'] ?? 0))->firstOrFail();
            $before = $this->snapshot($widget);
            if ($name === 'remove_widget') {
                $widget->delete();

                return ['kind' => 'remove', 'text' => __('bi.chat.widget_removed'), 'widget_id' => $before['id'], 'undo' => ['kind' => 'recreate', 'widget' => $before]];
            }
            if (array_key_exists('title', $args)) {
                $widget->title = mb_substr((string) $args['title'], 0, 120);
            }
            if (array_key_exists('type', $args)) {
                $widget->type = WidgetType::tryFrom((string) $args['type']) ?? throw ValidationException::withMessages(['type' => __('bi.validation.widget_type_unknown')]);
            }
            if (array_key_exists('spec', $args)) {
                $widget->spec = (array) $args['spec'];
            }
            if (array_key_exists('filters', $args)) {
                $widget->filters = (array) $args['filters'];
            }
            $widget->setRelation('dataset', $dataset);
            $this->specs->build($widget, $dashboard);
            $widget->save();

            return $this->widgetEffect($widget, $dashboard, __('bi.chat.widget_updated'), ['kind' => 'restore', 'widget' => $before]);
        }

        return ['kind' => 'refusal', 'text' => __('bi.chat.cannot_answer')];
    }

    /** @return array<string, mixed> */
    private function widgetEffect(BiWidget $widget, BiDashboard $dashboard, string $text, array $undo): array
    {
        $item = $this->renderer->render($widget, $dashboard);

        return ['kind' => 'widget', 'text' => $text, 'widget_id' => $widget->id,
            'widget' => ['id' => $widget->id, 'title' => $widget->title, 'type' => $widget->type->value, 'dataset_id' => $widget->bi_dataset_id, 'spec' => $widget->spec, 'filters' => $widget->filters ?? [], 'slot' => $widget->slot()],
            'html' => view('bi.partials.widget-card', compact('item'))->render(), 'undo' => $undo];
    }

    /** @return array<string, mixed> */
    private function snapshot(BiWidget $widget): array
    {
        return $widget->only(['id', 'bi_dataset_id', 'title', 'type', 'spec', 'filters', 'options', 'grid_x', 'grid_y', 'grid_w', 'grid_h']);
    }

    /** Restore one recorded change, once, under a lock so two clicks cannot replay it. */
    public function undo(User $owner, BiDashboard $dashboard, BiChatMessage $message): array
    {
        return DB::transaction(function () use ($owner, $dashboard, $message): array {
            $locked = BiChatMessage::ownedBy($owner)->where('bi_dashboard_id', $dashboard->id)->whereKey($message->id)->lockForUpdate()->firstOrFail();
            if ($locked->undone_at) {
                throw ValidationException::withMessages(['message' => __('bi.chat.already_undone')]);
            }
            $undos = array_reverse($locked->payload['undo'] ?? []);
            foreach ($undos as $undo) {
                if ($undo['kind'] === 'filters') {
                    $dashboard->forceFill(['filters' => $undo['filters']])->save();
                }
                if ($undo['kind'] === 'delete') {
                    $dashboard->widgets()->where('user_id', $owner->id)->whereKey($undo['id'])->delete();
                }
                if (in_array($undo['kind'], ['restore', 'recreate'], true)) {
                    $snapshot = $undo['widget'];
                    $dataset = BiDataset::ownedBy($owner)->whereKey($snapshot['bi_dataset_id'])->firstOrFail();
                    $widget = $undo['kind'] === 'restore'
                        ? $dashboard->widgets()->where('user_id', $owner->id)->whereKey($snapshot['id'])->firstOrFail()
                        : new BiWidget;
                    $widget->fill(collect($snapshot)->except(['id', 'bi_dataset_id'])->all());
                    $widget->user_id = $owner->id;
                    $widget->bi_dashboard_id = $dashboard->id;
                    $widget->bi_dataset_id = $dataset->id;
                    $widget->save();
                }
            }
            $locked->forceFill(['undone_at' => now()])->save();

            return ['message' => __('bi.chat.undone'), 'refresh' => true];
        });
    }
}
