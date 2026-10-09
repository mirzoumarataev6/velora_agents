<div class="mb-2 flex justify-end px-1 text-xs text-muted dark:text-night-muted" aria-live="polite">
    {{ $customers->total() }} {{ __('crm.customers.directory.customer_count') }}
</div>

@if ($customers->isEmpty())
    <div class="empty-state card">
        <div class="empty-state-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path stroke-linecap="round" d="m20 20-4-4"/></svg></div>
        <p class="empty-state-title">{{ __('crm.customers.directory.empty_title') }}</p>
        <p class="empty-state-text">{{ __('crm.customers.directory.empty_description') }}</p>
    </div>
@else
    <div class="card overflow-hidden">
        <div class="crm-customer-table-scroll overflow-x-auto">
            <table class="table-flat min-w-[850px]">
                <thead class="text-muted dark:text-night-muted/70">
                    <tr>
                        @foreach (['col_customer', 'col_status', 'col_purchases', 'col_dialogues', 'col_revenue', 'col_activity', 'col_workspace'] as $column)
                            <th class="px-4 py-3 text-left text-xs font-medium">{{ __('crm.customers.directory.'.$column) }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($customers as $customer)
                        <tr class="border-t border-hairline transition-colors hover:bg-sunk/70 dark:border-white/10 dark:hover:bg-night-raised">
                            <td class="px-4 py-3">
                        <a data-crm-nav href="{{ route('crm.customers.show', $customer) }}" class="block min-w-44 hover:text-brand">
                                    <span class="block truncate text-sm font-semibold">{{ $customer->displayName() }}</span>
                                    <span class="mt-0.5 block text-xs text-muted dark:text-night-muted">{{ $customer->phone ?: __('crm.customers.chat_only') }}</span>
                                </a>
                            </td>
                            <td class="px-4 py-3"><span class="badge badge-neutral">{{ __('crm.statuses.'.$customer->status) }}</span></td>
                            <td class="tnum px-4 py-3 text-sm">{{ $customer->purchase_count }}</td>
                            <td class="tnum px-4 py-3 text-sm">{{ $customer->conversations_count }}</td>
                            <td class="tnum px-4 py-3 text-sm">{{ $customer->purchase_count ? \App\Models\Lead::formatPrice($customer->purchase_value) : '—' }}</td>
                            <td class="px-4 py-3 text-sm text-muted dark:text-night-muted">{{ $customer->last_contact_at?->diffForHumans() ?? '—' }}</td>
                            <td class="px-4 py-3 text-sm">
                                @if ($customer->workspace)
                                    <span class="inline-flex max-w-40 items-center gap-2 truncate">
                                        <span aria-hidden="true" class="h-2 w-2 shrink-0 {{ ['blue' => 'bg-brand', 'violet' => 'bg-purple-500', 'green' => 'bg-green-500', 'amber' => 'bg-amber-500', 'rose' => 'bg-rose-500', 'slate' => 'bg-slate-500'][$customer->workspace->color] ?? 'bg-brand' }}"></span>
                                        <span class="truncate">{{ $customer->workspace->displayName() }}</span>
                                    </span>
                                @else
                                    <span class="text-muted dark:text-night-muted">—</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @if ($customers->lastPage() > 1)
            <div class="flex flex-col gap-3 border-t border-hairline px-4 py-3 text-sm dark:border-white/10 sm:flex-row sm:items-center sm:justify-between">
                <p class="text-xs text-muted dark:text-night-muted">
                    {{ __('crm.customers.directory.pagination_summary', ['from' => $customers->firstItem(), 'to' => $customers->lastItem(), 'total' => $customers->total()]) }}
                </p>
                <nav class="flex items-center justify-end gap-1" aria-label="{{ __('crm.customers.directory.pagination_label') }}">
                    @if ($customers->onFirstPage())
                        <span class="btn btn-secondary btn-sm pointer-events-none opacity-40" aria-disabled="true">{{ __('crm.customers.directory.previous_page') }}</span>
                    @else
                        <a data-crm-nav class="btn btn-secondary btn-sm" href="{{ $customers->previousPageUrl() }}">{{ __('crm.customers.directory.previous_page') }}</a>
                    @endif
                    @foreach ($customers->getUrlRange(max(1, $customers->currentPage() - 1), min($customers->lastPage(), $customers->currentPage() + 1)) as $page => $url)
                        <a data-crm-nav class="btn btn-sm {{ $page === $customers->currentPage() ? 'btn-primary' : 'btn-secondary' }}" href="{{ $url }}" @if ($page === $customers->currentPage()) aria-current="page" @endif>{{ $page }}</a>
                    @endforeach
                    @if ($customers->hasMorePages())
                        <a data-crm-nav class="btn btn-secondary btn-sm" href="{{ $customers->nextPageUrl() }}">{{ __('crm.customers.directory.next_page') }}</a>
                    @else
                        <span class="btn btn-secondary btn-sm pointer-events-none opacity-40" aria-disabled="true">{{ __('crm.customers.directory.next_page') }}</span>
                    @endif
                </nav>
            </div>
        @endif
    </div>
@endif
