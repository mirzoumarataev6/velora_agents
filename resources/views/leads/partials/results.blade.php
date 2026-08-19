{{--
    Everything that a search changes: the "results for …" line, the list itself
    (or its empty state) and the pager.

    Rendered two ways from one source — inline on first paint, and on its own as
    the fragment the live search swaps in. Keeping it in one file is the point:
    a client-side re-implementation of these cards would drift from this one the
    first time a field is added.

    Expects: $leads, $activeSearch, $activeStatus, $activeAgent.
--}}
@if ($activeSearch)
    <div class="mt-3 flex items-center gap-2 text-sm text-muted dark:text-night-muted">
        <span>{{ __('leads.index.search_results', ['term' => $activeSearch]) }}</span>
        <a href="{{ route('leads.index', array_filter(['status' => $activeStatus, 'agent' => $activeAgent])) }}"
            class="font-medium text-brand hover:underline">{{ __('leads.index.search_clear') }}</a>
    </div>
@endif

@if ($leads->isEmpty())
    <div class="mt-8 flex flex-col items-center justify-center rounded-3xl border border-dashed border-black/10 bg-white/50 px-6 py-16 text-center dark:border-white/10 dark:bg-white/5">
        <div class="flex h-14 w-14 items-center justify-center rounded-2xl bg-brand/10 text-brand">
            <svg class="h-7 w-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"/></svg>
        </div>
        @php $isFiltered = $activeSearch || $activeStatus || $activeAgent; @endphp
        <h2 class="mt-4 text-lg font-semibold">{{ $isFiltered ? __('leads.index.empty_filtered_title') : __('leads.index.empty_title') }}</h2>
        <p class="mt-1.5 max-w-sm text-sm text-muted dark:text-night-muted">{{ $isFiltered ? __('leads.index.empty_filtered_description') : __('leads.index.empty_description') }}</p>
    </div>
@else
    <div class="mt-6 space-y-3">
        @foreach ($leads as $lead)
            <div x-show="!removed.includes({{ $lead->id }})"
                class="rounded-2xl border border-black/10 bg-white p-4 transition-shadow hover:shadow-md dark:border-white/10 dark:bg-white/5">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <h3 class="text-base font-semibold">{{ $lead->customer_name ?: __('leads.index.no_name') }}</h3>
                        @if ($lead->contact)
                            <p class="mt-0.5 text-sm text-brand">{{ $lead->contact }}</p>
                        @endif
                    </div>
                    <div class="flex flex-col items-end gap-1 text-right">
                        @php $sourceKey = "leads.source.{$lead->source}"; @endphp
                        <span class="rounded-full bg-black/5 px-2.5 py-0.5 text-[11px] font-medium text-muted dark:bg-white/10 dark:text-night-muted">
                            {{ \Illuminate\Support\Facades\Lang::has($sourceKey) ? __($sourceKey) : ($lead->source ?: '—') }}
                        </span>
                        <span class="text-xs text-muted dark:text-night-muted">{{ $lead->created_at?->diffForHumans() }}</span>
                    </div>
                </div>

                @if ($lead->items)
                    <p class="mt-3 text-sm"><span class="text-muted dark:text-night-muted">{{ __('leads.index.items_label') }}:</span> {{ $lead->items }}</p>
                @endif
                @if ($lead->note)
                    <p class="mt-1 text-sm"><span class="text-muted dark:text-night-muted">{{ __('leads.index.note_label') }}:</span> {{ $lead->note }}</p>
                @endif

                <div class="mt-4 flex flex-wrap items-center justify-between gap-3 border-t border-black/5 pt-3 dark:border-white/10">
                    <a href="{{ route('agents.show', $lead->agent) }}" class="text-xs font-medium text-muted hover:text-brand dark:text-night-muted">
                        {{ $lead->agent->name }}
                    </a>
                    <div class="flex items-center gap-2">
                        <select @change="updateStatus({{ $lead->id }}, $event.target.value)"
                            class="field rounded-lg px-3 py-1.5 text-xs font-medium">
                            @foreach (['new', 'in_progress', 'done'] as $statusOption)
                                <option value="{{ $statusOption }}" @selected($lead->status === $statusOption)>{{ __("leads.status.$statusOption") }}</option>
                            @endforeach
                        </select>
                        <button type="button" @click="remove({{ $lead->id }})" aria-label="{{ __('leads.index.delete') }}"
                            class="flex h-8 w-8 items-center justify-center rounded-lg border border-black/10 text-muted transition-colors hover:border-danger/30 hover:bg-danger/5 hover:text-danger dark:border-white/10 dark:text-night-muted">
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
