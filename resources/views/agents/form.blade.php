@extends('layouts.dashboard')

@php
    $isEdit = $agent->exists;
    $rf = $agent->response_format ?? [];
    $behavior = $agent->behavior ?? [];

    // Which step to open on when validation bounced the form back. Ordered, so
    // the owner lands on the FIRST step that has something wrong with it.
    $step1Fields = ['name', 'description', 'tone'];
    $step2Fields = ['rules_instructions', 'custom_system_prompt'];
    $step3Fields = ['greeting_message', 'fallback_message', 'on_not_found', 'escalation', 'proactivity', 'max_length', 'use_emojis'];
    // Haggling has a step of its own: it is the only section where the owner is
    // setting a commercial policy rather than a conversational preference, and
    // burying a price floor among tone settings is how it gets set by accident.
    $step4Fields = ['haggling_level', 'haggle_min_price_percent', 'haggling_instructions'];

    $initialStep = match (true) {
        $errors->hasAny($step1Fields) => 1,
        $errors->hasAny($step2Fields) => 2,
        $errors->hasAny($step3Fields) => 3,
        $errors->hasAny($step4Fields) => 4,
        default => 1,
    };
@endphp

@section('title', $isEdit ? __('agents.form.title_edit') : __('agents.form.title_create'))

@section('content')
<div class="mx-auto max-w-2xl" x-data="{ step: {{ $initialStep }} }">
    <a href="{{ $isEdit ? route('agents.show', $agent) : route('agents.index') }}" class="flex items-center gap-1.5 text-sm font-medium text-muted hover:text-brand dark:text-night-muted">
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
        {{ __('agents.form.back') }}
    </a>

    <h1 class="mt-3 text-2xl font-semibold">{{ $isEdit ? __('agents.form.heading_edit') : __('agents.form.heading_create') }}</h1>

    {{-- Step indicator --}}
    <div class="mt-5 flex items-center gap-2">
        <template x-for="n in 5" :key="n">
            <div class="h-1.5 flex-1 rounded-full transition-colors duration-300" :class="step >= n ? 'bg-brand' : 'bg-black/10 dark:bg-white/10'"></div>
        </template>
    </div>

    <form method="POST" action="{{ $isEdit ? route('agents.update', $agent) : route('agents.store') }}" class="mt-6 space-y-6">
        @csrf
        @if ($isEdit) @method('PUT') @endif

        {{-- Step 1: basics --}}
        <div x-show="step === 1" x-cloak x-transition.opacity.duration.200ms class="reveal is-visible space-y-4 rounded-2xl border border-black/10 bg-white p-5 dark:border-white/10 dark:bg-white/5">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-muted dark:text-night-muted">{{ __('agents.form.step1_heading') }}</h2>

            <div>
                <label for="name" class="text-sm font-medium">{{ __('agents.form.name_label') }}</label>
                <input id="name" name="name" type="text" required value="{{ old('name', $agent->name) }}"
                    placeholder="{{ __('agents.form.name_placeholder') }}"
 class="field mt-1 w-full rounded-xl px-4 py-2.5 text-sm" />
                @error('name') <p class="mt-1 text-xs text-danger">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="description" class="text-sm font-medium">{{ __('agents.form.description_label') }}</label>
                <textarea id="description" name="description" rows="3"
                    placeholder="{{ __('agents.form.description_placeholder') }}"
 class="field mt-1 w-full rounded-xl px-4 py-2.5 text-sm">{{ old('description', $agent->description) }}</textarea>
                @error('description') <p class="mt-1 text-xs text-danger">{{ $message }}</p> @enderror
            </div>

            {{-- No language selector: the reply language is detected from each
                 incoming message, so an agent follows a customer who switches
                 mid-conversation instead of being pinned to one. --}}
            <div>
                <label for="tone" class="text-sm font-medium">{{ __('agents.form.tone_label') }}</label>
                <select id="tone" name="tone"
 class="field mt-1 w-full rounded-xl px-4 py-2.5 text-sm">
                    @foreach (__('agents.form.tone_options') as $value => $label)
                        <option value="{{ $value }}" @selected(old('tone', $agent->tone ?: 'friendly') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
                <p class="mt-1 text-xs text-muted dark:text-night-muted">{{ __('agents.form.preset_help') }}</p>
            </div>

            <div class="flex justify-end pt-2">
                <button type="button" @click="step = 2" class="rounded-full bg-brand px-5 py-2.5 text-sm font-semibold text-on-accent transition-all hover:-translate-y-0.5 hover:bg-brand-hover">{{ __('agents.form.next') }}</button>
            </div>
        </div>

        {{-- Step 2: the agent's instructions — the two free-text fields that
             shape behaviour. Both are optional; both outrank the presets. --}}
        <div x-show="step === 2" x-cloak x-transition.opacity.duration.200ms class="space-y-4 rounded-2xl border border-black/10 bg-white p-5 dark:border-white/10 dark:bg-white/5"
            x-data="{ draftApplied: false }" @persona-draft-applied.window="draftApplied = true">
            {{-- One helper for both fields rather than a button on each: they sit
                 together, and the same five questions answer both. Entirely
                 optional — the textareas below work exactly as they always did
                 for anyone who ignores it. --}}
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-muted dark:text-night-muted">{{ __('agents.form.step2_heading') }}</h2>
                @include('agents.partials.persona-assistant')
            </div>

            {{-- Stays until the owner saves: text they have not read yet must not
                 look like text they wrote. --}}
            <div x-show="draftApplied" x-cloak x-transition.opacity.duration.200ms
                class="flex items-start gap-2 rounded-xl border border-warning/30 bg-warning/[0.06] p-3 text-xs text-warning dark:text-warning-light">
                <svg class="mt-px h-3.5 w-3.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v4m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/></svg>
                <span>{{ __('agents.persona_assistant.draft_note') }}</span>
            </div>

            <div>
                <label for="rules_instructions" class="text-sm font-medium">{{ __('agents.form.rules_label') }}</label>
                <p class="mt-0.5 text-xs text-muted dark:text-night-muted">{{ __('agents.form.rules_help') }}</p>
                <textarea id="rules_instructions" name="rules_instructions" rows="8"
                    placeholder="{{ __('agents.form.rules_placeholder') }}"
                    class="field mt-1 w-full rounded-xl px-4 py-2.5 text-sm">{{ old('rules_instructions', $agent->rules_instructions) }}</textarea>
                @error('rules_instructions') <p class="mt-1 text-xs text-danger">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="custom_system_prompt" class="text-sm font-medium">{{ __('agents.form.prompt_label') }}</label>
                <p class="mt-0.5 text-xs text-muted dark:text-night-muted">{{ __('agents.form.prompt_help') }}</p>
                <textarea id="custom_system_prompt" name="custom_system_prompt" rows="6"
                    placeholder="{{ __('agents.form.prompt_placeholder') }}"
                    class="field mt-1 w-full rounded-xl px-4 py-2.5 text-sm">{{ old('custom_system_prompt', $agent->custom_system_prompt) }}</textarea>
                @error('custom_system_prompt') <p class="mt-1 text-xs text-danger">{{ $message }}</p> @enderror
            </div>

            <div class="flex justify-between pt-2">
                <button type="button" @click="step = 1" class="rounded-full border border-black/10 px-5 py-2.5 text-sm font-semibold transition-all hover:-translate-y-0.5 dark:border-white/10">{{ __('agents.form.back_button') }}</button>
                <button type="button" @click="step = 3" class="rounded-full bg-brand px-5 py-2.5 text-sm font-semibold text-on-accent transition-all hover:-translate-y-0.5 hover:bg-brand-hover">{{ __('agents.form.next') }}</button>
            </div>
        </div>

        {{-- Step 3: response settings --}}
        <div x-show="step === 3" x-cloak x-transition.opacity.duration.200ms class="space-y-4 rounded-2xl border border-black/10 bg-white p-5 dark:border-white/10 dark:bg-white/5">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-muted dark:text-night-muted">{{ __('agents.form.step3_heading') }}</h2>

            <div>
                <label for="greeting_message" class="text-sm font-medium">{{ __('agents.form.greeting_label') }}</label>
                <input id="greeting_message" name="greeting_message" type="text" value="{{ old('greeting_message', $rf['greeting_message'] ?? '') }}"
                    placeholder="{{ __('agents.form.greeting_placeholder') }}"
 class="field mt-1 w-full rounded-xl px-4 py-2.5 text-sm" />
            </div>

            <div>
                <label for="fallback_message" class="text-sm font-medium">{{ __('agents.form.fallback_label') }}</label>
                <input id="fallback_message" name="fallback_message" type="text" value="{{ old('fallback_message', $rf['fallback_message'] ?? '') }}"
                    placeholder="{{ __('agents.form.fallback_placeholder') }}"
 class="field mt-1 w-full rounded-xl px-4 py-2.5 text-sm" />
            </div>

            <div>
                <label for="on_not_found" class="text-sm font-medium">{{ __('agents.form.on_not_found_label') }}</label>
                <select id="on_not_found" name="on_not_found"
 class="field mt-1 w-full rounded-xl px-4 py-2.5 text-sm">
                    @foreach (__('agents.form.on_not_found_options') as $value => $label)
                        <option value="{{ $value }}" @selected(old('on_not_found', $behavior['on_not_found'] ?? 'apologize') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label for="escalation" class="text-sm font-medium">{{ __('agents.form.escalation_label') }}</label>
                <select id="escalation" name="escalation"
                    class="field mt-1 w-full rounded-xl px-4 py-2.5 text-sm">
                    @foreach (__('agents.form.escalation_options') as $value => $label)
                        <option value="{{ $value }}" @selected(old('escalation', $behavior['escalation'] ?? config('agent.escalation.default_sensitivity')) === $value)>{{ $label }}</option>
                    @endforeach
                </select>
                <p class="mt-1 text-xs text-muted dark:text-night-muted">{{ __('agents.form.escalation_help') }}</p>
            </div>

            <div>
                <label for="proactivity" class="text-sm font-medium">{{ __('agents.form.proactivity_label') }}</label>
                <select id="proactivity" name="proactivity"
 class="field mt-1 w-full rounded-xl px-4 py-2.5 text-sm">
                    @foreach (__('agents.form.proactivity_options') as $value => $label)
                        <option value="{{ $value }}" @selected(old('proactivity', $agent->proactivity ?? 'subtle') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
                <p class="mt-1 text-xs text-muted dark:text-night-muted">{{ __('agents.form.proactivity_help') }}</p>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label for="max_length" class="text-sm font-medium">{{ __('agents.form.max_length_label') }}</label>
                    <input id="max_length" name="max_length" type="number" min="50" max="4000" value="{{ old('max_length', $rf['max_length'] ?? 600) }}"
 class="field mt-1 w-full rounded-xl px-4 py-2.5 text-sm" />
                </div>
                <div class="flex items-end pb-2.5">
                    <label class="flex items-center gap-2 text-sm text-muted dark:text-night-muted">
                        <input type="hidden" name="use_emojis" value="0" />
                        <input type="checkbox" name="use_emojis" value="1" @checked(old('use_emojis', $rf['use_emojis'] ?? true)) class="h-4 w-4 rounded border-black/20 text-brand focus:ring-brand/40" />
                        {{ __('agents.form.use_emojis_label') }}
                    </label>
                </div>
            </div>

            <div class="flex justify-between pt-2">
                <button type="button" @click="step = 2" class="rounded-full border border-black/10 px-5 py-2.5 text-sm font-semibold transition-all hover:-translate-y-0.5 dark:border-white/10">{{ __('agents.form.back_button') }}</button>
                <button type="button" @click="step = 4" class="rounded-full bg-brand px-5 py-2.5 text-sm font-semibold text-on-accent transition-all hover:-translate-y-0.5 hover:bg-brand-hover">{{ __('agents.form.next') }}</button>
            </div>
        </div>

        {{-- Step 4: haggling — its own card, deliberately.

             Everything on step 3 is about how the assistant SOUNDS. This is the
             one place the owner sets what the assistant may do with their money,
             and it does not belong in a list of tone presets: a price floor
             skimmed past between "use emojis" and "max reply length" is a price
             floor set by accident.

             Three controls, three different jobs, and the copy has to keep them
             apart — the level is STYLE, the instructions are STYLE the owner
             writes themselves, and the floor is MONEY. No level and no
             instruction lets the assistant agree a price below the floor on its
             own; every one of them ends by asking the owner. --}}
        <div x-show="step === 4" x-cloak x-transition.opacity.duration.200ms class="space-y-4 rounded-2xl border border-black/10 bg-white p-5 dark:border-white/10 dark:bg-white/5">
            <div>
                <h2 class="text-sm font-semibold uppercase tracking-wide text-muted dark:text-night-muted">{{ __('agents.form.step4_heading') }}</h2>
                <p class="mt-1 text-sm text-muted dark:text-night-muted">{{ __('agents.form.haggling_section_description') }}</p>
            </div>

            <div x-data="{ haggling: {{ old('haggling_enabled', $agent->haggling_enabled ?? false) ? 'true' : 'false' }} }" class="space-y-4">
                <label class="flex items-start gap-2 rounded-xl border border-black/10 p-3 text-sm dark:border-white/10">
                    <input type="hidden" name="haggling_enabled" value="0" />
                    <input type="checkbox" name="haggling_enabled" value="1" x-model="haggling" class="mt-0.5 h-4 w-4 rounded border-black/20 text-brand focus:ring-brand/40" />
                    <span>
                        <span class="font-medium">{{ __('agents.form.haggling_label') }}</span>
                        <span class="mt-0.5 block text-xs text-muted dark:text-night-muted">{{ __('agents.form.haggling_help') }}</span>
                    </span>
                </label>

                <div x-show="haggling" x-cloak x-transition.opacity.duration.200ms class="space-y-4">
                    <div>
                        <label for="haggling_level" class="text-sm font-medium">{{ __('agents.form.haggling_level_label') }}</label>
                        <select id="haggling_level" name="haggling_level"
                            class="field mt-1 w-full rounded-xl px-4 py-2.5 text-sm">
                            @foreach (__('agents.form.haggling_level_options') as $value => $label)
                                <option value="{{ $value }}" @selected(old('haggling_level', $agent->haggling_level ?: 'moderate') === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                        <p class="mt-1 text-xs text-muted dark:text-night-muted">{{ __('agents.form.haggling_level_help') }}</p>
                        @error('haggling_level') <p class="mt-1 text-xs text-danger">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="haggling_instructions" class="text-sm font-medium">{{ __('agents.form.haggling_instructions_label') }}</label>
                        <p class="mt-0.5 text-xs text-muted dark:text-night-muted">{{ __('agents.form.haggling_instructions_help') }}</p>
                        <textarea id="haggling_instructions" name="haggling_instructions" rows="6"
                            placeholder="{{ __('agents.form.haggling_instructions_placeholder') }}"
                            class="field mt-1 w-full rounded-xl px-4 py-2.5 text-sm">{{ old('haggling_instructions', $agent->haggling_instructions) }}</textarea>
                        @error('haggling_instructions') <p class="mt-1 text-xs text-danger">{{ $message }}</p> @enderror
                    </div>

                    <div class="rounded-xl border border-black/10 p-3 dark:border-white/10">
                        <label for="haggle_min_price_percent" class="text-sm font-medium">{{ __('agents.form.haggle_floor_label') }}</label>
                        <div class="mt-1 flex items-center gap-2">
                            <input id="haggle_min_price_percent" name="haggle_min_price_percent" type="number" min="1" max="100"
                                value="{{ old('haggle_min_price_percent', $agent->haggle_min_price_percent ?? 100) }}"
                                class="field w-32 rounded-xl px-4 py-2.5 text-sm" />
                            <span class="text-sm text-muted dark:text-night-muted">{{ __('agents.form.haggle_floor_unit') }}</span>
                        </div>
                        <p class="mt-1 text-xs text-muted dark:text-night-muted">{{ __('agents.form.haggle_floor_help') }}</p>
                        {{-- Stated on the form as well as in the prompt, because
                             the textarea above invites exactly this mistake: an
                             owner writing "соглашайся на всё выше 3000" there has
                             written a sentence, not moved this number. --}}
                        <p class="mt-2 text-xs font-medium text-warning dark:text-warning-light">{{ __('agents.form.haggle_floor_authority') }}</p>
                        @error('haggle_min_price_percent') <p class="mt-1 text-xs text-danger">{{ $message }}</p> @enderror
                    </div>
                </div>
            </div>

            <div class="flex justify-between pt-2">
                <button type="button" @click="step = 3" class="rounded-full border border-black/10 px-5 py-2.5 text-sm font-semibold transition-all hover:-translate-y-0.5 dark:border-white/10">{{ __('agents.form.back_button') }}</button>
                <button type="button" @click="step = 5" class="rounded-full bg-brand px-5 py-2.5 text-sm font-semibold text-on-accent transition-all hover:-translate-y-0.5 hover:bg-brand-hover">{{ __('agents.form.next') }}</button>
            </div>
        </div>

        {{-- Step 5: confirm --}}
        <div x-show="step === 5" x-cloak x-transition.opacity.duration.200ms class="space-y-4 rounded-2xl border border-black/10 bg-white p-5 dark:border-white/10 dark:bg-white/5">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-muted dark:text-night-muted">{{ __('agents.form.step5_heading') }}</h2>
            <p class="text-sm text-muted dark:text-night-muted">
                {{ $isEdit ? __('agents.form.confirm_edit') : __('agents.form.confirm_create') }}
            </p>

            <div class="flex justify-between pt-2">
                <button type="button" @click="step = 4" class="rounded-full border border-black/10 px-5 py-2.5 text-sm font-semibold transition-all hover:-translate-y-0.5 dark:border-white/10">{{ __('agents.form.back_button') }}</button>
                <button type="submit" class="rounded-full bg-brand px-6 py-2.5 text-sm font-semibold text-on-accent shadow-glow-ember transition-all hover:-translate-y-0.5 hover:bg-brand-hover">
                    {{ $isEdit ? __('agents.form.save') : __('agents.form.create_submit') }}
                </button>
            </div>
        </div>
    </form>
</div>
@endsection
