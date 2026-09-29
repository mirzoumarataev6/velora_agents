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
{{-- THE WIZARD SHELL.

     What this replaced: a 672px card centred in a 1920px window, under five
     unlabelled progress bars, with roughly two thirds of the screen blank. It
     was the clearest example on the product of a layout that had never been
     looked at on a desktop.

     Three changes, in order of how much they matter:
       1. A LABELLED stepper. The bars said "somewhere in five"; the stepper
          says which five, which one you are on, and which are done.
       2. A side panel. Tips and a live summary of the current step, including
          the exact greeting only on the step where the owner writes it.
       3. A real width. 1100px of grid rather than 672px of card.

     `x-data` carries the step and the fields the step summary mirrors.
     Each of those inputs keeps its server-rendered `value`/`selected` as the
     source of truth for a no-JS submit, and gains an `x-model` initialised to
     the same value — so Alpine and the server can never disagree about what
     is in the box. --}}
<div class="mx-auto max-w-[70rem]" x-data="{
        step: {{ $initialStep }},
        name: {{ Js::from(old('name', $agent->name ?? '')) }},
        description: {{ Js::from(old('description', $agent->description ?? '')) }},
        tone: {{ Js::from(old('tone', $agent->tone ?: 'friendly')) }},
        rules: {{ Js::from(old('rules_instructions', $agent->rules_instructions ?? '')) }},
        persona: {{ Js::from(old('custom_system_prompt', $agent->custom_system_prompt ?? '')) }},
        greeting: {{ Js::from(old('greeting_message', $rf['greeting_message'] ?? '')) }},
        fallback: {{ Js::from(old('fallback_message', $rf['fallback_message'] ?? '')) }},
        onNotFound: {{ Js::from(old('on_not_found', $behavior['on_not_found'] ?? 'apologize')) }},
        hagglingEnabled: {{ old('haggling_enabled', $agent->haggling_enabled ?? false) ? 'true' : 'false' }},
        hagglingLevel: {{ Js::from(old('haggling_level', $agent->haggling_level ?: 'moderate')) }},
        priceFloor: {{ Js::from(old('haggle_min_price_percent', $agent->haggle_min_price_percent ?? 100)) }},
        toneLabels: {{ Js::from(__('agents.form.tone_options')) }},
        notFoundLabels: {{ Js::from(__('agents.form.on_not_found_options')) }},
        hagglingLabels: {{ Js::from(__('agents.form.haggling_level_options')) }},
        get previewName() { return this.name.trim() || {{ Js::from(__('agents.form.preview_placeholder_name')) }}; },
    }">
    <a href="{{ $isEdit ? route('agents.show', $agent) : route('agents.index') }}" class="section-link text-muted hover:text-brand-strong dark:text-night-muted">
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
        {{ __('agents.form.back') }}
    </a>

    <div class="page-header mt-2">
        <div>
            <p class="page-eyebrow" x-text="`{{ __('agents.form.step_word') }} ${step} / 5`"></p>
            <h1 class="page-title">{{ $isEdit ? __('agents.form.heading_edit') : __('agents.form.heading_create') }}</h1>
        </div>
    </div>

    {{-- THE STEPPER. Clicking a marker jumps to that step: every step is
         optional past the first, the form is one POST at the end, and an
         owner who wants to go straight to haggling should not have to click
         "Далее" four times. --}}
    <nav class="stepper mb-6" aria-label="{{ __('agents.form.step_word') }}">
        @foreach (__('agents.form.step_names') as $number => $stepName)
            <div class="stepper-step"
                :data-state="step === {{ $number }} ? 'current' : (step > {{ $number }} ? 'done' : 'todo')">
                <button type="button" @click="step = {{ $number }}"
                    class="flex items-start gap-2.5 text-left"
                    :aria-current="step === {{ $number }} ? 'step' : false"
                    aria-label="{{ __('agents.form.step_word') }} {{ $number }}: {{ $stepName }}">
                    <span class="stepper-marker">
                        {{-- A completed step shows a tick instead of its
                             number: the number is only useful while it is
                             still ahead of you. --}}
                        <span x-show="step <= {{ $number }}">{{ $number }}</span>
                        <svg x-show="step > {{ $number }}" x-cloak class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                    </span>
                    <span class="stepper-label">{{ $stepName }}</span>
                </button>
                @if (! $loop->last)
                    <span class="stepper-line" aria-hidden="true"></span>
                @endif
            </div>
        @endforeach
    </nav>

    <div class="grid grid-cols-1 gap-5 lg:grid-cols-[minmax(0,1fr)_20rem]">
    <div class="min-w-0">
    <form method="POST" action="{{ $isEdit ? route('agents.update', $agent) : route('agents.store') }}" class="mt-6 space-y-6">
        @csrf
        @if ($isEdit) @method('PUT') @endif

        {{-- Step 1: basics --}}
        <div x-show="step === 1" x-cloak x-transition.opacity.duration.200ms class="card reveal is-visible space-y-4 p-5">

            <div>
                <label for="name" class="text-sm font-medium">{{ __('agents.form.name_label') }}</label>
                <input id="name" name="name" type="text" required value="{{ old('name', $agent->name) }}"
                    x-model="name"
                    placeholder="{{ __('agents.form.name_placeholder') }}"
                    class="field mt-1 w-full px-4 py-2.5 text-sm" />
                @error('name') <p class="mt-1 text-xs text-danger">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="description" class="text-sm font-medium">{{ __('agents.form.description_label') }}</label>
                <textarea id="description" name="description" rows="3"
                    x-model="description"
                    placeholder="{{ __('agents.form.description_placeholder') }}"
                    class="field mt-1 w-full px-4 py-2.5 text-sm">{{ old('description', $agent->description) }}</textarea>
                @error('description') <p class="mt-1 text-xs text-danger">{{ $message }}</p> @enderror
            </div>

            {{-- No language selector: the reply language is detected from each
                 incoming message, so an agent follows a customer who switches
                 mid-conversation instead of being pinned to one. --}}
            <div>
                <label for="tone" class="text-sm font-medium">{{ __('agents.form.tone_label') }}</label>
                <select id="tone" name="tone" x-model="tone"
                    class="field mt-1 w-full px-4 py-2.5 text-sm">
                    @foreach (__('agents.form.tone_options') as $value => $label)
                        <option value="{{ $value }}" @selected(old('tone', $agent->tone ?: 'friendly') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
                <p class="mt-1 text-xs text-muted dark:text-night-muted">{{ __('agents.form.preset_help') }}</p>
            </div>

            <div class="flex justify-end pt-2">
                <button type="button" @click="step = 2" class="btn btn-primary">{{ __('agents.form.next') }}</button>
            </div>
        </div>

        {{-- Step 2: the agent's instructions — the two free-text fields that
             shape behaviour. Both are optional; both outrank the presets. --}}
        <div x-show="step === 2" x-cloak x-transition.opacity.duration.200ms class="card space-y-4 p-5"
            x-data="{ draftApplied: false }" @persona-draft-applied.window="draftApplied = true">
            {{-- One helper for both fields rather than a button on each: they sit
                 together, and the same five questions answer both. Entirely
                 optional — the textareas below work exactly as they always did
                 for anyone who ignores it. --}}
            {{-- The step's name is in the stepper above; what belongs here is
                 only the thing the stepper cannot carry — the assistant that
                 drafts both textareas for you. --}}
            <div class="flex flex-wrap items-center justify-end gap-3">
                @include('agents.partials.persona-assistant')
            </div>

            {{-- Stays until the owner saves: text they have not read yet must not
                 look like text they wrote. --}}
            <div x-show="draftApplied" x-cloak x-transition.opacity.duration.200ms
                class="flex items-start gap-2 rounded-xl border-warning/30 bg-warning/[0.06] p-3 text-xs text-warning dark:text-warning-light">
                <svg class="mt-px h-3.5 w-3.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v4m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/></svg>
                <span>{{ __('agents.persona_assistant.draft_note') }}</span>
            </div>

            <div>
                <label for="rules_instructions" class="text-sm font-medium">{{ __('agents.form.rules_label') }}</label>
                <p class="mt-0.5 text-xs text-muted dark:text-night-muted">{{ __('agents.form.rules_help') }}</p>
                <textarea id="rules_instructions" name="rules_instructions" rows="8"
                    x-model="rules"
                    placeholder="{{ __('agents.form.rules_placeholder') }}"
                    class="field mt-1 w-full px-4 py-2.5 text-sm">{{ old('rules_instructions', $agent->rules_instructions) }}</textarea>
                @error('rules_instructions') <p class="mt-1 text-xs text-danger">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="custom_system_prompt" class="text-sm font-medium">{{ __('agents.form.prompt_label') }}</label>
                <p class="mt-0.5 text-xs text-muted dark:text-night-muted">{{ __('agents.form.prompt_help') }}</p>
                <textarea id="custom_system_prompt" name="custom_system_prompt" rows="6"
                    x-model="persona"
                    placeholder="{{ __('agents.form.prompt_placeholder') }}"
                    class="field mt-1 w-full px-4 py-2.5 text-sm">{{ old('custom_system_prompt', $agent->custom_system_prompt) }}</textarea>
                @error('custom_system_prompt') <p class="mt-1 text-xs text-danger">{{ $message }}</p> @enderror
            </div>

            <div class="flex justify-between pt-2">
                <button type="button" @click="step = 1" class="btn btn-ghost">{{ __('agents.form.back_button') }}</button>
                <button type="button" @click="step = 3" class="btn btn-primary">{{ __('agents.form.next') }}</button>
            </div>
        </div>

        {{-- Step 3: response settings --}}
        <div x-show="step === 3" x-cloak x-transition.opacity.duration.200ms class="card space-y-4 p-5">

            <div>
                <label for="greeting_message" class="text-sm font-medium">{{ __('agents.form.greeting_label') }}</label>
                <input id="greeting_message" name="greeting_message" type="text" value="{{ old('greeting_message', $rf['greeting_message'] ?? '') }}"
                    x-model="greeting"
                    placeholder="{{ __('agents.form.greeting_placeholder') }}"
                    class="field mt-1 w-full px-4 py-2.5 text-sm" />
            </div>

            <div>
                <label for="fallback_message" class="text-sm font-medium">{{ __('agents.form.fallback_label') }}</label>
                <input id="fallback_message" name="fallback_message" type="text" value="{{ old('fallback_message', $rf['fallback_message'] ?? '') }}"
                    x-model="fallback"
                    placeholder="{{ __('agents.form.fallback_placeholder') }}"
 class="field mt-1 w-full px-4 py-2.5 text-sm" />
            </div>

            <div>
                <label for="on_not_found" class="text-sm font-medium">{{ __('agents.form.on_not_found_label') }}</label>
                <select id="on_not_found" name="on_not_found" x-model="onNotFound"
 class="field mt-1 w-full px-4 py-2.5 text-sm">
                    @foreach (__('agents.form.on_not_found_options') as $value => $label)
                        <option value="{{ $value }}" @selected(old('on_not_found', $behavior['on_not_found'] ?? 'apologize') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label for="escalation" class="text-sm font-medium">{{ __('agents.form.escalation_label') }}</label>
                <select id="escalation" name="escalation"
                    class="field mt-1 w-full px-4 py-2.5 text-sm">
                    @foreach (__('agents.form.escalation_options') as $value => $label)
                        <option value="{{ $value }}" @selected(old('escalation', $behavior['escalation'] ?? config('agent.escalation.default_sensitivity')) === $value)>{{ $label }}</option>
                    @endforeach
                </select>
                <p class="mt-1 text-xs text-muted dark:text-night-muted">{{ __('agents.form.escalation_help') }}</p>
            </div>

            <div>
                <label for="proactivity" class="text-sm font-medium">{{ __('agents.form.proactivity_label') }}</label>
                <select id="proactivity" name="proactivity"
 class="field mt-1 w-full px-4 py-2.5 text-sm">
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
 class="field mt-1 w-full px-4 py-2.5 text-sm" />
                </div>
                <div class="flex items-end pb-2.5">
                    <label class="flex items-center gap-2 text-sm text-muted dark:text-night-muted">
                        <input type="hidden" name="use_emojis" value="0" />
                        <input type="checkbox" name="use_emojis" value="1" @checked(old('use_emojis', $rf['use_emojis'] ?? true)) class="field-check h-4 w-4" />
                        {{ __('agents.form.use_emojis_label') }}
                    </label>
                </div>
            </div>

            <div class="flex justify-between pt-2">
                <button type="button" @click="step = 2" class="btn btn-ghost">{{ __('agents.form.back_button') }}</button>
                <button type="button" @click="step = 4" class="btn btn-primary">{{ __('agents.form.next') }}</button>
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
        <div x-show="step === 4" x-cloak x-transition.opacity.duration.200ms class="card space-y-4 p-5">
            <p class="text-sm text-muted dark:text-night-muted">{{ __('agents.form.haggling_section_description') }}</p>

            <div class="space-y-4">
                <label class="flex items-start gap-2 rounded-xl p-3 text-sm">
                    <input type="hidden" name="haggling_enabled" value="0" />
                    <input type="checkbox" name="haggling_enabled" value="1" x-model="hagglingEnabled" class="mt-0.5 h-4 w-4 rounded text-brand-strong focus:ring-brand/40" />
                    <span>
                        <span class="font-medium">{{ __('agents.form.haggling_label') }}</span>
                        <span class="mt-0.5 block text-xs text-muted dark:text-night-muted">{{ __('agents.form.haggling_help') }}</span>
                    </span>
                </label>

                <div x-show="hagglingEnabled" x-cloak x-transition.opacity.duration.200ms class="space-y-4">
                    <div>
                        <label for="haggling_level" class="text-sm font-medium">{{ __('agents.form.haggling_level_label') }}</label>
                        <select id="haggling_level" name="haggling_level" x-model="hagglingLevel"
                            class="field mt-1 w-full px-4 py-2.5 text-sm">
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
                            class="field mt-1 w-full px-4 py-2.5 text-sm">{{ old('haggling_instructions', $agent->haggling_instructions) }}</textarea>
                        @error('haggling_instructions') <p class="mt-1 text-xs text-danger">{{ $message }}</p> @enderror
                    </div>

                    <div class="rounded-xl p-3">
                        <label for="haggle_min_price_percent" class="text-sm font-medium">{{ __('agents.form.haggle_floor_label') }}</label>
                        <div class="mt-1 flex items-center gap-2">
                            <input id="haggle_min_price_percent" name="haggle_min_price_percent" type="number" min="1" max="100"
                                x-model="priceFloor"
                                value="{{ old('haggle_min_price_percent', $agent->haggle_min_price_percent ?? 100) }}"
                                class="field w-32 px-4 py-2.5 text-sm" />
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
                <button type="button" @click="step = 3" class="btn btn-ghost">{{ __('agents.form.back_button') }}</button>
                <button type="button" @click="step = 5" class="btn btn-primary">{{ __('agents.form.next') }}</button>
            </div>
        </div>

        {{-- Step 5: confirm --}}
        <div x-show="step === 5" x-cloak x-transition.opacity.duration.200ms class="card space-y-4 p-5">
            <p class="text-sm text-muted dark:text-night-muted">
                {{ $isEdit ? __('agents.form.confirm_edit') : __('agents.form.confirm_create') }}
            </p>

            <div class="flex justify-between pt-2">
                <button type="button" @click="step = 4" class="btn btn-ghost">{{ __('agents.form.back_button') }}</button>
                <button type="submit" class="btn btn-primary"
                    @click="if (!name.trim()) { $event.preventDefault(); step = 1; $nextTick(() => document.getElementById('name')?.focus()); }">
                    {{ $isEdit ? __('agents.form.save') : __('agents.form.create_submit') }}
                </button>
            </div>
        </div>
    </form>
    </div>

    {{-- A step-specific summary, followed by tips. It stays beside the field
         being filled in and reflects the actual form values. --}}
    <aside class="hidden lg:block">
        <div class="sticky top-6 space-y-4">
            <div class="card card-pad">
                <p class="text-overline text-muted dark:text-night-muted">{{ __('agents.form.preview_title') }}</p>
                <p class="mt-1 text-xs text-muted dark:text-night-muted" x-show="step === 1" x-cloak>{{ __('agents.form.preview_step_1') }}</p>
                <p class="mt-1 text-xs text-muted dark:text-night-muted" x-show="step === 2" x-cloak>{{ __('agents.form.preview_step_2') }}</p>
                <p class="mt-1 text-xs text-muted dark:text-night-muted" x-show="step === 3" x-cloak>{{ __('agents.form.preview_step_3') }}</p>
                <p class="mt-1 text-xs text-muted dark:text-night-muted" x-show="step === 4" x-cloak>{{ __('agents.form.preview_step_4') }}</p>
                <p class="mt-1 text-xs text-muted dark:text-night-muted" x-show="step === 5" x-cloak>{{ __('agents.form.preview_step_5') }}</p>

                <div x-show="step === 1" x-cloak class="mt-4 space-y-3">
                    <div class="flex items-center gap-2.5">
                        <span class="icon-circle icon-circle-sm shrink-0" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path stroke-linecap="round" stroke-linejoin="round" d="M12 12a4 4 0 100-8 4 4 0 000 8zm0 0c-4.4 0-8 2.24-8 5v2h16v-2c0-2.76-3.6-5-8-5z"/></svg>
                        </span>
                        <div class="min-w-0">
                            <p class="truncate text-sm font-semibold" x-text="previewName"></p>
                            <p class="truncate text-xs text-muted dark:text-night-muted" x-text="toneLabels[tone] ?? ''"></p>
                        </div>
                    </div>
                    <p class="line-clamp-4 text-xs leading-relaxed text-muted dark:text-night-muted"
                        x-text="description.trim() || @js(__('agents.form.preview_description_empty'))"></p>
                </div>

                <div x-show="step === 2" x-cloak class="mt-4 space-y-3">
                    <div>
                        <p class="text-xs font-semibold">{{ __('agents.form.rules_label') }}</p>
                        <p class="mt-1 line-clamp-4 whitespace-pre-line text-xs leading-relaxed text-muted dark:text-night-muted"
                            x-text="rules.trim() || @js(__('agents.form.preview_empty'))"></p>
                    </div>
                    <div class="border-t border-hairline pt-3 dark:border-white/10">
                        <p class="text-xs font-semibold">{{ __('agents.form.prompt_label') }}</p>
                        <p class="mt-1 line-clamp-4 whitespace-pre-line text-xs leading-relaxed text-muted dark:text-night-muted"
                            x-text="persona.trim() || @js(__('agents.form.preview_empty'))"></p>
                    </div>
                </div>

                <div x-show="step === 3" x-cloak class="mt-4 space-y-3">
                    <div class="rounded-xl bg-sunk p-3 dark:bg-night-raised">
                        <p class="text-xs text-faint dark:text-night-muted">{{ __('agents.form.preview_first_message') }}</p>
                        <p class="mt-1.5 break-words text-sm leading-relaxed"
                            x-text="greeting.trim() || @js(__('agents.form.preview_greeting_empty'))"></p>
                    </div>
                    <div>
                        <p class="text-xs font-semibold">{{ __('agents.form.fallback_label') }}</p>
                        <p class="mt-1 break-words text-xs leading-relaxed text-muted dark:text-night-muted"
                            x-text="fallback.trim() || @js(__('agents.form.preview_empty'))"></p>
                    </div>
                    <p class="border-t border-hairline pt-3 text-xs text-muted dark:border-white/10 dark:text-night-muted"
                        x-text="notFoundLabels[onNotFound] ?? ''"></p>
                </div>

                <div x-show="step === 4" x-cloak class="mt-4 space-y-3">
                    <p class="text-sm font-semibold"
                        x-text="hagglingEnabled ? @js(__('agents.form.preview_haggling_on')) : @js(__('agents.form.preview_haggling_off'))"></p>
                    <template x-if="hagglingEnabled">
                        <div class="rounded-xl bg-sunk p-3 dark:bg-night-raised">
                            <p class="text-xs text-muted dark:text-night-muted">{{ __('agents.form.haggling_level_label') }}</p>
                            <p class="mt-1 text-sm font-medium" x-text="hagglingLabels[hagglingLevel] ?? ''"></p>
                            <p class="mt-3 text-xs text-muted dark:text-night-muted">{{ __('agents.form.preview_floor_label') }}</p>
                            <p class="mt-1 text-lg font-semibold tnum" x-text="`${priceFloor}%`"></p>
                            <p class="mt-1 text-xs leading-relaxed text-muted dark:text-night-muted">{{ __('agents.form.preview_floor_help') }}</p>
                        </div>
                    </template>
                </div>

                <div x-show="step === 5" x-cloak class="mt-4 space-y-3 text-sm">
                    <p class="font-semibold" x-text="name.trim() || @js(__('agents.form.preview_name_missing'))"></p>
                    <p class="text-xs text-muted dark:text-night-muted" x-text="toneLabels[tone] ?? ''"></p>
                    <div class="border-t border-hairline pt-3 text-xs leading-relaxed text-muted dark:border-white/10 dark:text-night-muted">
                        <p x-text="greeting.trim() ? @js(__('agents.form.preview_greeting_set')) : @js(__('agents.form.preview_greeting_empty'))"></p>
                        <p class="mt-1" x-text="hagglingEnabled ? @js(__('agents.form.preview_haggling_on')) : @js(__('agents.form.preview_haggling_off'))"></p>
                    </div>
                    <p class="rounded-xl bg-azure-50 p-3 text-xs leading-relaxed text-brand-strong dark:bg-night-raised dark:text-brand-light">{{ __('agents.form.preview_after_save') }}</p>
                </div>
            </div>

            {{-- Tips for the CURRENT step, swapped as the step changes. All
                 five sets are in the DOM rather than fetched, so the swap is
                 instant and the instant locale switch can walk them. --}}
            <div class="card card-pad">
                <p class="text-overline text-muted dark:text-night-muted">{{ __('agents.form.tips_title') }}</p>
                @foreach (__('agents.form.step_tips') as $number => $stepTips)
                    <ul x-show="step === {{ $number }}" x-cloak x-transition.opacity.duration.200ms class="mt-3 space-y-2.5">
                        @foreach ($stepTips as $tip)
                            <li class="flex gap-2 text-xs leading-relaxed text-muted dark:text-night-muted">
                                <svg class="mt-0.5 h-3.5 w-3.5 shrink-0 text-brand-strong dark:text-brand-light" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                                <span>{{ $tip }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endforeach
            </div>
        </div>
    </aside>
    </div>
</div>
@endsection
