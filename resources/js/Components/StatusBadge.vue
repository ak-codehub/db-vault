<script setup>
import { computed } from 'vue';

const props = defineProps({
    status: {
        type: String,
        default: 'muted',
        // active/ok, pending/warn, rejected/expired/revoked -> bad, muted -> mute
        validator: (v) =>
            [
                'active', 'ok', 'approved', 'granted',
                'pending', 'warn', 'waiting',
                'rejected', 'bad', 'expired', 'revoked', 'denied',
                'muted', 'mute', 'draft',
            ].includes(v),
    },
    label: {
        type: String,
        default: null,
    },
});

const variant = computed(() => {
    const ok = ['active', 'ok', 'approved', 'granted'];
    const warn = ['pending', 'warn', 'waiting'];
    const bad = ['rejected', 'bad', 'expired', 'revoked', 'denied'];
    if (ok.includes(props.status)) return 'badge-ok';
    if (warn.includes(props.status)) return 'badge-warn';
    if (bad.includes(props.status)) return 'badge-bad';
    return 'badge-mute';
});

const text = computed(() => props.label ?? props.status);
</script>

<template>
    <span class="badge" :class="variant">{{ text }}</span>
</template>
