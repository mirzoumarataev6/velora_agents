@php
    $initialMessages = $playgroundConversation
        ? $playgroundConversation->messages->map(fn ($m) => [
            'id' => $m->id,
            'role' => $m->role,
            'content' => $m->content,
            'meta' => $m->meta,
            'source_type' => $m->source_type ?? 'text',
            'voice_duration' => $m->voice_duration,
        ])
        : [];
@endphp

<div x-data="playgroundTab({
        sendUrl: '{{ route('agents.playground.messages.store', $agent) }}',
        transcribeUrl: '{{ route('agents.playground.transcribe', $agent) }}',
        resetUrl: '{{ route('agents.playground.reset', $agent) }}',
        initialMessages: {{ Js::from($initialMessages) }},
        sourcesLabel: {{ Js::from(__('agents.playground.sources_label')) }},
        kbFallback: {{ Js::from(__('agents.playground.kb_fallback')) }},
        maxRecordingSeconds: {{ (int) config('agent.voice.max_duration_seconds', 300) }},
        voiceLabels: {{ Js::from(__('agents.playground.voice')) }},
    })" x-init="initVoice()"
    {{-- `tab` comes from the page's own x-data, one level up. x-effect re-runs
         whenever it changes, so the composer takes focus both when the page
         opens straight onto Песочница and when the owner switches to it later.
         Focusing a display:none input is a no-op, so the other tabs are
         unaffected. --}}
    x-effect="if (tab === 'playground') focusComposer()"
    class="flex h-[32rem] flex-col overflow-hidden rounded-2xl border border-black/10 dark:border-white/10">

    <div class="flex items-center justify-between border-b border-black/10 bg-white/60 px-4 py-3 dark:border-white/10 dark:bg-white/5">
        <div>
            <p class="text-sm font-semibold">{{ __('agents.playground.heading') }}</p>
            <p class="text-xs text-muted dark:text-night-muted">{{ __('agents.playground.subheading') }}</p>
        </div>
        <button type="button" @click="resetConversation()" class="flex items-center gap-1.5 rounded-full border border-black/10 px-3 py-1.5 text-xs font-medium transition-colors hover:bg-black/5 dark:border-white/10 dark:hover:bg-white/10">
            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
            {{ __('agents.playground.clear') }}
        </button>
    </div>

    <div class="flex-1 space-y-4 overflow-y-auto px-4 py-4" x-ref="scrollArea">
        <template x-if="messages.length === 0">
            <div class="flex h-full flex-col items-center justify-center gap-2 text-center">
                <svg class="h-8 w-8 text-muted dark:text-night-muted/70" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8-1.06 0-2.08-.16-3.02-.46L3 21l1.5-4.5C3.55 15.1 3 13.6 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>
                <p class="text-sm font-medium">{{ __('agents.playground.empty_title') }}</p>
                <p class="max-w-sm text-xs text-muted dark:text-night-muted">{{ __('agents.playground.empty_example') }}</p>
            </div>
        </template>

        <template x-for="msg in messages" :key="msg.id">
            <div class="flex flex-col" :class="msg.role === 'user' ? 'items-end' : 'items-start'">
                {{-- Two bubbles, not one with a conditional binding: what the
                     customer typed is shown verbatim, while the agent's reply is
                     markdown the model wrote and has to be rendered. --}}
                <template x-if="msg.role === 'user'">
                    <div class="max-w-[80%] rounded-2xl bg-brand px-4 py-2.5 text-sm whitespace-pre-wrap text-on-accent"
                        :class="msg._fresh && 'pg-msg-in'"
                        x-text="msg.content"></div>
                </template>
                <template x-if="msg.role !== 'user'">
                    <div class="pg-prose max-w-[80%] rounded-2xl bg-black/5 px-4 py-2.5 text-sm dark:bg-white/10"
                        :class="msg._fresh && 'pg-msg-in'"
                        x-html="renderMarkdown(msg.content)"></div>
                </template>

                {{-- Same marker the Диалоги inbox uses, so a transcript reads the
                     same way in both places. --}}
                <div x-show="msg.source_type === 'voice'" x-cloak
                    class="mt-1 flex items-center gap-1 rounded-full bg-brand/10 px-2 py-0.5 text-[10px] font-medium text-brand">
                    <span aria-hidden="true">🎤</span>
                    <span>{{ __('conversations.thread.voice_note') }}</span>
                </div>

                <template x-if="msg.role === 'assistant' && msg.meta">
                    <div class="mt-1 max-w-[80%] text-xs text-muted dark:text-night-muted">
                        <button type="button" @click="msg._sourcesOpen = !msg._sourcesOpen" class="flex items-center gap-1 hover:text-brand" x-show="(msg.meta.retrieved_chunks || []).length > 0">
                            <svg class="h-3 w-3 transition-transform" :class="msg._sourcesOpen ? 'rotate-90' : ''" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                            <span x-text="sourcesLabel + ' (' + (msg.meta.retrieved_chunks || []).length + ')'"></span>
                        </button>
                        <div x-show="msg._sourcesOpen" x-cloak x-transition.opacity.duration.200ms class="mt-1.5 space-y-1.5">
                            <template x-for="src in (msg.meta.retrieved_chunks || [])" :key="src.id">
                                <div class="rounded-xl border border-black/10 bg-white/60 p-2.5 dark:border-white/10 dark:bg-white/5">
                                    <div class="flex items-center justify-between gap-2">
                                        <span class="font-medium text-ink dark:text-white" x-text="src.source_name || kbFallback"></span>
                                        <span class="shrink-0 rounded-full bg-success/10 px-2 py-0.5 font-mono text-[10px] font-semibold text-success dark:text-success-light" x-text="Math.round(src.score * 100) + '%'"></span>
                                    </div>
                                    <p class="mt-1 line-clamp-3 text-muted dark:text-night-muted" x-text="src.content"></p>
                                </div>
                            </template>
                        </div>
                        <p class="mt-1 font-mono text-[10px] text-muted/70 dark:text-white/30" x-show="msg.meta.total_ms">
                            <span x-text="'retrieval: ' + msg.meta.retrieval_ms + 'ms · generation: ' + msg.meta.generation_ms + 'ms · total: ' + msg.meta.total_ms + 'ms'"></span>
                        </p>
                    </div>
                </template>
            </div>
        </template>

        {{-- Sits where the reply itself will land, so the bubble that arrives
             reads as the same object settling rather than a new one appearing
             somewhere else. --}}
        <div x-show="sending" x-cloak class="flex flex-col items-start">
            <div class="pg-msg-in flex items-center gap-1.5 rounded-2xl bg-black/5 px-4 py-3.5 dark:bg-white/10"
                role="status" aria-live="polite" aria-label="{{ __('agents.playground.typing') }}">
                <span class="pg-typing-dot h-1.5 w-1.5 rounded-full bg-current opacity-35"></span>
                <span class="pg-typing-dot h-1.5 w-1.5 rounded-full bg-current opacity-35"></span>
                <span class="pg-typing-dot h-1.5 w-1.5 rounded-full bg-current opacity-35"></span>
            </div>
        </div>
    </div>

    <div class="border-t border-black/10 dark:border-white/10">
        {{-- Recording / transcribing / error, in the strip above the composer so
             nothing in the row below shifts as the state changes. --}}
        <div x-show="recording || transcribing || voiceError" x-cloak
            class="flex items-center gap-2 px-3 pt-2.5 text-xs">
            <template x-if="recording">
                <span class="flex items-center gap-2 text-danger">
                    <span class="flex h-2 w-2 animate-pulse rounded-full bg-danger"></span>
                    <span x-text="voiceLabels.recording"></span>
                    <span class="tnum" x-text="elapsedLabel()"></span>
                </span>
            </template>
            <template x-if="transcribing">
                <span class="flex items-center gap-2 text-muted dark:text-night-muted">
                    <span class="spinner h-3 w-3"></span>
                    <span x-text="voiceLabels.transcribing"></span>
                </span>
            </template>
            <template x-if="voiceError">
                <span class="text-danger" x-text="voiceError"></span>
            </template>
        </div>

        <form @submit.prevent="sendMessage()" class="flex items-center gap-2 p-3">
            <input type="text" x-model="input" x-ref="messageInput" :disabled="sending || recording || transcribing"
                placeholder="{{ __('agents.playground.placeholder') }}"
                class="field flex-1 rounded-full px-4 py-2.5 text-sm" />

            {{-- Mic: idle vs recording. One button that swaps role, so focus is
                 never lost mid-recording. --}}
            <button type="button" x-show="voiceSupported" x-cloak
                @click="recording ? stopRecording() : startRecording()"
                :disabled="sending || transcribing"
                :aria-label="recording ? voiceLabels.stop : voiceLabels.record"
                :title="recording ? voiceLabels.stop : voiceLabels.record"
                :class="recording
                    ? 'bg-danger text-white animate-pulse'
                    : 'border border-black/10 text-muted hover:text-brand dark:border-white/10 dark:text-night-muted'"
                class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full transition-all disabled:pointer-events-none disabled:opacity-50">
                <svg x-show="!recording" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 15a3 3 0 003-3V6a3 3 0 00-6 0v6a3 3 0 003 3z"/><path stroke-linecap="round" stroke-linejoin="round" d="M19 11a7 7 0 01-14 0M12 18v3"/></svg>
                <svg x-show="recording" x-cloak class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><rect x="6" y="6" width="12" height="12" rx="2"/></svg>
            </button>

            <button type="submit" :disabled="sending || recording || transcribing || !input.trim()"
                class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-brand text-on-accent transition-all hover:bg-brand-hover disabled:pointer-events-none disabled:opacity-50">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 19V5m0 0l-6 6m6-6l6 6"/></svg>
            </button>
        </form>
    </div>
</div>

@once
@push('scripts')
<script>
    /**
     * A deliberately small markdown renderer for agent replies in the sandbox.
     *
     * Model output is untrusted text, so every character is HTML-escaped FIRST
     * and the tags below are the only markup added back afterwards — there is no
     * path from what the model writes to executable markup. That ordering is the
     * whole safety argument, so do not move the escape step.
     *
     * Scope is what the model actually emits: bold, emphasis, inline code,
     * bullet and numbered lists, and line breaks. Links are deliberately NOT
     * rendered — an anchor built from model output is a phishing vector, and the
     * raw URL is perfectly readable as text.
     */
    function renderPlaygroundMarkdown(text) {
        const entities = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };
        const escaped = String(text ?? '').replace(/[&<>"']/g, (char) => entities[char]);

        const inline = (line) => line
            .replace(/`([^`]+)`/g, '<code>$1</code>')
            .replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
            .replace(/__([^_]+)__/g, '<strong>$1</strong>')
            // Single markers only where they read as emphasis rather than as
            // punctuation or a stray asterisk mid-word (2*3, snake_case_name).
            .replace(/(^|[\s(])\*([^*\n]+)\*(?=[\s.,!?;:)]|$)/g, '$1<em>$2</em>')
            .replace(/(^|[\s(])_([^_\n]+)_(?=[\s.,!?;:)]|$)/g, '$1<em>$2</em>');

        const blocks = [];
        let list = null;
        let paragraph = [];

        const flushParagraph = () => {
            if (paragraph.length) {
                blocks.push('<p>' + paragraph.join('<br>') + '</p>');
                paragraph = [];
            }
        };
        const flushList = () => {
            if (list) {
                blocks.push(`<${list.tag}>` + list.items.map((item) => `<li>${item}</li>`).join('') + `</${list.tag}>`);
                list = null;
            }
        };

        for (const raw of escaped.split(/\r?\n/)) {
            const line = raw.trimEnd();
            // A bullet needs whitespace after the marker, so "**bold**" at the
            // start of a line is never mistaken for one.
            const bullet = line.match(/^\s*[-*•]\s+(.+)$/);
            const ordered = bullet ? null : line.match(/^\s*\d+[.)]\s+(.+)$/);

            if (bullet || ordered) {
                flushParagraph();
                const tag = bullet ? 'ul' : 'ol';
                if (!list || list.tag !== tag) {
                    flushList();
                    list = { tag, items: [] };
                }
                list.items.push(inline((bullet || ordered)[1]));
                continue;
            }

            flushList();

            if (line.trim() === '') {
                flushParagraph();
                continue;
            }

            paragraph.push(inline(line));
        }

        flushList();
        flushParagraph();

        // Falling back to the escaped text keeps a reply of pure punctuation
        // visible rather than rendering an empty bubble.
        return blocks.join('') || escaped;
    }

    function playgroundTab({ sendUrl, transcribeUrl, resetUrl, initialMessages, sourcesLabel, kbFallback, maxRecordingSeconds, voiceLabels }) {
        return {
            messages: initialMessages,
            input: '',
            sending: false,
            sourcesLabel,
            kbFallback,
            voiceLabels,

            // --- Voice input --------------------------------------------------
            voiceSupported: false,
            recording: false,
            transcribing: false,
            voiceError: '',
            elapsed: 0,
            _recorder: null,
            _chunks: [],
            _stream: null,
            _tick: null,
            _stopTimer: null,

            initVoice() {
                this.voiceSupported = typeof MediaRecorder !== 'undefined'
                    && !! (navigator.mediaDevices && navigator.mediaDevices.getUserMedia);
            },

            renderMarkdown(text) {
                return renderPlaygroundMarkdown(text);
            },

            /**
             * preventScroll because the composer sits below the fold on a short
             * window, and focusing it must not yank the page down while the
             * owner is reading the tab they just opened.
             */
            focusComposer() {
                this.$nextTick(() => this.$refs.messageInput?.focus({ preventScroll: true }));
            },

            scrollToLatest() {
                this.$nextTick(() => {
                    this.$refs.scrollArea.scrollTop = this.$refs.scrollArea.scrollHeight;
                });
            },

            elapsedLabel() {
                return Math.floor(this.elapsed / 60) + ':' + String(this.elapsed % 60).padStart(2, '0');
            },

            async startRecording() {
                if (this.recording || this.transcribing) return;
                this.voiceError = '';

                try {
                    // Prompts on first use; the rejection below is what a denied
                    // permission looks like.
                    this._stream = await navigator.mediaDevices.getUserMedia({ audio: true });
                } catch (e) {
                    this.voiceError = voiceLabels.denied;

                    return;
                }

                try {
                    this._chunks = [];
                    this._recorder = new MediaRecorder(this._stream);
                    this._recorder.ondataavailable = (event) => {
                        if (event.data && event.data.size > 0) this._chunks.push(event.data);
                    };
                    this._recorder.onstop = () => this.uploadRecording();
                    this._recorder.start();

                    this.recording = true;
                    this.elapsed = 0;
                    this._tick = setInterval(() => { this.elapsed += 1; }, 1000);

                    // Hard stop at the server's own limit, so a forgotten open mic
                    // can't produce a recording the endpoint would only reject.
                    this._stopTimer = setTimeout(() => this.stopRecording(), maxRecordingSeconds * 1000);
                } catch (e) {
                    this.releaseMic();
                    this.voiceError = voiceLabels.failed;
                }
            },

            stopRecording() {
                if (! this.recording) return;

                this.recording = false;
                clearInterval(this._tick);
                clearTimeout(this._stopTimer);

                try {
                    this._recorder?.stop(); // fires onstop -> uploadRecording()
                } catch (e) {
                    this.releaseMic();
                    this.voiceError = voiceLabels.failed;
                }
            },

            /** Drop the mic as soon as we are done — the browser shows a recording indicator while it is held. */
            releaseMic() {
                this._stream?.getTracks().forEach((track) => track.stop());
                this._stream = null;
                this._recorder = null;
            },

            async uploadRecording() {
                const seconds = this.elapsed;
                const blob = new Blob(this._chunks, { type: this._chunks[0]?.type || 'audio/webm' });

                this.releaseMic();

                if (blob.size === 0) {
                    this.voiceError = voiceLabels.failed;

                    return;
                }

                this.transcribing = true;

                try {
                    const body = new FormData();
                    // The extension matters: the endpoint validates the mimetype.
                    body.append('audio', blob, 'recording.' + (blob.type.includes('ogg') ? 'ogg' : 'webm'));
                    body.append('duration', String(seconds));

                    const res = await fetch(transcribeUrl, {
                        method: 'POST',
                        headers: {
                            Accept: 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                        },
                        body,
                    });

                    const data = await res.json().catch(() => ({}));

                    if (! res.ok) {
                        // The server sends a ready-to-show Russian sentence; it
                        // never contains the underlying error.
                        this.voiceError = data.message || voiceLabels.failed;

                        return;
                    }

                    const text = (data.text || '').trim();

                    if (! text) {
                        this.voiceError = voiceLabels.failed;

                        return;
                    }

                    // Straight into the ordinary send path — the agent is
                    // answering a sandbox message, exactly as if it were typed.
                    this.input = text;
                    await this.sendMessage({ fromVoice: true, duration: seconds });
                } catch (e) {
                    this.voiceError = voiceLabels.failed;
                } finally {
                    this.transcribing = false;
                }
            },

            async sendMessage({ fromVoice = false, duration = null } = {}) {
                const text = this.input.trim();
                if (!text || this.sending) return;
                this.input = '';
                this.sending = true;
                this.voiceError = '';

                // Optimistic: the bubble is on screen before the request leaves,
                // so the sandbox feels like a messenger rather than a form. The
                // negative id cannot collide with a real one, which matters
                // because x-for is keyed on it.
                const pendingId = -Date.now();
                this.messages.push({
                    id: pendingId,
                    role: 'user',
                    content: text,
                    meta: null,
                    source_type: fromVoice ? 'voice' : 'text',
                    voice_duration: fromVoice ? duration : null,
                    _fresh: true,
                });
                this.scrollToLatest();

                try {
                    const res = await fetch(sendUrl, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            Accept: 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                        },
                        body: JSON.stringify({
                            message: text,
                            source_type: fromVoice ? 'voice' : 'text',
                            voice_duration: fromVoice ? duration : null,
                        }),
                    });

                    if (!res.ok) {
                        this.rollbackPending(pendingId, text);
                        window.showToast?.(@json(__('agents.playground.toasts.send_failed')), 'error');
                        return;
                    }

                    const data = await res.json();

                    // Swap the optimistic bubble for the persisted one in place,
                    // keeping its position and its already-played animation —
                    // removing and re-adding it would make it flash.
                    const index = this.messages.findIndex((msg) => msg.id === pendingId);
                    const persisted = { ...data.user, _fresh: false };
                    if (index === -1) {
                        this.messages.push(persisted);
                    } else {
                        this.messages.splice(index, 1, persisted);
                    }

                    this.messages.push({ ...data.assistant, _sourcesOpen: false, _fresh: true });
                } catch (e) {
                    this.rollbackPending(pendingId, text);
                    window.showToast?.(@json(__('agents.playground.toasts.network_error')), 'error');
                } finally {
                    this.sending = false;
                    this.scrollToLatest();
                    // The input is disabled while sending, which drops focus.
                    // Put the cursor back so the next message can just be typed.
                    this.focusComposer();
                }
            },

            /**
             * Take back an optimistic bubble the server never accepted, and hand
             * the owner their text back rather than making them retype it.
             */
            rollbackPending(pendingId, text) {
                this.messages = this.messages.filter((msg) => msg.id !== pendingId);
                if (!this.input.trim()) this.input = text;
            },
            async resetConversation() {
                if (!confirm(@json(__('agents.playground.toasts.clear_confirm')))) return;
                await fetch(resetUrl, {
                    method: 'DELETE',
                    headers: {
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                    },
                });
                this.messages = [];
                this.focusComposer();
            },
        };
    }
</script>
@endpush
@endonce
