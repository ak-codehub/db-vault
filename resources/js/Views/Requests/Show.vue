<script setup>
import { ref, computed, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import AppLayout from '../../Layouts/AppLayout.vue';
import Card from '../../Components/Card.vue';
import Btn from '../../Components/Btn.vue';
import StatusBadge from '../../Components/StatusBadge.vue';
import PrivilegeMatrix from '../../Components/PrivilegeMatrix.vue';
import EmptyState from '../../Components/EmptyState.vue';
import { requestsApi, sessionsApi } from '../../api.js';

const route = useRoute();

const loading = ref(true);
const error = ref(null);
const request = ref(null);
const matrix = ref([]);
const approval = ref(null);
const session = ref(null);
const auditQueries = ref([]);
const busy = ref(false);

const isActive = computed(() => request.value?.status === 'active');
const isPending = computed(() => request.value?.status === 'pending');

async function load() {
    loading.value = true;
    error.value = null;
    try {
        const data = await requestsApi.show(route.params.id);
        request.value = data.request ?? null;
        matrix.value = data.matrix ?? [];
        approval.value = data.approval ?? null;
        session.value = data.session ?? null;
        auditQueries.value = data.auditQueries ?? [];
    } catch (e) {
        error.value = 'Could not load this request. Please try again.';
    } finally {
        loading.value = false;
    }
}

async function launchPma() {
    if (!session.value) return;
    busy.value = true;
    try {
        const { pma_url: pmaUrl } = await sessionsApi.launch(session.value.id);
        window.open(pmaUrl, '_blank', 'noopener');
    } finally {
        busy.value = false;
    }
}

async function revoke() {
    if (!session.value) return;
    busy.value = true;
    try {
        await sessionsApi.revoke(session.value.id);
        await load();
    } finally {
        busy.value = false;
    }
}

async function cancel() {
    busy.value = true;
    try {
        await requestsApi.cancel(request.value.id);
        await load();
    } finally {
        busy.value = false;
    }
}

onMounted(load);
</script>

<template>
    <AppLayout :breadcrumb="['Workspace', 'My requests', request ? `#${request.id}` : '']">
        <div v-if="loading" class="text-ink-3 text-[13px] py-10 text-center">Loading request…</div>
        <div v-else-if="error" class="note" style="background:#fef2f2;border-color:#fecaca;color:#dc2626;">
            <i class="ti ti-alert-triangle"></i>
            <div>{{ error }}</div>
        </div>
        <template v-else-if="request">
            <div class="page-head">
                <div>
                    <h1>Request #{{ request.id }}</h1>
                    <p>{{ request.database }} · {{ request.duration }} · requested by {{ request.developer }}</p>
                </div>
                <div class="flex gap-[10px]">
                    <Btn v-if="isActive" variant="primary" icon="ti ti-external-link" :disabled="busy" @click="launchPma">Launch phpMyAdmin</Btn>
                    <Btn v-if="isActive" variant="danger" icon="ti ti-plug-x" :disabled="busy" @click="revoke">Revoke</Btn>
                    <Btn v-if="isPending" variant="ghost" icon="ti ti-x" :disabled="busy" @click="cancel">Cancel request</Btn>
                </div>
            </div>

            <div class="grid gap-4" style="grid-template-columns: 1.6fr 1fr;">
                <div class="space-y-4">
                    <Card title="Privilege matrix">
                        <div class="p-[18px]">
                            <PrivilegeMatrix :model-value="matrix" readonly />
                        </div>
                    </Card>

                    <Card title="Audited queries">
                        <template #action>
                            <span class="badge badge-mute">bound to this session</span>
                        </template>
                        <table v-if="auditQueries.length" class="tbl">
                            <thead>
                                <tr><th>Time</th><th>Statement</th></tr>
                            </thead>
                            <tbody>
                                <tr v-for="(q, i) in auditQueries" :key="i">
                                    <td class="mono whitespace-nowrap">{{ q.at }}</td>
                                    <td class="mono">{{ q.statement }}</td>
                                </tr>
                            </tbody>
                        </table>
                        <EmptyState v-else icon="ti ti-database-search" title="No queries audited yet" />
                    </Card>
                </div>

                <div class="space-y-4">
                    <Card title="Status">
                        <div class="p-[18px] space-y-3">
                            <div class="flex items-center justify-between text-[13px]">
                                <span class="text-ink-2">Current status</span>
                                <StatusBadge :status="request.status" />
                            </div>
                            <div v-if="isActive" class="flex items-center justify-between text-[13px]">
                                <span class="text-ink-2">Expires in</span>
                                <span class="mono">{{ request.expiresIn }}</span>
                            </div>
                            <div v-if="session" class="flex items-center justify-between text-[13px]">
                                <span class="text-ink-2">MySQL user</span>
                                <span class="mono">{{ session.username }}</span>
                            </div>
                            <div class="text-[12.5px] text-ink-2 pt-2 border-t border-line-2">{{ request.reason }}</div>
                        </div>
                    </Card>

                    <Card v-if="approval" title="Approval">
                        <div class="p-[18px] space-y-3">
                            <div class="flex items-center justify-between text-[13px]">
                                <span class="text-ink-2">Decision</span>
                                <StatusBadge :status="approval.status" />
                            </div>
                            <div v-if="approval.note" class="text-[12.5px] text-ink-2 pt-2 border-t border-line-2">{{ approval.note }}</div>
                        </div>
                    </Card>
                </div>
            </div>
        </template>
    </AppLayout>
</template>
