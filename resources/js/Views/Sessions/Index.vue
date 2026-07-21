<script setup>
import { ref, onMounted } from 'vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import Card from '../../Components/Card.vue';
import Avatar from '../../Components/Avatar.vue';
import StatusBadge from '../../Components/StatusBadge.vue';
import Modal from '../../Components/Modal.vue';
import EmptyState from '../../Components/EmptyState.vue';
import { sessionsApi } from '../../api.js';

const loading = ref(true);
const error = ref(null);
const sessions = ref([]);
const busy = ref(false);

function scopeBadgeClass(tone) {
    if (tone === 'warn') return 'badge-warn';
    if (tone === 'ok') return 'badge-ok';
    return 'badge-mute';
}

async function load() {
    loading.value = true;
    error.value = null;
    try {
        const data = await sessionsApi.list();
        sessions.value = data.sessions ?? [];
    } catch (e) {
        error.value = 'Could not load active sessions. Please try again.';
    } finally {
        loading.value = false;
    }
}

async function launch(session) {
    busy.value = true;
    try {
        const { pma_url: pmaUrl } = await sessionsApi.launch(session.id);
        window.open(pmaUrl, '_blank', 'noopener');
    } finally {
        busy.value = false;
    }
}

const revokeTarget = ref(null);
const showRevoke = ref(false);

function askRevoke(session) {
    revokeTarget.value = session;
    showRevoke.value = true;
}

async function confirmRevoke() {
    if (revokeTarget.value) {
        busy.value = true;
        try {
            await sessionsApi.revoke(revokeTarget.value.id);
            sessions.value = sessions.value.filter((s) => s.id !== revokeTarget.value.id);
        } finally {
            busy.value = false;
        }
    }
    showRevoke.value = false;
}

onMounted(load);
</script>

<template>
    <AppLayout :breadcrumb="['Workspace', 'Active sessions']">
        <div class="page-head">
            <div>
                <h1>Active sessions</h1>
                <p>Live, provisioned database access across the team</p>
            </div>
            <span class="badge badge-ok">{{ sessions.length }} active</span>
        </div>

        <div v-if="loading" class="text-ink-3 text-[13px] py-10 text-center">Loading sessions…</div>
        <div v-else-if="error" class="note" style="background:#fef2f2;border-color:#fecaca;color:#dc2626;">
            <i class="ti ti-alert-triangle"></i>
            <div>{{ error }}</div>
        </div>
        <Card v-else>
            <table v-if="sessions.length" class="tbl">
                <thead>
                    <tr>
                        <th>Developer</th>
                        <th>MySQL user</th>
                        <th>Scope</th>
                        <th>Expires</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="s in sessions" :key="s.id">
                        <td>
                            <div class="u">
                                <Avatar :name="s.developer" :size="30" />
                                <b>{{ s.developer }}</b>
                            </div>
                        </td>
                        <td class="mono">{{ s.username }}</td>
                        <td><span class="badge" :class="scopeBadgeClass(s.scopeTone)">{{ s.scope }}</span></td>
                        <td class="mono">{{ s.expiresIn }}</td>
                        <td><StatusBadge :status="s.status" /></td>
                        <td>
                            <div class="flex gap-2 justify-end">
                                <button class="link-btn" :disabled="busy" @click="launch(s)">
                                    <i class="ti ti-external-link"></i>Open phpMyAdmin
                                </button>
                                <button class="link-btn text-bad hover:text-bad" :disabled="busy" @click="askRevoke(s)">
                                    <i class="ti ti-plug-x"></i>Revoke
                                </button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
            <EmptyState v-else icon="ti ti-plug-connected" title="No active sessions" message="Approved requests will appear here while provisioned." />
        </Card>

        <Modal v-model="showRevoke" title="Revoke session">
            <p class="text-[13px] text-ink-2">
                Revoke <b>{{ revokeTarget?.developer }}</b>'s session (<span class="mono">{{ revokeTarget?.username }}</span>)? Their MySQL user is dropped immediately.
            </p>
            <template #footer>
                <button class="btn btn-ghost" @click="showRevoke = false">Cancel</button>
                <button class="btn btn-danger" :disabled="busy" @click="confirmRevoke"><i class="ti ti-plug-x"></i>Revoke now</button>
            </template>
        </Modal>
    </AppLayout>
</template>
