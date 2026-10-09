{{--
    THE SCHEMA DRAWER. Everything the module decided about a dataset, and the
    owner's power to decide otherwise.

    A SLIDE-OVER rather than a modal, and that is a considered choice: correcting a
    type is work done AGAINST the list — "this is the Продажи sheet, not Возвраты" —
    so the list has to stay visible at the edge. A centred modal over a dimmed page
    makes the drawer feel like an interruption; this reads as a panel you opened.

    The order of the contents is the argument of the whole screen:
      1. WHAT WENT WRONG OR WAS ASSUMED, first, because it is why the owner is here.
      2. THE COLUMNS, dense, every judgement editable in place.
      3. THE PREVIEW, last, as the evidence they check their corrections against.

    Reads the parent biSources() scope: `schema`, `edits`, `savingSchema`.
--}}
<div x-show="schemaOpen" x-cloak class="fixed inset-0 z-50 flex justify-end"
    role="dialog" aria-modal="true" :aria-label="schema?.name">

    <div class="modal-scrim absolute inset-0" @click="closeSchema()"
        x-transition.opacity.duration.200ms></div>

    <div class="relative flex h-full w-full max-w-5xl flex-col bg-surface shadow-2xl dark:bg-night-card"
        x-transition:enter="transition ease-out duration-250"
        x-transition:enter-start="translate-x-full"
        x-transition:enter-end="translate-x-0"
        x-transition:leave="transition ease-in duration-200"
        x-transition:leave-start="translate-x-0"
        x-transition:leave-end="translate-x-full"
        @keydown.escape.window="closeSchema()">

        <template x-if="schemaLoading">
            <div class="flex flex-1 items-center justify-center">
                <span class="spinner h-6 w-6"></span>
            </div>
        </template>

        <template x-if="schema">
            <div class="flex min-h-0 flex-1 flex-col">
                {{-- HEADER --}}
                <header class="rule-b shrink-0 px-6 py-4">
                    <div class="flex items-start gap-3">
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2">
                                <h2 class="truncate text-base font-semibold" x-text="schema.name"></h2>
                                <span class="badge"
                                    :class="schema.status === 'ready' ? 'badge-success' : (schema.status === 'failed' ? 'badge-danger' : 'badge-warning')"
                                    x-text="schema.status_label"></span>
                            </div>
                            <p class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-xs text-muted dark:text-night-muted">
                                <template x-if="schema.kind === 'connection'">
                                    <span class="font-mono" x-text="schema.external_table"></span>
                                </template>
                                <template x-if="schema.kind !== 'connection'">
                                    <span class="tabular-nums" x-text="`${formatNumber(schema.row_count)} ${@js(__('bi.data.rows'))}`"></span>
                                </template>
                                <span aria-hidden="true">·</span>
                                <span class="tabular-nums" x-text="`${schema.column_count} ${@js(__('bi.data.columns'))}`"></span>
                                <template x-if="schema.sheet_name">
                                    <span><span aria-hidden="true">·</span> <span x-text="`${@js(__('bi.data.sheet'))} ${schema.sheet_name}`"></span></span>
                                </template>
                            </p>
                        </div>

                        <button type="button" @click="closeSchema()" class="btn btn-ghost btn-sm shrink-0"
                            aria-label="{{ __('bi.schema.close') }}" title="{{ __('bi.schema.close') }}">
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 6l12 12M18 6L6 18"/></svg>
                        </button>
                    </div>
                    <p class="mt-2 text-xs leading-relaxed text-muted dark:text-night-muted"
                        data-i18n="bi.schema.subtitle">{{ __('bi.schema.subtitle') }}</p>
                </header>

                {{-- SCROLLING BODY --}}
                <div class="min-h-0 flex-1 overflow-y-auto px-6 py-4">

                    {{-- 1. THE DISCLOSURES. A dataset that dropped rows, assumed a date
                            order or rejected values says so here, at the top, before
                            anything else. This block is the entire reason the honest
                            reporting in ingestion is worth doing. --}}
                    <div class="space-y-2">
                        <template x-if="schema.error">
                            <div class="rounded-xl bg-danger/8 px-3.5 py-3 text-xs">
                                <p class="font-medium text-danger" x-text="schema.error"></p>
                                <p x-show="schema.error_detail" class="mt-1 break-words text-muted dark:text-night-muted"
                                    x-text="schema.error_detail"></p>
                            </div>
                        </template>

                        <template x-if="schema.truncated">
                            <p class="rounded-xl bg-warning/10 px-3.5 py-2.5 text-xs text-warning dark:text-warning-light">
                                {{ __('bi.notes.truncated', ['limit' => number_format((int) config('agent.bi.ingest.max_rows'), 0, '.', ' ')]) }}
                            </p>
                        </template>

                        <template x-if="schema.skipped_reasons.length">
                            <div class="rounded-xl bg-sunk px-3.5 py-2.5 text-xs text-muted dark:bg-white/[0.04] dark:text-night-muted">
                                <p class="font-medium" data-i18n="bi.schema.skipped_title">{{ __('bi.schema.skipped_title') }}</p>
                                <ul class="mt-1 space-y-0.5">
                                    <template x-for="reason in schema.skipped_reasons" :key="reason">
                                        <li x-text="reason"></li>
                                    </template>
                                </ul>
                            </div>
                        </template>

                        <template x-if="ambiguousDates().length">
                            <p class="rounded-xl bg-warning/10 px-3.5 py-2.5 text-xs text-warning dark:text-warning-light">
                                <span data-i18n="bi.schema.date_ambiguous">{{ __('bi.schema.date_ambiguous') }}</span>
                                <span class="font-medium" x-text="ambiguousDates().join(', ')"></span>
                            </p>
                        </template>

                        <template x-if="schema.kind === 'connection'">
                            <p class="rounded-xl bg-brand-circle px-3.5 py-2.5 text-xs text-brand-strong dark:bg-brand/10"
                                data-i18n="bi.schema.live_notice">{{ __('bi.schema.live_notice') }}</p>
                        </template>

                        <template x-if="rebuilding">
                            <p class="flex items-center gap-2 rounded-xl bg-warning/10 px-3.5 py-2.5 text-xs text-warning dark:text-warning-light">
                                <span class="spinner h-3.5 w-3.5"></span>
                                <span data-i18n="bi.schema.rebuilding">{{ __('bi.schema.rebuilding') }}</span>
                            </p>
                        </template>
                    </div>

                    {{-- 2. THE COLUMNS. A table rather than cards: this is reference
                            data an owner scans down, and thirty cards would be thirty
                            times the vertical travel to compare two types. --}}
                    <div class="mt-4 overflow-x-auto">
                        <table class="table-flat w-full min-w-[50rem]">
                            <thead>
                                <tr>
                                    <th class="py-2.5 pl-1 pr-3 text-left font-medium">{{ __('bi.schema.column') }}</th>
                                    <th class="px-3 py-2.5 text-left font-medium">{{ __('bi.schema.type') }}</th>
                                    <th class="px-3 py-2.5 text-left font-medium">{{ __('bi.schema.role') }}</th>
                                    <th class="px-3 py-2.5 text-left font-medium">{{ __('bi.schema.label') }}</th>
                                    <th class="py-2.5 pl-3 pr-1 text-left font-medium">{{ __('bi.schema.stats') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <template x-for="column in schema.columns" :key="column.id">
                                    <tr class="rule align-top">
                                        {{-- The owner's own name for the column, and the
                                             sample values that tell them which one it is
                                             faster than the name does. --}}
                                        <td class="max-w-[15rem] py-3 pl-1 pr-3">
                                            <p class="truncate text-sm font-medium" x-text="column.source_name"
                                                :title="column.source_name"></p>
                                            <p x-show="column.sample_values.length"
                                                class="mt-0.5 truncate text-xs text-muted dark:text-night-muted"
                                                x-text="column.sample_values.join(' · ')"
                                                :title="column.sample_values.join(' · ')"></p>
                                            <p x-show="column.external_type"
                                                class="mt-0.5 font-mono text-[11px] text-muted dark:text-night-muted"
                                                x-text="`${@js(__('bi.schema.external_type', ['type' => '']))}${column.external_type}`"></p>
                                        </td>

                                        {{-- TYPE, plus the date pattern when the type is
                                             temporal. The pattern is offered as an EXAMPLE
                                             DATE (`25.12.2026`) rather than as `d.m.Y`,
                                             because asking an owner to read PHP format
                                             characters to resolve день/месяц would defeat
                                             the point of offering the choice at all. --}}
                                        <td class="px-3 py-3">
                                            <select class="field w-full min-w-[9rem] px-2.5 py-1.5 text-sm"
                                                :value="edit(column, 'type')"
                                                @change="setEdit(column, 'type', $event.target.value)">
                                                <template x-for="option in schema.options.types" :key="option.value">
                                                    <option :value="option.value" x-text="option.label"
                                                        :selected="edit(column, 'type') === option.value"></option>
                                                </template>
                                            </select>

                                            <select x-show="isTemporal(column)" x-cloak
                                                class="field mt-1.5 w-full min-w-[9rem] px-2.5 py-1.5 text-xs"
                                                :value="edit(column, 'date_format') || ''"
                                                @change="setEdit(column, 'date_format', $event.target.value)">
                                                <template x-for="option in schema.options.date_formats" :key="option.value">
                                                    <option :value="option.value" x-text="option.label"
                                                        :selected="edit(column, 'date_format') === option.value"></option>
                                                </template>
                                            </select>

                                            {{-- "You changed this", with the original
                                                 offered back in one click. Inference's
                                                 proposal is kept on the row precisely so
                                                 this is possible. --}}
                                            <button type="button" x-show="isOverridden(column)" x-cloak
                                                @click="resetType(column)"
                                                class="mt-1.5 text-[11px] text-brand-strong underline-offset-2 hover:underline"
                                                x-text="@js(__('bi.schema.reset_type', ['type' => ':t'])).replace(':t', typeLabel(column.inferred_type))"></button>
                                        </td>

                                        <td class="px-3 py-3">
                                            <select class="field w-full min-w-[8rem] px-2.5 py-1.5 text-sm"
                                                :value="edit(column, 'role')"
                                                @change="setEdit(column, 'role', $event.target.value)">
                                                <template x-for="option in schema.options.roles" :key="option.value">
                                                    <option :value="option.value" x-text="option.label"
                                                        :selected="edit(column, 'role') === option.value"></option>
                                                </template>
                                            </select>
                                        </td>

                                        {{-- The label a chart shows, and the description
                                             that later feeds the AI analyst's schema
                                             context — the most effective fix there is when
                                             it keeps choosing the wrong revenue column. --}}
                                        <td class="px-3 py-3">
                                            <input type="text" class="field w-full min-w-[10rem] px-2.5 py-1.5 text-sm"
                                                :placeholder="column.source_name"
                                                :value="edit(column, 'label') ?? ''"
                                                @input="setEdit(column, 'label', $event.target.value)" />
                                            <input type="text" class="field mt-1.5 w-full min-w-[10rem] px-2.5 py-1.5 text-xs"
                                                placeholder="{{ __('bi.schema.description_placeholder') }}"
                                                :value="edit(column, 'description') ?? ''"
                                                @input="setEdit(column, 'description', $event.target.value)" />
                                        </td>

                                        {{-- What inference MEASURED. Not decoration: the
                                             rejected-value count and its examples are how
                                             an owner discovers that a column they think is
                                             numeric has 200 rows saying "по запросу". --}}
                                        <td class="w-[11rem] min-w-[11rem] py-3 pl-3 pr-1">
                                            <div class="flex flex-wrap items-center gap-1">
                                                <template x-if="column.distinct_count !== null">
                                                    <span class="badge badge-neutral tabular-nums"
                                                        x-text="column.distinct_exact
                                                            ? @js(__('bi.schema.distinct', ['count' => ':n'])).replace(':n', formatNumber(column.distinct_count))
                                                            : @js(__('bi.schema.distinct_many', ['count' => ':n'])).replace(':n', formatNumber(column.distinct_count))"></span>
                                                </template>
                                                <span x-show="column.blank_count > 0" class="badge badge-neutral tabular-nums"
                                                    x-text="@js(__('bi.schema.blanks', ['count' => ':n'])).replace(':n', formatNumber(column.blank_count))"></span>
                                                <span x-show="column.cast_failures > 0" class="badge badge-warning tabular-nums"
                                                    :title="column.cast_failure_samples.join(' · ')"
                                                    x-text="@js(__('bi.schema.failures', ['count' => ':n'])).replace(':n', formatNumber(column.cast_failures))"></span>
                                                <span x-show="column.indexed" class="badge badge-brand"
                                                    data-i18n="bi.schema.indexed">{{ __('bi.schema.indexed') }}</span>
                                            </div>
                                            <p x-show="column.cast_failures > 0 && column.cast_failure_samples.length" x-cloak
                                                class="mt-1 truncate text-[11px] text-muted dark:text-night-muted"
                                                x-text="column.cast_failure_samples.join(' · ')"></p>
                                        </td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>

                    {{-- 3. THE EVIDENCE. Real rows from the materialized table, so a
                            correction can be checked rather than trusted. --}}
                    <div class="mt-6">
                        <h3 class="text-sm font-semibold" data-i18n="bi.schema.preview">{{ __('bi.schema.preview') }}</h3>

                        <template x-if="schema.preview.length">
                            <div class="mt-2 overflow-x-auto rounded-xl bg-sunk/60 dark:bg-white/[0.03]">
                                <table class="table-flat w-full text-xs">
                                    <thead>
                                        <tr>
                                            <template x-for="column in schema.columns" :key="`h-${column.id}`">
                                                <th class="whitespace-nowrap px-3 py-2 text-left font-medium"
                                                    x-text="column.display_name"></th>
                                            </template>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <template x-for="(row, index) in schema.preview" :key="index">
                                            <tr>
                                                <template x-for="column in schema.columns" :key="`c-${index}-${column.id}`">
                                                    <td class="max-w-[14rem] truncate px-3 py-1.5"
                                                        :class="isNumeric(column) && 'tabular-nums'"
                                                        x-text="previewValue(column, row[column.physical_name])"></td>
                                                </template>
                                            </tr>
                                        </template>
                                    </tbody>
                                </table>
                            </div>
                        </template>

                        <template x-if="!schema.preview.length">
                            <p class="mt-2 rounded-xl bg-sunk/60 px-3.5 py-3 text-xs text-muted dark:bg-white/[0.03] dark:text-night-muted"
                                data-i18n="bi.schema.no_preview">{{ __('bi.schema.no_preview') }}</p>
                        </template>
                    </div>
                </div>

                {{-- FOOTER. The warning about what saving costs sits next to the button
                     that costs it, and only appears when it is true. --}}
                <footer class="rule shrink-0 px-6 py-4">
                    <p x-show="willRebuild()" x-cloak
                        class="mb-3 rounded-xl bg-warning/10 px-3.5 py-2.5 text-xs leading-relaxed text-warning dark:text-warning-light"
                        data-i18n="bi.schema.rebuild_warning">{{ __('bi.schema.rebuild_warning') }}</p>

                    <p x-show="schemaError" x-cloak x-text="schemaError" class="mb-3 text-xs text-danger"></p>

                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div class="flex flex-wrap items-center gap-2">
                            <button type="button" @click="rebuild()" x-show="schema.can_rebuild"
                                :disabled="savingSchema || rebuilding"
                                class="btn btn-ghost btn-sm"
                                :title="schema.kind === 'connection' ? @js(__('bi.schema.refresh_schema_hint')) : @js(__('bi.schema.rebuild_hint'))"
                                x-text="schema.kind === 'connection' ? @js(__('bi.schema.refresh_schema')) : @js(__('bi.schema.rebuild'))"></button>

                            <button type="button" @click="destroyDataset()" :disabled="savingSchema"
                                class="btn btn-ghost btn-sm text-muted hover:text-danger dark:text-night-muted"
                                data-i18n="bi.schema.delete_dataset">{{ __('bi.schema.delete_dataset') }}</button>
                        </div>

                        <div class="flex items-center gap-2">
                            <button type="button" @click="closeSchema()" class="btn btn-ghost btn-sm"
                                data-i18n="bi.data.cancel">{{ __('bi.data.cancel') }}</button>
                            <button type="button" @click="saveSchema()"
                                :disabled="savingSchema || !hasEdits()"
                                class="btn btn-primary btn-sm">
                                <span x-show="!savingSchema" data-i18n="bi.schema.save">{{ __('bi.schema.save') }}</span>
                                <span x-show="savingSchema" x-cloak class="spinner h-4 w-4"></span>
                            </button>
                        </div>
                    </div>
                </footer>
            </div>
        </template>
    </div>
</div>
