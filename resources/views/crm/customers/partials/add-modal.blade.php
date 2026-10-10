{{--
    "+ Добавить клиента" — the manual counterpart to the profiles
    CustomerResolver opens automatically from an inbound thread. A plain POST
    form, like the workspace modal: this is a simple, infrequent action with
    no reason to pay for a fetch-based round trip the way the deal panel's
    edit form does.
--}}
<div x-show="adding" x-cloak
    class="fixed inset-0 z-[60] flex items-end justify-center bg-black/45 p-0 sm:items-center sm:p-4"
    @click.self="adding = false" @keydown.escape.window="adding = false">
    <section class="w-full max-w-lg border border-hairline bg-surface p-5 shadow-e3 dark:border-white/10 dark:bg-night-card sm:p-6"
        role="dialog" aria-modal="true" aria-label="{{ __('crm.customers.directory.add') }}">
        <header class="mb-5 flex items-start justify-between gap-4 border-b border-hairline pb-4 dark:border-white/10">
            <h2 class="text-lg font-semibold">{{ __('crm.customers.directory.add') }}</h2>
            <button type="button" @click="adding = false" class="btn btn-ghost btn-sm shrink-0"
                aria-label="{{ __('crm.board.close') }}">✕</button>
        </header>

        <form method="POST" action="{{ route('crm.customers.store') }}" class="space-y-4">
            @csrf
            <div>
                <label for="new-customer-name" class="mb-1.5 block text-sm font-medium">{{ __('crm.customers.name') }}</label>
                <input id="new-customer-name" name="name" value="{{ old('name') }}" required maxlength="255"
                    class="field w-full !rounded-md px-3 py-2.5 text-sm" />
                @error('name')<p class="mt-1 text-sm text-danger">{{ $message }}</p>@enderror
            </div>
            <div>
                <label for="new-customer-phone" class="mb-1.5 block text-sm font-medium">{{ __('crm.customers.phone') }}</label>
                <input id="new-customer-phone" name="phone" value="{{ old('phone') }}" maxlength="32" inputmode="tel"
                    class="field w-full !rounded-md px-3 py-2.5 text-sm" />
                @error('phone')<p class="mt-1 text-sm text-danger">{{ $message }}</p>@enderror
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label for="new-customer-status" class="mb-1.5 block text-sm font-medium">{{ __('crm.customers.status') }}</label>
                    <select id="new-customer-status" name="status" class="field w-full !rounded-md px-3 py-2.5 text-sm">
                        @foreach (\App\Models\Customer::STATUSES as $status)
                            <option value="{{ $status }}" @selected(old('status', 'lead') === $status)>{{ __('crm.statuses.'.$status) }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="new-customer-workspace" class="mb-1.5 block text-sm font-medium">{{ __('crm.workspaces.heading') }}</label>
                    <select id="new-customer-workspace" name="crm_workspace_id" class="field w-full !rounded-md px-3 py-2.5 text-sm">
                        @foreach ($workspaces as $workspace)
                            <option value="{{ $workspace->id }}" @selected((int) old('crm_workspace_id', 0) === $workspace->id)>{{ $workspace->displayName() }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div>
                <label for="new-customer-note" class="mb-1.5 block text-sm font-medium">{{ __('crm.customers.note') }}</label>
                <textarea id="new-customer-note" name="note" rows="2" maxlength="5000"
                    class="field w-full !rounded-md px-3 py-2.5 text-sm">{{ old('note') }}</textarea>
            </div>
            <footer class="flex justify-end gap-2 border-t border-hairline pt-4 dark:border-white/10">
                <button type="button" @click="adding = false" class="btn btn-ghost btn-sm">{{ __('crm.workspaces.cancel') }}</button>
                <button type="submit" class="btn btn-primary btn-sm">{{ __('crm.customers.save') }}</button>
            </footer>
        </form>
    </section>
</div>
