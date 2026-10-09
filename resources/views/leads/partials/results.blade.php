{{--
    Everything that a search changes: the "results for …" line, the list itself
    (or its empty state) and the pager.

    Rendered two ways from one source — inline on first paint, and on its own as
    the fragment the live search swaps in. Keeping it in one file is the point:
    a client-side re-implementation of these cards would drift from this one the
    first time a field is added.

    Expects: $leads, $activeView, $activeSearch, $activeStatus, $activeAgent.
--}}
@php $tab = $activeView === 'orders' ? null : $activeView; @endphp
@if ($activeSearch)
    <div class="mt-3 flex items-center gap-2 text-sm text-muted dark:text-night-muted">
        <span>{{ __('leads.index.search_results', ['term' => $activeSearch]) }}</span>
        <a href="{{ route('leads.index', array_filter(['view' => $tab, 'status' => $activeStatus, 'agent' => $activeAgent])) }}"
            class="font-medium text-brand-strong hover:underline">{{ __('leads.index.search_clear') }}</a>
    </div>
@endif

@if ($leads->isEmpty())
    <div class="mt-8 flex flex-col items-center justify-center rounded-3xl bg-white/50 px-6 py-16 text-center dark:bg-night-raised">
        <div class="icon-circle icon-circle-lg">
            <svg class="h-7 w-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"/></svg>
        </div>
        @php $isFiltered = $activeSearch || $activeStatus || $activeAgent; @endphp
        @if ($isFiltered)
            <h2 class="mt-4 text-lg font-semibold">{{ __('leads.index.empty_filtered_title') }}</h2>
            <p class="mt-1.5 max-w-sm text-sm text-muted dark:text-night-muted">{{ __('leads.index.empty_filtered_description') }}</p>
        @else
            {{-- An empty Торг tab is not the same news as an empty Заявки tab:
                 one means nobody has written, the other means nobody is waiting
                 on you, which is the good outcome. --}}
            <h2 class="mt-4 text-lg font-semibold">{{ __("leads.views.empty_{$activeView}_title") }}</h2>
            <p class="mt-1.5 max-w-sm text-sm text-muted dark:text-night-muted">{{ __("leads.views.empty_{$activeView}_description") }}</p>
        @endif
    </div>
@else
    <div class="mt-6 space-y-3">
        @foreach ($leads as $lead)
            {{-- A pending decision is bordered and tinted, so that even inside
                 its own tab the ones still waiting on the owner are the ones
                 the eye lands on first. --}}
            <div x-show="!removed.includes({{ $lead->id }})"
                class="rounded-2xl border p-4 transition-shadow hover:shadow-md {{ $lead->awaitsPriceApproval()
                    ? 'border-warning/40 bg-warning/[0.04]'
                    : 'border-black/10 bg-white dark:border-white/10 dark:bg-white/5' }}">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <h3 class="text-base font-semibold">{{ $lead->customer_name ?: __('leads.index.no_name') }}</h3>
                        <div class="mt-0.5 flex flex-wrap items-center gap-2">
                            @if ($lead->phone)
                                <p class="text-sm text-brand-strong">{{ $lead->phone }}</p>
                            @endif
                            @if ($lead->contact && $lead->contact !== $lead->phone)
                                <p class="text-sm {{ $lead->phone ? 'text-muted dark:text-night-muted' : 'text-brand' }}">{{ $lead->contact }}</p>
                            @endif
                            {{-- The order was taken without a number, on purpose: the
                                 customer declined and the sale was kept anyway. The owner
                                 has to see that calling is not an option here before they
                                 go looking for a number that was never given. --}}
                            @if ($lead->isChatOnly())
                                <span title="{{ __('leads.index.chat_only_hint') }}"
                                    class="badge badge-warning">
                                    <svg class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.86 9.86 0 01-4-.8L3 20l1.4-3.5A7.9 7.9 0 013 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>
                                    {{ __('leads.index.chat_only') }}
                                </span>
                            @endif
                        </div>
                    </div>
                    <div class="flex flex-col items-end gap-1 text-right">
                        @if ($lead->awaitsPriceApproval())
                            <span class="inline-flex items-center gap-1 rounded-full bg-warning px-2 py-0.5 text-xs font-semibold text-white">
                                {{ __('leads.haggle.waiting_badge') }}
                            </span>
                        @endif
                        @php $sourceKey = "leads.source.{$lead->source}"; @endphp
                        <span class="badge badge-neutral">
                            {{ \Illuminate\Support\Facades\Lang::has($sourceKey) ? __($sourceKey) : ($lead->source ?: '—') }}
                        </span>
                        <span class="text-xs text-muted dark:text-night-muted">{{ $lead->created_at?->diffForHumans() }}</span>
                    </div>
                </div>

                @if ($lead->items)
                    <p class="mt-3 text-sm"><span class="text-muted dark:text-night-muted">{{ __('leads.index.items_label') }}:</span> {{ $lead->items }}</p>
                @endif
                @if ($lead->note && ! $lead->isHaggle())
                    <p class="mt-1 text-sm"><span class="text-muted dark:text-night-muted">{{ __('leads.index.note_label') }}:</span> {{ $lead->note }}</p>
                @endif

                {{-- A price request is a decision, not a record: the two numbers
                     it turns on go first and large, and the negotiation summary
                     is what tells the owner whether the discount is worth it. --}}
                @if ($lead->isHaggle())
                    <div class="mt-3 rounded-xl bg-azure-50 p-3">
                        <div class="flex flex-wrap items-baseline gap-x-4 gap-y-1">
                            <span class="text-sm">
                                <span class="text-muted dark:text-night-muted">{{ __('leads.haggle.requested_label') }}:</span>
                                <span class="text-base font-semibold text-brand-strong">{{ \App\Models\Lead::formatPrice($lead->requested_price) ?? '—' }}</span>
                            </span>
                            @if ($lead->listed_price)
                                <span class="text-sm text-muted dark:text-night-muted">
                                    {{ __('leads.haggle.listed_label') }}:
                                    <span class="line-through">{{ \App\Models\Lead::formatPrice($lead->listed_price) }}</span>
                                </span>
                            @endif
                            @if ($lead->requestedDiscountPercent() !== null)
                                <span class="badge badge-warning">
                                    −{{ $lead->requestedDiscountPercent() }}%
                                </span>
                            @endif
                        </div>

                        @if ($lead->negotiation_summary)
                            <p class="mt-2 text-sm">{{ $lead->negotiation_summary }}</p>
                        @endif

                        @if ($lead->approved_price)
                            <p class="mt-2 text-sm font-medium text-success">
                                {{ __('leads.haggle.settled_at', ['price' => \App\Models\Lead::formatPrice($lead->approved_price)]) }}
                            </p>
                        @endif
                    </div>
                @endif

                <div class="mt-4 flex flex-wrap items-center justify-between gap-3 border-t border-hairline pt-3 dark:border-white/10">
                    <a href="{{ route('agents.show', $lead->agent) }}" class="text-xs font-medium text-muted hover:text-brand-strong dark:text-night-muted">
                        {{ $lead->agent->name }}
                    </a>
                    <div class="flex items-center gap-2">
                        @if ($lead->awaitsPriceApproval())
                            {{-- A customer is waiting in a live chat for this
                                 answer, so the two decisions are the only
                                 controls offered — the workflow dropdown would
                                 let the owner "move" the lead without ever
                                 telling them anything. Both take an optional
                                 number: approving at a different price and
                                 declining with a counter are the same gesture
                                 from the owner's side. --}}
                            <div class="flex items-center gap-2" x-data="{ price: '' }">
                                <input type="number" min="0" step="0.01" x-model="price"
                                    aria-label="{{ __('leads.haggle.price_placeholder') }}"
                                    placeholder="{{ __('leads.haggle.price_placeholder') }}"
                                    class="field min-h-10 w-28 px-3 py-1.5 text-sm" />
                                <button type="button" data-requested-price="{{ \App\Models\Lead::formatPrice($lead->requested_price) }}" @click="approvePrice({{ $lead->id }}, price, $el.dataset.requestedPrice)"
                                    class="min-h-10 rounded-lg bg-success px-3 py-1.5 text-sm font-semibold text-white transition-colors hover:opacity-90 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand">
                                    {{ __('leads.haggle.approve') }}
                                </button>
                                <button type="button" @click="declinePrice({{ $lead->id }}, price)"
                                    class="min-h-10 rounded-lg px-3 py-1.5 text-sm font-semibold text-muted transition-colors hover:border-danger/30 hover:text-danger focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand dark:text-night-muted">
                                    {{ __('leads.haggle.decline') }}
                                </button>
                            </div>
                        @else
                        <select @change="updateStatus({{ $lead->id }}, $event.target.value)"
                            class="field min-h-10 px-3 py-1.5 text-sm font-medium">
                            @foreach (['new', 'in_progress', 'done'] as $statusOption)
                                <option value="{{ $statusOption }}" @selected($lead->status === $statusOption)>{{ __("leads.status.$statusOption") }}</option>
                            @endforeach
                        </select>
                        @endif
                        <button type="button" @click="remove({{ $lead->id }})" aria-label="{{ __('leads.index.delete') }}"
                            class="flex h-10 w-10 items-center justify-center rounded-full text-muted transition-colors hover:border-danger/30 hover:bg-danger/5 hover:text-danger focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand dark:text-night-muted">
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                        </button>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    <div class="mt-6">
        {{ $leads->links() }}
    </div>
@endif
