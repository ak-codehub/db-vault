<script setup>
import { computed } from 'vue';

const props = defineProps({
    name: {
        type: String,
        default: '',
    },
    size: {
        type: Number,
        default: 34,
    },
});

const initials = computed(() => {
    const parts = props.name.trim().split(/\s+/).filter(Boolean);
    if (parts.length === 0) return '?';
    if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase();
    return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
});
</script>

<template>
    <div
        class="rounded-full bg-brand-100 text-brand-600 grid place-items-center font-semibold shrink-0"
        :style="{ width: `${size}px`, height: `${size}px`, fontSize: `${Math.max(10, Math.round(size * 0.34))}px` }"
    >
        {{ initials }}
    </div>
</template>
