{{--
    Questions the agent had nothing to say to, grouped by what was asked.

    The reason tag beside each one is the actionable half: "not in the catalog"
    and "not in the knowledge base" send the owner to two different places, and
    "service outage" sends them nowhere at all — which is worth knowing before
    they go looking for content that was never missing.

    Expects: $metrics.
--}}
@php $unanswered = $metrics['unanswered']; @endphp

<div class="card flex h-full flex-col p-5">
    <h2 class="section-head-title">{{ __('dashboard.unanswered.title') }}</h2>
    <p class="mt-0.5 text-xs text-muted dark:text-night-muted">{{ __('dashboard.unanswered.description') }}</p>

    @if ($unanswered === [])
        <div class="empty-state flex-1">
            <span class="empty-state-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M8.228 9a3.001 3.001 0 015.772 1c0 2-3 3-3 3m.05 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            </span>
            <p class="empty-state-text">{{ __('dashboard.unanswered.empty') }}</p>
        </div>
    @else
        {{-- The reference's list shape: rows separated by a thin rule, nothing
             boxed, the count sitting out on the right. --}}
        <ul class="list -mx-5 mt-3">
            @foreach ($unanswered as $row)
                @php $reasonKey = 'dashboard.unanswered.reasons.'.($row['reason'] ?? ''); @endphp
                <li class="list-row items-start">
                    <div class="min-w-0 flex-1">
                        <p class="line-clamp-2 text-sm">{{ $row['question'] }}</p>
                        @if (\Illuminate\Support\Facades\Lang::has($reasonKey))
                            <span class="badge badge-neutral mt-1.5">{{ __($reasonKey) }}</span>
                        @endif
                    </div>
                    <span class="badge badge-brand shrink-0">
                        {{ __('dashboard.unanswered.times', ['count' => $row['count']]) }}
                    </span>
                </li>
            @endforeach
        </ul>
    @endif
</div>
