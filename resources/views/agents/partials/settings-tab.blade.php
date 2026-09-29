<div class="max-w-xl space-y-4" x-data="agentStatus({
        statusUrl: {{ Js::from(route('agents.status', $agent)) }},
        status: {{ Js::from($agent->status) }},
        labels: {{ Js::from([
            'active' => __('agents.settings_tab.switch_active'),
            'paused' => __('agents.settings_tab.switch_paused'),
            'failed' => __('agents.settings_tab.toasts.status_failed'),
            'network' => __('agents.settings_tab.toasts.network_error'),
        ]) }},
    })">
    <div class="card p-5 transition-shadow">
        <h3 class="text-base font-semibold">{{ __('agents.settings_tab.general_heading') }}</h3>
        <p class="mt-1 text-sm text-muted dark:text-night-muted">{{ __('agents.settings_tab.general_description') }}</p>
        <a href="{{ route('agents.edit', $agent) }}" class="mt-4 inline-flex items-center gap-2 rounded-full px-4 py-2 text-sm font-semibold transition-all">
            {{ __('agents.settings_tab.edit_agent') }}
        </a>
    </div>

    <div class="card p-5 transition-shadow">
        <h3 class="text-base font-semibold">{{ __('agents.settings_tab.status_heading') }}</h3>
        <p class="mt-1 text-sm text-muted dark:text-night-muted">
            <span x-show="status === 'active'">{{ __('agents.settings_tab.status_active_description') }}</span>
            <span x-show="status !== 'active'">{{ __('agents.settings_tab.status_paused_description') }}</span>
        </p>
        <div class="mt-4 flex items-center gap-3">
            {{-- Pill-shaped track + animated thumb. role="switch" so assistive tech
                 announces on/off state rather than a generic button press. --}}
            <button type="button" role="switch" @click="toggle()" :disabled="loading"
                :aria-checked="status === 'active' ? 'true' : 'false'"
                aria-label="{{ __('agents.settings_tab.switch_label') }}"
                class="relative inline-flex h-7 w-12 shrink-0 items-center rounded-full border-transparent transition-colors duration-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand/50 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60 dark:focus-visible:ring-offset-night"
                :class="status === 'active' ? 'bg-brand' : 'bg-black/20 dark:bg-white/20'">
                <span aria-hidden="true"
                    class="pointer-events-none flex h-5 w-5 items-center justify-center rounded-full bg-white ring-0 transition-transform duration-200 ease-out"
                    :class="status === 'active' ? 'translate-x-6' : 'translate-x-1'">
                    <span x-show="loading" class="spinner h-3 w-3 border-[1.5px]"></span>
                </span>
            </button>

            <span class="text-sm font-semibold transition-colors"
                :class="status === 'active' ? 'text-brand-strong' : 'text-muted dark:text-night-muted'"
                x-text="status === 'active' ? labels.active : labels.paused"></span>
        </div>
    </div>

    @include('partials.lead-notify-card', ['agent' => $agent])

    <div class="rounded-2xl border-danger/20 bg-danger/[0.03] p-5">
        <h3 class="text-base font-semibold text-danger dark:text-danger-light">{{ __('agents.settings_tab.danger_heading') }}</h3>
        <p class="mt-1 text-sm text-muted dark:text-night-muted">{{ __('agents.settings_tab.danger_description') }}</p>
        <form method="POST" action="{{ route('agents.destroy', $agent) }}" class="mt-4" onsubmit="return confirm({{ Js::from(str_replace(':name', $agent->name, __('agents.settings_tab.delete_confirm'))) }})">
            @csrf
            @method('DELETE')
            <button type="submit" class="btn btn-danger">
                {{ __('agents.settings_tab.delete_button') }}
            </button>
        </form>
    </div>
</div>

@once
@push('scripts')
<script>
    function agentStatus({ statusUrl, status, labels }) {
        return {
            status,
            labels,
            loading: false,
            async toggle() {
                this.loading = true;
                const next = this.status === 'active' ? 'paused' : 'active';
                try {
                    const res = await fetch(statusUrl, {
                        method: 'PATCH',
                        headers: {
                            'Content-Type': 'application/json',
                            Accept: 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                        },
                        body: JSON.stringify({ status: next }),
                    });
                    if (!res.ok) {
                        window.showToast?.(this.labels.failed, 'error');
                        return;
                    }
                    const data = await res.json();
                    this.status = data.status;
                    window.showToast?.(data.message, 'success');
                } catch (e) {
                    window.showToast?.(this.labels.network, 'error');
                } finally {
                    this.loading = false;
                }
            },
        };
    }
</script>
@endpush
@endonce
