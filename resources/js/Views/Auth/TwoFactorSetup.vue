<script setup>
import { ref, computed, onMounted } from 'vue';
import { useRouter, useRoute } from 'vue-router';
import GuestLayout from '../../Layouts/GuestLayout.vue';
import Btn from '../../Components/Btn.vue';
import { useAuth } from '../../composables/useAuth.js';

const router = useRouter();
const route = useRoute();
const { pendingSetup, confirmTwoFactorSetup } = useAuth();

// The QR svg + recovery codes were handed to us by login()'s
// 'two-factor-setup-required' response, held in memory (never in the URL).
// If we arrived without them (e.g. a page refresh cleared state), the setup
// can't proceed — send the user back to sign in again.
const setup = computed(() => pendingSetup.value);

const code = ref(['', '', '', '', '', '']);
const inputs = ref([]);
const errors = ref({});
const processing = ref(false);
const savedAck = ref(false);

const codeString = computed(() => code.value.join(''));

onMounted(() => {
    if (!setup.value) {
        router.replace({ name: 'login', query: { redirect: route.query.redirect } });
    }
});

function onDigit(i, e) {
    const val = e.target.value.replace(/[^0-9]/g, '').slice(-1);
    code.value[i] = val;
    if (val && i < 5) inputs.value[i + 1]?.focus();
}

function onBackspace(i, e) {
    if (e.key === 'Backspace' && !code.value[i] && i > 0) inputs.value[i - 1]?.focus();
}

async function submit() {
    processing.value = true;
    errors.value = {};
    try {
        const data = await confirmTwoFactorSetup({ code: codeString.value });
        if (data.status === 'authenticated') {
            router.push(route.query.redirect ?? { name: 'dashboard' });
        }
    } catch (e) {
        if (e?.response?.status === 422) {
            errors.value = e.response.data?.errors ?? {};
        } else if (e?.response?.status === 419) {
            router.replace({ name: 'login' });
        }
    } finally {
        processing.value = false;
    }
}
</script>

<template>
    <GuestLayout
        title="Set up two-factor authentication"
        subtitle="Two-factor is required. Scan the code with your authenticator app, then confirm."
    >
        <template v-if="setup">
            <!-- 1. Scan QR -->
            <div class="flex justify-center mb-4">
                <div class="p-3 bg-white border border-line rounded-[12px]" v-html="setup.qr"></div>
            </div>
            <p class="text-[12.5px] text-ink-2 text-center mb-1">
                Can't scan? Enter this key manually:
            </p>
            <p class="text-center font-mono text-[12.5px] text-ink-1 mb-5 select-all break-all">{{ setup.secret }}</p>

            <!-- 2. Recovery codes -->
            <div class="note mb-4" style="display:block;">
                <div class="flex items-center gap-2 mb-2 font-semibold text-ink-1">
                    <i class="ti ti-key"></i> Save your recovery codes
                </div>
                <p class="text-[12px] text-ink-2 mb-2">
                    Store these somewhere safe. Each can be used once if you lose your authenticator.
                </p>
                <div class="grid grid-cols-2 gap-1 font-mono text-[12px] text-ink-1 mb-3">
                    <span v-for="rc in setup.recovery_codes" :key="rc" class="select-all">{{ rc }}</span>
                </div>
                <label class="flex items-center gap-2 text-[12px] text-ink-2 font-medium select-none">
                    <input v-model="savedAck" type="checkbox" class="w-[15px] h-[15px] rounded accent-brand">
                    I have saved my recovery codes
                </label>
            </div>

            <!-- 3. Confirm a code -->
            <form class="space-y-4" @submit.prevent="submit">
                <label class="block text-xs font-semibold text-ink-2">Enter the 6-digit code from your app</label>
                <div class="flex justify-between gap-2">
                    <input
                        v-for="(digit, i) in code"
                        :key="i"
                        :ref="el => (inputs[i] = el)"
                        :value="digit"
                        type="text"
                        inputmode="numeric"
                        maxlength="1"
                        class="w-12 h-14 text-center text-[20px] font-semibold border border-line rounded-[10px] outline-none focus:border-brand focus:ring-[3px] focus:ring-brand-50"
                        @input="onDigit(i, $event)"
                        @keydown="onBackspace(i, $event)"
                    >
                </div>
                <p v-if="errors.code" class="text-bad text-xxs">{{ errors.code[0] }}</p>

                <Btn
                    type="submit"
                    variant="primary"
                    icon="ti ti-shield-check"
                    class="w-full justify-center"
                    :disabled="processing || codeString.length < 6 || !savedAck"
                >
                    Enable two-factor and continue
                </Btn>
            </form>
        </template>
    </GuestLayout>
</template>
