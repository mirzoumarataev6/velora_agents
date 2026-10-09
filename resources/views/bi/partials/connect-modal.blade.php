{{--
    CONNECTING A DATABASE — the BI module's OWN, asked for here.

    This modal used to list the connections an owner had already set up for
    their agents and borrow one, on the reasoning that re-asking for verified
    credentials was asking them to trust us twice. That reasoning was wrong in a
    way worth recording: `database_connections` is keyed one-per-agent and
    cascades from the agent, so deleting an assistant silently broke every BI
    dataset built on it. And one credential served two features with different
    threat models — an agent's connection is read by a language model answering
    customers; this one is read by the owner analysing their own books.

    So this asks for the credentials. An owner who wants the same physical
    database their agent reads types it again, and gets a record they can
    rotate, revoke and delete without touching the assistant.

    Two views, and which one opens is decided by whether anything is saved:
      - `list`, to pick a saved connection and choose tables from it;
      - `form`, to add or edit one. A first-time owner lands straight here,
        because an empty list with a button is one click of nothing.

    Reads the parent biSources() scope.
--}}
<div x-show="connectOpen" x-cloak
    class="fixed inset-0 z-50 flex items-end justify-center p-0 sm:items-center sm:p-4"
    role="dialog" aria-modal="true" aria-label="{{ __('bi.connect.title') }}"
    @keydown.escape.window="connectOpen = false">

    <div class="modal-scrim absolute inset-0" @click="connectOpen = false"></div>

    <div class="modal relative flex max-h-[90vh] w-full max-w-lg flex-col">
        <header class="rule-b shrink-0 px-5 py-4">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <h2 class="text-base font-semibold" data-i18n="bi.connect.title">{{ __('bi.connect.title') }}</h2>
                    <p class="mt-1 text-xs leading-relaxed text-muted dark:text-night-muted"
                        data-i18n="bi.connect.subtitle">{{ __('bi.connect.subtitle') }}</p>
                </div>
                <button type="button" @click="connectOpen = false" class="btn btn-ghost btn-sm shrink-0"
                    aria-label="{{ __('bi.schema.close') }}">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 6l12 12M18 6L6 18"/></svg>
                </button>
            </div>
        </header>

        <div class="min-h-0 flex-1 overflow-y-auto px-5 py-4">
            <template x-if="connectLoading">
                <div class="flex justify-center py-8"><span class="spinner h-5 w-5"></span></div>
            </template>

            {{-- No longer a dead end at all. An owner with no connections is
                 put straight into the form, because this screen is now where a
                 connection is MADE rather than where one is borrowed from
                 somewhere else. --}}

            {{-- ---------------------------------------------------------- the form --}}
            <template x-if="!connectLoading && connectView === 'form'">
                <div class="space-y-3.5">
                    <div>
                        <label class="block text-xs font-medium" data-i18n="bi.connect.name">{{ __('bi.connect.name') }}</label>
                        <input type="text" class="field mt-1 w-full px-3 py-2 text-sm" x-model="form.name"
                            placeholder="{{ __('bi.connect.name_placeholder') }}" />
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-medium" data-i18n="bi.connect.engine">{{ __('bi.connect.engine') }}</label>
                            <select class="field mt-1 w-full px-3 py-2 text-sm" x-model="form.engine" @change="onEngineChange()">
                                <template x-for="option in engines" :key="option.key">
                                    <option :value="option.key" x-text="option.label"></option>
                                </template>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-medium" data-i18n="bi.connect.port">{{ __('bi.connect.port') }}</label>
                            <input type="number" min="1" max="65535" class="field mt-1 w-full px-3 py-2 text-sm" x-model.number="form.port" />
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-medium" data-i18n="bi.connect.host">{{ __('bi.connect.host') }}</label>
                        <input type="text" class="field mt-1 w-full px-3 py-2 text-sm" x-model="form.host"
                            autocomplete="off" spellcheck="false" />
                    </div>

                    <div>
                        <label class="block text-xs font-medium" data-i18n="bi.connect.database">{{ __('bi.connect.database') }}</label>
                        <input type="text" class="field mt-1 w-full px-3 py-2 text-sm" x-model="form.database"
                            autocomplete="off" spellcheck="false" />
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-medium" data-i18n="bi.connect.username">{{ __('bi.connect.username') }}</label>
                            <input type="text" class="field mt-1 w-full px-3 py-2 text-sm" x-model="form.username"
                                autocomplete="off" spellcheck="false" />
                        </div>
                        <div>
                            <label class="block text-xs font-medium" data-i18n="bi.connect.password">{{ __('bi.connect.password') }}</label>
                            {{-- Never prefilled: the server does not send the
                                 secret back. On an edit an empty field means
                                 "keep the stored one", which the hint says. --}}
                            <input type="password" class="field mt-1 w-full px-3 py-2 text-sm" x-model="form.password"
                                autocomplete="new-password" />
                        </div>
                    </div>

                    <p x-show="form.id" x-cloak class="text-xs text-muted dark:text-night-muted"
                        data-i18n="bi.connect.password_kept">{{ __('bi.connect.password_kept') }}</p>

                    <label class="flex cursor-pointer items-center gap-2 text-xs">
                        <input type="checkbox" class="field-check" x-model="form.ssl" />
                        <span data-i18n="bi.connect.ssl">{{ __('bi.connect.ssl') }}</span>
                    </label>

                    {{-- Said out loud, because it is the thing an owner should
                         check before handing over a credential: we only read,
                         and the driver opens a read-only session to prove it. --}}
                    <p class="rounded-xl bg-sunk/60 px-3 py-2 text-xs text-muted dark:bg-white/[0.03] dark:text-night-muted"
                        data-i18n="bi.connect.read_only_hint">{{ __('bi.connect.read_only_hint') }}</p>

                    <div x-show="connectNotice" x-cloak class="rounded-xl bg-success/10 px-3.5 py-2.5 text-xs font-medium text-success"
                        x-text="connectNotice"></div>

                    <div x-show="connectError" x-cloak class="rounded-xl bg-danger/8 px-3.5 py-3 text-xs">
                        <p class="font-medium text-danger" x-text="connectError"></p>
                    </div>

                </div>
            </template>

            <template x-if="!connectLoading && connectView === 'list' && connections.length > 0">
                <div class="space-y-4">
                    <div>
                        <label class="text-xs font-medium" for="bi-connection"
                            data-i18n="bi.connect.choose">{{ __('bi.connect.choose') }}</label>
                        <select id="bi-connection" class="field mt-1 w-full px-3 py-2 text-sm"
                            @change="selectConnection($event.target.value)">
                            <template x-for="connection in connections" :key="connection.id">
                                <option :value="connection.id" :selected="connectionId === connection.id"
                                    x-text="`${connection.label} · ${connection.engine}`"></option>
                            </template>
                        </select>
                        {{-- What this connection IS — host and database, never
                             a credential, and deliberately no mention of any
                             agent: these records have nothing to do with them. --}}
                        <div class="mt-1 flex items-center justify-between gap-2">
                            <p class="truncate text-xs text-muted dark:text-night-muted"
                                x-text="selectedConnection()
                                    ? `${selectedConnection().username}@${selectedConnection().host}:${selectedConnection().port}/${selectedConnection().database}`
                                    : ''"></p>
                            <span class="flex shrink-0 gap-2">
                                <button type="button" class="text-xs text-brand-strong hover:underline"
                                    @click="newConnection()" data-i18n="bi.connect.new">{{ __('bi.connect.new') }}</button>
                                <button type="button" class="text-xs text-brand-strong hover:underline"
                                    @click="editConnection()">{{ __('bi.dashboards.rename') }}</button>
                                <button type="button" class="text-xs text-danger hover:underline"
                                    @click="deleteConnection()" data-i18n="bi.connect.delete">{{ __('bi.connect.delete') }}</button>
                            </span>
                        </div>
                    </div>

                    <div>
                        <label class="text-xs font-medium" for="bi-source-name"
                            data-i18n="bi.connect.name">{{ __('bi.connect.name') }}</label>
                        <input id="bi-source-name" type="text" class="field mt-1 w-full px-3 py-2 text-sm"
                            placeholder="{{ __('bi.connect.name_placeholder') }}" x-model="connectName" />
                    </div>

                    <div>
                        <div class="flex items-center justify-between gap-2">
                            <label class="text-xs font-medium" data-i18n="bi.connect.tables">{{ __('bi.connect.tables') }}</label>
                            <span x-show="chosenTables.length" x-cloak class="text-xs tabular-nums text-muted dark:text-night-muted"
                                x-text="@js(__('bi.connect.selected', ['count' => ':n'])).replace(':n', chosenTables.length)"></span>
                        </div>
                        <p class="mt-0.5 text-xs text-muted dark:text-night-muted"
                            data-i18n="bi.connect.tables_hint">{{ __('bi.connect.tables_hint') }}</p>

                        <template x-if="tablesLoading">
                            <p class="mt-2 flex items-center gap-2 text-xs text-muted dark:text-night-muted">
                                <span class="spinner h-3.5 w-3.5"></span>
                                <span data-i18n="bi.connect.loading_tables">{{ __('bi.connect.loading_tables') }}</span>
                            </p>
                        </template>

                        <template x-if="!tablesLoading && tables.length === 0 && !connectError">
                            <p class="mt-2 text-xs text-muted dark:text-night-muted"
                                data-i18n="bi.connect.no_tables">{{ __('bi.connect.no_tables') }}</p>
                        </template>

                        <template x-if="!tablesLoading && tables.length > 0">
                            {{-- Capped height with its own scroll: a schema with 400
                                 tables must not push the Connect button off the screen. --}}
                            <div class="mt-2 max-h-56 space-y-0.5 overflow-y-auto rounded-xl bg-sunk/60 p-1.5 dark:bg-white/[0.03]">
                                <template x-for="table in tables" :key="table.name">
                                    <label class="flex cursor-pointer items-center gap-2 rounded-lg px-2 py-1.5 text-sm hover:bg-surface dark:hover:bg-white/5">
                                        <input type="checkbox" class="field-check shrink-0"
                                            :value="table.name"
                                            :checked="chosenTables.includes(table.name)"
                                            @change="toggleTable(table.name)" />
                                        <span class="min-w-0 flex-1 truncate font-mono text-xs" x-text="table.name"></span>
                                        <span x-show="table.type !== 'table'" class="badge badge-neutral" x-text="table.type"></span>
                                    </label>
                                </template>
                            </div>
                        </template>
                    </div>

                    <div x-show="connectError" x-cloak class="rounded-xl bg-danger/8 px-3.5 py-3 text-xs">
                        <p class="font-medium text-danger" x-text="connectError"></p>
                        {{-- The technical remainder, for a support message — the same
                             pairing a failed file upload gets. --}}
                        <p x-show="connectErrorDetail" class="mt-1 break-words text-muted dark:text-night-muted"
                            x-text="connectErrorDetail"></p>
                    </div>
                </div>
            </template>
        </div>

        {{-- Form and list share one footer. Keeping form actions inside the
             scroll area left a second, mostly empty footer below them and
             made Cancel look detached from the decision it cancels. --}}
        <footer class="rule shrink-0 flex flex-wrap items-center justify-end gap-2 px-5 py-4">
            <button type="button" @click="connectOpen = false" class="btn btn-ghost btn-sm"
                data-i18n="bi.data.cancel">{{ __('bi.data.cancel') }}</button>
            <button type="button" x-show="connectView === 'form' && connections.length > 0" x-cloak
                class="btn btn-ghost btn-sm" @click="connectView = 'list'; connectError = ''; connectNotice = ''"
                data-i18n="bi.connect.choose">{{ __('bi.connect.choose') }}</button>
            <button type="button" x-show="connectView === 'form'" x-cloak class="btn btn-secondary btn-sm"
                @click="testConnection()" :disabled="testing">
                <span x-show="!testing" data-i18n="bi.connect.test">{{ __('bi.connect.test') }}</span>
                <span x-show="testing" x-cloak class="spinner h-4 w-4"></span>
            </button>
            <button type="button" x-show="connectView === 'form'" x-cloak class="btn btn-primary btn-sm"
                @click="saveConnection()" :disabled="savingConnection">
                <span x-show="!savingConnection" data-i18n="bi.connect.save">{{ __('bi.connect.save') }}</span>
                <span x-show="savingConnection" x-cloak class="spinner h-4 w-4"></span>
            </button>
            <button type="button" x-show="connectView === 'list'" @click="registerConnection()"
                :disabled="registering || chosenTables.length === 0"
                class="btn btn-primary btn-sm">
                <span x-show="!registering" data-i18n="bi.connect.register">{{ __('bi.connect.register') }}</span>
                <span x-show="registering" x-cloak class="spinner h-4 w-4"></span>
            </button>
        </footer>
    </div>
</div>
