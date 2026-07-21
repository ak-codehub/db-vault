<script setup>
import { ref, computed } from 'vue';
import { useRouter, useRoute } from 'vue-router';
import GuestLayout from '../../Layouts/GuestLayout.vue';
import Btn from '../../Components/Btn.vue';
import { useAuth } from '../../composables/useAuth.js';

const router = useRouter();
const route = useRoute();
const { verifyTwoFactor } = useAuth();

const email = computed(() => route.query.email ?? '');

const useRecoveryCode = ref(false);
const code = ref(['', '', '', '', '', '']);
const recoveryCode = ref('');
const inputs = ref([]);
const errors = ref({});
const processing = ref(false);

const codeString = computed(() => code.value.join(''));

function onDigit(i, e) {
    const val = e.target.value.replace(/[^0-9]/g, '').slice(-1);
    code.value[i] = val;
    if (val && i < 5) {
        inputs.value[i + 1]?.focus();
    }
}

function onBackspace(i, e) {
    if (e.key === 'Backspace' && !code.value[i] && i > 0) {
        inputs.value[i - 1]?.focus();
    }
}

async function submit() {
    processing.value = true;
    errors.value = {};
    try {
        const payload = useRecoveryCode.value
            ? { recovery_code: recoveryCode.value }
            : { code: codeString.value };
        const data = await verifyTwoFactor(payload);
        if (data.status === 'authenticated') {
            router.push(route.query.redirect ?? { name: 'dashboard' });
        }
    } catch (e) {
        if (e?.response?.status === 422) {
            errors.value = e.response.data?.errors ?? {};
        }
    } finally {
        processing.value = false;
    }
}
</script>

<template>
    <GuestLayout title="Verify it's you" :subtitle="`Enter the 6-digit code sent to ${email}`">
        <form v-if="!useRecoveryCode" class="space-y-5" @submit.prevent="submit">
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

            <Btn type="submit" variant="primary" icon="ti ti-shield-check" class="w-full justify-center" :disabled="processing || codeString.length < 6">
                Verify and continue
            </Btn>
        </form>

        <form v-else class="space-y-4" @submit.prevent="submit">
            <div class="field">
                <label for="recovery">Recovery code</label>
                <input id="recovery" v-model="recoveryCode" type="text" placeholder="xxxxx-xxxxx" autocomplete="one-time-code">
                <p v-if="errors.recovery_code" class="text-bad text-xxs mt-[6px]">{{ errors.recovery_code[0] }}</p>
            </div>
            <Btn type="submit" variant="primary" icon="ti ti-shield-check" class="w-full justify-center" :disabled="processing || !recoveryCode">
                Verify and continue
            </Btn>
        </form>

        <div class="text-center mt-5">
            <button
                type="button"
                class="link-btn"
                @click="useRecoveryCode = !useRecoveryCode"
            >
                <i class="ti ti-mail"></i>
                {{ useRecoveryCode ? 'Use authenticator app instead' : 'Use a recovery code instead' }}
            </button>
        </div>
    </GuestLayout>
</template>
