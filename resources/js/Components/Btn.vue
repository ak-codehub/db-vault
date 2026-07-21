<script setup>
import { computed } from 'vue';
import { RouterLink } from 'vue-router';

const props = defineProps({
    variant: {
        type: String,
        default: 'primary', // primary | ghost | danger | mini | mini-ok
    },
    icon: {
        type: String,
        default: null, // e.g. "ti ti-plus"
    },
    to: {
        type: [String, Object],
        default: null, // internal vue-router destination
    },
    href: {
        type: String,
        default: null, // external / plain anchor link
    },
    as: {
        type: String,
        default: null, // 'button' | 'a' | 'router-link' — auto-detected when omitted
    },
    type: {
        type: String,
        default: 'button',
    },
    disabled: {
        type: Boolean,
        default: false,
    },
});

const tag = computed(() => {
    if (props.as) return props.as;
    if (props.to) return RouterLink;
    if (props.href) return 'a';
    return 'button';
});

const classes = computed(() => {
    const map = {
        primary: 'btn btn-primary',
        ghost: 'btn btn-ghost',
        danger: 'btn btn-danger',
        mini: 'mini',
        'mini-ok': 'mini mini-ok',
        'mini-bad': 'mini mini-bad',
    };
    return map[props.variant] ?? map.primary;
});
</script>

<template>
    <component
        :is="tag"
        :to="to ?? undefined"
        :href="!to ? (href ?? undefined) : undefined"
        :type="tag === 'button' ? type : undefined"
        :disabled="tag === 'button' ? disabled : undefined"
        :class="classes"
    >
        <i v-if="icon" :class="icon"></i>
        <slot />
    </component>
</template>
