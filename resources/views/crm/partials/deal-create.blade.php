{{--
    Opening a deal by hand, for the business that did not arrive through a chat
    — a phone call, a walk-in, a repeat customer who just says "the usual" —
    and, right here, a brand-new customer too when they are not in the book yet.

    Its own x-data, nested inside the board's: the drag state above has nothing
    to do with this form, and giving the form its own scope means its fields
    reset for free every time this partial's DOM is torn down — which never
    happens, but keeps the two concerns from leaking into one shared bag of
    properties as either one grows.
--}}
<div x-show="adding" x-cloak
    x-data="dealCreateForm({
        lookupUrlBase: '{{ route('crm.customers.lookup') }}',
        searchUrlBase: '{{ route('crm.customers.search') }}',
        initial: {
            customerId: @js(old('customer_id', '')),
            customerName: @js($initialCustomer?->displayName()),
            name: @js(old('new_customer_name', '')),
            phone: @js(old('new_customer_phone', '')),
        },
    })"
    x-effect="if (adding && presetCustomerId) { applyPreset(presetCustomerId, presetCustomerName); presetCustomerId = null; }"
    class="fixed inset-0 z-50 flex items-end justify-center bg-black/40 p-0 sm:items-center sm:p-4"
    @click.self="adding = false" @keydown.escape.window="adding = false">

    <div class="card card-pad w-full max-w-md" role="dialog" aria-modal="true">
        <div class="mb-3 flex items-center justify-between gap-3">
            <p class="text-lg font-semibold">{{ __('crm.board.add') }}</p>
            <button type="button" @click="adding = false" class="btn btn-ghost btn-sm" aria-label="{{ __('crm.board.close') }}">✕</button>
        </div>

        <form method="POST" action="{{ route('crm.deals.store') }}" class="space-y-3">
            @csrf

            <div>
                <label for="deal-customer" class="mb-1 block text-sm font-medium">{{ __('crm.board.add_customer') }}</label>
                <input type="hidden" name="customer_id" x-model="customerId" />
                <div class="relative" @click.outside="customerDropdownOpen = false">
                    <input id="deal-customer" type="search" x-model="customerSearch" @focus="searchCustomers()"
                        @input.debounce.250ms="searchCustomers()" autocomplete="off"
                        :placeholder="@js(__('crm.board.add_customer_placeholder'))"
                        class="field w-full px-3 py-2 text-sm" aria-describedby="deal-customer-error" aria-controls="deal-customer-options" />
                    <div id="deal-customer-options" x-show="customerDropdownOpen" x-cloak
                        class="absolute z-20 mt-1 max-h-64 w-full overflow-y-auto rounded-xl border border-hairline bg-surface p-1 shadow-e3 dark:border-white/10 dark:bg-night-card">
                        <button type="button" @click="chooseNewCustomer()" class="w-full rounded-lg px-3 py-2 text-left text-sm font-medium text-brand hover:bg-brand/5">{{ __('crm.board.add_customer_new') }}</button>
                        <template x-for="customer in customerResults" :key="customer.id">
                            <button type="button" @click="chooseCustomer(customer)" class="flex w-full items-center justify-between gap-3 rounded-lg px-3 py-2 text-left text-sm hover:bg-black/5 dark:hover:bg-white/5">
                                <span class="truncate font-medium" x-text="customer.name"></span>
                                <span class="shrink-0 text-xs text-muted dark:text-night-muted" x-text="customer.phone || ''"></span>
                            </button>
                        </template>
                        <p x-show="customerSearch.trim() && !customerResults.length" x-cloak class="px-3 py-2 text-xs text-muted dark:text-night-muted">{{ __('crm.board.customer_search_empty') }}</p>
                    </div>
                </div>
                @error('customer_id')
                    <p id="deal-customer-error" class="mt-1 text-xs text-danger">{{ $message }}</p>
                @enderror
            </div>

            {{-- Revealed only for "+ Новый клиент" — everyone else is already
                 in the dropdown above and needs nothing more. --}}
            <div x-show="customerId === '__new__'" x-cloak
                class="space-y-3 rounded-xl bg-black/[0.03] p-3 dark:bg-white/[0.03]">
                <div>
                    <label for="deal-new-customer-name" class="mb-1 block text-sm font-medium">{{ __('crm.customers.name') }}</label>
                    <input id="deal-new-customer-name" name="new_customer_name" x-model="name"
                        :required="customerId === '__new__'" maxlength="255"
                        class="field w-full px-3 py-2 text-sm" aria-describedby="deal-new-customer-name-error" />
                    @error('new_customer_name')
                        <p id="deal-new-customer-name-error" class="mt-1 text-xs text-danger">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="deal-new-customer-phone" class="mb-1 block text-sm font-medium">{{ __('crm.customers.phone') }}</label>
                    <input id="deal-new-customer-phone" name="new_customer_phone" x-model="phone"
                        @input.debounce.400ms="checkPhone()" maxlength="32"
                        class="field w-full px-3 py-2 text-sm" />

                    {{-- The dedup check. A phone number never gets a second
                         profile — this is the chance to say so BEFORE
                         submitting, and let the owner confirm it is the same
                         person rather than finding two of them later. The
                         server re-checks independently either way. --}}
                    <p x-show="match" x-cloak class="mt-1.5 text-xs text-brand">
                        <span x-text="labels.matchPrefix"></span> <span class="font-medium" x-text="match?.name"></span>
                        — <button type="button" @click="useMatch()" class="underline">{{ __('crm.board.phone_match_use') }}</button>
                    </p>
                </div>
            </div>

            <div>
                <label for="deal-new-title" class="mb-1 block text-sm font-medium">{{ __('crm.board.add_title') }}</label>
                <input id="deal-new-title" name="title" value="{{ old('title') }}" required maxlength="255"
                    class="field w-full px-3 py-2 text-sm" aria-describedby="deal-new-title-error" />
                @error('title')
                    <p id="deal-new-title-error" class="mt-1 text-xs text-danger">{{ $message }}</p>
                @enderror
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label for="deal-new-amount" class="mb-1 block text-sm font-medium">{{ __('crm.board.add_amount') }}</label>
                    <input id="deal-new-amount" name="amount" type="number" step="0.01" min="0.01" required
                        value="{{ old('amount') }}" class="field w-full px-3 py-2 text-sm" aria-describedby="deal-new-amount-error" />
                    @error('amount')
                        <p id="deal-new-amount-error" class="mt-1 text-xs text-danger">{{ $message }}</p>
                    @enderror
                </div>
                <div>
                    <label for="deal-new-date" class="mb-1 block text-sm font-medium">{{ __('crm.board.add_date') }}</label>
                    {{-- Optional: left blank, the deal is dated by when it was
                         actually entered (today) — same as always. Filled in,
                         it backdates a sale the owner is only now logging. --}}
                    <input id="deal-new-date" name="deal_date" type="date" value="{{ old('deal_date') }}" class="field w-full px-3 py-2 text-sm" />
                </div>
            </div>

            <button type="submit" class="btn btn-primary btn-sm btn-block">{{ __('crm.board.add_submit') }}</button>
        </form>
    </div>
</div>
