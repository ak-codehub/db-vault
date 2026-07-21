<script setup>
// Rows = tables, columns = grantable privileges only.
// Drop / Truncate / Trigger are intentionally NEVER offered — the vault
// structurally cannot grant them under any matrix.
const COLUMNS = [
    { key: 'select', label: 'Select' },
    { key: 'insert', label: 'Insert' },
    { key: 'update', label: 'Update' },
    { key: 'delete', label: 'Delete' },
    { key: 'alter', label: 'Alter' },
    { key: 'index', label: 'Index' },
];

import { computed } from 'vue';

const props = defineProps({
    modelValue: {
        type: Array,
        default: () => [],
        // [{ table: 'orders', note: 'write requested', select: true, insert: true, ... }]
    },
    readonly: {
        type: Boolean,
        default: false,
    },
    showNote: {
        type: Boolean,
        default: true,
    },
    // Optional case-insensitive substring filter on the table name. Column-
    // and row-level "All" toggles act on the FILTERED set, so operators can
    // e.g. filter to "order" then tick Select across just those tables.
    filter: {
        type: String,
        default: '',
    },
    // When true, each row shows a remove (×) control that emits `remove`.
    removable: {
        type: Boolean,
        default: false,
    },
    // When false, the component does NOT add its own horizontal-scroll
    // wrapper — use this when a parent already provides a scroll container
    // (avoids a nested scroll region that clips the rightmost column).
    scroll: {
        type: Boolean,
        default: true,
    },
});

const emit = defineEmits(['update:modelValue', 'remove']);

// Rows currently visible after the filter. Toggles resolve rows by their
// stable `table` name (not a filtered index) so edits always hit the right
// underlying row.
const visibleRows = computed(() => {
    const q = props.filter.trim().toLowerCase();
    if (!q) return props.modelValue;
    return props.modelValue.filter((r) => String(r.table).toLowerCase().includes(q));
});

const visibleTables = computed(() => new Set(visibleRows.value.map((r) => r.table)));

function toggle(table, key) {
    if (props.readonly) return;
    const rows = props.modelValue.map((r) => (r.table === table ? { ...r, [key]: !r[key] } : r));
    emit('update:modelValue', rows);
}

// Whole-column select-all over the VISIBLE rows: true only when every
// visible row already has this privilege. Clicking flips them all.
function columnAll(key) {
    return visibleRows.value.length > 0 && visibleRows.value.every((r) => r[key]);
}

function toggleColumn(key) {
    if (props.readonly) return;
    const target = !columnAll(key);
    emit('update:modelValue', props.modelValue.map(
        (r) => (visibleTables.value.has(r.table) ? { ...r, [key]: target } : r),
    ));
}

// Whole-row select-all across every grantable privilege.
function rowAll(row) {
    return COLUMNS.every((col) => row[col.key]);
}

function toggleRow(table) {
    if (props.readonly) return;
    const rows = props.modelValue.map((r) => {
        if (r.table !== table) return r;
        const target = !rowAll(r);
        return { ...r, ...Object.fromEntries(COLUMNS.map((col) => [col.key, target])) };
    });
    emit('update:modelValue', rows);
}
</script>

<template>
    <div>
        <div :class="scroll ? 'overflow-x-auto' : ''">
            <table class="tbl matrix min-w-full">
                <thead class="sticky top-0 z-[1] bg-panel">
                    <tr>
                        <th class="text-left min-w-[160px] whitespace-nowrap">Table</th>
                        <th v-if="!readonly" class="text-center">All</th>
                        <th v-for="col in COLUMNS" :key="col.key" class="text-center">
                            <span class="block">{{ col.label }}</span>
                            <button
                                v-if="!readonly"
                                type="button"
                                class="text-brand-600 font-semibold text-xxs mt-[3px] hover:underline"
                                @click="toggleColumn(col.key)"
                            >{{ columnAll(col.key) ? 'Clear' : 'All' }}</button>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="visibleRows.length === 0">
                        <td :colspan="COLUMNS.length + (readonly ? 1 : 2) + (removable ? 1 : 0)" class="text-ink-3 text-center py-5 text-[12.5px]">
                            No tables to show.
                        </td>
                    </tr>
                    <tr v-for="row in visibleRows" :key="row.table">
                        <td class="font-semibold text-[12.5px] whitespace-nowrap">
                            {{ row.table }}
                            <span v-if="row.note" class="text-ink-3 font-medium text-xxs block">{{ row.note }}</span>
                        </td>
                        <td v-if="!readonly" class="text-center">
                            <span
                                class="chk"
                                :class="[rowAll(row) ? 'on' : '']"
                                :title="rowAll(row) ? 'Clear all privileges for this table' : 'Select all privileges for this table'"
                                @click="toggleRow(row.table)"
                            >
                                <i v-if="rowAll(row)" class="ti ti-check"></i>
                            </span>
                        </td>
                        <td v-for="col in COLUMNS" :key="col.key" class="text-center">
                            <span
                                class="chk"
                                :class="[row[col.key] ? 'on' : '', readonly ? 'cursor-default' : '']"
                                @click="toggle(row.table, col.key)"
                            >
                                <i v-if="row[col.key]" class="ti ti-check"></i>
                            </span>
                        </td>
                        <td v-if="removable" class="text-center">
                            <button
                                type="button"
                                class="text-ink-3 hover:text-bad"
                                title="Remove this table"
                                @click="emit('remove', row.table)"
                            >
                                <i class="ti ti-x"></i>
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-if="showNote" class="note mt-3">
            <i class="ti ti-shield-check"></i>
            <div>
                <b class="text-brand-600">Drop, Truncate and Trigger are never available.</b>
                <span class="text-brand-600/90"> The vault cannot grant them — table drops and truncation are structurally impossible under any matrix.</span>
            </div>
        </div>
    </div>
</template>
