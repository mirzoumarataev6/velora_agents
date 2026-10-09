<script>
    /**
     * The Данные panel's one Alpine component: uploading, polling, the schema drawer
     * and the connect flow.
     *
     * One component rather than four because they share state that would otherwise
     * have to be passed between them — an upload finishing has to start the poller,
     * and a poller seeing a rebuild finish has to refresh the open drawer. Splitting
     * them would mean events between siblings for no gain.
     */
    function biSources(config) {
        return {
            /* upload */
            dragging: false,
            uploading: false,
            progress: 0,
            uploadName: '',
            uploadError: '',

            /* polling */
            timer: null,

            /* schema drawer */
            schemaOpen: false,
            schemaLoading: false,
            schema: null,
            edits: {},
            savingSchema: false,
            schemaError: '',

            /* connect — the BI module's OWN connections, not the agents'. */
            connectOpen: false,
            connectLoading: false,
            connections: [],
            connectionId: null,
            connectName: '',
            /* 'list' when saved connections exist, 'form' when adding or
               editing one. A first-time owner lands straight on the form:
               showing them an empty list and a button is one click of nothing. */
            connectView: 'list',
            engines: [],
            testing: false,
            savingConnection: false,
            connectNotice: '',
            /* The credential form. `password` empty on an EDIT means "keep the
               stored one" — the server never sends the secret back, so the
               field cannot be prefilled and must not be treated as a blanking. */
            form: { id: null, name: '', engine: 'mysql', host: '', port: 3306, database: '', username: '', password: '', ssl: false },
            tables: [],
            tablesLoading: false,
            chosenTables: [],
            registering: false,
            connectError: '',
            connectErrorDetail: '',

            labels: config.labels,

            /**
             * True once a poll has seen work finish while the drawer was open, so the
             * list behind it is out of date.
             *
             * The reload is then deferred to when the drawer closes. Reloading
             * immediately would slam shut the panel the owner is reading — and it is
             * usually the panel they triggered the rebuild FROM, which makes it read
             * as the app having crashed rather than having finished.
             */
            listStale: false,

            init() {
                // Poll only if something is actually in flight, decided from a flag
                // the server computed. The first version of this asked
                // `document.querySelector('.spinner')`, which matched the connect
                // modal's own hidden spinner — so every visit polled once, found
                // everything settled, and reloaded the page two seconds in.
                if (config.pending) this.schedule();
                if (new URLSearchParams(window.location.search).has('connect')) this.openConnect();
            },

            /* ---------------------------------------------------------------- upload */

            pick(file) {
                if (!file || this.uploading) return;
                this.upload(file);
            },

            /**
             * XMLHttpRequest rather than fetch, for exactly one reason: fetch has no
             * upload-progress event. On a 100MB file over a slow line that is the
             * difference between a moving bar and two minutes of silence.
             */
            upload(file) {
                this.uploading = true;
                this.progress = 0;
                this.uploadName = file.name;
                this.uploadError = '';

                const body = new FormData();
                body.append('file', file);

                const request = new XMLHttpRequest();
                request.open('POST', config.storeUrl, true);
                request.setRequestHeader('Accept', 'application/json');
                request.setRequestHeader('X-CSRF-TOKEN', config.csrf);
                request.withCredentials = true;

                request.upload.addEventListener('progress', (event) => {
                    if (event.lengthComputable) {
                        this.progress = Math.round((event.loaded / event.total) * 100);
                    }
                });

                request.addEventListener('load', () => {
                    this.uploading = false;

                    if (request.status === 201) {
                        window.showToast(this.labels.queued, 'success');
                        // The list is server-rendered, so a reload is both the
                        // simplest and the most correct way to show the new card —
                        // rebuilding it here would be a second copy of the Blade.
                        window.location.reload();
                        return;
                    }

                    this.uploadError = this.messageFrom(request) || this.labels.failed;
                });

                request.addEventListener('error', () => {
                    this.uploading = false;
                    this.uploadError = this.labels.failed;
                });

                request.send(body);
            },

            /**
             * A validation message out of a JSON error response.
             *
             * Laravel returns 422 with `errors.file`, and that message is already
             * translated server-side — so it is shown verbatim rather than mapped to
             * a generic string here, which would throw away the specific advice.
             */
            messageFrom(request) {
                try {
                    const payload = JSON.parse(request.responseText);
                    if (payload.errors) return Object.values(payload.errors).flat()[0];
                    return payload.message || '';
                } catch {
                    return '';
                }
            },

            /* --------------------------------------------------------------- polling */

            schedule() {
                clearTimeout(this.timer);
                this.timer = setTimeout(() => this.poll(), 2500);
            },

            async poll() {
                try {
                    const response = await fetch(config.statusUrl, {
                        headers: { Accept: 'application/json' },
                        credentials: 'same-origin',
                    });

                    if (!response.ok) return this.schedule();

                    const { sources } = await response.json();
                    const settled = ['ready', 'failed'];
                    let pending = false;

                    sources.forEach((source) => {
                        if (!settled.includes(source.status)) pending = true;
                        source.datasets.forEach((dataset) => {
                            if (!settled.includes(dataset.status)) pending = true;
                        });
                    });

                    if (pending) {
                        // The drawer, if open, is looking at a dataset that may have
                        // just finished rebuilding — refresh it in place so its
                        // preview and counts are not stale.
                        if (this.schemaOpen && this.schema) this.refreshSchema(this.schema.id);
                        return this.schedule();
                    }

                    // Everything settled. A finished run changes row counts, notes and
                    // per-column warnings — more of the card than is worth re-rendering
                    // by hand — so one reload ends the poll. Unless the drawer is open,
                    // in which case refresh it in place and reload once it closes.
                    if (this.schemaOpen && this.schema) {
                        this.listStale = true;
                        await this.refreshSchema(this.schema.id);

                        return;
                    }

                    window.location.reload();
                } catch {
                    this.schedule();
                }
            },

            /* ---------------------------------------------------------------- sources */

            async retry(id) {
                await this.send(config.retryUrlBase.replace('__ID__', id), 'POST');
                window.location.reload();
            },

            async destroySource(id) {
                if (!window.confirm(this.labels.deleteConfirm)) return;

                if (await this.send(config.deleteUrlBase.replace('__ID__', id), 'DELETE')) {
                    window.showToast(this.labels.deleted, 'success');
                    window.location.reload();
                }
            },

            /* ----------------------------------------------------------------- schema */

            async openSchema(id) {
                this.schemaOpen = true;
                this.schemaLoading = true;
                this.schema = null;
                this.edits = {};
                this.schemaError = '';

                await this.refreshSchema(id);

                this.schemaLoading = false;

                // A rebuild triggered from the drawer needs the poller running even
                // if the page had nothing in flight when it loaded.
                if (this.rebuilding) this.schedule();
            },

            async refreshSchema(id) {
                try {
                    const response = await fetch(config.datasetUrlBase.replace('__ID__', id), {
                        headers: { Accept: 'application/json' },
                        credentials: 'same-origin',
                    });

                    if (response.ok) this.schema = await response.json();
                } catch {
                    this.schemaError = this.labels.failed;
                }
            },

            closeSchema() {
                this.schemaOpen = false;
                this.schema = null;
                this.edits = {};
                this.schemaError = '';

                // Work finished while the panel was open, so the counts and badges
                // behind it are stale. Now is the moment the reload costs nothing.
                if (this.listStale) window.location.reload();
            },

            get rebuilding() {
                return this.schema?.status === 'materializing';
            },

            /**
             * The current value of one editable field: the owner's pending edit if
             * they have touched it, otherwise what the server said.
             *
             * Keeping edits in their own map rather than mutating `schema` is what
             * makes `hasEdits()` and `willRebuild()` answerable at all — and what lets
             * the poller refresh the drawer underneath without discarding typing.
             */
            edit(column, field) {
                const pending = this.edits[column.id];

                if (pending && Object.prototype.hasOwnProperty.call(pending, field)) {
                    return pending[field];
                }

                return column[field];
            },

            setEdit(column, field, value) {
                const normalized = (field === 'label' || field === 'description')
                    ? (value === '' ? null : value)
                    : value;

                // An edit back to the server's own value is not an edit. Dropping it
                // keeps the Save button honest about whether anything changed.
                if (normalized === column[field]) {
                    if (this.edits[column.id]) delete this.edits[column.id][field];
                    if (this.edits[column.id] && Object.keys(this.edits[column.id]).length === 0) {
                        delete this.edits[column.id];
                    }
                    return;
                }

                this.edits[column.id] = { ...(this.edits[column.id] ?? {}), [field]: normalized };
            },

            hasEdits() {
                return Object.keys(this.edits).length > 0;
            },

            isTemporal(column) {
                return ['date', 'datetime'].includes(this.edit(column, 'type'));
            },

            isNumeric(column) {
                return ['integer', 'decimal'].includes(this.edit(column, 'type'));
            },

            isOverridden(column) {
                return this.edit(column, 'type') !== column.inferred_type;
            },

            resetType(column) {
                this.setEdit(column, 'type', column.inferred_type);
            },

            typeLabel(value) {
                return this.schema?.options.types.find((option) => option.value === value)?.label ?? value;
            },

            /** Columns whose day/month order was assumed rather than proved. */
            ambiguousDates() {
                return (this.schema?.columns ?? [])
                    .filter((column) => column.date_ambiguous && this.isTemporal(column))
                    .map((column) => column.display_name);
            },

            /**
             * Whether saving would rebuild the table — mirrors SchemaEditor's rule, so
             * the warning shown before the click matches what the server then does.
             */
            willRebuild() {
                if (this.schema?.kind === 'connection') return false;

                return (this.schema?.columns ?? []).some((column) => {
                    const pending = this.edits[column.id];
                    if (!pending) return false;

                    return (pending.type !== undefined && pending.type !== column.type)
                        || (pending.date_format !== undefined && pending.date_format !== column.date_format);
                });
            },

            async saveSchema() {
                if (!this.hasEdits() || this.savingSchema) return;

                this.savingSchema = true;
                this.schemaError = '';

                try {
                    const response = await fetch(config.columnsUrlBase.replace('__ID__', this.schema.id), {
                        method: 'PATCH',
                        headers: {
                            Accept: 'application/json',
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': config.csrf,
                        },
                        credentials: 'same-origin',
                        body: JSON.stringify({ columns: this.edits }),
                    });

                    if (!response.ok) {
                        const payload = await response.json().catch(() => ({}));
                        this.schemaError = payload.errors
                            ? Object.values(payload.errors).flat()[0]
                            : (payload.message || this.labels.failed);
                        return;
                    }

                    const payload = await response.json();
                    this.schema = payload.dataset;
                    this.edits = {};

                    window.showToast(this.labels.saved, 'success');

                    // The list behind the drawer shows types nowhere, but it does show
                    // status — and a rebuild has just changed it, so the poller has to
                    // take over from here.
                    if (payload.rebuilding) this.schedule();
                } catch {
                    this.schemaError = this.labels.failed;
                } finally {
                    this.savingSchema = false;
                }
            },

            async rebuild() {
                this.savingSchema = true;
                this.schemaError = '';

                try {
                    const response = await fetch(config.rebuildUrlBase.replace('__ID__', this.schema.id), {
                        method: 'POST',
                        headers: {
                            Accept: 'application/json',
                            'X-CSRF-TOKEN': config.csrf,
                        },
                        credentials: 'same-origin',
                    });

                    const payload = await response.json().catch(() => ({}));

                    if (!response.ok) {
                        this.schemaError = payload.errors
                            ? Object.values(payload.errors).flat()[0]
                            : (payload.message || this.labels.failed);
                        return;
                    }

                    this.schema = payload.dataset;
                    window.showToast(payload.refreshed ? this.labels.refreshed : this.labels.saved, 'success');

                    if (payload.rebuilding) this.schedule();
                } catch {
                    this.schemaError = this.labels.failed;
                } finally {
                    this.savingSchema = false;
                }
            },

            async destroyDataset() {
                if (!window.confirm(this.labels.datasetDeleteConfirm)) return;

                if (await this.send(config.datasetDeleteUrlBase.replace('__ID__', this.schema.id), 'DELETE')) {
                    window.showToast(this.labels.datasetDeleted, 'success');
                    window.location.reload();
                }
            },

            /* ---------------------------------------------------------------- connect

               THESE ARE THE BI MODULE'S OWN CONNECTIONS. They live in
               `bi_connections`, are owned by `user_id`, and have nothing to do
               with the connections an owner set up for their agents — see
               BiConnection for why borrowing those was wrong. Nothing in this
               file may ever send a `database_connections` id. */

            async openConnect() {
                this.connectOpen = true;
                this.connectLoading = true;
                this.connectError = '';
                this.connectErrorDetail = '';
                this.connectNotice = '';
                this.chosenTables = [];
                this.tables = [];

                try {
                    const response = await fetch(config.connectionsUrl, {
                        headers: { Accept: 'application/json' },
                        credentials: 'same-origin',
                    });

                    const payload = await response.json();
                    this.connections = payload.connections ?? [];
                    this.engines = payload.engines ?? [];

                    if (this.connections.length > 0) {
                        this.connectView = 'list';
                        await this.selectConnection(this.connections[0].id);
                    } else {
                        this.newConnection();
                    }
                } catch {
                    this.connectError = this.labels.failed;
                } finally {
                    this.connectLoading = false;
                }
            },

            selectedConnection() {
                return this.connections.find((connection) => connection.id === this.connectionId) ?? null;
            },

            defaultPort(engine) {
                return this.engines.find((option) => option.key === engine)?.port ?? 3306;
            },

            newConnection() {
                this.connectView = 'form';
                this.connectError = '';
                this.connectNotice = '';
                this.form = {
                    id: null, name: '', engine: this.engines[0]?.key ?? 'mysql',
                    host: '', port: this.defaultPort(this.engines[0]?.key ?? 'mysql'),
                    database: '', username: '', password: '', ssl: false,
                };
            },

            editConnection() {
                const connection = this.selectedConnection();
                if (!connection) return;

                this.connectView = 'form';
                this.connectError = '';
                this.connectNotice = '';
                // No password: the server never sends it back. An empty field
                // on save means "keep the stored one".
                this.form = {
                    id: connection.id, name: connection.name, engine: connection.engine,
                    host: connection.host, port: connection.port, database: connection.database,
                    username: connection.username, password: '', ssl: connection.ssl,
                };
            },

            onEngineChange() {
                // Only move the port if it is still a default — an owner who
                // typed 3307 means it.
                const defaults = this.engines.map((option) => option.port);
                if (defaults.includes(Number(this.form.port))) {
                    this.form.port = this.defaultPort(this.form.engine);
                }
            },

            /* Dial the host without saving. The same endpoint the save path
               uses internally, exposed so an owner can prove credentials work
               before committing them. */
            async testConnection() {
                if (this.testing) return;

                this.testing = true;
                this.connectError = '';
                this.connectNotice = '';

                try {
                    const url = this.form.id
                        ? config.connectionRetestUrlBase.replace('__ID__', this.form.id)
                        : config.connectionTestUrl;

                    const response = await fetch(url, {
                        method: 'POST',
                        headers: {
                            Accept: 'application/json',
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': config.csrf,
                        },
                        credentials: 'same-origin',
                        body: JSON.stringify(this.form),
                    });

                    const payload = await response.json().catch(() => ({}));

                    if (!response.ok) {
                        this.connectError = payload.message
                            || (payload.errors ? Object.values(payload.errors).flat()[0] : this.labels.failed);
                        return;
                    }

                    this.connectNotice = payload.message ?? this.labels.tested;
                } catch {
                    this.connectError = this.labels.failed;
                } finally {
                    this.testing = false;
                }
            },

            /* Test-then-save, enforced on the server too: a connection that has
               not just been proven is never written. */
            async saveConnection() {
                if (this.savingConnection) return;

                this.savingConnection = true;
                this.connectError = '';
                this.connectNotice = '';

                try {
                    const editing = Boolean(this.form.id);
                    const url = editing
                        ? config.connectionUpdateUrlBase.replace('__ID__', this.form.id)
                        : config.connectionStoreUrl;

                    const response = await fetch(url, {
                        method: editing ? 'PATCH' : 'POST',
                        headers: {
                            Accept: 'application/json',
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': config.csrf,
                        },
                        credentials: 'same-origin',
                        body: JSON.stringify(this.form),
                    });

                    const payload = await response.json().catch(() => ({}));

                    if (!response.ok) {
                        this.connectError = payload.errors
                            ? Object.values(payload.errors).flat()[0]
                            : (payload.message || this.labels.failed);
                        return;
                    }

                    window.showToast(editing ? this.labels.connectionUpdated : this.labels.connectionCreated, 'success');

                    // Reload the list and land on the connection just saved, so
                    // the next step — picking tables — is already in front of
                    // them rather than one more click away.
                    const savedId = payload.id ?? this.form.id;
                    this.connectLoading = true;

                    const listed = await fetch(config.connectionsUrl, {
                        headers: { Accept: 'application/json' },
                        credentials: 'same-origin',
                    }).then((r) => r.json()).catch(() => ({}));

                    this.connections = listed.connections ?? [];
                    this.connectLoading = false;
                    this.connectView = 'list';

                    if (savedId) await this.selectConnection(savedId);
                } catch {
                    this.connectError = this.labels.failed;
                } finally {
                    this.savingConnection = false;
                }
            },

            async deleteConnection() {
                const connection = this.selectedConnection();
                if (!connection || !window.confirm(this.labels.connectionDeleteConfirm)) return;

                try {
                    const response = await fetch(
                        config.connectionDeleteUrlBase.replace('__ID__', connection.id),
                        {
                            method: 'DELETE',
                            headers: { Accept: 'application/json', 'X-CSRF-TOKEN': config.csrf },
                            credentials: 'same-origin',
                        },
                    );

                    if (!response.ok) {
                        this.connectError = this.labels.failed;
                        return;
                    }

                    window.showToast(this.labels.connectionDeleted, 'success');
                    // A full reload: deleting a connection deletes the sources
                    // built on it, so the list behind the modal is stale.
                    window.location.reload();
                } catch {
                    this.connectError = this.labels.failed;
                }
            },

            async selectConnection(id) {
                this.connectionId = Number(id);
                this.chosenTables = [];
                this.tables = [];
                this.tablesLoading = true;
                this.connectError = '';
                this.connectErrorDetail = '';

                const connection = this.selectedConnection();
                if (connection && !this.connectName) this.connectName = connection.label;

                // An unverified connection is not dialled: the server refuses to
                // register from one anyway, and the useful thing to show is why.
                if (connection && !connection.usable) {
                    this.tablesLoading = false;
                    this.connectError = this.labels.notVerified;
                    return;
                }

                try {
                    const response = await fetch(
                        config.connectionTablesUrlBase.replace('__ID__', this.connectionId),
                        { headers: { Accept: 'application/json' }, credentials: 'same-origin' },
                    );

                    const payload = await response.json().catch(() => ({}));

                    if (!response.ok) {
                        // The sentence, plus the driver's own classified reason —
                        // "wrong password" stays distinguishable from "host
                        // unreachable", which is the whole difference between a
                        // fixable problem and a mystery.
                        this.connectError = payload.errors
                            ? Object.values(payload.errors).flat()[0]
                            : (payload.message || this.labels.failed);
                        this.connectErrorDetail = payload.detail ?? '';
                        return;
                    }

                    this.tables = payload.tables ?? [];
                } catch {
                    this.connectError = this.labels.failed;
                } finally {
                    this.tablesLoading = false;
                }
            },

            toggleTable(name) {
                this.chosenTables = this.chosenTables.includes(name)
                    ? this.chosenTables.filter((table) => table !== name)
                    : [...this.chosenTables, name];
            },

            async registerConnection() {
                if (this.registering || this.chosenTables.length === 0) return;

                this.registering = true;
                this.connectError = '';

                try {
                    const response = await fetch(config.connectSourceUrl, {
                        method: 'POST',
                        headers: {
                            Accept: 'application/json',
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': config.csrf,
                        },
                        credentials: 'same-origin',
                        body: JSON.stringify({
                            // The BI connection's id. There is deliberately no
                            // field here that could carry an agent's.
                            bi_connection_id: this.connectionId,
                            tables: this.chosenTables,
                            name: this.connectName || null,
                        }),
                    });

                    const payload = await response.json().catch(() => ({}));

                    if (!response.ok) {
                        this.connectError = payload.errors
                            ? Object.values(payload.errors).flat()[0]
                            : (payload.message || this.labels.failed);
                        return;
                    }

                    window.showToast(this.labels.registered, 'success');
                    window.location.reload();
                } catch {
                    this.connectError = this.labels.failed;
                } finally {
                    this.registering = false;
                }
            },

            /* ----------------------------------------------------------------- shared */

            async send(url, method) {
                try {
                    const response = await fetch(url, {
                        method,
                        headers: { Accept: 'application/json', 'X-CSRF-TOKEN': config.csrf },
                        credentials: 'same-origin',
                    });

                    if (!response.ok) throw new Error('failed');

                    return true;
                } catch {
                    window.showToast(this.labels.failed, 'error');

                    return false;
                }
            },

            /**
             * One preview cell, rendered the way a person reads it.
             *
             * The stored value is faithful and unreadable: a DECIMAL(20,6) column
             * hands back `537.500000`, and a boolean hands back `1`. Neither is wrong,
             * and both make the evidence pane harder to check a correction against
             * than the owner's own spreadsheet was.
             *
             * Only trailing zeros are trimmed — nothing is rounded — so the number
             * shown is still exactly the number stored.
             */
            previewValue(column, value) {
                if (value === null || value === undefined || value === '') return '—';

                const type = this.edit(column, 'type');

                if (type === 'boolean') {
                    return Number(value) === 1 ? this.labels.yes : this.labels.no;
                }

                if (type === 'decimal') {
                    const text = String(value);

                    return text.includes('.')
                        ? text.replace(/0+$/, '').replace(/\.$/, '')
                        : text;
                }

                return String(value);
            },

            /**
             * Space-grouped thousands, matching the server's own
             * `number_format(…, '.', ' ')` exactly.
             *
             * Done by hand rather than with `toLocaleString`: that emits a
             * NON-BREAKING space for ru-RU and a comma for en-US, so the same figure
             * would be grouped differently depending on the viewer's locale and on
             * which half of the page rendered it — which reads as a bug.
             */
            formatNumber(value) {
                const number = Math.trunc(Number(value ?? 0));

                return String(number).replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
            },
        };
    }
</script>
