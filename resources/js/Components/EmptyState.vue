<script setup>
defineProps({
    icon: {
        type: String,
        default: 'ti ti-inbox',
    },
    title: {
        type: String,
        default: 'Nothing here yet',
    },
    message: {
        type: String,
        default: '',
    },
});
</script>

<template>
    <div class="flex flex-col items-center justify-center text-center py-16 px-6">
        <div class="w-12 h-12 rounded-[12px] bg-brand-50 text-brand-600 grid place-items-center text-2xl mb-4">
            <i :class="icon"></i>
        </div>
        <div class="text-ink font-semibold text-[14px]">{{ title }}</div>
        <p v-if="message" class="text-ink-3 text-[12.5px] mt-1 max-w-sm">{{ message }}</p>
        <div v-if="$slots.action" class="mt-5">
            <slot name="action" />
        </div>
    </div>
</template>
