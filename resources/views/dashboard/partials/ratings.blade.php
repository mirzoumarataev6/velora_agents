{{--
    "Оценка ответов" — the customer's verdict on the agent's answers.

    Given a full-width section of its own rather than a quarter of a grid row,
    because of what it is rather than how much data it has. Every other widget
    on this page is derived from something the pipeline already knew about
    itself. This one carries information that exists NOWHERE else in the
    system: whether an answer the agent was completely confident about was
    actually right. A wrong price quoted fluently produces no error, no
    escalation, no unanswered flag and no lead — it looks identical to a good
    turn from the inside. Only the person reading it knows.

    Kept deliberately APART from "вопросы без ответа" two rows down, which
    measures the opposite failure: the agent KNOWING it had nothing to say.
    Folding the two into one "quality" score would bury the dangerous half
    under the harmless one.

    Three zones, left to right: the score, the trend, the actual bad answers.
    The last is the one that makes the card actionable — it ends in a link to
    the inbox filtered to exactly these replies.

    Expects: $metrics.
--}}
@php
    $ratings = $metrics['ratings'];
    $series = $ratings['series'];
    $dense = $metrics['range'] > 7;

    $score = $ratings['score'];

    // Bars are sized in rem against a track of this height. A percentage needs
    // a parent with a definite height to resolve against, and a flex item under
    // `items-end` has none — see the messages chart for the same reasoning.
    $trackRem = 3.5;
    $peak = max(1, max(array_map(fn ($day) => $day['up'] + $day['down'], $series)));
@endphp

<section class="card overflow-hidden">
    {{-- Header. The explainer is not decoration: without it this reads as
         another chart, and the one thing worth understanding about it is what
         it catches that nothing else does. --}}
    <div class="flex flex-wrap items-start justify-between gap-x-6 gap-y-2 border-b border-hairline p-5 dark:border-white/10">
        <div class="min-w-0 flex-1">
            <div class="flex items-center gap-2">
                <span class="icon-circle icon-circle-sm shrink-0" aria-hidden="true">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M7 11v9H4a1 1 0 01-1-1v-7a1 1 0 011-1h3zm0 0l4.5-8a2 2 0 013.6 1.5L14.5 8H19a2 2 0 012 2.3l-1.2 7A2 2 0 0117.8 19H7"/></svg>
                </span>
                <h2 class="section-head-title">{{ __('dashboard.ratings.title') }}</h2>
            </div>
            <p class="mt-2 max-w-3xl text-xs leading-relaxed text-muted dark:text-night-muted">
                {{ __('dashboard.ratings.description') }}
            </p>
        </div>

        <span class="badge badge-brand shrink-0">
            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M21.9 4.3l-3.1 14.7c-.2 1-.9 1.3-1.7.8l-4.7-3.5-2.3 2.2c-.3.3-.5.5-1 .5l.4-4.9 8.9-8c.4-.3-.1-.5-.6-.2L6.7 12.5l-4.7-1.5c-1-.3-1-1 .2-1.5l18.4-7.1c.9-.3 1.6.2 1.3 1.9z"/></svg>
            {{ __('dashboard.ratings.telegram_only') }}
        </span>
    </div>

    @if ($ratings['total'] === 0)
        {{-- An honest empty state. No zeroed ring, no flat chart, and above all
             no "100%" — an agent nobody has rated has not scored perfectly, it
             has not been scored. What it gives instead is the thing a first-time
             reader actually needs: how these numbers get here at all. --}}
        <div class="flex flex-wrap items-center gap-x-10 gap-y-6 p-6 sm:p-7">
            <div class="min-w-0 flex-1">
                <div class="flex items-center gap-3">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-sunk text-lg dark:bg-night-raised" aria-hidden="true">👍</span>
                    <div>
                        <p class="font-display text-lg leading-tight">{{ __('dashboard.ratings.empty_title') }}</p>
                        <p class="mt-0.5 text-sm text-muted dark:text-night-muted">{{ __('dashboard.ratings.empty') }}</p>
                    </div>
                </div>
            </div>

            <div class="min-w-[16rem] flex-1 rounded-xl bg-sunk p-4 dark:bg-night-raised">
                <p class="text-xs font-semibold">{{ __('dashboard.ratings.empty_how_title') }}</p>
                <ul class="mt-2 space-y-1.5">
                    @foreach (__('dashboard.ratings.empty_how') as $step)
                        <li class="flex gap-2 text-xs text-muted dark:text-night-muted">
                            <span class="mt-1.5 h-1 w-1 shrink-0 rounded-full bg-brand" aria-hidden="true"></span>
                            <span>{{ $step }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>
    @else
        <div class="grid grid-cols-1 gap-x-8 gap-y-7 p-5 sm:p-6 lg:grid-cols-12">
            {{-- ZONE 1 · the score, as a ring and as a number --}}
            <div class="flex items-center gap-5 lg:col-span-4">
                {{-- The ring is a conic gradient behind a radial MASK rather
                     than a coloured disc behind a smaller solid one: the centre
                     is genuinely transparent, so it needs no knowledge of the
                     card's background and cannot drift out of step with it in
                     dark mode. --}}
                <div class="relative h-28 w-28 shrink-0" role="img"
                    aria-label="{{ $score }}% — {{ __('dashboard.ratings.score') }}">
                    <div class="h-full w-full rounded-full"
                        style="background: conic-gradient(var(--color-success) 0 {{ $score }}%, var(--color-danger) {{ $score }}% 100%);
                               -webkit-mask: radial-gradient(farthest-side, transparent calc(100% - 11px), #000 calc(100% - 10px));
                               mask: radial-gradient(farthest-side, transparent calc(100% - 11px), #000 calc(100% - 10px));"></div>
                    <div class="absolute inset-0 flex flex-col items-center justify-center">
                        <span class="font-display text-2xl leading-none {{ $score >= 70 ? 'text-success' : 'text-danger' }}"
                            data-countup="{{ $score }}" data-countup-suffix="%">{{ $score }}%</span>
                    </div>
                </div>

                <div class="min-w-0">
                    <p class="text-sm font-medium">{{ __('dashboard.ratings.score') }}</p>
                    <p class="mt-0.5 text-xs text-muted dark:text-night-muted">
                        <span class="font-semibold text-ink tnum dark:text-night-ink">{{ $ratings['total'] }}</span>
                        {{ __('dashboard.ratings.total') }}
                    </p>

                    <div class="mt-3 space-y-1.5">
                        <div class="flex items-center gap-2 text-sm">
                            <span aria-hidden="true">👍</span>
                            <span class="tnum font-semibold text-success">{{ $ratings['up'] }}</span>
                            <span class="text-xs text-muted dark:text-night-muted">{{ __('dashboard.ratings.up') }}</span>
                        </div>
                        <div class="flex items-center gap-2 text-sm">
                            <span aria-hidden="true">👎</span>
                            <span class="tnum font-semibold text-danger">{{ $ratings['down'] }}</span>
                            <span class="text-xs text-muted dark:text-night-muted">{{ __('dashboard.ratings.down') }}</span>
                        </div>
                    </div>
                </div>
            </div>

            {{-- ZONE 2 · the trend --}}
            <div class="lg:col-span-4">
                <p class="text-xs font-semibold">{{ __('dashboard.ratings.trend_title') }}</p>

                {{-- Up above the baseline, down below it: a day's mood is
                     readable without counting, and a run of red is visible from
                     across a room. --}}
                <div class="mt-3 flex items-stretch {{ $dense ? 'gap-0.5' : 'gap-1.5' }}">
                    @foreach ($series as $day)
                        @php
                            $upRem = $day['up'] === 0 ? 0 : max(0.2, round($day['up'] / $peak * $trackRem, 3));
                            $downRem = $day['down'] === 0 ? 0 : max(0.2, round($day['down'] / $peak * $trackRem, 3));
                        @endphp
                        <div class="group flex flex-1 flex-col items-center gap-1"
                            title="{{ $day['label'] }} — 👍 {{ $day['up'] }} / 👎 {{ $day['down'] }}">
                            <div class="flex w-full flex-col justify-end" style="height: {{ $trackRem }}rem">
                                <div class="w-full rounded-t-sm bg-success transition-opacity group-hover:opacity-75" style="height: {{ $upRem }}rem"></div>
                            </div>
                            <div class="h-px w-full bg-sunk dark:bg-white/15"></div>
                            <div class="flex w-full flex-col justify-start" style="height: {{ $trackRem }}rem">
                                <div class="w-full rounded-b-sm bg-danger transition-opacity group-hover:opacity-75" style="height: {{ $downRem }}rem"></div>
                            </div>
                            @unless ($dense)
                                <span class="text-xs text-muted dark:text-night-muted">{{ $day['label'] }}</span>
                            @endunless
                        </div>
                    @endforeach
                </div>
            </div>

            {{-- ZONE 3 · the answers themselves, and the way through to them --}}
            <div class="flex flex-col lg:col-span-4">
                <p class="text-xs font-semibold">{{ __('dashboard.ratings.worst_title') }}</p>

                @if ($ratings['worst'] === [])
                    <p class="mt-3 flex-1 text-xs text-muted dark:text-night-muted">{{ __('dashboard.ratings.no_negative') }}</p>
                @else
                    <ul class="mt-2 flex-1 space-y-0.5">
                        @foreach ($ratings['worst'] as $reply)
                            <li>
                                <a href="{{ route('conversations.index', ['conversation' => $reply['conversation_id']]) }}"
                                    title="{{ __('dashboard.ratings.open_conversation') }}"
                                    class="group -mx-2 flex items-start gap-2 rounded-lg px-2 py-1.5 transition-colors hover:bg-danger/[0.06]">
                                    <span class="mt-0.5 shrink-0 text-xs" aria-hidden="true">👎</span>
                                    <span class="min-w-0 flex-1 truncate text-xs text-muted group-hover:text-ink dark:text-night-muted dark:group-hover:text-night-ink">{{ $reply['excerpt'] }}</span>
                                    <span class="shrink-0 text-xs tnum text-muted dark:text-night-muted">{{ $reply['at'] }}</span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @endif

                {{-- Both links turn this card from a report into a task. The
                     inbox has a matching filter for each, so a click lands on
                     exactly those replies rather than on the inbox in
                     general. Symmetric on purpose: a positive rating is as
                     much a fact worth reviewing (what's working) as a
                     negative one. --}}
                <div class="mt-3 flex flex-wrap items-center gap-2">
                    @if ($ratings['down'] > 0)
                        <a href="{{ route('conversations.index', ['status' => 'rated_down']) }}"
                            class="flex w-fit items-center gap-1.5 rounded-full bg-danger/10 px-3.5 py-1.5 text-xs font-semibold text-danger transition-all hover:bg-danger/15">
                            {{ __('dashboard.ratings.view_negative') }}
                            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14m-6-6 6 6-6 6"/></svg>
                        </a>
                    @endif
                    @if ($ratings['up'] > 0)
                        <a href="{{ route('conversations.index', ['status' => 'rated_up']) }}"
                            class="flex w-fit items-center gap-1.5 rounded-full bg-success/10 px-3.5 py-1.5 text-xs font-semibold text-success transition-all hover:bg-success/15">
                            {{ __('dashboard.ratings.view_positive') }}
                            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14m-6-6 6 6-6 6"/></svg>
                        </a>
                    @endif
                </div>
            </div>
        </div>
    @endif
</section>
