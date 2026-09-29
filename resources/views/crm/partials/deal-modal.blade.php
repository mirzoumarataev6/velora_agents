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

    <div class="card w-full max-w-lg overflow-y-auto sm:max-h-[90vh]" role="dialog" aria-modal="true">
        <template x-if="selected">
            <form @submit.prevent="save()" class="card-pad space-y-3">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="truncate text-lg font-semibold" x-text="selected.customer_name"></p>
                        <p class="tnum text-xs text-muted dark:text-night-muted"
                            x-text="selected.customer_phone || @js(__('crm.customers.chat_only'))"></p>
                    </div>
                    <button type="button" @click="close()" class="btn btn-ghost btn-sm shrink-0"
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
                    <select @change="changeCustomerStatus(selected, $event.target.value)"
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

                <section class="rounded-2xl bg-black/[0.025] p-3 dark:bg-white/[0.035]" aria-label="{{ __('crm.board.timeline_heading') }}">
                    <div class="mb-2 flex items-center justify-between gap-2">
                        <h2 class="text-sm font-semibold">{{ __('crm.board.timeline_heading') }}</h2>
                        <span x-show="activityLoading" x-cloak class="text-xs text-muted dark:text-night-muted">{{ __('crm.board.timeline_loading') }}</span>
                    </div>
                    <p x-show="!activityLoading && !activityItems.length" x-cloak class="py-2 text-xs text-muted dark:text-night-muted">{{ __('crm.board.timeline_empty') }}</p>
                    <ol x-show="activityItems.length" class="max-h-48 space-y-2 overflow-y-auto pr-1">
                        <template x-for="(item, index) in activityItems" :key="item.kind + '-' + (item.id || index)">
                            <li class="flex gap-2 text-xs">
                                <span class="mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full" :class="item.kind === 'message' ? 'bg-brand' : 'bg-muted/50'"></span>
                                <div class="min-w-0 flex-1">
                                    <p class="font-medium" x-text="item.label"></p>
                                    <p class="mt-0.5 whitespace-pre-line break-words text-muted dark:text-night-muted" x-text="item.text"></p>
                                    <p class="mt-0.5 text-[10px] text-muted/70 dark:text-night-muted/70" x-text="new Date(item.at).toLocaleString(document.documentElement.lang)"></p>
                                </div>
                                <span x-html="channelIcon(item.channel)" class="mt-0.5 shrink-0"></span>
                            </li>
                        </template>
                    </ol>
                </section>

                <hr class="rule" />

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
                        <div x-show="editForm.stage === 'cancelled'" x-cloak class="mt-2">
                            <label class="mb-1 block text-xs font-medium" :for="'deal-cancel-reason-' + selected.id">{{ __('crm.board.cancel_reason') }}</label>
                            <textarea :id="'deal-cancel-reason-' + selected.id" x-model="editForm.cancelled_reason" rows="2"
                                :required="editForm.stage === 'cancelled'" maxlength="2000" class="field w-full px-3 py-2 text-sm"></textarea>
                            <p x-show="editErrors.cancelled_reason" x-cloak class="mt-1 text-xs text-danger" x-text="editErrors.cancelled_reason?.[0]"></p>
                        </div>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium" :for="'deal-date-' + selected.id">{{ __('crm.board.add_date') }}</label>
                        <input :id="'deal-date-' + selected.id" x-model="editForm.deal_date" type="date" class="field w-full px-3 py-2 text-sm" />
                    </div>
                </div>

                {{-- "Закрыто" only exists while the deal is sitting in Оплата
                     — closing is the owner attesting a PAID sale is fully
                     done, so it makes no sense anywhere else, and leaving the
                     stage here (even before saving) hides the checkbox again
                     rather than let it linger unchecked-but-visible. --}}
                <label x-show="editForm.stage === 'payment'" x-cloak
                    class="flex items-center gap-2 text-sm">
                    <input type="checkbox" x-model="editForm.closed" class="field-check h-4 w-4" />
                    {{ __('crm.board.closed_label') }}
                </label>
                <p x-show="editForm.stage === 'payment'" x-cloak class="-mt-2 text-xs text-muted dark:text-night-muted">
                    {{ __('crm.board.closed_hint') }}
                </p>

                <div>
                    <label class="mb-1 block text-sm font-medium" :for="'deal-note-' + selected.id">{{ __('crm.board.note') }}</label>
                    <textarea :id="'deal-note-' + selected.id" x-model="editForm.note" rows="3" class="field w-full px-3 py-2 text-sm"></textarea>
                </div>

                <section class="rounded-2xl border border-hairline p-3 dark:border-white/10">
                    <h2 class="mb-2 text-sm font-semibold">{{ __('crm.board.next_step') }}</h2>
                    <div class="grid gap-2 sm:grid-cols-[1fr_10rem_auto]">
                        <div>
                            <input x-model="taskForm.title" maxlength="255" :aria-label="@js(__('crm.tasks.add_title'))"
                                :placeholder="@js(__('crm.board.next_step_placeholder'))" class="field w-full px-3 py-2 text-sm" />
                            <p x-show="taskErrors.title" x-cloak class="mt-1 text-xs text-danger" x-text="taskErrors.title?.[0]"></p>
                        </div>
                        <div class="min-w-0 flex-1">
                            <input x-model="taskForm.due_at" type="datetime-local" :aria-label="@js(__('crm.tasks.add_due'))" :aria-invalid="!!taskErrors.due_at" class="field w-full px-3 py-2 text-sm" />
                            <p x-show="taskErrors.due_at" x-cloak class="mt-1 text-xs text-danger" x-text="taskErrors.due_at?.[0]"></p>
                        </div>
                        <button type="button" @click="addNextTask()" :disabled="taskSaving || !taskForm.title.trim()" class="btn btn-secondary btn-sm disabled:opacity-50">{{ __('crm.board.next_step_add') }}</button>
                    </div>
                </section>

                <hr class="rule" />

                <div class="flex gap-2">
                    <button type="submit" :disabled="editSaving" class="btn btn-primary btn-sm flex-1">{{ __('crm.board.save') }}</button>
                    <button type="button" @click="destroy(selected)" class="btn btn-danger-soft btn-sm">{{ __('crm.board.delete') }}</button>
                </div>
            </form>
        </template>
    </div>
</div>
