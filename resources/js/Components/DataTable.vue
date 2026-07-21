<script setup>
defineProps({
    headers: {
        type: Array,
        default: () => [], // array of strings, or { label, align: 'left'|'center'|'right', class }
    },
    rows: {
        type: Array,
        default: () => [],
    },
    emptyTitle: {
        type: String,
        default: 'Nothing here yet',
    },
    emptyMessage: {
        type: String,
        default: '',
    },
});

function headerLabel(h) {
    return typeof h === 'string' ? h : h.label;
}

function headerClass(h) {
    return typeof h === 'string' ? '' : (h.class ?? '');
}
</script>

<template>
    <div class="overflow-x-auto">
        <table class="tbl">
            <thead>
                <tr>
                    <th v-for="(h, i) in headers" :key="i" :class="headerClass(h)">{{ headerLabel(h) }}</th>
                </tr>
            </thead>
            <tbody v-if="rows.length || $slots.default">
                <slot>
                    <tr v-for="(row, i) in rows" :key="i">
                        <slot name="row" :row="row" :index="i" />
                    </tr>
                </slot>
            </tbody>
        </table>
        <div v-if="!rows.length && !$slots.default" class="py-14 text-center">
            <div class="text-ink-2 text-[13px] font-semibold">{{ emptyTitle }}</div>
            <div v-if="emptyMessage" class="text-ink-3 text-xs mt-1">{{ emptyMessage }}</div>
        </div>
    </div>
</template>
