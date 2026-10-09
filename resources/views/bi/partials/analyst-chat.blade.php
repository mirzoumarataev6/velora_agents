{{-- Each answer shows the validated calculation, not model-generated figures.
     The same payload survives a page reload in bi_chat_messages. --}}
<aside x-show="chatOpen" x-cloak @keydown.escape.window="if (chatOpen) { chatOpen=false; $nextTick(() => $refs.chatToggle?.focus()) }" class="fixed inset-y-0 right-0 z-30 flex w-[min(100vw,25rem)] shrink-0 flex-col border-l border-hairline bg-surface shadow-xl lg:sticky lg:top-6 lg:h-[min(78vh,48rem)] lg:rounded-2xl lg:border lg:shadow-sm dark:border-night-raised dark:bg-night-card" aria-label="{{ __('bi.chat.title') }}">
    <header class="flex items-center justify-between border-b border-hairline px-5 py-4 dark:border-night-raised">
        <div><h2 class="font-semibold">{{ __('bi.chat.title') }}</h2><p class="mt-0.5 text-xs text-muted dark:text-night-muted">{{ __('bi.chat.intro') }}</p></div>
        <button type="button" x-ref="chatClose" class="btn-ghost flex h-10 w-10 items-center justify-center rounded-lg" @click="chatOpen=false; $nextTick(() => $refs.chatToggle?.focus())" aria-label="{{ __('bi.schema.close') }}">×</button>
    </header>
    <div x-ref="chatScroll" class="flex-1 space-y-4 overflow-y-auto px-4 py-5" aria-live="polite">
        <template x-for="message in chatMessages" :key="message.id">
            <div :class="message.role === 'user' ? 'ml-8 bg-brand-circle dark:bg-night-raised' : 'mr-4 bg-paper dark:bg-night-soft'" class="rounded-2xl px-4 py-3 text-sm">
                <p class="whitespace-pre-wrap leading-relaxed" x-text="message.text"></p>
                <template x-for="effect in message.effects || []" :key="effect.kind + (effect.widget_id || '')">
                    <div>
                        <template x-if="effect.kind === 'answer' && effect.rows?.length">
                            <div class="mt-3 rounded-xl bg-surface p-3 dark:bg-night-card">
                                <template x-for="(row, i) in effect.rows.slice(0, 8)" :key="i">
                                    <div class="mb-2 flex items-center gap-2 text-xs">
                                        <span class="w-20 shrink-0 truncate" x-text="String(row.d0 ?? '')"></span>
                                        <div class="h-2 flex-1 overflow-hidden rounded-full bg-sunk dark:bg-night-raised"><div class="h-full rounded-full bg-brand" :style="`width:${Math.max(3, Math.min(100, 100 * Number(row.m0 || 0) / Math.max(1, ...effect.rows.map(r => Number(r.m0 || 0)))))}%`"></div></div>
                                        <span class="w-16 text-right tabular-nums" x-text="new Intl.NumberFormat(document.documentElement.lang, {maximumFractionDigits:2}).format(Number(row.m0 || 0))"></span>
                                    </div>
                                </template>
                                <p x-show="effect.has_more" class="text-xs text-muted dark:text-night-muted">{{ __('bi.widgets.more_rows') }}</p>
                            </div>
                        </template>
                        <template x-if="effect.kind === 'clarification'">
                            <div class="mt-3 flex flex-wrap gap-2"><template x-for="option in effect.options" :key="option.id"><button type="button" class="btn btn-ghost btn-sm" x-text="option.label" @click="chooseColumn(option)"></button></template></div>
                        </template>
                    </div>
                </template>
                <button x-show="message.undoable" type="button" class="btn-ghost mt-2 text-xs" @click="undoChat(message)">{{ __('bi.chat.undo') }}</button>
            </div>
        </template>
        <p x-show="chatLoading" class="text-xs text-muted dark:text-night-muted">{{ __('bi.analysis.processing') }}…</p>
    </div>
    <form @submit.prevent="sendChat()" class="border-t border-hairline p-4 dark:border-night-raised">
        <p x-show="chatError" x-text="chatError" class="mb-2 text-xs text-danger" role="alert"></p>
        <div class="flex gap-2"><input type="text" class="field min-w-0 flex-1" x-model="chatDraft" maxlength="2000" required placeholder="{{ __('bi.chat.placeholder') }}"><button type="submit" class="btn btn-primary btn-sm" :disabled="chatLoading">{{ __('bi.chat.send') }}</button></div>
    </form>
</aside>
