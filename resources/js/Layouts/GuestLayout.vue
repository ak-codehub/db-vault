<script setup>
defineProps({
    title: {
        type: String,
        default: '',
    },
    subtitle: {
        type: String,
        default: '',
    },
});
</script>

<template>
    <div class="min-h-screen bg-page flex items-center justify-center p-6">
        <div class="w-full max-w-[400px]">
            <div class="flex flex-col items-center gap-3 mb-8">
                <div class="w-12 h-12 rounded-[12px] bg-gradient-to-br from-brand to-violet-500 grid place-items-center text-white text-2xl shadow-card-lg">
                    <i class="ti ti-shield-lock"></i>
                </div>
                <div class="text-center">
                    <b class="block text-ink font-semibold text-[17px] tracking-[-.01em]">DB Vault</b>
                    <span class="block text-ink-3 text-xs font-medium mt-[2px]">Scoped, audited access to production data</span>
                </div>
            </div>

            <div class="card p-7">
                <div v-if="title" class="mb-6">
                    <h1 class="text-[18px] font-semibold text-ink tracking-[-.01em]">{{ title }}</h1>
                    <p v-if="subtitle" class="text-ink-2 text-[13px] mt-[6px]">{{ subtitle }}</p>
                </div>
                <slot />
            </div>

            <p class="text-center text-ink-3 text-xxs mt-6">
                Every session is scoped, time-boxed and fully audited. No credentials are ever displayed.
            </p>
        </div>
    </div>
</template>
