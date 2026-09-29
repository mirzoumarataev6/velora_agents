{{--
    What customers asked the catalog for, counted per search rather than per
    sale — a product asked about nine times and bought once is the most useful
    row here, and every sales-side report shows it as a single unit.

    The miss rate beside each term is what turns the list from trivia into a
    stocking decision.

    Expects: $metrics.
--}}
@php $products = $metrics['products']; @endphp

<div class="card flex h-full flex-col p-5">
    <h2 class="section-head-title">{{ __('dashboard.products.title') }}</h2>
    <p class="mt-0.5 text-xs text-muted dark:text-night-muted">{{ __('dashboard.products.description') }}</p>

    @if ($products === [])
        <div class="empty-state flex-1">
            <span class="empty-state-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
            </span>
            <p class="empty-state-text">{{ __('dashboard.products.empty') }}</p>
        </div>
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
                        <div class="h-1.5 flex-1 overflow-hidden rounded-full bg-sunk dark:bg-night-raised">
                            <div class="h-full rounded-full bg-brand" style="width: {{ max(4, round($product['count'] / $top * 100)) }}%"></div>
                        </div>
                        @if ($product['miss_rate'] > 0)
                            <span class="badge badge-warning shrink-0">
                                {{ __('dashboard.products.miss_rate', ['percent' => $product['miss_rate']]) }}
                            </span>
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
</div>
