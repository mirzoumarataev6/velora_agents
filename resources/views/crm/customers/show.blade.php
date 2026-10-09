@extends('layouts.dashboard')

@section('title', $customer->displayName())

@section('content')
<div class="crm-page">
    <div class="page-header">
        <div class="min-w-0">
            <a href="{{ route('crm.board') }}" class="page-eyebrow hover:underline">{{ __('crm.customers.back') }}</a>
            <h1 class="page-title truncate">{{ $customer->displayName() }}</h1>
            <p class="page-sub">
                @if ($customer->phone)
                    <span class="tnum">{{ $customer->phone }}</span>
                @else
                    <span title="{{ __('crm.customers.chat_only_hint') }}">{{ __('crm.customers.chat_only') }}</span>
                @endif
            </p>
        </div>
        <div class="page-actions flex items-center gap-2">
            @foreach ($customer->channelTypes() as $type)
                <x-channel-icon :type="$type" size="h-5 w-5" />
            @endforeach
        </div>
    </div>

    @php
        $paidDeals = $customer->deals->filter(fn ($deal) => $deal->payment_status === 'paid' && $deal->stage !== \App\Models\Deal::STAGE_CANCELLED);
        $socialPurchases = $paidDeals->where('sales_origin', 'social');
        $inPersonPurchases = $paidDeals->where('sales_origin', 'in_person');
        $paidTotal = (float) $paidDeals->sum(fn ($deal) => (float) $deal->amount);
    @endphp
    @if ($paidDeals->isNotEmpty())
    <section class="mt-5 grid grid-cols-2 gap-3 xl:grid-cols-4" aria-label="{{ __('crm.customers.purchase_summary') }}">
        @foreach ([
            [__('crm.customers.purchase_count'), $paidDeals->count()],
            [__('crm.customers.purchase_total'), \App\Models\Lead::formatPrice($paidTotal) ?: '—'],
            [__('crm.sales_origins.social'), $socialPurchases->count()],
            [__('crm.sales_origins.in_person'), $inPersonPurchases->count()],
        ] as [$label, $value])
            <div class="card card-pad-sm">
                <p class="text-xs text-muted dark:text-night-muted">{{ $label }}</p>
                <p class="tnum mt-1 text-xl font-semibold text-ink dark:text-night-ink">{{ $value }}</p>
            </div>
        @endforeach
    </section>
    @endif

    @if ($socialPurchases->isEmpty() && $inPersonPurchases->isEmpty())
        <p class="card mt-4 px-4 py-3 text-sm text-muted dark:text-night-muted">{{ __('crm.customers.purchase_empty') }}</p>
    @else
    <section class="mt-4 grid gap-3 lg:grid-cols-2" aria-label="{{ __('crm.customers.purchase_summary') }}">
        @foreach ([
            ['origin' => 'social', 'deals' => $socialPurchases, 'title' => __('crm.customers.online_purchases')],
            ['origin' => 'in_person', 'deals' => $inPersonPurchases, 'title' => __('crm.customers.offline_purchases')],
        ] as $purchaseGroup)
            <article class="card min-w-0 overflow-hidden">
                <header class="flex items-start justify-between gap-4 border-b border-hairline p-4 dark:border-white/10">
                    <div class="min-w-0">
                        <h2 class="truncate text-sm font-semibold">{{ $purchaseGroup['title'] }}</h2>
                        <p class="mt-1 text-xs text-muted dark:text-night-muted">
                            {{ trans_choice('crm.customers.purchase_count_plural', $purchaseGroup['deals']->count(), ['count' => $purchaseGroup['deals']->count()]) }}
                        </p>
                    </div>
                    <p class="tnum shrink-0 text-sm font-semibold">{{ \App\Models\Lead::formatPrice((float) $purchaseGroup['deals']->sum(fn ($deal) => (float) $deal->amount)) }}</p>
                </header>
                @forelse ($purchaseGroup['deals']->take(5) as $purchase)
                    <a data-crm-nav href="{{ route('crm.board', ['origin' => $purchaseGroup['origin'], 'workspace' => $purchase->crm_workspace_id, 'deal' => $purchase->id]) }}"
                        class="flex min-w-0 items-center gap-3 border-b border-hairline px-4 py-3 last:border-0 hover:bg-sunk dark:border-white/10 dark:hover:bg-night-raised">
                        <span class="min-w-0 flex-1">
                            <span class="block break-words text-sm font-medium">{{ $purchase->title ?: __('crm.customers.no_name') }}</span>
                            <span class="mt-1 block text-xs text-muted dark:text-night-muted">{{ $purchase->deal_date?->format('d.m.Y') ?? $purchase->created_at?->format('d.m.Y') }}</span>
                        </span>
                        <span class="tnum shrink-0 text-sm font-semibold">{{ \App\Models\Lead::formatPrice($purchase->amount) }}</span>
                    </a>
                @empty
                    <p class="px-4 py-4 text-sm text-muted dark:text-night-muted">{{ __('crm.customers.purchase_empty') }}</p>
                @endforelse
            </article>
        @endforeach
    </section>
    @endif

    <div class="mt-5 grid min-w-0 gap-4 lg:grid-cols-3">
        {{-- Left: who they are and what the owner knows about them. --}}
        <div class="min-w-0 space-y-4">
            <form method="POST" action="{{ route('crm.customers.update', $customer) }}" class="card card-pad space-y-3">
                @csrf
                @method('PATCH')

                <div>
                    <label for="customer-name" class="mb-1 block text-sm font-medium">{{ __('crm.customers.name') }}</label>
                    <input id="customer-name" name="name" value="{{ old('name', $customer->name) }}" class="field w-full px-3 py-2 text-sm" />
                </div>

                <div>
                    <label for="customer-status" class="mb-1 block text-sm font-medium">{{ __('crm.customers.status') }}</label>
                    <select id="customer-status" name="status" class="field w-full px-3 py-2 text-sm">
                        @foreach (\App\Models\Customer::STATUSES as $status)
                            <option value="{{ $status }}" @selected(old('status', $customer->status) === $status)>{{ __('crm.statuses.'.$status) }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label for="customer-workspace" class="mb-1 block text-sm font-medium">{{ __('crm.customers.workspace') }}</label>
                    <select id="customer-workspace" name="crm_workspace_id" class="field w-full px-3 py-2 text-sm">
                        @foreach ($workspaces as $workspace)
                            <option value="{{ $workspace->id }}" @selected((int) old('crm_workspace_id', $customer->crm_workspace_id) === $workspace->id)>{{ $workspace->displayName() }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label for="customer-tags" class="mb-1 block text-sm font-medium">{{ __('crm.customers.tags') }}</label>
                    <input id="customer-tags" name="tags" value="{{ old('tags', implode(', ', $customer->tags ?? [])) }}"
                        placeholder="{{ __('crm.customers.tags_hint') }}" class="field w-full px-3 py-2 text-sm" />
                </div>

                <div>
                    <label for="customer-note" class="mb-1 block text-sm font-medium">{{ __('crm.customers.note') }}</label>
                    <textarea id="customer-note" name="note" rows="3" class="field w-full px-3 py-2 text-sm">{{ old('note', $customer->note) }}</textarea>
                </div>

                <button type="submit" class="btn btn-primary btn-sm btn-block">{{ __('crm.customers.save') }}</button>
            </form>

            <div class="card card-pad space-y-2 text-sm">
                <div class="flex justify-between gap-3">
                    <span class="text-muted dark:text-night-muted">{{ __('crm.customers.source') }}</span>
                    <span class="flex items-center gap-1.5">
                        <x-channel-icon :type="$customer->source" size="h-4 w-4" />
                        {{ $customer->source ? __('crm.channels.'.$customer->source) : '—' }}
                    </span>
                </div>
                <div class="flex justify-between gap-3">
                    <span class="text-muted dark:text-night-muted">{{ __('crm.customers.first_contact') }}</span>
                    <span class="tnum text-right">{{ $customer->first_contact_at?->format('d.m.Y') ?? '—' }}</span>
                </div>
                <div class="flex justify-between gap-3">
                    <span class="text-muted dark:text-night-muted">{{ __('crm.customers.last_contact') }}</span>
                    <span>{{ $customer->last_contact_at?->diffForHumans() ?? '—' }}</span>
                </div>
            </div>

            {{-- Orders stay linked, not copied: this is the same Заявка the
                 leads page works, shown against the person who placed it. --}}
            <div class="card card-pad">
                <p class="mb-2 text-sm font-semibold">{{ __('crm.customers.orders') }}</p>
                @forelse ($customer->leads as $lead)
                    <div class="border-t border-black/5 py-2 text-sm first:border-t-0 first:pt-0 dark:border-white/5">
                        <div class="flex items-center justify-between gap-2">
                            <span class="truncate">{{ $lead->items ?: __('crm.customers.no_name') }}</span>
                            <span class="badge badge-neutral shrink-0">{{ $lead->created_at?->format('d.m.Y') }}</span>
                        </div>
                        @if ($lead->approved_price || $lead->requested_price)
                            <p class="tnum mt-0.5 text-xs text-muted dark:text-night-muted">
                                {{ \App\Models\Lead::formatPrice($lead->approved_price ?: $lead->requested_price) }}
                            </p>
                        @endif
                    </div>
                @empty
                    <p class="text-sm text-muted dark:text-night-muted">{{ __('crm.customers.no_orders') }}</p>
                @endforelse
            </div>

            {{-- The pipeline for this person: every deal, open or closed. --}}
            <div class="card card-pad">
                <p class="mb-2 text-sm font-semibold">{{ __('crm.customers.deals') }}</p>
                @forelse ($customer->deals as $deal)
                            <a data-crm-nav href="{{ route('crm.board', ['origin' => $deal->sales_origin ?: 'social', 'workspace' => $deal->crm_workspace_id, 'deal' => $deal->id]) }}"
                        class="-mx-2 flex items-center justify-between gap-2 rounded-lg px-2 py-1.5 text-sm transition-colors hover:bg-black/5 dark:hover:bg-white/5">
                        <span class="min-w-0 truncate">{{ $deal->title ?: __('crm.stages.'.$deal->stage) }}</span>
                        <span class="flex shrink-0 items-center gap-1.5">
                            @if ($deal->amount)
                                <span class="tnum text-xs">{{ \App\Models\Lead::formatPrice($deal->amount) ?: '—' }}</span>
                            @endif
                            <span class="badge {{ \App\Models\Deal::badgeClass($deal->stage) }}">
                                {{ __('crm.stages.'.$deal->stage) }}
                            </span>
                            @if ($deal->closed_at)
                                <span class="badge badge-success">{{ __('crm.board.closed_badge') }}</span>
                            @endif
                        </span>
                    </a>
                @empty
                    <p class="text-sm text-muted dark:text-night-muted">{{ __('crm.customers.no_deals') }}</p>
                @endforelse
            </div>

            {{-- Reminders about this person, and a one-field way to add one
                 while you are already looking at them. --}}
            <div class="card card-pad">
                <p class="mb-2 text-sm font-semibold">{{ __('crm.nav.tasks') }}</p>

                <div class="space-y-1.5">
                    @foreach ($customer->tasks as $task)
                        <div class="flex items-center gap-2 text-sm">
                            {{-- data-turbo="false": this form is already fully owned by the
                                 document-level submit handler in app.js (a lightweight JSON
                                 PATCH, no navigation at all) — without this, Turbo's own form
                                 interception (registered before that handler, so it would see
                                 the submit event first) would turn it into a full page visit. --}}
                            <form method="POST" action="{{ route('crm.tasks.update', $task) }}" data-task-toggle data-turbo="false" data-success="{{ __('crm.tasks.saved') }}" data-failure="{{ __('crm.tasks.save_failed') }}" class="flex shrink-0">
                                @csrf
                                @method('PATCH')
                                <input type="hidden" name="done" value="{{ $task->isDone() ? 0 : 1 }}" />
                                <button type="submit" aria-label="{{ __('crm.tasks.done_label') }}"
                                    class="flex h-10 w-10 items-center justify-center rounded-lg transition-colors hover:bg-sunk dark:hover:bg-night-raised">
                                    <span class="flex h-4 w-4 items-center justify-center rounded border {{ $task->isDone() ? 'border-brand bg-brand text-white' : 'border-black/20 dark:border-white/20' }}">
                                    @if ($task->isDone())
                                        <svg class="h-2.5 w-2.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                                    @endif
                                    </span>
                                </button>
                            </form>
                            <span data-task-label class="min-w-0 flex-1 truncate {{ $task->isDone() ? 'text-muted line-through dark:text-night-muted' : '' }}">{{ $task->title }}</span>
                            <span class="shrink-0 text-xs {{ $task->isOverdue() ? 'font-medium text-danger' : 'text-muted dark:text-night-muted' }}">
                                {{ $task->due_at?->format('d.m') }}
                            </span>
                        </div>
                    @endforeach
                </div>

                <form method="POST" action="{{ route('crm.tasks.store') }}" x-data="customerDueDate()" @submit="validate($event)" class="mt-2 grid min-w-0 grid-cols-[minmax(0,1fr)_auto] gap-2 sm:flex sm:flex-wrap">
                    @csrf
                    <input type="hidden" name="customer_id" value="{{ $customer->id }}" />
                    <input name="title" required maxlength="255" placeholder="{{ __('crm.tasks.add_title') }}"
                        aria-label="{{ __('crm.tasks.add_title') }}" class="field col-span-2 min-w-0 w-full flex-1 px-3 py-1.5 text-sm sm:col-span-1" />
                    <div class="relative min-w-0 sm:w-44 sm:shrink-0">
                        <input type="text" inputmode="numeric" autocomplete="off" x-model="display"
                            placeholder="{{ __('crm.board.date_format_hint') }}" aria-label="{{ __('crm.tasks.add_due') }}" :aria-invalid="!!error"
                            class="field min-w-0 w-full px-2 py-1.5 pr-9 text-sm" />
                        <input type="hidden" name="due_at" :value="iso() || ''" />
                        <input type="date" x-ref="picker" class="pointer-events-none absolute right-0 top-0 h-1 w-1 opacity-0" @change="display = format($event.target.value)">
                        <button type="button" @click="if ($refs.picker.showPicker) $refs.picker.showPicker(); else $refs.picker.focus()"
                            class="absolute inset-y-0 right-0 flex w-9 items-center justify-center text-muted hover:text-brand-strong dark:text-night-muted dark:hover:text-brand-light"
                            aria-label="{{ __('crm.board.open_calendar') }}"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M7 3v4m10-4v4M3 10h18"/></svg></button>
                    </div>
                    <p x-show="error" x-cloak x-text="error" role="alert" class="col-span-2 text-xs text-danger sm:order-last sm:w-full"></p>
                    <button type="submit" aria-label="{{ __('crm.tasks.add_submit') }}" class="btn btn-secondary btn-sm min-h-10 shrink-0">+</button>
                </form>
            </div>

            {{-- The manual escape hatch. Only offered for profiles with no phone
                 number, because those are exactly the ones no automatic rule
                 could ever have matched. --}}
            <div class="card card-pad" x-data="{ open: false }">
                <button type="button" @click="open = !open" class="flex w-full items-center justify-between text-sm font-semibold">
                    {{ __('crm.merge.heading') }}
                    <svg class="h-4 w-4 transition-transform" :class="open && 'rotate-180'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" d="M6 9l6 6 6-6"/></svg>
                </button>

                <div x-show="open" x-cloak class="mt-3 space-y-2">
                    <p class="text-xs text-muted dark:text-night-muted">{{ __('crm.merge.hint') }}</p>
                    <p class="text-xs text-muted dark:text-night-muted">{{ __('crm.merge.why') }}</p>

                    @if ($mergeCandidates->isEmpty())
                        <p class="text-sm text-muted dark:text-night-muted">{{ __('crm.merge.empty') }}</p>
                    @else
                        <form method="POST" action="{{ route('crm.customers.merge', $customer) }}"
                            onsubmit="return confirm(@js(__('crm.merge.confirm')))" class="space-y-2">
                            @csrf
                            <select name="customer_id" required class="field w-full px-3 py-2 text-sm">
                                <option value="">{{ __('crm.merge.placeholder') }}</option>
                                @foreach ($mergeCandidates as $candidate)
                                    <option value="{{ $candidate->id }}">
                                        {{ $candidate->name ?: __('crm.customers.no_name') }}
                                        @if ($candidate->source) · {{ __('crm.channels.'.$candidate->source) }} @endif
                                    </option>
                                @endforeach
                            </select>
                            <button type="submit" class="btn btn-secondary btn-sm btn-block">{{ __('crm.merge.submit') }}</button>
                        </form>
                    @endif
                </div>
            </div>
        </div>

        {{-- Right: the reason this page exists — every channel, one story. --}}
        <div class="lg:col-span-2 lg:flex lg:flex-col lg:gap-4">
            <div class="card card-pad shrink-0">
                <p class="text-sm font-semibold">{{ __('crm.customers.threads') }}</p>
                <div class="mt-2 space-y-1.5">
                    @foreach ($customer->conversations as $conversation)
                        <a href="{{ route('conversations.index', ['conversation' => $conversation->id]) }}"
                            class="flex items-center gap-2 rounded-lg px-2 py-1.5 text-sm transition-colors hover:bg-black/5 dark:hover:bg-white/5">
                            <x-channel-icon :type="$conversation->channel?->type" size="h-4 w-4" />
                            <span class="truncate">{{ $conversation->agent?->name }}</span>
                            <span class="ml-auto shrink-0 text-xs text-muted dark:text-night-muted">
                                {{ $conversation->last_message_at?->diffForHumans() }}
                            </span>
                        </a>
                    @endforeach
                </div>
            </div>

            <div class="card card-pad mt-4 flex min-h-[32rem] flex-col lg:mt-0 lg:min-h-0 lg:flex-1">
                <div class="flex items-baseline justify-between gap-3">
                    <p class="text-sm font-semibold">{{ __('crm.customers.timeline') }}</p>
                    <p class="text-xs text-muted dark:text-night-muted">{{ __('crm.customers.timeline_hint') }}</p>
                </div>

                @if ($timeline->isEmpty())
                    <p class="mt-3 text-sm text-muted dark:text-night-muted">{{ __('crm.customers.timeline_empty') }}</p>
                @else
                    {{-- Keep the transcript in document flow: nested scrolling traps
                         the mouse wheel when the pointer is over a message. --}}
                    <div class="mt-3 flex-1 space-y-3 pr-1">
                        @foreach ($timeline as $entry)
                            <div class="flex gap-2 {{ $entry['role'] === 'user' ? '' : 'flex-row-reverse text-right' }}">
                                <div class="max-w-[80%] rounded-2xl px-3 py-2 text-sm {{ $entry['role'] === 'user' ? 'bg-black/5 dark:bg-white/5' : 'bg-brand/10' }}">
                                    <p class="mb-0.5 flex items-center gap-1.5 text-xs text-muted dark:text-night-muted {{ $entry['role'] === 'user' ? '' : 'justify-end' }}">
                                        <x-channel-icon :type="$entry['channel']" size="h-3 w-3" />
                                        {{ __('crm.roles.'.$entry['role']) }} · {{ $entry['at']?->format('d.m.Y H:i') }}
                                    </p>
                                    <p class="whitespace-pre-line break-words">{{ $entry['content'] }}</p>
                                </div>
                            </div>
                        @endforeach
                    </div>
                    @if ($timeline->hasPages())
                        <div class="mt-4 flex items-center justify-between border-t border-hairline pt-3 text-sm dark:border-white/10">
                            <span class="text-muted dark:text-night-muted">{{ $timeline->firstItem() }}–{{ $timeline->lastItem() }} / {{ $timeline->total() }}</span>
                            <div class="flex gap-2">
                                @if ($timeline->previousPageUrl())
                                    <a class="btn btn-secondary btn-sm" href="{{ $timeline->previousPageUrl() }}">{{ __('crm.customers.timeline_previous') }}</a>
                                @endif
                                @if ($timeline->nextPageUrl())
                                    <a class="btn btn-secondary btn-sm" href="{{ $timeline->nextPageUrl() }}">{{ __('crm.customers.timeline_next') }}</a>
                                @endif
                            </div>
                        </div>
                    @endif
                @endif
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
function customerDueDate() {
    return {
        display: '', error: '', european: @js(app()->getLocale() !== 'en'),
        format(value) {
            if (!value || !this.european) return value || '';
            const match = value.match(/^(\d{4})-(\d{2})-(\d{2})$/);
            return match ? `${match[3]}.${match[2]}.${match[1]}` : value;
        },
        iso() {
            if (!this.display.trim()) return '';
            const match = this.european
                ? this.display.trim().match(/^(\d{2})\.(\d{2})\.(\d{4})$/)
                : this.display.trim().match(/^(\d{4})-(\d{2})-(\d{2})$/);
            if (!match) return null;
            const value = this.european ? `${match[3]}-${match[2]}-${match[1]}` : this.display.trim();
            const date = new Date(`${value}T00:00:00Z`);
            return Number.isNaN(date.getTime()) || date.toISOString().slice(0, 10) !== value ? null : value;
        },
        validate(event) {
            this.error = '';
            if (this.iso() === null) {
                event.preventDefault();
                this.error = @js(__('crm.board.invalid_date'));
            }
        },
    };
}
</script>
@endpush
