<script>
/**
 * The board's Alpine component: gridstack wiring and the widget editor's state.
 *
 * The board uses fetch for edits and refreshes so the owner can keep their
 * place while cards update. The only PUT is layout persistence: a drag changes
 * coordinates rather than a form field.
 *
 * That split is deliberate. A widget save can change the whole board (a new
 * card shifts the grid, a changed filter changes its neighbours' cache keys),
 * so the server remains the single source of truth while fetch swaps in each
 * newly rendered card. A drag changes only four numbers per row and persists
 * quietly so the card stays under the cursor.
 */
function biBoard(config) {
    return {
        ...config,

        grid: null,
        // The owner explicitly opened a dashboard, not the analyst. On phones
        // the chat covers it; on desktop it compresses four KPI cards into
        // narrow columns. Keep the analyst one visible click away in both.
        chatOpen: false,
        chatMessages: config.chatHistory || [], chatDraft: '', chatLoading: false, chatError: '', refreshing: false,
        globalFrom: '', globalTo: '', globalFromDisplay: '', globalToDisplay: '', globalCategory: '', globalValue: '', filtersOpen: false, savingFilters: false, filterError: '',
        editorOpen: false,
        editorError: '', savingEditor: false,
        widgetTypeNames: {{ Js::from(collect($widgetTypes)->mapWithKeys(fn ($type) => [$type->value => __('bi.widgets.types.'.$type->value)])->all()) }},
        aggregateNames: {{ Js::from(collect(\App\Services\Bi\Query\Aggregate::cases())->mapWithKeys(fn ($aggregate) => [$aggregate->value => __('bi.aggregates.'.$aggregate->value)])->all()) }},
        draft: { id: null, title: '', type: 'column', dataset_id: null, dimensions: [], measures: [], filters: [], limit: null },

        async readJson(response) {
            try {
                return JSON.parse(await response.text());
            } catch (_) {
                // A proxy/PHP error page is HTML, not JSON. Never leak the
                // browser's technical JSON.parse message into the BI interface.
                throw new Error(this.labels.failed);
            }
        },

        /* The shape rules, mirrored from WidgetType so the editor can hide a
           control the server would refuse. The server is still the authority —
           WidgetSpecBuilder re-checks every one of these — this copy exists only
           so the owner is never offered a combination that cannot work. Keep
           the two in step; the test suite asserts the server half. */
        shapes: {
            line: { d: [1, 1], m: [1, 4] },
            area: { d: [1, 1], m: [1, 4] },
            bar: { d: [1, 2], m: [1, 4] },
            column: { d: [1, 2], m: [1, 4] },
            donut: { d: [1, 1], m: [1, 1] },
            table: { d: [0, 6], m: [0, 6] },
            kpi: { d: [0, 0], m: [1, 1] },
        },

        async init() {
            const active = this.dashboardFilters?.[String(this.mainDatasetId)] || [];
            this.globalFrom = active.find(f => f.operator === 'gte' && f.column === this.timeColumn?.name)?.value || '';
            this.globalTo = active.find(f => f.operator === 'lte' && f.column === this.timeColumn?.name)?.value || '';
            this.globalFromDisplay = this.formatFilterDate(this.globalFrom);
            this.globalToDisplay = this.formatFilterDate(this.globalTo);
            this.globalCategory = active.find(f => f.operator === 'eq')?.column || this.categoryColumns[0]?.name || '';
            this.globalValue = active.find(f => f.operator === 'eq' && f.column === this.globalCategory)?.value || '';
            if (!this.$refs.grid) return;

            const [GridStack, mountCharts] = await Promise.all([window.loadGridStack(), window.loadBiCharts()]);

            this.grid = GridStack.init(
                {
                    column: 12,
                    // A twelve-column canvas makes each 3-column KPI barely
                    // readable on a phone. Gridstack keeps the 12-column
                    // desktop layout internally while presenting one full-
                    // width column below 768px.
                    columnOpts: {breakpoints: [{w: 767, c: 1}], breakpointForWindow: true, layout: 'move'},
                    cellHeight: window.matchMedia('(max-width: 767px)').matches ? 100 : 92,
                    margin: 8,
                    /* The header is the handle. See `.widget-head` in app.css:
                       a fully draggable card is a card you cannot select a
                       number out of. */
                    handle: '.widget-head',
                    resizable: { handles: 'se, sw' },
                    staticGrid: !this.canManage || window.matchMedia('(max-width: 767px)').matches,
                    animate: !window.matchMedia('(prefers-reduced-motion: reduce)').matches,
                },
                this.$refs.grid
            );

            /* The signal our own fallback CSS waits on. Gridstack v14 sets no
               stable "initialized" class of its own, so the pre-boot stacking
               rule in app.css keys on this one — see the note there. */
            this.$refs.grid.classList.add('grid-stack-ready');
            mountCharts(this.$refs.grid);
            this.mountCharts = mountCharts;

            /* Debounced, because gridstack fires `change` continuously during a
               resize — without this one drag is a write per animation frame,
               which is also what the bi-layout limiter is there to catch. */
            let pending = null;
            this.grid.on('change', () => {
                clearTimeout(pending);
                pending = setTimeout(() => this.saveLayout(), 400);
            });
        },

        get currentDataset() { return this.datasets.find(d => d.id === this.mainDatasetId); },
        get timeColumn() { return this.currentDataset?.columns.find(c => c.temporal) || null; },
        get categoryColumns() { return this.currentDataset?.columns.filter(c => !c.temporal && !c.numeric && (c.distinct ?? 9999) <= 20) || []; },
        get categoryValues() { return [...new Set((this.categoryColumns.find(c => c.name === this.globalCategory)?.samples || []).filter(v => v != null && v !== ''))].slice(0, 8); },

        formatFilterDate(iso) {
            if (!iso) return '';
            const match = String(iso).match(/^(\d{4})-(\d{2})-(\d{2})$/);
            return match && this.dateFormat === 'DD.MM.YYYY' ? `${match[3]}.${match[2]}.${match[1]}` : String(iso);
        },
        parseFilterDate(value) {
            if (!value?.trim()) return '';
            const match = this.dateFormat === 'DD.MM.YYYY'
                ? value.trim().match(/^(\d{2})\.(\d{2})\.(\d{4})$/)
                : value.trim().match(/^(\d{4})-(\d{2})-(\d{2})$/);
            if (!match) return null;
            const iso = this.dateFormat === 'DD.MM.YYYY' ? `${match[3]}-${match[2]}-${match[1]}` : value.trim();
            const date = new Date(`${iso}T00:00:00Z`);
            return Number.isNaN(date.getTime()) || date.toISOString().slice(0, 10) !== iso ? null : iso;
        },

        async saveGlobalFilters() {
            if (this.savingFilters) return;
            this.filterError = '';
            const from = this.parseFilterDate(this.globalFromDisplay);
            const to = this.parseFilterDate(this.globalToDisplay);
            if (from === null || to === null) { this.filterError = this.labels.invalidDate; return; }
            if (from && to && from > to) { this.filterError = this.labels.invalidRange; return; }
            this.globalFrom = from; this.globalTo = to;
            this.savingFilters = true;
            const filters = [];
            if (this.timeColumn && this.globalFrom) filters.push({column: this.timeColumn.name, operator: 'gte', value: this.globalFrom});
            if (this.timeColumn && this.globalTo) filters.push({column: this.timeColumn.name, operator: 'lte', value: this.globalTo});
            if (this.globalCategory && this.globalValue) filters.push({column: this.globalCategory, operator: 'eq', value: this.globalValue});
            const all = {...this.dashboardFilters, [String(this.mainDatasetId)]: filters};
            try {
                const response = await fetch(this.dashboardUpdateUrl, {
                    method: 'PATCH', credentials: 'same-origin',
                    headers: {'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': this.csrf},
                    body: JSON.stringify({name: this.dashboardName, filters: all}),
                });
                const data = await this.readJson(response);
                if (!response.ok) throw new Error(Object.values(data.errors || {}).flat()[0] || data.message);
                this.dashboardFilters = data.filters || all;
                await this.refreshBoard();
            } catch (_) { this.filterError = this.labels.failed; }
            finally { this.savingFilters = false; }
        },

        clearGlobalFilters() {
            this.globalFrom = ''; this.globalTo = ''; this.globalFromDisplay = ''; this.globalToDisplay = ''; this.globalValue = '';
            this.saveGlobalFilters();
        },

        async changeGrain(id, grain) {
            const widget = this.widgets.find(w => w.id === id);
            if (!widget) return;
            const spec = structuredClone(widget.spec);
            if (!spec.dimensions?.[0]) return;
            spec.dimensions[0].grain = grain;
            try {
                const response = await fetch(this.widgetUpdateUrl(id), {
                    method: 'PATCH', credentials: 'same-origin',
                    headers: {'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': this.csrf},
                    body: JSON.stringify({bi_dataset_id: widget.dataset_id, title: widget.title, type: widget.type, spec, filters: widget.filters}),
                });
                const data = await this.readJson(response);
                if (!response.ok) throw new Error(Object.values(data.errors || {}).flat()[0] || data.message);
                this.applyWidgetData(data);
            } catch (error) { window.showToast?.(error.message || this.labels.failed, 'error'); }
        },

        async saveLayout() {
            const layout = this.grid.save(false).map((node) => ({
                id: Number(node.id),
                x: node.x, y: node.y, w: node.w, h: node.h,
            }));

            try {
                await fetch(this.layoutUrl, {
                    method: 'PUT',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': this.csrf,
                    },
                    body: JSON.stringify({ layout }),
                });
            } catch (e) {
                /* Swallowed on purpose, and this is the one place in the file
                   worth arguing about. The cards are already where the owner
                   dropped them; a toast saying "could not save layout" on a
                   flaky connection would be noise about something they can fix
                   by dragging again, and the next drag retries anyway. A FAILED
                   SAVE IS VISIBLE ON THE NEXT PAGE LOAD, which is the honest
                   signal — the board comes back in its old arrangement. */
            }
        },

        async sendChat() {
            const question = this.chatDraft.trim();
            if (!question || this.chatLoading) return;
            this.chatDraft = ''; this.chatError = ''; this.chatLoading = true;
            const localId = `pending-${Date.now()}`;
            this.chatMessages.push({id: localId, role: 'user', text: question, effects: []});
            this.$nextTick(() => this.$refs.chatScroll?.scrollTo(0, this.$refs.chatScroll.scrollHeight));
            try {
                const response = await fetch(this.chatUrl, {
                    method: 'POST', credentials: 'same-origin',
                    headers: {'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': this.csrf},
                    body: JSON.stringify({message: question}),
                });
                const data = await this.readJson(response);
                if (!response.ok) throw new Error(Object.values(data.errors || {}).flat()[0] || data.message);
                this.chatMessages.push({id: data.id, role: 'assistant', text: data.text, effects: data.effects, undoable: data.undoable});
                for (const effect of data.effects || []) {
                    if (effect.kind === 'widget') { this.applyWidgetData(effect); this.highlightWidget(effect.widget_id); }
                    if (effect.kind === 'remove') {
                        const element = this.$refs.grid.querySelector(`[gs-id="${effect.widget_id}"]`);
                        if (element) this.grid.removeWidget(element);
                        this.widgets = this.widgets.filter(w => w.id !== effect.widget_id);
                    }
                    if (effect.kind === 'filter') await this.refreshBoard();
                }
                this.$nextTick(() => this.$refs.chatScroll?.scrollTo(0, this.$refs.chatScroll.scrollHeight));
            } catch (error) {
                this.chatMessages = this.chatMessages.filter(m => m.id !== localId);
                this.chatDraft = question; this.chatError = error.message || this.labels.failed;
            } finally { this.chatLoading = false; }
        },

        chooseColumn(option) {
            const last = [...this.chatMessages].reverse().find(m => m.role === 'user');
            this.chatDraft = `${last?.text || ''} — ${option.label} (${option.id})`;
            this.sendChat();
        },

        async undoChat(message) {
            try {
                const response = await fetch(this.undoUrlBase.replace('__ID__', message.id), {
                    method: 'POST', credentials: 'same-origin',
                    headers: {Accept: 'application/json', 'X-CSRF-TOKEN': this.csrf},
                });
                const data = await this.readJson(response);
                if (!response.ok) throw new Error(Object.values(data.errors || {}).flat()[0] || data.message);
                message.undoable = false;
                await this.refreshBoard();
                window.showToast?.(data.message, 'success');
            } catch (error) { window.showToast?.(error.message || this.labels.failed, 'error'); }
        },

        async refreshBoard(forceFresh = false) {
            if (this.refreshing) return;
            this.refreshing = true;
            try {
            const url = new URL(this.boardUrl, window.location.origin);
            if (forceFresh) url.searchParams.set('fresh', '1');
            const response = await fetch(url, {headers: {Accept: 'application/json'}, credentials: 'same-origin'});
            if (!response.ok) throw new Error(this.labels.failed);
            const fresh = (await this.readJson(response)).widgets;
            const ids = new Set(fresh.map(item => item.widget.id));
            for (const widget of this.widgets) {
                if (!ids.has(widget.id)) {
                    const element = this.$refs.grid.querySelector(`[gs-id="${widget.id}"]`);
                    if (element) this.grid.removeWidget(element);
                }
            }
            this.widgets = [];
            for (const item of fresh) this.applyWidgetData(item);
            } catch (_) {
                window.showToast?.(this.labels.failed, 'error');
            } finally { this.refreshing = false; }
        },

        highlightWidget(id) {
            const card = this.$refs.grid.querySelector(`[gs-id="${id}"] .widget-card`);
            if (!card) return;
            card.classList.add('ring-2', 'ring-brand');
            setTimeout(() => card.classList.remove('ring-2', 'ring-brand'), 3200);
        },

        widgetUpdateUrl(id) {
            return this.widgetUpdateUrlBase.replace('__ID__', id);
        },

        get columns() {
            const dataset = this.datasets.find((d) => d.id === this.draft.dataset_id);
            return dataset ? dataset.columns : [];
        },

        get hasNumeric() { return this.columns.some(c => c.numeric); },
        canUseType(type) {
            if (['line', 'area'].includes(type)) return this.columns.some(c => c.temporal);
            if (type === 'donut') return this.columns.some(c => !c.temporal && (c.distinct ?? 9999) <= 6);
            return this.columns.length > 0;
        },
        get dimensionColumns() {
            if (['line', 'area'].includes(this.draft.type)) return this.columns.filter(c => c.temporal);
            if (this.draft.type === 'donut') return this.columns.filter(c => !c.temporal && (c.distinct ?? 9999) <= 6);
            return this.columns;
        },
        ordered(name) { const col = this.columns.find(c => c.name === name); return !!col && (col.temporal || col.numeric); },
        addFilter() { if (this.columns.length) this.draft.filters.push({column: this.columns[0].name, operator: 'eq', value: ''}); },
        onMeasureChange(measure) {
            if (measure.aggregate === 'count') { measure.column = ''; return; }
            if (!this.measureColumns(measure.aggregate).some(c => c.name === measure.column)) {
                measure.column = this.measureColumns(measure.aggregate)[0]?.name ?? '';
            }
        },

        /* SUM and AVG only make sense on a number; MIN and MAX are fine on a
           date or a name. Mirrors Aggregate::needsNumeric(). */
        measureColumns(aggregate) {
            return ['sum', 'avg'].includes(aggregate)
                ? this.columns.filter((c) => c.numeric)
                : this.columns;
        },

        isTemporal(name) {
            return !!this.columns.find((c) => c.name === name && c.temporal);
        },

        get shape() {
            return this.shapes[this.draft.type] ?? this.shapes.column;
        },
        get minDimensions() { return this.shape.d[0]; },
        get maxDimensions() { return this.shape.d[1]; },
        get minMeasures() { return this.shape.m[0]; },
        get maxMeasures() { return this.shape.m[1]; },

        addDimension() {
            const column = this.dimensionColumns.find((c) => c.role === 'dimension') ?? this.dimensionColumns[0];
            if (!column) return;
            this.draft.dimensions.push({ column: column.name, grain: column.temporal ? 'month' : '' });
        },

        addMeasure() {
            const column = this.columns.find((c) => c.numeric) ?? this.columns[0];
            this.draft.measures.push({ aggregate: column?.numeric ? 'sum' : 'count', column: column?.name ?? '' });
        },

        /* Changing the type RESHAPES the draft rather than refusing to change:
           an owner switching a four-series line chart to a donut means "show me
           this as a donut", and the useful response is to keep the first series
           and drop the rest, not to make them delete three measures first. */
        onTypeChange() {
            if (!this.canUseType(this.draft.type)) this.draft.type = 'table';
            this.draft.dimensions = this.draft.dimensions.filter(d => this.dimensionColumns.some(c => c.name === d.column));
            while (this.draft.dimensions.length > this.maxDimensions) this.draft.dimensions.pop();
            while (this.draft.dimensions.length < this.minDimensions) {
                const before = this.draft.dimensions.length;
                this.addDimension();
                if (this.draft.dimensions.length === before) break;
            }
            while (this.draft.measures.length > this.maxMeasures) this.draft.measures.pop();
            while (this.draft.measures.length < this.minMeasures) this.addMeasure();
        },

        /* A new dataset means new columns, and the old references point at
           columns that may not exist in it. Rebuilt from scratch rather than
           remapped by name: `c2` means something different in every dataset,
           so carrying it across would produce a chart of the wrong column
           rather than an error. */
        onDatasetChange() {
            this.draft.dimensions = [];
            this.draft.measures = [];
            this.draft.filters = [];
            this.onTypeChange();
        },

        newWidget() {
            this.draft = {
                id: null,
                title: '',
                type: this.datasets[0]?.columns?.some(c => c.temporal) ? 'area' : 'table',
                dataset_id: this.datasets[0]?.id ?? null,
                dimensions: [],
                measures: [],
                filters: [],
                limit: null,
            };
            this.onTypeChange();
            this.editorOpen = true;
        },

        editWidget(id) {
            const widget = this.widgets.find((w) => w.id === id);
            if (!widget) return;

            this.draft = {
                id: widget.id,
                title: widget.title,
                type: widget.type,
                dataset_id: widget.dataset_id,
                dimensions: (widget.spec?.dimensions ?? []).map((d) => ({ column: d.column, grain: d.grain ?? '' })),
                measures: (widget.spec?.measures ?? []).map((m) => ({ aggregate: m.aggregate, column: m.column ?? '' })),
                filters: (widget.filters ?? []).map(f => ({...f})),
                limit: widget.spec?.limit ?? null,
            };
            this.editorOpen = true;
        },

        closeEditor() {
            this.editorOpen = false;
            this.editorError = '';
        },

        async saveWidget(event) {
            if (this.savingEditor) return;
            this.savingEditor = true; this.editorError = '';
            const form = event.target;
            const body = new FormData(form);
            try {
                const response = await fetch(form.action, {
                    method: 'POST', credentials: 'same-origin',
                    headers: {Accept: 'application/json', 'X-CSRF-TOKEN': this.csrf}, body,
                });
                const data = await this.readJson(response);
                if (!response.ok) {
                    this.editorError = Object.values(data.errors || {}).flat()[0] || data.message || this.labels.failed;
                    return;
                }
                this.applyWidgetData(data);
                this.closeEditor(); window.showToast?.(data.message, 'success');
            } catch (_) { this.editorError = this.labels.failed; }
            finally { this.savingEditor = false; }
        },

        applyWidgetData(data) {
            const widget = data.widget;
            const old = this.$refs.grid.querySelector(`[gs-id="${widget.id}"]`);
            if (old) {
                old.querySelector('.grid-stack-item-content').innerHTML = data.html;
                this.widgets = this.widgets.some(w => w.id === widget.id)
                    ? this.widgets.map(w => w.id === widget.id ? widget : w)
                    : [...this.widgets, widget];
                window.Alpine.initTree(old); this.mountCharts(old);
                return;
            }
            const slot = widget.slot;
            const element = document.createElement('div'); element.className = 'grid-stack-item';
            element.setAttribute('gs-id', widget.id);
            element.setAttribute('gs-x', slot.x); element.setAttribute('gs-y', slot.y);
            element.setAttribute('gs-w', slot.w); element.setAttribute('gs-h', slot.h);
            const content = document.createElement('div'); content.className = 'grid-stack-item-content';
            content.innerHTML = data.html; element.appendChild(content);
            this.$refs.grid.appendChild(element); this.grid.makeWidget(element);
            this.widgets.push(widget);
            window.Alpine.initTree(element); this.mountCharts(element);
        },

        async duplicateWidget(id) {
            const widget = this.widgets.find(w => w.id === id);
            if (!widget) return;
            try {
                const response = await fetch(this.widgetStoreUrl, {
                    method: 'POST', credentials: 'same-origin',
                    headers: {'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': this.csrf},
                    body: JSON.stringify({bi_dataset_id: widget.dataset_id, title: widget.title + ' — ' + @js(__('bi.widgets.copy')),
                        type: widget.type, spec: widget.spec, filters: widget.filters}),
                });
                const data = await this.readJson(response);
                if (!response.ok) throw new Error(Object.values(data.errors || {}).flat()[0] || data.message);
                this.applyWidgetData(data); window.showToast?.(data.message, 'success');
            } catch (error) { window.showToast?.(error.message || this.labels.failed, 'error'); }
        },

        async removeWidget(id) {
            if (!window.confirm(this.labels.deleteWidget)) return;
            try {
                const response = await fetch(this.widgetDeleteUrlBase.replace('__ID__', id), {
                    method: 'DELETE', credentials: 'same-origin',
                    headers: {Accept: 'application/json', 'X-CSRF-TOKEN': this.csrf},
                });
                if (!response.ok) throw new Error('delete');
                const data = await this.readJson(response);
                const element = this.$refs.grid.querySelector(`[gs-id="${id}"]`);
                if (element) this.grid.removeWidget(element);
                this.widgets = this.widgets.filter(w => w.id !== id);
                window.showToast?.(data.message, 'success');
            } catch (_) { window.showToast?.(this.labels.failed, 'error'); }
        },

        /* The retry button on a transient failure. The server still owns the
           card markup; refresh the JSON board and replace its HTML in place so
           a retry never throws away the dashboard's scroll or editor state. */
        async refreshWidget(id) {
            try {
                const url = this.widgetDataUrlBase.replace('__ID__', id) + '?fresh=1';
                const response = await fetch(url, {
                    credentials: 'same-origin',
                    headers: { Accept: 'application/json' },
                });
                const payload = await this.readJson(response);

                if (payload.error) {
                    window.showToast(payload.message ?? this.labels.failed, 'error');
                    return;
                }

                await this.refreshBoard(true);
            } catch (e) {
                window.showToast(this.labels.failed, 'error');
            }
        },
    };
}

function biTable(config) {
    return {
        ...config, search: '', sort: '', direction: 'asc', loading: false, error: '',
        async readJson(response) {
            try { return JSON.parse(await response.text()); }
            catch (_) { throw new Error(this.errorLabel); }
        },
        format(value, column) {
            if (value === null || value === '') return '—';
            if (column.kind === 'measure' || ['integer', 'decimal'].includes(column.type)) {
                return new Intl.NumberFormat(document.documentElement.lang, {maximumFractionDigits: 2}).format(Number(value));
            }
            if (column.type === 'date' || column.type === 'datetime') {
                const date = new Date(String(value).slice(0, 10) + 'T12:00:00');
                return Number.isNaN(date.getTime()) ? String(value) : new Intl.DateTimeFormat(document.documentElement.lang).format(date);
            }
            return String(value);
        },
        sortBy(alias) { this.direction = this.sort === alias && this.direction === 'asc' ? 'desc' : 'asc'; this.sort = alias; this.load(0); },
        async load(offset) {
            this.loading = true; this.error = '';
            const url = new URL(this.url, window.location.origin);
            url.searchParams.set('offset', String(offset));
            if (this.sort) { url.searchParams.set('sort', this.sort); url.searchParams.set('direction', this.direction); }
            if (this.search && this.searchColumn) { url.searchParams.set('search', this.search); url.searchParams.set('search_column', this.searchColumn); }
            try {
                const response = await fetch(url, {headers: {Accept: 'application/json'}, credentials: 'same-origin'});
                const data = await this.readJson(response);
                if (!response.ok || data.error) throw new Error(data.message || this.errorLabel);
                this.rows = data.rows; this.columns = data.columns; this.hasMore = data.has_more;
                this.offset = data.offset; this.pageSize = data.page_size;
            } catch (error) { this.error = error.message || @js(__('bi.errors.internal_error')); }
            finally { this.loading = false; }
        },
    };
}
</script>
