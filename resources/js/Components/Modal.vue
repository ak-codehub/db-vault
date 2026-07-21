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
    width: {
        type: String,
        default: '440px',
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
            enter-active-class="transition-opacity duration-150"
            leave-active-class="transition-opacity duration-100"
            enter-from-class="opacity-0"
            leave-to-class="opacity-0"
        >
            <div v-if="modelValue" class="fixed inset-0 bg-ink/40 z-40 flex items-center justify-center p-4" @click.self="close">
                <div class="bg-panel rounded-xl shadow-card-lg w-full" :style="{ maxWidth: width }">
                    <div class="flex items-center justify-between px-5 py-4 border-b border-line-2">
                        <h3 class="text-[15px] font-semibold text-ink">{{ title }}</h3>
                        <button class="w-8 h-8 rounded-[9px] border border-line grid place-items-center text-ink-2 hover:bg-page" @click="close">
                            <i class="ti ti-x text-base"></i>
                        </button>
                    </div>
                    <div class="px-5 py-5">
                        <slot />
                    </div>
                    <div v-if="$slots.footer" class="px-5 py-4 border-t border-line-2 flex justify-end gap-[10px]">
                        <slot name="footer" />
                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>
