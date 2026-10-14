{{--
    The deal opened up: everything a row cannot show, editable in place, plus
    the two links that take the owner to the actual work — the customer and
    the live chat.

    Submits through fetch (save()/destroy() in the parent scope) rather than
    as a plain form POST: this is the one place on the page a validation
    error has to show up WITHOUT a reload, since a reload here would also
    have to smuggle back which row was open and what the owner had typed —
    the create-deal modal below still gets away with a plain POST because it
    is a much simpler round trip to redo.

    Inside the board's Alpine scope, reading `selected`, `editForm`, `editErrors`.
--}}
<div x-show="selected" x-cloak
    class="fixed inset-0 z-50 flex items-end justify-center bg-black/40 p-0 sm:items-center sm:p-4"
    @click.self="close()" @keydown.escape.window="close()">

    <div class="card max-h-[100dvh] w-full max-w-3xl overflow-y-auto !rounded-xl sm:max-h-[90vh]" role="dialog" aria-modal="true">
        <template x-if="selected">
            <form @submit.prevent="save()" class="card-pad space-y-3">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="truncate text-lg font-semibold" x-text="selected.customer_name"></p>
                        <p class="tnum text-xs text-muted dark:text-night-muted"
                            x-text="selected.customer_phone || @js(__('crm.customers.chat_only'))"></p>
                    </div>
                    <button type="button" x-ref="dealClose" @click="close()" class="btn btn-ghost btn-sm min-h-10 min-w-10 shrink-0"
                        aria-label="{{ __('crm.board.close') }}">✕</button>
                </div>

                {{-- The person, folded into the deal rather than reached
                     through a separate directory — opening this panel is
                     meant to be enough to see who they are. Status is a
                     quick, independent save (changeCustomerStatus()) so
                     correcting it costs one click, not a trip to another
                     page; tags stay read-only here and are edited on the
                     full profile, which is still one click away. --}}
                <div class="flex flex-wrap items-center gap-1.5">
                    <select @disabled(! auth()->user()->can('analytics.manage')) @change="changeCustomerStatus(selected, $event.target.value)"
                        class="field w-fit px-2 py-1 text-xs">
                        @foreach (\App\Models\Customer::STATUSES as $status)
                            <option value="{{ $status }}" :selected="selected.customer_status === @js($status)">
                                {{ __('crm.statuses.'.$status) }}
                            </option>
                        @endforeach
                    </select>
                    <template x-for="tag in (selected.customer_tags || [])" :key="tag">
                        <span class="badge badge-neutral" x-text="tag"></span>
                    </template>
                </div>

                <div class="flex flex-wrap gap-2 text-sm">
                    <a :href="customerUrl(selected.customer_id)" class="btn btn-secondary btn-sm">{{ __('crm.board.full_profile') }}</a>
                    <a x-show="selected.conversation_id" x-cloak :href="chatUrl(selected.conversation_id)"
                        class="btn btn-secondary btn-sm">{{ __('crm.board.open_chat') }}</a>
                </div>

                <section class="rounded-xl border border-hairline p-3 dark:border-white/10" aria-label="{{ __('crm.board.timeline_heading') }}">
                    <div class="flex items-center justify-between gap-2">
                        <button type="button" class="flex min-h-8 items-center gap-2 text-left text-sm font-semibold" @click="activityOpen = !activityOpen" :aria-expanded="activityOpen">
                            {{ __('crm.board.timeline_heading') }}
                            <span class="badge badge-neutral tnum" x-show="activityItems.length" x-text="activityItems.length"></span>
                            <span class="text-muted dark:text-night-muted" aria-hidden="true" x-text="activityOpen ? '−' : '+'"></span>
                        </button>
                        <span x-show="activityLoading" x-cloak class="text-xs text-muted dark:text-night-muted">{{ __('crm.board.timeline_loading') }}</span>
                    </div>
                    <p x-show="activityOpen && !activityLoading && !activityItems.length" x-cloak class="pt-2 text-xs text-muted dark:text-night-muted">{{ __('crm.board.timeline_empty') }}</p>
                    <ol x-show="activityOpen && activityItems.length" x-cloak class="mt-3 max-h-48 space-y-2 overflow-y-auto pr-1">
                        <template x-for="(item, index) in activityItems" :key="item.kind + '-' + (item.id || item.note_id || index)">
                            <li class="flex gap-2 text-xs">
                                <span class="mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full" :class="item.kind === 'message' ? 'bg-brand' : (item.kind === 'crm_note' ? 'bg-brand-strong' : 'bg-muted/50')"></span>
                                <div class="min-w-0 flex-1">
                                    <p class="flex items-center gap-1 font-medium">
                                        <span x-text="item.label"></span>
                                        <span x-show="item.kind === 'crm_note'" x-cloak class="text-muted dark:text-night-muted" x-text="'· ' + item.author"></span>
                                    </p>
                                    <p class="mt-0.5 whitespace-pre-line break-words text-muted dark:text-night-muted" x-text="item.text"></p>
                                    <p class="mt-0.5 text-xs text-muted dark:text-night-muted" x-text="new Date(item.at).toLocaleString(document.documentElement.lang)"></p>
                                </div>
                                @can('analytics.manage')
                                <button type="button" x-show="item.kind === 'crm_note' && item.can_delete" x-cloak
                                    @click="deleteNote(item)" class="mt-0.5 shrink-0 text-muted hover:text-danger dark:text-night-muted"
                                    aria-label="{{ __('crm.notes.delete') }}">✕</button>
                                @endcan
                                <span x-show="item.kind !== 'crm_note'" x-html="channelIcon(item.channel)" class="mt-0.5 shrink-0"></span>
                            </li>
                        </template>
                    </ol>

                    {{-- The "add entry" box: a note or a manually logged call/visit/meeting — posts via addNote() and prepends into the thread above. --}}
                    @can('analytics.manage')
                    <div class="mt-3 space-y-2 border-t border-hairline pt-3 dark:border-white/10">
                        <div class="flex gap-2">
                            <select x-model="noteForm.kind" class="field w-32 shrink-0 px-2 py-1.5 text-xs">
                                @foreach (\App\Models\CrmNote::KINDS as $kind)
                                    <option value="{{ $kind }}">{{ __('crm.notes.kinds.'.$kind) }}</option>
                                @endforeach
                            </select>
                            <input type="date" x-model="noteForm.happened_at" :max="new Date().toISOString().slice(0, 10)"
                                class="field w-28 shrink-0 px-2 py-1.5 text-xs" aria-label="{{ __('crm.notes.happened_at') }}" />
                        </div>
                        <textarea x-model="noteForm.body" rows="2" maxlength="5000" placeholder="{{ __('crm.notes.body_placeholder') }}"
                            class="field w-full px-3 py-2 text-xs"></textarea>
                        <p x-show="noteErrors.body" x-cloak class="text-xs text-danger" x-text="noteErrors.body?.[0]"></p>
                        <button type="button" @click="addNote()" :disabled="noteSaving || !noteForm.body.trim()"
                            class="btn btn-secondary btn-sm disabled:opacity-50">{{ __('crm.notes.add') }}</button>
                    </div>
                    @endcan
                </section>

                <hr class="rule" />

                <fieldset @disabled(! auth()->user()->can('analytics.manage'))>
                <div>
                    <label class="mb-1 block text-sm font-medium" :for="'deal-title-' + selected.id">{{ __('crm.board.add_title') }}</label>
                    <input :id="'deal-title-' + selected.id" x-model="editForm.title" required maxlength="255"
                        class="field w-full px-3 py-2 text-sm" />
                    <p x-show="editErrors.title" x-cloak class="mt-1 text-xs text-danger" x-text="editErrors.title?.[0]"></p>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="mb-1 block text-sm font-medium" :for="'deal-amount-' + selected.id">{{ __('crm.board.amount') }}</label>
                        <input :id="'deal-amount-' + selected.id" x-model="editForm.amount" type="number" step="0.01" min="0.01" required
                            class="field w-full px-3 py-2 text-sm" />
                        <p x-show="editErrors.amount" x-cloak class="mt-1 text-xs text-danger" x-text="editErrors.amount?.[0]"></p>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium" :for="'deal-payment-' + selected.id">{{ __('crm.board.payment') }}</label>
                        <select :id="'deal-payment-' + selected.id" x-model="editForm.payment_status" class="field w-full px-3 py-2 text-sm">
                            @foreach (\App\Models\Deal::PAYMENT_STATUSES as $status)
                                <option value="{{ $status }}">{{ __('crm.payment_statuses.'.$status) }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div>
                    <label class="mb-1 block text-sm font-medium" :for="'deal-origin-' + selected.id">{{ __('crm.board.sales_origin') }}</label>
                    <select :id="'deal-origin-' + selected.id" x-model="editForm.sales_origin" class="field w-full px-3 py-2 text-sm">
                        @foreach (\App\Models\Deal::SALES_ORIGINS as $origin)
                            <option value="{{ $origin }}">{{ __('crm.sales_origins.'.$origin) }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="mb-1 block text-sm font-medium" :for="'deal-workspace-' + selected.id">{{ __('crm.workspaces.heading') }}</label>
                    <select :id="'deal-workspace-' + selected.id" x-model="editForm.crm_workspace_id" class="field w-full !rounded-md px-3 py-2 text-sm">
                        @foreach ($workspaces as $workspace)
                            <option value="{{ $workspace->id }}">{{ $workspace->displayName() }}</option>
                        @endforeach
                    </select>
                </div>

                {{-- Mirrors the customer profile's own tags input: free-typed,
                     comma separated — the fastest thing to enter and to skim. --}}
                <div>
                    <label class="mb-1 block text-sm font-medium" :for="'deal-tags-' + selected.id">{{ __('crm.board.tags') }}</label>
                    <input :id="'deal-tags-' + selected.id" x-model="editForm.tags" maxlength="500"
                        placeholder="{{ __('crm.board.tags_hint') }}" class="field w-full px-3 py-2 text-sm" />
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="mb-1 block text-sm font-medium" :for="'deal-stage-' + selected.id">{{ __('crm.board.col_stage') }}</label>
                        {{-- The same field the row's quick-select offers —
                             here too, since editing is often where an owner
                             also realises the stage is stale. Goes through
                             save(), which calls the update endpoint, which
                             moves it through DealBoard::move() exactly like
                             every other hand change. --}}
                        <select :id="'deal-stage-' + selected.id" x-model="editForm.stage" class="field w-full px-3 py-2 text-sm">
                            <template x-for="option in stages" :key="option">
                                <option :value="option" x-text="labels.stages[option]"></option>
                            </template>
                        </select>
                        <div x-show="editForm.stage === cancelledStage" x-cloak class="mt-2">
                            <label class="mb-1 block text-xs font-medium" :for="'deal-cancel-reason-' + selected.id">{{ __('crm.board.cancel_reason') }}</label>
                            <textarea :id="'deal-cancel-reason-' + selected.id" x-model="editForm.cancelled_reason" rows="2"
                                :required="editForm.stage === cancelledStage" maxlength="2000" class="field w-full px-3 py-2 text-sm"></textarea>
                            <p x-show="editErrors.cancelled_reason" x-cloak class="mt-1 text-xs text-danger" x-text="editErrors.cancelled_reason?.[0]"></p>
                        </div>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium" :for="'deal-date-' + selected.id">{{ __('crm.board.add_date') }}</label>
                        <div class="relative">
                            <input :id="'deal-date-' + selected.id" x-model="editDateDisplay" type="text" inputmode="numeric" autocomplete="off"
                                placeholder="{{ __('crm.board.date_format_hint') }}" class="field w-full px-3 py-2 pr-10 text-sm" :aria-invalid="!!editErrors.deal_date" />
                            <input type="date" x-ref="editDatePicker" class="pointer-events-none absolute right-0 top-0 h-1 w-1 opacity-0" @change="editDateDisplay = crmFormatDate($event.target.value, dateEuropean)">
                            <button type="button" @click="if ($refs.editDatePicker.showPicker) $refs.editDatePicker.showPicker(); else $refs.editDatePicker.focus()"
                                class="absolute inset-y-0 right-0 flex w-9 items-center justify-center text-muted hover:text-brand-strong dark:text-night-muted dark:hover:text-brand-light"
                                aria-label="{{ __('crm.board.open_calendar') }}"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M7 3v4m10-4v4M3 10h18"/></svg></button>
                        </div>
                        <p x-show="editErrors.deal_date" x-cloak class="mt-1 text-xs text-danger" x-text="editErrors.deal_date?.[0]"></p>
                    </div>
                </div>

                {{-- "Закрыто" only exists while the deal is sitting in Оплата
                     — closing is the owner attesting a PAID sale is fully
                     done, so it makes no sense anywhere else, and leaving the
                     stage here (even before saving) hides the checkbox again
                     rather than let it linger unchecked-but-visible. --}}
                <label x-show="editForm.stage === wonStage" x-cloak
                    class="flex items-center gap-2 text-sm">
                    <input type="checkbox" x-model="editForm.closed" class="field-check h-4 w-4" />
                    {{ __('crm.board.closed_label') }}
                </label>
                <p x-show="editForm.stage === wonStage" x-cloak class="-mt-2 text-xs text-muted dark:text-night-muted">
                    {{ __('crm.board.closed_hint') }}
                </p>

                {{-- The won counterpart to the cancel-reason field above —
                     same shape, optional rather than required, and only
                     shown once "Закрыто" is actually ticked. --}}
                <div x-show="editForm.stage === wonStage && editForm.closed" x-cloak>
                    <label class="mb-1 block text-xs font-medium" :for="'deal-won-reason-' + selected.id">{{ __('crm.board.won_reason') }}</label>
                    <textarea :id="'deal-won-reason-' + selected.id" x-model="editForm.won_reason" rows="2"
                        maxlength="2000" class="field w-full px-3 py-2 text-sm"></textarea>
                </div>

                <section class="rounded-md border border-hairline p-3 dark:border-white/10">
                    <h2 class="mb-2 text-sm font-semibold">{{ __('crm.board.next_step') }}</h2>
                    <div class="grid gap-2 sm:grid-cols-[1fr_14rem_auto]">
                        <div>
                            <input x-model="taskForm.title" maxlength="255" :aria-label="@js(__('crm.tasks.add_title'))"
                                :placeholder="@js(__('crm.board.next_step_placeholder'))" class="field w-full px-3 py-2 text-sm" />
                            <p x-show="taskErrors.title" x-cloak class="mt-1 text-xs text-danger" x-text="taskErrors.title?.[0]"></p>
                        </div>
                        <div class="relative min-w-0 flex-1">
                            <input x-model="taskDueDisplay" type="text" inputmode="numeric" autocomplete="off"
                                placeholder="{{ __('crm.board.datetime_format_hint') }}" :aria-label="@js(__('crm.tasks.add_due'))" :aria-invalid="!!taskErrors.due_at" class="field w-full px-3 py-2 pr-10 text-sm" />
                            <input type="datetime-local" x-ref="taskDuePicker" class="pointer-events-none absolute right-0 top-0 h-1 w-1 opacity-0" @change="taskDueDisplay = crmFormatDateTime($event.target.value, dateEuropean)">
                            <button type="button" @click="if ($refs.taskDuePicker.showPicker) $refs.taskDuePicker.showPicker(); else $refs.taskDuePicker.focus()"
                                class="absolute right-0 top-0 flex h-10 w-9 items-center justify-center text-muted hover:text-brand-strong dark:text-night-muted dark:hover:text-brand-light"
                                aria-label="{{ __('crm.board.open_calendar') }}"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M7 3v4m10-4v4M3 10h18"/></svg></button>
                            <p x-show="taskErrors.due_at" x-cloak class="mt-1 text-xs text-danger" x-text="taskErrors.due_at?.[0]"></p>
                        </div>
                        <button type="button" @click="addNextTask()" :disabled="taskSaving || !taskForm.title.trim()" class="btn btn-secondary btn-sm disabled:opacity-50">{{ __('crm.board.next_step_add') }}</button>
                    </div>
                </section>

                <hr class="rule" />

                @can('analytics.manage')
                <div class="sticky bottom-0 z-10 flex gap-2 border-t border-hairline bg-surface py-3 dark:border-white/10 dark:bg-night-card">
                    <button type="submit" :disabled="editSaving" class="btn btn-primary btn-sm flex-1">{{ __('crm.board.save') }}</button>
                    <button type="button" @click="destroy(selected)" class="btn btn-danger-soft btn-sm">{{ __('crm.board.delete') }}</button>
                </div>
                @endcan
                </fieldset>
            </form>
        </template>
    </div>
</div>
