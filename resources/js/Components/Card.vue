<script setup>
defineProps({
    title: {
        type: String,
        default: null,
    },
    padded: {
        type: Boolean,
        default: false,
    },
});
</script>

<template>
    <div class="card">
        <div v-if="title || $slots.header || $slots.action" class="card-h">
            <h3 v-if="title">{{ title }}</h3>
            <slot v-else name="header" />
            <slot name="action" />
        </div>
        <div :class="padded ? 'p-[18px]' : ''">
            <slot />
        </div>
    </div>
</template>
