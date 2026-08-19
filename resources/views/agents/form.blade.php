@extends('layouts.dashboard')

@php
    $isEdit = $agent->exists;
    $rf = $agent->response_format ?? [];
    $behavior = $agent->behavior ?? [];

    // Which step to open on when validation bounced the form back. Ordered, so
    // the owner lands on the FIRST step that has something wrong with it.
    $step1Fields = ['name', 'description', 'language', 'tone'];
    $step2Fields = ['rules_instructions', 'custom_system_prompt'];
    $step3Fields = ['greeting_message', 'fallback_message', 'on_not_found', 'escalation', 'proactivity', 'max_length', 'use_emojis'];

    $initialStep = match (true) {
        $errors->hasAny($step1Fields) => 1,
        $errors->hasAny($step2Fields) => 2,
        $errors->hasAny($step3Fields) => 3,
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
        <template x-for="n in 4" :key="n">
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

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label for="language" class="text-sm font-medium">{{ __('agents.form.language_label') }}</label>
                    <select id="language" name="language"
 class="field mt-1 w-full rounded-xl px-4 py-2.5 text-sm">
                        @foreach (__('agents.form.language_options') as $value => $label)
                            <option value="{{ $value }}" @selected(old('language', $agent->language ?: 'auto') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                    <p class="mt-1 text-xs text-muted dark:text-night-muted">{{ __('agents.form.preset_help') }}</p>
                </div>
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
            </div>

            <div class="flex justify-end pt-2">
                <button type="button" @click="step = 2" class="rounded-full bg-brand px-5 py-2.5 text-sm font-semibold text-on-accent transition-all hover:-translate-y-0.5 hover:bg-brand-hover">{{ __('agents.form.next') }}</button>
            </div>
        </div>

        {{-- Step 2: the agent's instructions — the two free-text fields that
             shape behaviour. Both are optional; both outrank the presets. --}}
        <div x-show="step === 2" x-cloak x-transition.opacity.duration.200ms class="space-y-4 rounded-2xl border border-black/10 bg-white p-5 dark:border-white/10 dark:bg-white/5">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-muted dark:text-night-muted">{{ __('agents.form.step2_heading') }}</h2>

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

            <div class="rounded-xl border border-black/10 p-3 dark:border-white/10">
                <label class="flex items-start gap-2 text-sm">
                    <input type="hidden" name="capture_leads" value="0" />
                    <input type="checkbox" name="capture_leads" value="1" @checked(old('capture_leads', $agent->capture_leads ?? true)) class="mt-0.5 h-4 w-4 rounded border-black/20 text-brand focus:ring-brand/40" />
                    <span>
                        <span class="font-medium">{{ __('agents.form.capture_leads_label') }}</span>
                        <span class="mt-0.5 block text-xs text-muted dark:text-night-muted">{{ __('agents.form.capture_leads_help') }}</span>
                    </span>
                </label>
            </div>

            <div class="rounded-xl border border-black/10 p-3 dark:border-white/10">
                <label class="flex items-start gap-2 text-sm">
                    <input type="hidden" name="humanize_replies" value="0" />
                    <input type="checkbox" name="humanize_replies" value="1" @checked(old('humanize_replies', $agent->humanize_replies ?? true)) class="mt-0.5 h-4 w-4 rounded border-black/20 text-brand focus:ring-brand/40" />
                    <span>
                        <span class="font-medium">{{ __('agents.form.humanize_replies_label') }}</span>
                        <span class="mt-0.5 block text-xs text-muted dark:text-night-muted">{{ __('agents.form.humanize_replies_help') }}</span>
                    </span>
                </label>
            </div>

            <div class="rounded-xl border border-black/10 p-3 dark:border-white/10">
                <label class="flex items-start gap-2 text-sm">
                    <input type="hidden" name="transcribe_voice" value="0" />
                    <input type="checkbox" name="transcribe_voice" value="1" @checked(old('transcribe_voice', $agent->transcribe_voice ?? true)) class="mt-0.5 h-4 w-4 rounded border-black/20 text-brand focus:ring-brand/40" />
                    <span>
                        <span class="font-medium">{{ __('agents.form.transcribe_voice_label') }}</span>
                        <span class="mt-0.5 block text-xs text-muted dark:text-night-muted">{{ __('agents.form.transcribe_voice_help') }}</span>
                    </span>
                </label>
            </div>

            <div class="flex justify-between pt-2">
                <button type="button" @click="step = 2" class="rounded-full border border-black/10 px-5 py-2.5 text-sm font-semibold transition-all hover:-translate-y-0.5 dark:border-white/10">{{ __('agents.form.back_button') }}</button>
                <button type="button" @click="step = 4" class="rounded-full bg-brand px-5 py-2.5 text-sm font-semibold text-on-accent transition-all hover:-translate-y-0.5 hover:bg-brand-hover">{{ __('agents.form.next') }}</button>
            </div>
        </div>

        {{-- Step 4: confirm --}}
        <div x-show="step === 4" x-cloak x-transition.opacity.duration.200ms class="space-y-4 rounded-2xl border border-black/10 bg-white p-5 dark:border-white/10 dark:bg-white/5">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-muted dark:text-night-muted">{{ __('agents.form.step4_heading') }}</h2>
            <p class="text-sm text-muted dark:text-night-muted">
                {{ $isEdit ? __('agents.form.confirm_edit') : __('agents.form.confirm_create') }}
            </p>

            <div class="flex justify-between pt-2">
                <button type="button" @click="step = 3" class="rounded-full border border-black/10 px-5 py-2.5 text-sm font-semibold transition-all hover:-translate-y-0.5 dark:border-white/10">{{ __('agents.form.back_button') }}</button>
                <button type="submit" class="rounded-full bg-brand px-6 py-2.5 text-sm font-semibold text-on-accent shadow-glow-ember transition-all hover:-translate-y-0.5 hover:bg-brand-hover">
                    {{ $isEdit ? __('agents.form.save') : __('agents.form.create_submit') }}
                </button>
            </div>
        </div>
    </form>
</div>
@endsection
