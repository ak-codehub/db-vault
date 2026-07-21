<script setup>
import { reactive, ref } from 'vue';
import { useRouter, useRoute } from 'vue-router';
import GuestLayout from '../../Layouts/GuestLayout.vue';
import Btn from '../../Components/Btn.vue';
import { useAuth } from '../../composables/useAuth.js';

const router = useRouter();
const route = useRoute();
const { login } = useAuth();

const form = reactive({
    email: '',
    password: '',
    remember: false,
});

const errors = ref({});
const processing = ref(false);
const status = ref(null);

async function submit() {
    processing.value = true;
    errors.value = {};
    try {
        const data = await login({ email: form.email, password: form.password, remember: form.remember });
        if (data.status === 'two-factor-required') {
            router.push({ name: 'two-factor', query: { email: form.email, redirect: route.query.redirect } });
        } else if (data.status === 'two-factor-setup-required') {
            router.push({ name: 'two-factor-setup', query: { email: form.email, redirect: route.query.redirect } });
        } else if (data.status === 'authenticated') {
            router.push(route.query.redirect ?? { name: 'dashboard' });
        }
    } catch (e) {
        if (e?.response?.status === 422) {
            errors.value = e.response.data?.errors ?? {};
            status.value = e.response.data?.message ?? null;
        } else {
            status.value = 'Unable to sign in right now. Please try again.';
        }
    } finally {
        form.password = '';
        processing.value = false;
    }
}
</script>

<template>
    <GuestLayout title="Sign in" subtitle="Use your corporate SSO email to continue">
        <div v-if="status" class="note mb-5">
            <i class="ti ti-info-circle"></i>
            <div>{{ status }}</div>
        </div>

        <form class="space-y-4" @submit.prevent="submit">
            <div class="field">
                <label for="email">Work email</label>
                <input
                    id="email"
                    v-model="form.email"
                    type="email"
                    autocomplete="username"
                    placeholder="you@stellaripl.com"
                    required
                >
                <p v-if="errors.email" class="text-bad text-xxs mt-[6px]">{{ errors.email[0] }}</p>
            </div>

            <div class="field">
                <label for="password">Password</label>
                <input
                    id="password"
                    v-model="form.password"
                    type="password"
                    autocomplete="current-password"
                    placeholder="••••••••••"
                    required
                >
                <p v-if="errors.password" class="text-bad text-xxs mt-[6px]">{{ errors.password[0] }}</p>
            </div>

            <label class="flex items-center gap-2 text-[12.5px] text-ink-2 font-medium select-none">
                <input v-model="form.remember" type="checkbox" class="w-[15px] h-[15px] rounded accent-brand">
                Keep me signed in
            </label>

            <Btn type="submit" variant="primary" icon="ti ti-login" class="w-full justify-center" :disabled="processing">
                Sign in
            </Btn>
        </form>

        <div class="note mt-5">
            <i class="ti ti-shield-check"></i>
            <div>Access is granted per-request, time-boxed and fully audited. Credentials for the target database are never shown to you.</div>
        </div>
    </GuestLayout>
</template>
