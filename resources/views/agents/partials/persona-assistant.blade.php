{{--
    "Помочь заполнить" — a stepped wizard that drafts BOTH free-text fields.

    One helper rather than two, because the two fields it fills sit side by side
    on the same step and are answered by the same handful of questions. Asking
    the owner "what should it never discuss?" twice, once per field, would be the
    cost of the tidier-looking alternative.

    Nothing here writes to the server. The endpoint returns a draft; this puts it
    into the textareas the owner is already looking at and marks them as a draft
    until they save the form themselves. Closing the dialog without pressing
    "Вставить" leaves both textareas exactly as they were.

    Expects: $agent (may be an unsaved instance on the create form).
--}}
<div x-data="personaAssistant({
        endpoint: '{{ route('agents.persona-assistant') }}',
        agent: {{ Js::from($agent->exists ? $agent->slug : null) }},
        questions: {{ Js::from(\App\Services\Agents\AgentPersonaAssistant::QUESTIONS) }},
        labels: {{ Js::from([
            'noAnswers' => __('agents.persona_assistant.errors.no_answers'),
            'failed' => __('agents.persona_assistant.errors.failed'),
            'network' => __('agents.persona_assistant.errors.network'),
            'nameRequired' => __('agents.persona_assistant.errors.name_required'),
        ]) }},
    })">
    <button type="button" @click="open()"
        class="flex items-center gap-1.5 rounded-full border-brand/30 bg-azure-50 px-3.5 py-1.5 text-xs font-semibold text-brand-strong transition-colors hover:bg-brand/10">
        <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.5 6.5L22 12l-6.5 2.5L13 21l-2.5-6.5L4 12l6.5-2.5L13 3z"/></svg>
        {{ __('agents.persona_assistant.button') }}
    </button>

    {{-- The dialog. Teleported to <body> so the form's own stacking context
         can't trap it behind a step card. --}}
    <template x-teleport="body">
        <div x-show="showing" x-cloak @keydown.escape.window="close()"
            class="fixed inset-0 z-50 flex items-end justify-center overflow-y-auto bg-black/40 p-0 backdrop-blur-sm sm:items-center sm:p-6">
            <div @click.outside="close()" x-transition.opacity.duration.200ms
                class="w-full max-w-lg rounded-t-3xl bg-white p-6 dark:bg-night-card sm:rounded-3xl">

                <div class="flex items-start justify-between gap-3">
                    <div>
                        <h2 class="text-lg font-semibold">{{ __('agents.persona_assistant.title') }}</h2>
                        <p x-show="stage === 'questions'" class="mt-1 text-xs text-muted dark:text-night-muted"
                            x-text="stepLabel()"></p>
                    </div>
                    <button type="button" @click="close()" aria-label="{{ __('agents.persona_assistant.close') }}"
                        class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg text-muted transition-colors hover:bg-sunk dark:text-night-muted dark:hover:bg-night-raised">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>

                {{-- Questions --}}
                <div x-show="stage === 'questions'">
                    <p x-show="qIndex === 0" class="mt-3 text-sm text-muted dark:text-night-muted">{{ __('agents.persona_assistant.intro') }}</p>

                    <div class="mt-4 flex items-center gap-1.5">
                        <template x-for="(q, i) in questions" :key="q">
                            <div class="h-1 flex-1 rounded-full transition-colors duration-300"
                                :class="i <= qIndex ? 'bg-brand' : 'bg-black/10 dark:bg-white/10'"></div>
                        </template>
                    </div>

                    @foreach (\App\Services\Agents\AgentPersonaAssistant::QUESTIONS as $index => $key)
                        <div x-show="qIndex === {{ $index }}" x-cloak class="mt-5">
                            <label for="pa_{{ $key }}" class="text-sm font-medium">{{ __('agents.persona_assistant.questions.'.$key) }}</label>
                            <textarea id="pa_{{ $key }}" rows="4" x-model="answers.{{ $key }}"
                                maxlength="{{ \App\Services\Agents\AgentPersonaAssistant::MAX_ANSWER_LENGTH }}"
                                placeholder="{{ __('agents.persona_assistant.placeholders.'.$key) }}"
                                class="field mt-1.5 w-full px-4 py-2.5 text-sm"></textarea>
                            <p class="mt-1 text-xs text-muted dark:text-night-muted">{{ __('agents.persona_assistant.optional') }}</p>
                        </div>
                    @endforeach

                    <p x-show="error" x-cloak x-text="error" class="mt-3 text-xs text-danger"></p>

                    <div class="mt-5 flex items-center justify-between gap-3">
                        <button type="button" @click="qIndex--" x-show="qIndex > 0" x-cloak
                            class="rounded-full px-4 py-2 text-sm font-semibold transition-all">
                            {{ __('agents.persona_assistant.back') }}
                        </button>
                        <div class="ml-auto">
                            <button type="button" @click="qIndex++" x-show="qIndex < questions.length - 1"
                                class="btn btn-primary">
                                {{ __('agents.persona_assistant.next') }}
                            </button>
                            <button type="button" @click="generate()" x-show="qIndex === questions.length - 1" x-cloak
                                :disabled="loading"
                                class="btn btn-primary disabled:cursor-not-allowed disabled:opacity-60">
                                <span x-show="loading" class="spinner h-3.5 w-3.5 border-[1.5px]"></span>
                                <span x-text="loading ? @js(__('agents.persona_assistant.generating')) : @js(__('agents.persona_assistant.generate'))"></span>
                            </button>
                        </div>
                    </div>
                </div>

                {{-- The draft, shown BEFORE it goes anywhere near the form. The
                     owner reads it here and presses "Вставить"; closing instead
                     leaves their textareas untouched. --}}
                <div x-show="stage === 'draft'" x-cloak class="mt-4">
                    <div class="flex items-center gap-2">
                        <span class="badge badge-warning">
                            {{ __('agents.persona_assistant.draft_badge') }}
                        </span>
                    </div>
                    <p class="mt-2 text-sm text-muted dark:text-night-muted">{{ __('agents.persona_assistant.draft_note') }}</p>

                    <div class="mt-4 max-h-72 space-y-4 overflow-y-auto pr-1">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-wide text-muted dark:text-night-muted">{{ __('agents.persona_assistant.rules_preview') }}</p>
                            <pre class="mt-1.5 whitespace-pre-wrap rounded-xl bg-sunk p-3 font-sans text-sm dark:bg-night-raised" x-text="draft.rules"></pre>
                        </div>
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-wide text-muted dark:text-night-muted">{{ __('agents.persona_assistant.persona_preview') }}</p>
                            <pre class="mt-1.5 whitespace-pre-wrap rounded-xl bg-sunk p-3 font-sans text-sm dark:bg-night-raised" x-text="draft.persona"></pre>
                        </div>
                    </div>

                    <div class="mt-5 flex items-center justify-between gap-3">
                        <button type="button" @click="close()"
                            class="rounded-full px-4 py-2 text-sm font-semibold transition-all">
                            {{ __('agents.persona_assistant.discard') }}
                        </button>
                        <button type="button" @click="apply()"
                            class="btn btn-primary">
                            {{ __('agents.persona_assistant.apply') }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </template>
</div>

@once
@push('scripts')
<script>
    function personaAssistant({ endpoint, agent, questions, labels }) {
        return {
            showing: false,
            stage: 'questions',
            // NOT `step`: the agent form's own wizard uses that name, and this
            // dialog renders inside it. Distinct names keep an x-show in here
            // from reading like one of the form's steps.
            qIndex: 0,
            loading: false,
            error: null,
            questions,
            answers: Object.fromEntries(questions.map((q) => [q, ''])),
            draft: { rules: '', persona: '' },

            open() {
                this.error = null;
                this.stage = 'questions';
                this.qIndex = 0;
                this.showing = true;
            },

            close() {
                // Deliberately does NOT touch the textareas. A draft the owner
                // walked away from is a draft they rejected.
                this.showing = false;
                this.loading = false;
            },

            stepLabel() {
                return @js(__('agents.persona_assistant.step'))
                    .replace(':current', this.qIndex + 1)
                    .replace(':total', this.questions.length);
            },

            /** The business name/description as they stand in the form right now. */
            formValue(name) {
                return document.querySelector(`[name="${name}"]`)?.value?.trim() ?? '';
            },

            async generate() {
                const name = this.formValue('name');

                if (!name) {
                    this.error = labels.nameRequired;
                    return;
                }

                if (!Object.values(this.answers).some((a) => a.trim() !== '')) {
                    this.error = labels.noAnswers;
                    return;
                }

                this.loading = true;
                this.error = null;

                try {
                    const res = await fetch(endpoint, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            Accept: 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                        },
                        body: JSON.stringify({
                            agent,
                            name,
                            description: this.formValue('description'),
                            answers: this.answers,
                        }),
                    });

                    const body = await res.json().catch(() => ({}));

                    if (!res.ok) {
                        this.error = body.message || labels.failed;
                        return;
                    }

                    this.draft = { rules: body.rules || '', persona: body.persona || '' };
                    this.stage = 'draft';
                } catch (e) {
                    this.error = labels.network;
                } finally {
                    this.loading = false;
                }
            },

            /**
             * Put the draft into the real fields and flag them as unreviewed.
             * `input` is dispatched so anything bound to those textareas sees the
             * change the way it would see typing.
             */
            apply() {
                const fill = (name, value) => {
                    const el = document.querySelector(`[name="${name}"]`);
                    if (!el || !value) return;
                    el.value = value;
                    el.dispatchEvent(new Event('input', { bubbles: true }));
                };

                fill('rules_instructions', this.draft.rules);
                fill('custom_system_prompt', this.draft.persona);

                window.dispatchEvent(new CustomEvent('persona-draft-applied'));
                this.showing = false;
            },
        };
    }
</script>
@endpush
@endonce
