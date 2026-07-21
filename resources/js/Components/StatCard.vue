<script setup>
import { computed } from 'vue';

const props = defineProps({
    label: {
        type: String,
        required: true,
    },
    value: {
        type: [String, Number],
        required: true,
    },
    icon: {
        type: String,
        required: true, // e.g. "ti ti-plug-connected"
    },
    tone: {
        type: String,
        default: 'brand', // brand | ok | warn | info
    },
    trend: {
        type: String,
        default: null,
    },
    trendDirection: {
        type: String,
        default: null, // 'up' | 'down' | null
    },
    trendIcon: {
        type: String,
        default: null,
    },
});

const toneClasses = computed(() => {
    const map = {
        brand: 'bg-brand-50 text-brand-600',
        ok: 'bg-ok-bg text-ok',
        warn: 'bg-warn-bg text-warn',
        info: 'bg-[#eff6ff] text-[#2563eb]',
    };
    return map[props.tone] ?? map.brand;
});

const trendClasses = computed(() => {
    if (props.trendDirection === 'up') return 'text-ok';
    if (props.trendDirection === 'down') return 'text-bad';
    return 'text-ink-3';
});
</script>

<template>
    <div class="card p-[18px]">
        <div class="w-[38px] h-[38px] rounded-[10px] grid place-items-center text-xl mb-[14px]" :class="toneClasses">
            <i :class="icon"></i>
        </div>
        <div class="text-ink-2 text-[12.5px] font-medium">{{ label }}</div>
        <div class="text-[26px] font-bold tracking-[-.02em] mt-[2px] text-ink">{{ value }}</div>
        <div v-if="trend" class="text-xs font-semibold mt-[6px] inline-flex items-center gap-[3px]" :class="trendClasses">
            <i v-if="trendIcon" :class="trendIcon"></i>
            <span :class="!trendDirection ? 'font-mono text-ink-3 font-normal' : ''">{{ trend }}</span>
        </div>
    </div>
</template>
