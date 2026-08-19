{{--
    The Alpine component behind the "Telegram lead alerts" card.

    Lives in its own partial because two pages render that card: an agent's own
    Settings tab, and the account-wide Настройки page which lists one card per
    agent. @once keeps a single definition on the page no matter how many cards
    are on it.
--}}
@once
@push('scripts')
<script>
    function leadNotify({ linkUrl, statusUrl, unlinkUrl, telegramConnected, initial, labels }) {
        return {
            linked: initial.linked,
            username: initial.username,
            telegramConnected,
            deepLink: null,
            linking: false,
            // Unlinking asks for confirmation in place — the button becomes its
            // own "sure?" prompt — rather than through a native dialog, which
            // would block every browser tool on the page and can't be styled.
            confirmingUnlink: false,
            _poll: null,
            _confirmTimer: null,
            headers(extra = {}) {
                return {
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                    ...extra,
                };
            },
            askUnlink() {
                this.confirmingUnlink = true;
                clearTimeout(this._confirmTimer);
                // An abandoned prompt shouldn't stay armed indefinitely.
                this._confirmTimer = setTimeout(() => { this.confirmingUnlink = false; }, 6000);
            },
            cancelUnlink() {
                this.confirmingUnlink = false;
                clearTimeout(this._confirmTimer);
            },
            async startLink() {
                this.linking = true;
                try {
                    const res = await fetch(linkUrl, { method: 'POST', headers: this.headers() });
                    if (res.status === 422) {
                        const data = await res.json();
                        window.showToast?.(data.message || labels.failed, 'error');
                        return;
                    }
                    if (!res.ok) {
                        window.showToast?.(labels.failed, 'error');
                        return;
                    }
                    const data = await res.json();
                    this.deepLink = data.deep_link;
                    // Open the bot so the owner just taps Start; then poll for the
                    // webhook-driven completion.
                    window.open(data.deep_link, '_blank');
                    this.pollStatus();
                } catch (e) {
                    window.showToast?.(labels.network, 'error');
                } finally {
                    this.linking = false;
                }
            },
            pollStatus() {
                clearInterval(this._poll);
                let attempts = 0;
                this._poll = setInterval(async () => {
                    attempts += 1;
                    try {
                        const res = await fetch(statusUrl, { headers: { Accept: 'application/json' } });
                        if (res.ok) {
                            const data = await res.json();
                            if (data.linked) {
                                this.linked = true;
                                this.username = data.username;
                                this.deepLink = null;
                                clearInterval(this._poll);
                                window.showToast?.(labels.linked, 'success');
                            }
                        }
                    } catch (e) { /* keep polling */ }
                    if (attempts > 60) clearInterval(this._poll); // give up after ~3 minutes
                }, 3000);
            },
            async unlink() {
                this.cancelUnlink();
                try {
                    const res = await fetch(unlinkUrl, { method: 'DELETE', headers: this.headers() });
                    if (!res.ok) {
                        window.showToast?.(labels.failed, 'error');
                        return;
                    }
                    const data = await res.json();
                    this.linked = data.linked;
                    this.username = null;
                    this.deepLink = null;
                    clearInterval(this._poll);
                    window.showToast?.(labels.unlinked, 'success');
                } catch (e) {
                    window.showToast?.(labels.network, 'error');
                }
            },
        };
    }
</script>
@endpush
@endonce
