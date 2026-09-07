{{--
    What customers asked the catalog for, counted per search rather than per
    sale — a product asked about nine times and bought once is the most useful
    row here, and every sales-side report shows it as a single unit.

    The miss rate beside each term is what turns the list from trivia into a
    stocking decision.

    Expects: $metrics.
--}}
@php $products = $metrics['products']; @endphp

<div class="flex h-full flex-col rounded-2xl border border-black/10 bg-white p-5 dark:border-white/10 dark:bg-white/5">
    <h2 class="text-base font-semibold">{{ __('dashboard.products.title') }}</h2>
    <p class="mt-0.5 text-xs text-muted dark:text-night-muted">{{ __('dashboard.products.description') }}</p>

    @if ($products === [])
        <p class="mt-8 flex-1 text-center text-sm text-muted dark:text-night-muted">{{ __('dashboard.products.empty') }}</p>
    @else
        @php $top = max(array_column($products, 'count')); @endphp
        <ul class="mt-4 space-y-2.5">
            @foreach ($products as $product)
                <li>
                    <div class="flex items-baseline justify-between gap-3">
                        <span class="min-w-0 truncate text-sm font-medium">{{ $product['term'] }}</span>
                        <span class="shrink-0 text-xs text-muted dark:text-night-muted">{{ __('dashboard.products.asked', ['count' => $product['count']]) }}</span>
                    </div>
                    <div class="mt-1.5 flex items-center gap-2">
                        <div class="h-1.5 flex-1 overflow-hidden rounded-full bg-black/5 dark:bg-white/10">
                            <div class="h-full rounded-full bg-brand" style="width: {{ max(4, round($product['count'] / $top * 100)) }}%"></div>
                        </div>
                        @if ($product['miss_rate'] > 0)
                            <span class="shrink-0 rounded-full bg-warning/10 px-2 py-0.5 text-[10px] font-medium text-warning dark:text-warning-light">
                                {{ __('dashboard.products.miss_rate', ['percent' => $product['miss_rate']]) }}
                            </span>
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
</div>
