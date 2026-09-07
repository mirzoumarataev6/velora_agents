{{--
    Questions the agent had nothing to say to, grouped by what was asked.

    The reason tag beside each one is the actionable half: "not in the catalog"
    and "not in the knowledge base" send the owner to two different places, and
    "service outage" sends them nowhere at all — which is worth knowing before
    they go looking for content that was never missing.

    Expects: $metrics.
--}}
@php $unanswered = $metrics['unanswered']; @endphp

<div class="flex h-full flex-col rounded-2xl border border-black/10 bg-white p-5 dark:border-white/10 dark:bg-white/5">
    <h2 class="text-base font-semibold">{{ __('dashboard.unanswered.title') }}</h2>
    <p class="mt-0.5 text-xs text-muted dark:text-night-muted">{{ __('dashboard.unanswered.description') }}</p>

    @if ($unanswered === [])
        <p class="mt-8 flex-1 text-center text-sm text-muted dark:text-night-muted">{{ __('dashboard.unanswered.empty') }}</p>
    @else
        <ul class="mt-4 space-y-2.5">
            @foreach ($unanswered as $row)
                @php $reasonKey = 'dashboard.unanswered.reasons.'.($row['reason'] ?? ''); @endphp
                <li class="flex items-start justify-between gap-3 rounded-xl border border-black/5 p-3 dark:border-white/10">
                    <div class="min-w-0">
                        <p class="line-clamp-2 text-sm">{{ $row['question'] }}</p>
                        @if (\Illuminate\Support\Facades\Lang::has($reasonKey))
                            <span class="mt-1 inline-block rounded-full bg-black/5 px-2 py-0.5 text-[10px] font-medium text-muted dark:bg-white/10 dark:text-night-muted">
                                {{ __($reasonKey) }}
                            </span>
                        @endif
                    </div>
                    <span class="shrink-0 rounded-full bg-brand/10 px-2 py-0.5 text-[11px] font-semibold text-brand">
                        {{ __('dashboard.unanswered.times', ['count' => $row['count']]) }}
                    </span>
                </li>
            @endforeach
        </ul>
    @endif
</div>
