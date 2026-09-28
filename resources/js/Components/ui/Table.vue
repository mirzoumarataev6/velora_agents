<script setup>
/**
 * A data table with sorting, pagination and an empty state.
 *
 * Rows are 44–48px, which is Tabler's density opened up one notch: at projector
 * distance a 36px row turns a table into a grey texture.
 *
 * Numeric columns are right-aligned and tabular — `align: 'end'` on a column
 * switches both. That is not cosmetic: a column of figures that is neither
 * aligned nor tabular cannot be compared down its length, which is the only
 * reason to put numbers in a column in the first place.
 *
 * Sorting is client-side here because that is what the gallery and the small
 * tables need. A screen with server-side paging passes `manual` and handles the
 * `sort` event itself, so the two never fight over who owns the order.
 */
import { computed, ref } from 'vue';
import { IconArrowDown, IconArrowUp, IconArrowsSort } from '@tabler/icons-vue';
import EmptyState from './EmptyState.vue';

const props = defineProps({
    /** [{ key, label, align?: 'start'|'end', sortable?: boolean, width?: string }] */
    columns: { type: Array, required: true },
    rows: { type: Array, default: () => [] },
    /** Row identity. A key, or a function of the row. */
    rowKey: { type: [String, Function], default: 'id' },
    /** Leave ordering to the caller (server-side sorting). */
    manual: { type: Boolean, default: false },
    perPage: { type: Number, default: 0 },
    emptyTitle: { type: String, default: null },
    emptyDescription: { type: String, default: null },
    emptyIcon: { type: [Object, Function], default: null },
});

const emit = defineEmits(['sort']);

const sortKey = ref(null);
const sortDir = ref('asc');
const page = ref(1);

function toggleSort(column) {
    if (!column.sortable) return;

    if (sortKey.value === column.key) {
        sortDir.value = sortDir.value === 'asc' ? 'desc' : 'asc';
    } else {
        sortKey.value = column.key;
        sortDir.value = 'asc';
    }

    page.value = 1;
    emit('sort', { key: sortKey.value, direction: sortDir.value });
}

const sorted = computed(() => {
    if (props.manual || !sortKey.value) return props.rows;

    // Copy first: sorting props.rows in place would mutate the caller's array,
    // which for an Inertia prop means mutating page state.
    return [...props.rows].sort((a, b) => {
        const left = a[sortKey.value];
        const right = b[sortKey.value];

        if (left === right) return 0;
        if (left === null || left === undefined) return 1;
        if (right === null || right === undefined) return -1;

        const result = typeof left === 'number' && typeof right === 'number'
            ? left - right
            : String(left).localeCompare(String(right));

        return sortDir.value === 'asc' ? result : -result;
    });
});

const pageCount = computed(() => (props.perPage > 0 ? Math.max(1, Math.ceil(sorted.value.length / props.perPage)) : 1));
const visible = computed(() => (props.perPage > 0
    ? sorted.value.slice((page.value - 1) * props.perPage, page.value * props.perPage)
    : sorted.value));

const keyFor = (row, index) => (typeof props.rowKey === 'function' ? props.rowKey(row) : row[props.rowKey] ?? index);

function ariaSort(column) {
    if (!column.sortable) return undefined;
    if (sortKey.value !== column.key) return 'none';

    return sortDir.value === 'asc' ? 'ascending' : 'descending';
}
</script>

<template>
    <div>
        <div v-if="visible.length" class="overflow-x-auto">
            <table class="w-full border-collapse text-body-sm">
                <thead>
                    <tr class="border-b border-border bg-surface-2">
                        <th
                            v-for="column in columns"
                            :key="column.key"
                            scope="col"
                            :style="column.width ? { width: column.width } : undefined"
                            :aria-sort="ariaSort(column)"
                            class="px-4 py-2.5 text-caption font-semibold uppercase tracking-wide text-muted"
                            :class="column.align === 'end' ? 'text-right' : 'text-left'"
                        >
                            <button
                                v-if="column.sortable"
                                type="button"
                                class="inline-flex items-center gap-1 rounded transition-colors duration-fast hover:text-text"
                                :class="column.align === 'end' ? 'flex-row-reverse' : ''"
                                @click="toggleSort(column)"
                            >
                                {{ column.label }}
                                <IconArrowUp v-if="sortKey === column.key && sortDir === 'asc'" :size="14" :stroke-width="2" />
                                <IconArrowDown v-else-if="sortKey === column.key" :size="14" :stroke-width="2" />
                                <IconArrowsSort v-else :size="14" :stroke-width="2" class="opacity-50" />
                            </button>
                            <template v-else>{{ column.label }}</template>
                        </th>
                    </tr>
                </thead>

                <tbody>
                    <tr
                        v-for="(row, index) in visible"
                        :key="keyFor(row, index)"
                        class="border-b border-border transition-colors duration-fast last:border-0 hover:bg-surface-2"
                    >
                        <td
                            v-for="column in columns"
                            :key="column.key"
                            class="h-11 px-4 align-middle text-text"
                            :class="column.align === 'end' ? 'text-right tabular-nums' : 'text-left'"
                        >
                            <slot :name="`cell:${column.key}`" :row="row" :value="row[column.key]">
                                {{ row[column.key] }}
                            </slot>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <EmptyState
            v-else
            :icon="emptyIcon"
            :title="emptyTitle ?? '—'"
            :description="emptyDescription"
        />

        <div
            v-if="perPage > 0 && pageCount > 1"
            class="flex items-center justify-between gap-4 border-t border-border px-4 py-3"
        >
            <p class="text-caption text-muted">{{ page }} / {{ pageCount }}</p>

            <div class="flex gap-2">
                <button
                    type="button"
                    :disabled="page === 1"
                    class="rounded-md border border-border px-3 py-1.5 text-caption font-medium text-text transition-colors duration-fast hover:bg-surface-2 disabled:opacity-50"
                    @click="page = Math.max(1, page - 1)"
                >
                    ←
                </button>
                <button
                    type="button"
                    :disabled="page === pageCount"
                    class="rounded-md border border-border px-3 py-1.5 text-caption font-medium text-text transition-colors duration-fast hover:bg-surface-2 disabled:opacity-50"
                    @click="page = Math.min(pageCount, page + 1)"
                >
                    →
                </button>
            </div>
        </div>
    </div>
</template>
