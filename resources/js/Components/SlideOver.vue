<script setup>
import { Teleport, Transition } from 'vue';

defineProps({
    modelValue: {
        type: Boolean,
        default: false,
    },
    title: {
        type: String,
        default: '',
    },
    subtitle: {
        type: String,
        default: '',
    },
    width: {
        type: String,
        default: '560px',
    },
});

const emit = defineEmits(['update:modelValue', 'close']);

function close() {
    emit('update:modelValue', false);
    emit('close');
}
</script>

<template>
    <Teleport to="body">
        <Transition
            enter-active-class="transition-opacity duration-200"
            leave-active-class="transition-opacity duration-150"
            enter-from-class="opacity-0"
            leave-to-class="opacity-0"
        >
            <div v-if="modelValue" class="fixed inset-0 bg-ink/40 z-40" @click="close"></div>
        </Transition>

        <Transition
            enter-active-class="transition-transform duration-200 ease-out"
            leave-active-class="transition-transform duration-150 ease-in"
            enter-from-class="translate-x-full"
            leave-to-class="translate-x-full"
        >
            <div
                v-if="modelValue"
                class="fixed top-0 right-0 h-full bg-panel z-50 shadow-card-lg flex flex-col"
                :style="{ width, maxWidth: '92vw' }"
            >
                <div class="flex items-start justify-between px-6 py-5 border-b border-line-2">
                    <div>
                        <h3 class="text-[16px] font-semibold text-ink">{{ title }}</h3>
                        <p v-if="subtitle" class="text-ink-3 text-xs mt-1">{{ subtitle }}</p>
                    </div>
                    <button
                        class="w-9 h-9 rounded-[10px] border border-line grid place-items-center text-ink-2 hover:bg-page"
                        @click="close"
                    >
                        <i class="ti ti-x text-lg"></i>
                    </button>
                </div>
                <div class="flex-1 overflow-y-auto px-6 py-5">
                    <slot />
                </div>
                <div v-if="$slots.footer" class="px-6 py-4 border-t border-line-2 flex justify-end gap-[10px]">
                    <slot name="footer" />
                </div>
            </div>
        </Transition>
    </Teleport>
</template>
