@php
    /**
     * The «Подключение к базе данных» tab — the advanced product source.
     *
     * ONE STEP AT A TIME. The tab has three states and shows the one the owner
     * is actually in, rather than stacking every section on one page:
     *
     *   connect — no connection yet: the credentials form, and nothing about
     *             tables or mapping, which are meaningless before a server
     *             answers.
     *   review  — connected but not confirmed: what discovery found, why, real
     *             rows, and Confirm. The credentials fold into one line.
     *   done    — confirmed: a compact status card. Rescanning, changing the
     *             table and disconnecting are secondary actions; the manual
     *             panel stays collapsed as the escape hatch it is.
     *
     * `step` is DERIVED from the connection payload, never stored: there is no
     * new state to keep in sync with the server, and a refresh lands the owner
     * exactly where they were.
     *
     * Nothing here is written until the connection has actually been proven, so
     * an owner cannot end up with a saved connection that has never worked.
     */
    $fieldLabels = __('agents.database.fields');
@endphp

<div x-data="databaseTab({
        showUrl: '{{ route('agents.database.show', $agent) }}',
        testUrl: '{{ route('agents.database.test', $agent) }}',
        storeUrl: '{{ route('agents.database.store', $agent) }}',
        discoverUrl: '{{ route('agents.database.discover', $agent) }}',
        confirmUrl: '{{ route('agents.database.discovery.confirm', $agent) }}',
        tablesUrl: '{{ route('agents.database.tables', $agent) }}',
        describeUrl: '{{ route('agents.database.describe', $agent) }}',
        mappingUrl: '{{ route('agents.database.mapping', $agent) }}',
        sourceUrl: '{{ route('agents.database.source', $agent) }}',
        destroyUrl: '{{ route('agents.database.destroy', $agent) }}',
        initial: {{ Js::from($database) }},
    })" class="space-y-7">

    {{-- ── Header ──────────────────────────────────────────────────────────
         One line of what this is, and one button for everything else. The
         explanation and the read-only guidance are both still here, a click
         away — they are worth reading once and worth not re-reading on every
         visit. --}}
    <header>
        <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
            <h3 class="text-sm font-semibold">{{ __('agents.database.intro_title') }}</h3>
            <span class="inline-flex items-center rounded-full bg-brand/10 px-2 py-0.5 text-[11px] font-medium text-brand">
                {{ __('agents.database.badge') }}
            </span>
            <button type="button" @click="showHelp = !showHelp"
                :aria-expanded="showHelp" aria-controls="db-help"
                class="ml-auto inline-flex items-center gap-1 rounded-full px-2 py-1 text-xs text-muted transition-colors hover:text-brand dark:text-night-muted">
                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path stroke-linecap="round" d="M12 11v5M12 7.5v.5"/></svg>
                {{ __('agents.database.help_more') }}
            </button>
        </div>

        <p class="mt-1 text-xs text-muted dark:text-night-muted">{{ __('agents.database.intro_tagline') }}</p>

        <div id="db-help" x-show="showHelp" x-cloak x-transition.opacity.duration.200ms class="mt-3 space-y-2 border-l-2 border-black/10 pl-3 dark:border-white/10">
            <p class="text-xs leading-relaxed text-muted dark:text-night-muted">{{ __('agents.database.intro_description') }}</p>
            <p class="text-xs leading-relaxed text-warning dark:text-warning-light">{{ __('agents.database.readonly_notice') }}</p>
        </div>

        {{-- Where the owner is, in three words. Not a progress bar: the steps
             are states, and going back to one is normal. --}}
        <ol class="mt-5 flex items-center gap-2 text-[11px] font-medium" aria-label="{{ __('agents.database.steps.aria') }}">
            @foreach (['connect', 'review', 'done'] as $index => $name)
                @if ($index > 0)
                    <li aria-hidden="true" class="h-px w-6 flex-none bg-black/10 dark:bg-white/10"></li>
                @endif
                <li class="flex items-center gap-1.5"
                    :aria-current="step === '{{ $name }}' ? 'step' : false">
                    <span class="flex h-4 w-4 flex-none items-center justify-center rounded-full text-[10px] leading-none transition-colors"
                        :class="stepState('{{ $name }}') === 'done'
                            ? 'bg-success/15 text-success dark:text-success-light'
                            : (stepState('{{ $name }}') === 'current'
                                ? 'bg-brand text-on-accent'
                                : 'bg-black/[0.06] text-muted dark:bg-white/10 dark:text-night-muted')">
                        <template x-if="stepState('{{ $name }}') === 'done'">
                            <svg class="h-2.5 w-2.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m5 13 4 4L19 7"/></svg>
                        </template>
                        <template x-if="stepState('{{ $name }}') !== 'done'">
                            <span>{{ $index + 1 }}</span>
                        </template>
                    </span>
                    <span :class="stepState('{{ $name }}') === 'pending' ? 'text-muted dark:text-night-muted' : ''">
                        {{ __("agents.database.steps.{$name}") }}
                    </span>
                </li>
            @endforeach
        </ol>
    </header>

    {{-- ── Which source answers customers ──────────────────────────────────
         The most important fact on the tab and the smallest control on it:
         one line, two choices, the live one marked. --}}
    <div class="flex flex-wrap items-center gap-x-3 gap-y-2 rounded-xl bg-black/[0.03] px-3 py-2 dark:bg-white/[0.04]">
        <span class="text-xs font-medium text-muted dark:text-night-muted">{{ __('agents.database.source_title') }}</span>

        <div class="flex items-center gap-0.5 rounded-lg bg-black/[0.04] p-0.5 dark:bg-white/[0.06]">
            @foreach (['dataset', 'database'] as $source)
                <button type="button" @click="activeSource === '{{ $source }}' || switchSource('{{ $source }}')"
                    :disabled="switching" :aria-pressed="activeSource === '{{ $source }}'"
                    title="{{ __("agents.database.source_{$source}_hint") }}"
                    :class="activeSource === '{{ $source }}'
                        ? 'bg-surface text-brand shadow-sm dark:bg-white/10'
                        : 'text-muted hover:text-ink dark:text-night-muted dark:hover:text-night-ink'"
                    class="rounded-md px-2.5 py-1 text-xs font-medium transition-colors disabled:opacity-60">
                    {{ __("agents.database.source_{$source}") }}
                </button>
            @endforeach
        </div>

        <span x-show="activeSource === 'dataset' && !hasDataset" x-cloak
            class="text-[11px] text-warning dark:text-warning-light">
            {{ __('agents.database.source_empty_dataset') }}
        </span>
    </div>

    {{-- ── Connected: the credentials, folded to one line ──────────────────
         Once a server has answered, the host and the password are settings —
         not the thing the owner came here to look at. --}}
    <div x-show="connection && !editingCredentials" x-cloak
        class="flex flex-wrap items-center gap-x-3 gap-y-2 rounded-xl border border-black/10 px-4 py-3 dark:border-white/10">
        <svg class="h-4 w-4 flex-none text-muted dark:text-night-muted/70" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><ellipse cx="12" cy="6" rx="8" ry="3"/><path stroke-linecap="round" d="M4 6v12c0 1.66 3.58 3 8 3s8-1.34 8-3V6M4 12c0 1.66 3.58 3 8 3s8-1.34 8-3"/></svg>

        <span class="min-w-0 truncate text-sm font-medium" x-text="connectionSummary()"></span>

        <span :class="connection?.status === 'ready'
                ? 'bg-success/10 text-success dark:text-success-light'
                : (connection?.status === 'error' ? 'bg-danger/10 text-danger' : 'bg-black/[0.05] text-muted dark:bg-white/5 dark:text-night-muted')"
            class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-medium"
            x-text="statusLabel()"></span>

        <button type="button" @click="editingCredentials = true" :disabled="busy"
            class="ml-auto rounded-full px-2 py-1 text-xs font-medium text-brand transition-colors hover:underline disabled:opacity-60">
            {{ __('agents.database.edit_connection') }}
        </button>
    </div>

    {{-- ── Step 1: credentials ─────────────────────────────────────────────
         The whole screen when there is no connection, and a panel the owner
         opened on purpose otherwise. --}}
    <section x-show="!connection || editingCredentials" x-cloak x-transition.opacity.duration.200ms>
        <div class="flex flex-wrap items-baseline justify-between gap-2">
            <h4 class="text-sm font-semibold">{{ __('agents.database.form_title') }}</h4>
            <button type="button" x-show="connection" x-cloak @click="cancelEditing()"
                class="text-xs font-medium text-muted transition-colors hover:text-brand dark:text-night-muted">
                {{ __('agents.database.cancel_editing') }}
            </button>
        </div>

        <div class="mt-4 grid gap-4 sm:grid-cols-2">
            <label class="block">
                <span class="text-xs font-medium text-muted dark:text-night-muted">{{ __('agents.database.engine_label') }}</span>
                <select x-model="form.engine" @change="applyDefaultPort()"
                    class="field mt-1 w-full rounded-xl px-3 py-2 text-sm">
                    <template x-for="engine in engines" :key="engine.key">
                        <option :value="engine.key" x-text="engine.label"></option>
                    </template>
                </select>
            </label>

            <div class="grid grid-cols-3 gap-3">
                <label class="col-span-2 block">
                    <span class="text-xs font-medium text-muted dark:text-night-muted">{{ __('agents.database.host_label') }}</span>
                    <input type="text" x-model="form.host" placeholder="{{ __('agents.database.host_placeholder') }}"
                        class="field mt-1 w-full rounded-xl px-3 py-2 text-sm" />
                </label>
                <label class="block">
                    <span class="text-xs font-medium text-muted dark:text-night-muted">{{ __('agents.database.port_label') }}</span>
                    <input type="number" min="1" max="65535" x-model.number="form.port"
                        class="field mt-1 w-full rounded-xl px-3 py-2 text-sm" />
                </label>
            </div>

            <label class="block">
                <span class="text-xs font-medium text-muted dark:text-night-muted">{{ __('agents.database.database_label') }}</span>
                <input type="text" x-model="form.database" class="field mt-1 w-full rounded-xl px-3 py-2 text-sm" />
            </label>

            <label class="block">
                <span class="text-xs font-medium text-muted dark:text-night-muted">{{ __('agents.database.username_label') }}</span>
                <input type="text" x-model="form.username" autocomplete="off"
                    class="field mt-1 w-full rounded-xl px-3 py-2 text-sm" />
            </label>

            <label class="block">
                <span class="text-xs font-medium text-muted dark:text-night-muted">{{ __('agents.database.password_label') }}</span>
                {{-- Always empty on load. The stored secret is never sent to the
                     browser; leaving this blank keeps whatever is saved. --}}
                <input type="password" x-model="form.password" autocomplete="new-password"
                    :placeholder="connection?.has_password ? passwordSavedLabel : ''"
                    class="field mt-1 w-full rounded-xl px-3 py-2 text-sm" />
            </label>

            <label class="flex items-start gap-2 sm:pt-6">
                <input type="checkbox" x-model="form.ssl" class="mt-0.5 h-4 w-4 rounded border-black/20 text-brand dark:border-white/20" />
                <span>
                    <span class="text-sm font-medium">{{ __('agents.database.ssl_label') }}</span>
                    <span class="block text-xs text-muted dark:text-night-muted">{{ __('agents.database.ssl_hint') }}</span>
                </span>
            </label>
        </div>

        <div class="mt-4 flex flex-wrap items-center justify-end gap-3">
            <p x-show="error" x-cloak x-text="error" class="mr-auto text-xs text-danger"></p>
            <p x-show="tested" x-cloak class="mr-auto text-xs text-success dark:text-success-light">
                {{ __('agents.database.toasts.connection_ok') }}
            </p>

            <button type="button" @click="test()" :disabled="busy"
                class="rounded-full border border-black/10 px-4 py-2 text-sm font-semibold disabled:opacity-60 dark:border-white/10">
                <span x-show="!testing">{{ __('agents.database.test_button') }}</span>
                <span x-show="testing" x-cloak class="spinner"></span>
            </button>
            <button type="button" @click="save()" :disabled="busy"
                class="rounded-full bg-brand px-5 py-2 text-sm font-semibold text-on-accent hover:bg-brand-hover disabled:opacity-60">
                <span x-show="!saving">{{ __('agents.database.save_button') }}</span>
                <span x-show="saving" x-cloak class="spinner"></span>
            </button>
        </div>
    </section>

    {{-- ── Step 3: the connection at rest ──────────────────────────────────
         What is live, in one card, with everything else behind a secondary
         action. This is the screen an owner sees every visit after setup, so
         it is the one that has to stay quiet. --}}
    <section x-show="step === 'done' && !editingCredentials && !discovering && columns.length === 0 && proposals.length === 0" x-cloak>
        <div class="rounded-2xl border border-black/10 p-5 dark:border-white/10">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-success/10 px-2.5 py-1 text-xs font-medium text-success dark:text-success-light">
                        <svg class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m5 13 4 4L19 7"/></svg>
                        {{ __('agents.database.configured_title') }}
                    </span>
                    <p class="mt-2 truncate text-sm font-semibold" x-text="tableLabel()"></p>
                    <p x-show="connection?.discovered_at" x-cloak class="text-xs text-muted dark:text-night-muted"
                        x-text="discoveredLabel.replace(':time', connection.discovered_at)"></p>
                </div>

                <span x-show="connection?.confidence" x-cloak
                    :class="confidenceClass(connection?.confidence)"
                    class="inline-flex flex-none items-center rounded-full px-2.5 py-0.5 text-[11px] font-medium"
                    x-text="confidenceLabels[connection?.confidence] ?? ''"></span>
            </div>

            {{-- Mapping and joins as one flat run of chips: two lists of the
                 same kind of fact, and boxing each of them was what made this
                 card look like a form. --}}
            <div class="mt-4 flex flex-wrap gap-1.5">
                <template x-for="entry in mappingSummary()" :key="'m-' + entry.field">
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-black/[0.04] px-2.5 py-1 text-xs dark:bg-white/5">
                        <span class="font-medium" x-text="entry.label"></span>
                        <span class="text-muted dark:text-night-muted" x-text="entry.column"></span>
                    </span>
                </template>
                <template x-for="join in (connection?.joins ?? [])" :key="'j-' + join.table">
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-brand/[0.08] px-2.5 py-1 text-xs">
                        <span class="font-medium" x-text="join.fields.map((f) => fieldLabels[f] ?? f).join(', ')"></span>
                        <span class="text-muted dark:text-night-muted">←</span>
                        <span class="text-muted dark:text-night-muted" x-text="join.table"></span>
                    </span>
                </template>
            </div>

            <div class="mt-5 flex flex-wrap items-center gap-x-4 gap-y-2 border-t border-black/5 pt-4 text-xs font-medium dark:border-white/10">
                <button type="button" @click="discover()" :disabled="busy" class="text-brand transition-colors hover:underline disabled:opacity-60">
                    <span x-show="!discovering">{{ __('agents.database.discovery.rescan') }}</span>
                    <span x-show="discovering" x-cloak class="spinner"></span>
                </button>
                <button type="button" @click="chooseTable(connection.table)" :disabled="busy" class="text-muted transition-colors hover:text-brand disabled:opacity-60 dark:text-night-muted">
                    {{ __('agents.database.change_table') }}
                </button>
                <button type="button" @click="disconnect()" :disabled="busy" class="ml-auto text-danger transition-colors hover:underline disabled:opacity-60">
                    {{ __('agents.database.disconnect') }}
                </button>
            </div>
        </div>
    </section>

    {{-- ── Step 2: what we found ───────────────────────────────────────────
         The review screen. Visible while there is a connection and no
         confirmed table, and whenever a rescan has produced something to
         look at. --}}
    <section x-show="connection && !editingCredentials && columns.length === 0 && (step === 'review' || proposals.length > 0 || discovering)"
        x-cloak class="space-y-4">

        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h4 class="text-sm font-semibold">{{ __('agents.database.discovery.title') }}</h4>
                <p x-show="!scanned && !discovering" x-cloak class="mt-1 max-w-xl text-xs leading-relaxed text-muted dark:text-night-muted">
                    {{ __('agents.database.discovery.description') }}
                </p>
                <p x-show="discovering" x-cloak class="mt-1 text-xs text-muted dark:text-night-muted">
                    {{ __('agents.database.discovery.scanning') }}
                </p>
                <p x-show="proposals.length > 1" x-cloak class="mt-1 text-xs text-muted dark:text-night-muted">
                    {{ __('agents.database.discovery.summary_ambiguous') }}
                </p>
            </div>

            <button type="button" @click="discover()" :disabled="busy"
                :class="proposals.length
                    ? 'border border-black/10 dark:border-white/10'
                    : 'bg-brand text-on-accent hover:bg-brand-hover'"
                class="flex-none rounded-full px-5 py-2 text-sm font-semibold disabled:opacity-60">
                <span x-show="!discovering" x-text="proposals.length ? rescanLabel : discoverLabel"></span>
                <span x-show="discovering" x-cloak class="spinner"></span>
            </button>
        </div>

        {{-- Nothing recognizable: say so plainly and point at the fallback,
             rather than proposing something weak. --}}
        <p x-show="scanned && proposals.length === 0" x-cloak
            class="rounded-xl bg-warning/10 px-3 py-2 text-xs leading-relaxed text-warning dark:text-warning-light">
            {{ __('agents.database.discovery.summary_none') }}
        </p>

        {{-- THE TOP CANDIDATE, open. One surface, no border: it is the only
             thing on this screen, and a box around the only thing is noise. --}}
        <template x-if="proposals.length > 0">
            <div>
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <div class="min-w-0">
                        <p class="text-sm font-medium" x-text="summaryFor(proposals[0], 0)"></p>
                        <p class="mt-0.5 text-xs text-muted dark:text-night-muted" x-text="joinSentence(proposals[0])"></p>
                    </div>
                    <div class="flex flex-none flex-col items-end gap-1">
                        <span :class="confidenceClass(proposals[0].confidence)"
                            class="inline-flex items-center rounded-full px-2.5 py-0.5 text-[11px] font-medium"
                            x-text="confidenceLabels[proposals[0].confidence] ?? ''"></span>
                        <span x-show="proposals[0].row_count" x-cloak class="text-[11px] text-muted dark:text-night-muted"
                            x-text="rowsLabel.replace(':count', proposals[0].row_count)"></span>
                    </div>
                </div>

                <p x-show="proposals[0].inferred_relations" x-cloak
                    class="mt-3 rounded-lg bg-warning/10 px-3 py-1.5 text-[11px] leading-relaxed text-warning dark:text-warning-light"
                    x-text="inferredLabel"></p>

                {{-- What we found and did NOT read. Silence here reads as
                     "this shop keeps no stock", which is a different claim. --}}
                <template x-for="note in (proposals[0].notes ?? [])" :key="note.code + JSON.stringify(note.params ?? {})">
                    <p class="mt-2 rounded-lg bg-warning/10 px-3 py-1.5 text-[11px] leading-relaxed text-warning dark:text-warning-light"
                        x-text="noteText(note)"></p>
                </template>

                {{-- Real rows, expanded. An owner cannot check a mapping by
                     reading column names, but they know their own products on
                     sight — so this is the part that must never be a click
                     away for the candidate we are proposing. --}}
                <div class="mt-4">
                    <p x-show="proposals[0].preview.length === 0" x-cloak class="text-xs text-warning dark:text-warning-light">
                        {{ __('agents.database.discovery.preview_empty') }}
                    </p>
                    <template x-if="proposals[0].preview.length > 0">
                        <div class="overflow-x-auto rounded-xl bg-black/[0.02] p-1 dark:bg-white/[0.03]">
                            @include('agents.partials.database-preview', ['proposal' => 'proposals[0]', 'assertive' => true])
                        </div>
                    </template>
                </div>

                <div class="mt-4">
                    <button type="button" @click="confirmDiscovery(proposals[0].table)" :disabled="busy"
                        class="rounded-full bg-brand px-5 py-2 text-sm font-semibold text-on-accent hover:bg-brand-hover disabled:opacity-60">
                        <span x-show="!confirming">{{ __('agents.database.discovery.confirm') }}</span>
                        <span x-show="confirming" x-cloak class="spinner"></span>
                    </button>
                </div>

                {{-- The evidence, in the owner's own terms. Under the decision
                     it supports rather than above it: the rows are what an
                     owner actually judges by, and the reasoning is what they
                     open when the rows surprised them. --}}
                <details class="group mt-3">
                    <summary class="inline-flex cursor-pointer list-none items-center gap-1.5 text-xs font-medium text-muted transition-colors hover:text-brand dark:text-night-muted [&::-webkit-details-marker]:hidden">
                        <svg class="h-3 w-3 transition-transform group-open:rotate-90" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m9 5 7 7-7 7"/></svg>
                        {{ __('agents.database.discovery.why') }}
                    </summary>
                    <ul class="mt-2 space-y-1">
                        <template x-for="reason in proposals[0].reasons" :key="reason.code + JSON.stringify(reason.params ?? {})">
                            <li class="flex gap-2 text-xs text-muted dark:text-night-muted">
                                <span aria-hidden="true">•</span>
                                <span x-text="reasonText(reason)"></span>
                            </li>
                        </template>
                    </ul>
                </details>
            </div>
        </template>

        {{-- ALTERNATIVES, collapsed. They are real options and they are not
             what we are claiming — one row each, opened on demand. --}}
        <div x-show="proposals.length > 1" x-cloak class="border-t border-black/5 pt-4 dark:border-white/10">
            <p class="text-[11px] font-semibold uppercase tracking-wide text-muted dark:text-night-muted/70">
                {{ __('agents.database.discovery.alternatives_title') }}
            </p>

            <div class="mt-1 divide-y divide-black/5 dark:divide-white/10">
                <template x-for="(proposal, index) in proposals.slice(1)" :key="proposal.table">
                    <details class="group py-2">
                        <summary class="flex cursor-pointer list-none flex-wrap items-center gap-x-2 gap-y-1 text-sm [&::-webkit-details-marker]:hidden">
                            <svg class="h-3 w-3 flex-none text-muted transition-transform group-open:rotate-90 dark:text-night-muted" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m9 5 7 7-7 7"/></svg>
                            <span class="min-w-0 truncate" x-text="summaryFor(proposal, index + 1)"></span>
                            <span :class="confidenceClass(proposal.confidence)"
                                class="ml-auto inline-flex flex-none items-center rounded-full px-2 py-0.5 text-[11px] font-medium"
                                x-text="confidenceLabels[proposal.confidence] ?? ''"></span>
                        </summary>

                        <div class="mt-3 pl-5">
                            <p class="text-xs text-muted dark:text-night-muted" x-text="joinSentence(proposal)"></p>

                            <template x-for="note in (proposal.notes ?? [])" :key="note.code + JSON.stringify(note.params ?? {})">
                                <p class="mt-2 rounded-lg bg-warning/10 px-3 py-1.5 text-[11px] leading-relaxed text-warning dark:text-warning-light"
                                    x-text="noteText(note)"></p>
                            </template>

                            <p x-show="proposal.preview.length === 0" x-cloak class="mt-2 text-xs text-warning dark:text-warning-light">
                                {{ __('agents.database.discovery.preview_empty') }}
                            </p>
                            <template x-if="proposal.preview.length > 0">
                                <div class="mt-2 overflow-x-auto rounded-xl bg-black/[0.02] p-1 dark:bg-white/[0.03]">
                                    @include('agents.partials.database-preview', ['proposal' => 'proposal', 'assertive' => false])
                                </div>
                            </template>

                            <ul class="mt-3 space-y-1">
                                <template x-for="reason in proposal.reasons" :key="reason.code + JSON.stringify(reason.params ?? {})">
                                    <li class="flex gap-2 text-xs text-muted dark:text-night-muted">
                                        <span aria-hidden="true">•</span>
                                        <span x-text="reasonText(reason)"></span>
                                    </li>
                                </template>
                            </ul>

                            <button type="button" @click="confirmDiscovery(proposal.table)" :disabled="busy"
                                class="mt-3 rounded-full border border-black/10 px-4 py-1.5 text-xs font-semibold disabled:opacity-60 dark:border-white/10">
                                <span x-show="!confirming">{{ __('agents.database.discovery.confirm') }}</span>
                                <span x-show="confirming" x-cloak class="spinner"></span>
                            </button>
                        </div>
                    </details>
                </template>
            </div>
        </div>
    </section>

    {{-- ── Column mapping, by hand ─────────────────────────────────────────
         Reached from "change table" or from the advanced panel below. It
         appears where the owner is looking rather than inside the collapsed
         panel that opened it. --}}
    <section x-show="columns.length > 0" x-cloak class="rounded-2xl border border-black/10 dark:border-white/10">
        <div class="flex flex-wrap items-start justify-between gap-2 border-b border-black/5 px-5 py-4 dark:border-white/10">
            <div class="min-w-0">
                <h4 class="text-sm font-semibold">{{ __('agents.database.mapping_title') }}</h4>
                <p class="mt-1 text-xs leading-relaxed text-muted dark:text-night-muted">{{ __('agents.database.mapping_description') }}</p>
                <p class="mt-1 text-xs font-medium" x-text="selectedTable"></p>
                <p x-show="mappingError" x-cloak x-text="mappingError" class="mt-2 text-xs text-danger"></p>
            </div>
            <button type="button" @click="closeMapping()" class="text-xs font-medium text-muted transition-colors hover:text-brand dark:text-night-muted">
                {{ __('agents.database.cancel_editing') }}
            </button>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="bg-black/[0.03] text-xs uppercase tracking-wide text-muted dark:bg-white/5 dark:text-night-muted/70">
                    <tr>
                        <th class="px-5 py-3 font-medium">{{ __('agents.database.mapping_col_db') }}</th>
                        <th class="px-5 py-3 font-medium">{{ __('agents.database.mapping_col_sample') }}</th>
                        <th class="px-5 py-3 font-medium">{{ __('agents.database.mapping_col_field') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="column in columns" :key="column.name">
                        <tr class="border-t border-black/5 dark:border-white/10">
                            <td class="max-w-[14rem] px-5 py-3">
                                <span class="block truncate font-medium" x-text="column.name"></span>
                                <span class="block text-xs text-muted dark:text-night-muted" x-text="column.type"></span>
                            </td>
                            <td class="max-w-xs truncate px-5 py-3 text-xs text-muted dark:text-night-muted" x-text="sampleFor(column.name)"></td>
                            <td class="px-5 py-3">
                                <select :value="fieldFor(column.name)" @change="assign(column.name, $event.target.value)"
                                    class="field w-full min-w-[11rem] rounded-xl px-3 py-2 text-sm">
                                    <option value="">{{ $fieldLabels['ignore'] }}</option>
                                    @foreach (['name', 'price', 'stock', 'category', 'description', 'sku', 'url'] as $field)
                                        <option value="{{ $field }}">{{ $fieldLabels[$field] }}</option>
                                    @endforeach
                                </select>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>

        <div class="flex justify-end gap-2 border-t border-black/5 px-5 py-4 dark:border-white/10">
            <button type="button" @click="saveMapping()" :disabled="busy"
                class="rounded-full bg-brand px-5 py-2 text-sm font-semibold text-on-accent hover:bg-brand-hover disabled:opacity-60">
                <span x-show="!savingMapping">{{ __('agents.database.mapping_save') }}</span>
                <span x-show="savingMapping" x-cloak class="spinner"></span>
            </button>
        </div>
    </section>

    {{-- ── The escape hatch ────────────────────────────────────────────────
         Deliberately the quietest thing on the page: no card, no border, one
         line of small text. It exists for the schema discovery cannot read,
         and most owners should never need to open it. --}}
    <details x-show="connection && !editingCredentials" x-cloak class="group">
        <summary class="inline-flex cursor-pointer list-none items-center gap-1.5 text-xs font-medium text-muted transition-colors hover:text-brand dark:text-night-muted [&::-webkit-details-marker]:hidden">
            <svg class="h-3 w-3 transition-transform group-open:rotate-90" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m9 5 7 7-7 7"/></svg>
            {{ __('agents.database.discovery.advanced') }}
        </summary>

        <div class="mt-3 pl-4.5">
            <p class="text-xs leading-relaxed text-muted dark:text-night-muted">{{ __('agents.database.discovery.advanced_hint') }}</p>

            <div class="mt-3 flex flex-wrap items-end gap-3">
                <label class="block min-w-[16rem] flex-1">
                    <span class="text-xs font-medium text-muted dark:text-night-muted">{{ __('agents.database.table_label') }}</span>
                    <select :value="selectedTable" @change="chooseTable($event.target.value)"
                        class="field mt-1 w-full rounded-xl px-3 py-2 text-sm">
                        <option value="">{{ __('agents.database.choose_table') }}</option>
                        <template x-for="table in tables" :key="table.name">
                            <option :value="table.name"
                                x-text="table.type === 'view' ? table.name + ' · {{ __('agents.database.view_suffix') }}' : table.name"></option>
                        </template>
                    </select>
                </label>
                <button type="button" @click="loadTables()" :disabled="busy"
                    class="rounded-full border border-black/10 px-4 py-2 text-xs font-medium disabled:opacity-60 dark:border-white/10">
                    <span x-show="!loadingTables">{{ __('agents.database.refresh_tables') }}</span>
                    <span x-show="loadingTables" x-cloak class="spinner"></span>
                </button>
            </div>
        </div>
    </details>

    {{-- Nothing connected yet: a single quiet line under the form. --}}
    <p x-show="!connection" x-cloak class="text-xs text-muted dark:text-night-muted">
        {{ __('agents.database.empty_description') }}
    </p>
</div>

@once
@push('scripts')
<script>
    function databaseTab({ showUrl, testUrl, storeUrl, discoverUrl, confirmUrl, tablesUrl, describeUrl, mappingUrl, sourceUrl, destroyUrl, initial }) {
        return {
            connection: initial.connection,
            engines: initial.engines ?? [],
            activeSource: initial.active_source ?? 'dataset',
            hasDataset: initial.has_dataset ?? false,

            form: {
                engine: initial.connection?.engine ?? (initial.engines?.[0]?.key ?? 'mysql'),
                host: initial.connection?.host ?? '',
                port: initial.connection?.port ?? (initial.engines?.[0]?.port ?? 3306),
                database: initial.connection?.database ?? '',
                username: initial.connection?.username ?? '',
                // Never seeded from the server — see the field's comment above.
                password: '',
                ssl: initial.connection?.ssl ?? false,
            },

            tables: [],
            selectedTable: initial.connection?.table ?? '',
            columns: [],
            sample: [],
            mapping: {},

            // Automatic discovery. `proposals` is what the server found on the
            // last scan; nothing here is stored until confirmDiscovery().
            proposals: [],
            scanned: false,
            discovering: false,
            confirming: false,

            testing: false,
            saving: false,
            savingMapping: false,
            loadingTables: false,
            switching: false,
            tested: false,
            error: '',
            mappingError: '',

            // Presentation only: which panel the owner has opened. Neither is
            // sent anywhere, and a reload returns both to their resting state.
            showHelp: false,
            editingCredentials: false,

            fieldLabels: @json($fieldLabels),
            neutralFieldLabels: @json(__('agents.database.fields_neutral')),
            discoverLabel: @json(__('agents.database.discovery.button')),
            rescanLabel: @json(__('agents.database.discovery.rescan')),
            inferredLabel: @json(__('agents.database.discovery.inferred_relations')),
            rowsLabel: @json(__('agents.database.discovery.rows_estimate')),
            foundLabel: @json(__('agents.database.discovery.summary_found')),
            foundViewLabel: @json(__('agents.database.discovery.summary_view')),
            altLabel: @json(__('agents.database.discovery.summary_alternative')),
            altViewLabel: @json(__('agents.database.discovery.summary_alternative_view')),
            joinLabel: @json(__('agents.database.discovery.summary_join')),
            joinSummedLabel: @json(__('agents.database.discovery.summary_join_summed')),
            confidenceLabels: @json(__('agents.database.discovery.confidence')),
            reasonLabels: @json(__('agents.database.discovery.reasons')),
            noteLabels: @json(__('agents.database.discovery.notes')),
            passwordSavedLabel: @json(__('agents.database.password_saved')),
            statusLabels: {
                ready: @json(__('agents.database.status_ready')),
                error: @json(__('agents.database.status_error')),
                unverified: @json(__('agents.database.status_unverified')),
            },
            testedLabel: @json(__('agents.database.status_tested')),
            discoveredLabel: @json(__('agents.database.configured_updated')),
            tableLabelTemplate: @json(__('agents.database.configured_table')),
            emptyValue: @json(__('agents.database.empty_value')),
            networkError: @json(__('agents.database.errors.network_error')),
            rateLimited: @json(__('agents.database.errors.rate_limited')),

            get busy() {
                return this.testing || this.saving || this.savingMapping || this.loadingTables
                    || this.switching || this.discovering || this.confirming;
            },

            /**
             * Which of the three screens the owner is on.
             *
             * Derived, never stored: the server already knows whether there is
             * a connection and whether it is usable, and a second copy of that
             * here would be one more thing able to disagree with it.
             */
            get step() {
                if (!this.connection) return 'connect';

                return this.connection.usable ? 'done' : 'review';
            },

            /** 'done' | 'current' | 'pending', for one dot on the step rail. */
            stepState(name) {
                const order = ['connect', 'review', 'done'];
                const at = order.indexOf(this.step);
                const of = order.indexOf(name);

                if (of < at) return 'done';

                // The last step is an end state, not a step in progress.
                return of === at ? (name === 'done' ? 'done' : 'current') : 'pending';
            },

            /** "MySQL · db.example.com:3306 · shop" */
            connectionSummary() {
                const c = this.connection;

                if (!c) return '';

                const engine = this.engines.find((e) => e.key === c.engine)?.label ?? c.engine;

                return `${engine} · ${c.host}:${c.port} · ${c.database}`;
            },

            /** Close the credentials panel without touching what is stored. */
            cancelEditing() {
                this.editingCredentials = false;
                this.form.password = '';
                this.error = '';
                this.tested = false;
            },

            /** Leave the manual mapping screen. Nothing is saved by closing it. */
            closeMapping() {
                this.columns = [];
                this.sample = [];
                this.mapping = {};
                this.mappingError = '';
            },

            /**
             * Scan the customer's schema and show what we found. Saves nothing —
             * the owner reads the summary and the sample rows and decides.
             */
            async discover() {
                this.discovering = true;
                this.proposals = [];

                const data = await this.request(discoverUrl, 'POST');

                if (data) {
                    this.proposals = data.proposals ?? [];
                    this.scanned = true;
                }

                this.discovering = false;
            },

            /**
             * Adopt one proposal. Only the table NAME is sent: the server
             * recomputes the mapping and the joins from its own fresh scan.
             */
            async confirmDiscovery(table) {
                this.confirming = true;

                const data = await this.request(confirmUrl, 'PUT', { table });

                if (data) {
                    this.apply(data);
                    this.proposals = [];
                    this.scanned = false;
                    this.columns = [];
                    this.sample = [];
                    window.showToast?.(@json(__('agents.database.discovery.confirmed')), 'success');
                }

                this.confirming = false;
            },

            /**
             * How sure the sentence sounds has to match how sure we are.
             *
             * The first candidate is the one the ranking actually backs, so it
             * says we found products. Everything after it is offered as an
             * alternative — saying "we found products in «customers»" four times
             * over makes the UI confidently wrong three times.
             */
            summaryFor(proposal, index) {
                const view = proposal.type === 'view';

                const template = index === 0
                    ? (view ? this.foundViewLabel : this.foundLabel)
                    : (view ? this.altViewLabel : this.altLabel);

                return template.replace(':table', proposal.table);
            },

            /** "Categories from `categories`, stock summed from `stocks`." */
            joinSentence(proposal) {
                return (proposal.join_summary ?? [])
                    .map((entry) => (entry.aggregated ? this.joinSummedLabel : this.joinLabel)
                        .replace(':field', this.fieldLabels[entry.field] ?? entry.field)
                        .replace(':table', entry.table))
                    .join(' · ');
            },

            /** A reason code plus its parameters, rendered in the owner's language. */
            reasonText(reason) {
                return this.interpolate(this.reasonLabels[reason.code] ?? reason.code, reason.params);
            },

            /** A note code — what we found and did not use — in the same shape. */
            noteText(note) {
                return this.interpolate(this.noteLabels[note.code] ?? note.code, note.params);
            },

            interpolate(text, params) {
                for (const [key, value] of Object.entries(params ?? {})) {
                    text = text.replace(`:${key}`, value);
                }

                return text;
            },

            /**
             * The preview's column heading. Assertive only for the candidate we
             * are actually asserting: "Название товара" over a column of
             * customer names is the wrong claim made by a label.
             */
            headerFor(field, assertive) {
                const labels = assertive ? this.fieldLabels : this.neutralFieldLabels;

                return labels[field] ?? this.fieldLabels[field] ?? field;
            },

            /** Where an alternative's column actually comes from. */
            sourceFor(proposal, field) {
                const own = (proposal.mapping ?? {})[field];

                if (own) return own;

                const joined = (proposal.join_summary ?? []).find((entry) => entry.field === field);

                return joined ? joined.table : '';
            },

            confidenceClass(confidence) {
                if (confidence === 'high') return 'bg-success/10 text-success dark:text-success-light';
                if (confidence === 'medium') return 'bg-brand/10 text-brand';

                return 'bg-warning/10 text-warning dark:text-warning-light';
            },

            csrfHeaders(extra = {}) {
                return {
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                    ...extra,
                };
            },

            /**
             * One fetch helper for the whole tab: JSON in, JSON out, with 429
             * given its own message. Returns null on any failure, having already
             * shown the reason — every caller only has to handle success.
             */
            async request(url, method, body = null, { inline = false } = {}) {
                try {
                    const res = await fetch(url, {
                        method,
                        headers: this.csrfHeaders(body ? { 'Content-Type': 'application/json' } : {}),
                        body: body ? JSON.stringify(body) : undefined,
                    });
                    const data = await res.json().catch(() => ({}));

                    if (res.status === 429) {
                        this.report(this.rateLimited, inline);
                        return null;
                    }

                    if (!res.ok) {
                        const message = data.message
                            || Object.values(data.errors ?? {})[0]?.[0]
                            || this.networkError;
                        this.report(message, inline);
                        return null;
                    }

                    return data;
                } catch (e) {
                    this.report(this.networkError, inline);
                    return null;
                }
            },

            /** Inline next to the form the owner is looking at; toast otherwise. */
            report(message, inline) {
                if (inline) {
                    this.error = message;
                } else {
                    window.showToast?.(message, 'error');
                }
            },

            statusLabel() {
                const base = this.statusLabels[this.connection?.status] ?? '';

                return this.connection?.last_tested_at
                    ? `${base} · ${this.testedLabel.replace(':time', this.connection.last_tested_at)}`
                    : base;
            },

            tableLabel() {
                return this.tableLabelTemplate.replace(':table', this.connection?.table ?? '');
            },

            applyDefaultPort() {
                const engine = this.engines.find((e) => e.key === this.form.engine);
                if (engine) this.form.port = engine.port;
            },

            async test() {
                this.testing = true;
                this.error = '';
                this.tested = false;

                const data = await this.request(testUrl, 'POST', this.form, { inline: true });
                if (data) this.tested = true;

                this.testing = false;
            },

            async save() {
                this.saving = true;
                this.error = '';
                this.tested = false;

                const data = await this.request(storeUrl, 'PUT', this.form, { inline: true });

                if (data) {
                    this.apply(data);
                    // The secret is now stored; clearing the field keeps the tab
                    // honest about the fact that it no longer holds one.
                    this.form.password = '';
                    // Credentials are settings again, not the task at hand.
                    this.editingCredentials = false;
                    window.showToast?.(@json(__('agents.database.toasts.saved')), 'success');
                    // Straight into discovery: the whole point is that saving
                    // credentials is the last thing the owner has to type. The
                    // table picker in the advanced panel loads on demand.
                    await this.discover();
                }

                this.saving = false;
            },

            async loadTables() {
                this.loadingTables = true;

                const data = await this.request(tablesUrl, 'GET');
                if (data) this.tables = data.tables ?? [];

                this.loadingTables = false;
            },

            async chooseTable(table) {
                this.selectedTable = table;
                this.columns = [];
                this.sample = [];
                this.mapping = {};
                this.mappingError = '';

                if (!table) return;

                if (this.tables.length === 0) await this.loadTables();

                const data = await this.request(describeUrl, 'POST', { table });

                if (data) {
                    this.columns = data.columns ?? [];
                    this.sample = data.sample ?? [];
                    this.mapping = { ...(data.mapping ?? {}) };
                }
            },

            sampleFor(column) {
                return this.sample
                    .map((row) => row[column])
                    .filter((value) => value !== undefined && value !== null && value !== '')
                    .slice(0, 3)
                    .join(' · ') || this.emptyValue;
            },

            fieldFor(column) {
                for (const [field, value] of Object.entries(this.mapping)) {
                    if (value === column) return field;
                }
                return '';
            },

            /**
             * One column per field and one field per column: picking "Цена" for
             * a second column moves it rather than leaving two prices for the
             * search to arbitrate between.
             */
            assign(column, field) {
                for (const key of Object.keys(this.mapping)) {
                    if (this.mapping[key] === column) delete this.mapping[key];
                }

                if (field !== '') this.mapping[field] = column;
            },

            mappingSummary() {
                const mapping = this.connection?.mapping ?? {};

                return Object.entries(mapping).map(([field, column]) => ({
                    field,
                    label: this.fieldLabels[field] ?? field,
                    column,
                }));
            },

            async saveMapping() {
                this.savingMapping = true;
                this.mappingError = '';

                const data = await this.request(mappingUrl, 'PUT', {
                    table: this.selectedTable,
                    mapping: this.mapping,
                });

                if (data) {
                    this.apply(data);
                    // Collapse back to the summary card: the table is chosen and
                    // the mapping is stored, so the editing surface has no more
                    // work to do.
                    this.columns = [];
                    this.sample = [];
                    window.showToast?.(@json(__('agents.database.toasts.mapping_saved')), 'success');
                } else {
                    this.mappingError = this.error || this.mappingError;
                    this.error = '';
                }

                this.savingMapping = false;
            },

            async switchSource(source) {
                this.switching = true;

                const data = await this.request(sourceUrl, 'PUT', { source });

                if (data) {
                    this.apply(data);
                    window.showToast?.(
                        source === 'database'
                            ? @json(__('agents.database.toasts.source_database'))
                            : @json(__('agents.database.toasts.source_dataset')),
                        'success',
                    );
                }

                this.switching = false;
            },

            async disconnect() {
                if (!confirm(@json(__('agents.database.disconnect_confirm')))) return;

                const data = await this.request(destroyUrl, 'DELETE');

                if (data) {
                    this.apply(data);
                    this.tables = [];
                    this.columns = [];
                    this.sample = [];
                    this.mapping = {};
                    this.selectedTable = '';
                    this.proposals = [];
                    this.scanned = false;
                    this.editingCredentials = false;
                    this.form.password = '';
                    window.showToast?.(@json(__('agents.database.toasts.disconnected')), 'success');
                }
            },

            /** Merge a fresh state payload from any endpoint that returns one. */
            apply(data) {
                if (!('connection' in data)) return;

                this.connection = data.connection;
                this.activeSource = data.active_source ?? this.activeSource;
                this.hasDataset = data.has_dataset ?? this.hasDataset;
                this.selectedTable = data.connection?.table ?? '';
                this.error = '';
            },
        };
    }
</script>
@endpush
@endonce
