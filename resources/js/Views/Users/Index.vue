<script setup>
import { onMounted, reactive, ref } from 'vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import Card from '../../Components/Card.vue';
import Btn from '../../Components/Btn.vue';
import Modal from '../../Components/Modal.vue';
import Avatar from '../../Components/Avatar.vue';
import { usersApi } from '../../api.js';

const users = ref([]);
const roleOptions = ref([]);
const loading = ref(true);
const loadError = ref(null);

const modalOpen = ref(false);
const saving = ref(false);
const formErrors = ref({});
const formError = ref(null);

const blankForm = () => ({ name: '', email: '', password: '', roles: ['developer'] });
const form = reactive(blankForm());

async function load() {
    loading.value = true;
    loadError.value = null;
    try {
        const data = await usersApi.list();
        users.value = data.users ?? [];
        roleOptions.value = data.roles ?? [];
    } catch (e) {
        loadError.value = 'Could not load users.';
    } finally {
        loading.value = false;
    }
}

onMounted(load);

function openCreate() {
    Object.assign(form, blankForm());
    formErrors.value = {};
    formError.value = null;
    modalOpen.value = true;
}

function toggleRole(role) {
    const i = form.roles.indexOf(role);
    if (i === -1) form.roles.push(role);
    else form.roles.splice(i, 1);
}

// A quick temp password the admin can copy and hand over out-of-band.
function generatePassword() {
    const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
    let out = '';
    const rnd = new Uint32Array(16);
    crypto.getRandomValues(rnd);
    for (let i = 0; i < 16; i += 1) out += chars[rnd[i] % chars.length];
    form.password = out;
}

async function submit() {
    saving.value = true;
    formErrors.value = {};
    formError.value = null;
    try {
        await usersApi.create({
            name: form.name,
            email: form.email,
            password: form.password,
            roles: form.roles,
        });
        modalOpen.value = false;
        await load();
    } catch (e) {
        if (e?.response?.status === 422) {
            formErrors.value = e.response.data?.errors ?? {};
            formError.value = e.response.data?.message ?? null;
        } else {
            formError.value = 'Could not create the user. Please try again.';
        }
    } finally {
        saving.value = false;
    }
}

async function toggleActive(user) {
    try {
        const { user: updated } = await usersApi.update(user.id, { is_active: !user.is_active });
        Object.assign(user, updated);
    } catch (e) {
        // Server refuses self-deactivation with a 422 — surface nothing
        // destructive, just reload to stay consistent.
        await load();
    }
}

const roleBadgeClass = (role) =>
    ({
        admin: 'badge-warn',
        approver: 'badge-ok',
        auditor: 'badge-mute',
        developer: 'badge-mute',
    })[role] ?? 'badge-mute';
</script>

<template>
    <AppLayout :breadcrumb="['Admin', 'Users & roles']">
        <div class="page-head">
            <div>
                <h1>Users &amp; roles</h1>
                <p>Onboard people to the vault and manage their access</p>
            </div>
            <Btn variant="primary" icon="ti ti-user-plus" @click="openCreate">Add user</Btn>
        </div>

        <div v-if="loadError" class="note mb-4" style="background:#fef2f2;border-color:#fecaca;color:#dc2626;">
            <i class="ti ti-alert-triangle"></i>
            <div>{{ loadError }}</div>
        </div>

        <Card>
            <table class="tbl">
                <thead>
                    <tr>
                        <th class="text-left">User</th>
                        <th class="text-left">Roles</th>
                        <th class="text-left">2FA</th>
                        <th class="text-left">Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="loading">
                        <td colspan="5" class="text-ink-3 text-center py-6">Loading…</td>
                    </tr>
                    <tr v-else-if="users.length === 0">
                        <td colspan="5" class="text-ink-3 text-center py-6">No users yet.</td>
                    </tr>
                    <tr v-for="u in users" :key="u.id">
                        <td>
                            <div class="flex items-center gap-[10px]">
                                <Avatar :name="u.name" :size="30" />
                                <div>
                                    <b class="font-semibold text-[13px] block">{{ u.name }}</b>
                                    <span class="text-ink-3 text-[11.5px]">{{ u.email }}</span>
                                </div>
                            </div>
                        </td>
                        <td>
                            <span v-for="r in u.roles" :key="r" class="badge mr-1 capitalize" :class="roleBadgeClass(r)">{{ r }}</span>
                        </td>
                        <td>
                            <span v-if="u.two_factor_enabled" class="badge badge-ok">on</span>
                            <span v-else class="badge badge-mute">off</span>
                        </td>
                        <td>
                            <span class="badge" :class="u.is_active ? 'badge-ok' : 'badge-bad'">
                                {{ u.is_active ? 'active' : 'disabled' }}
                            </span>
                        </td>
                        <td class="text-right">
                            <button
                                type="button"
                                class="link-btn"
                                :class="u.is_active ? 'text-bad' : 'text-brand-600'"
                                @click="toggleActive(u)"
                            >
                                {{ u.is_active ? 'Deactivate' : 'Reactivate' }}
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </Card>

        <!-- Onboard a new user -->
        <Modal v-model="modalOpen" title="Add user" width="480px">
            <form id="add-user-form" @submit.prevent="submit">
                <div v-if="formError" class="note mb-3" style="background:#fef2f2;border-color:#fecaca;color:#dc2626;">
                    <i class="ti ti-alert-triangle"></i>
                    <div>{{ formError }}</div>
                </div>

                <div class="field mb-3">
                    <label for="u-name">Name</label>
                    <input id="u-name" v-model="form.name" type="text" placeholder="Arun Kumar">
                    <p v-if="formErrors.name" class="text-bad text-xxs mt-[6px]">{{ formErrors.name[0] }}</p>
                </div>

                <div class="field mb-3">
                    <label for="u-email">Email</label>
                    <input id="u-email" v-model="form.email" type="email" placeholder="arun@example.com">
                    <p v-if="formErrors.email" class="text-bad text-xxs mt-[6px]">{{ formErrors.email[0] }}</p>
                </div>

                <div class="field mb-3">
                    <label for="u-pass">Temporary password</label>
                    <div class="flex gap-2">
                        <input id="u-pass" v-model="form.password" type="text" class="flex-1" placeholder="Min 12 characters">
                        <button type="button" class="btn btn-ghost" @click="generatePassword">
                            <i class="ti ti-dice"></i>Generate
                        </button>
                    </div>
                    <p class="text-ink-3 text-xxs mt-[6px]">Share this with the user out-of-band; they can change it after signing in.</p>
                    <p v-if="formErrors.password" class="text-bad text-xxs mt-[6px]">{{ formErrors.password[0] }}</p>
                </div>

                <div class="field">
                    <label>Roles</label>
                    <div class="flex flex-wrap gap-2 mt-1">
                        <label
                            v-for="r in roleOptions"
                            :key="r"
                            class="flex items-center gap-2 text-[12.5px] text-ink capitalize cursor-pointer border border-line rounded-[8px] px-[10px] py-[6px]"
                        >
                            <input type="checkbox" :checked="form.roles.includes(r)" @change="toggleRole(r)">
                            {{ r }}
                        </label>
                    </div>
                    <p v-if="formErrors.roles" class="text-bad text-xxs mt-[6px]">{{ formErrors.roles[0] }}</p>
                </div>
            </form>

            <template #footer>
                <Btn variant="ghost" @click="modalOpen = false">Cancel</Btn>
                <Btn variant="primary" type="submit" form="add-user-form" icon="ti ti-check" :disabled="saving">
                    Create user
                </Btn>
            </template>
        </Modal>
    </AppLayout>
</template>
