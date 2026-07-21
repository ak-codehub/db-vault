<script setup>
import { ref, onMounted } from 'vue';
import AppLayout from '../Layouts/AppLayout.vue';
import StatCard from '../Components/StatCard.vue';
import Card from '../Components/Card.vue';
import Btn from '../Components/Btn.vue';
import Avatar from '../Components/Avatar.vue';
import StatusBadge from '../Components/StatusBadge.vue';
import EmptyState from '../Components/EmptyState.vue';
import { dashboardApi, approvalsApi } from '../api.js';
import { useAuth } from '../composables/useAuth.js';

const { server } = useAuth();

const loading = ref(true);
const error = ref(null);

const stats = ref({
    activeSessions: 0,
    pendingApprovals: 0,
    grantedToday: 0,
    queriesAudited: 0,
});
const activeSessions = ref([]);
const pendingApprovals = ref([]);

function scopeBadgeClass(tone) {
    if (tone === 'warn') return 'badge-warn';
    if (tone === 'ok') return 'badge-ok';
    return 'badge-mute';
}

async function load() {
    loading.value = true;
    error.value = null;
    try {
        const data = await dashboardApi.get();
        stats.value = data.stats ?? stats.value;
        activeSessions.value = data.activeSessions ?? [];
        pendingApprovals.value = data.pendingApprovals ?? [];
    } catch (e) {
        error.value = 'Could not load the dashboard. Please try again.';
    } finally {
        loading.value = false;
    }
}

async function approve(id) {
    await approvalsApi.approve(id);
    pendingApprovals.value = pendingApprovals.value.filter((a) => a.id !== id);
    stats.value.pendingApprovals = Math.max(0, (stats.value.pendingApprovals ?? 1) - 1);
}

async function reject(id) {
    await approvalsApi.reject(id);
    pendingApprovals.value = pendingApprovals.value.filter((a) => a.id !== id);
    stats.value.pendingApprovals = Math.max(0, (stats.value.pendingApprovals ?? 1) - 1);
}

onMounted(load);
</script>

<template>
    <AppLayout :breadcrumb="['Workspace', 'Dashboard']">
        <div class="page-head">
            <div>
                <h1>Dashboard</h1>
                <p>Access activity for {{ server.label }}</p>
            </div>
            <Btn variant="primary" icon="ti ti-plus" :to="{ name: 'requests.create' }">New request</Btn>
        </div>

        <div v-if="loading" class="text-ink-3 text-[13px] py-10 text-center">Loading dashboard…</div>
        <div v-else-if="error" class="note" style="background:#fef2f2;border-color:#fecaca;color:#dc2626;">
            <i class="ti ti-alert-triangle"></i>
            <div>{{ error }}</div>
        </div>
        <template v-else>
            <div class="grid grid-cols-4 gap-4 mb-[22px]">
                <StatCard
                    label="Active sessions"
                    :value="stats.activeSessions"
                    icon="ti ti-plug-connected"
                    tone="info"
                />
                <StatCard
                    label="Pending approvals"
                    :value="stats.pendingApprovals"
                    icon="ti ti-clock-hour-4"
                    tone="warn"
                />
                <StatCard
                    label="Granted today"
                    :value="stats.grantedToday"
                    icon="ti ti-checks"
                    tone="ok"
                />
                <StatCard
                    label="Queries audited"
                    :value="stats.queriesAudited"
                    icon="ti ti-database-search"
                    tone="brand"
                    trend="last 24h"
                />
            </div>

            <div class="grid gap-4" style="grid-template-columns: 1.6fr 1fr;">
                <Card title="Active sessions">
                    <template #action>
                        <router-link :to="{ name: 'sessions.index' }" class="card-h-link">View all</router-link>
                    </template>
                    <table v-if="activeSessions.length" class="tbl">
                        <thead>
                            <tr>
                                <th>Developer</th>
                                <th>Scope</th>
                                <th>Expires</th>
                                <th>Status</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="s in activeSessions" :key="s.id">
                                <td>
                                    <div class="u">
                                        <Avatar :name="s.developer" :size="30" />
                                        <div>
                                            <b>{{ s.developer }}</b>
                                            <span>{{ s.username }}</span>
                                        </div>
                                    </div>
                                </td>
                                <td><span class="badge" :class="scopeBadgeClass(s.scopeTone)">{{ s.scope }}</span></td>
                                <td class="mono">{{ s.expiresIn }}</td>
                                <td><StatusBadge :status="s.status" /></td>
                                <td>
                                    <a v-if="s.pmaUrl" class="link-btn" :href="s.pmaUrl" target="_blank" rel="noopener">
                                        <i class="ti ti-external-link"></i>phpMyAdmin
                                    </a>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <EmptyState v-else icon="ti ti-plug-connected" title="No active sessions" />
                </Card>

                <Card title="Awaiting your approval">
                    <template #action>
                        <router-link :to="{ name: 'approvals.index' }" class="card-h-link">{{ pendingApprovals.length }} total</router-link>
                    </template>
                    <div v-if="!pendingApprovals.length" class="p-8 text-center text-ink-3 text-[13px]">
                        Nothing waiting on you right now.
                    </div>
                    <div v-for="item in pendingApprovals" :key="item.id" class="appr">
                        <div class="top">
                            <Avatar :name="item.developer" :size="26" />
                            <b>{{ item.developer }}</b>
                            <span>{{ item.requestedAgo }}</span>
                        </div>
                        <div class="rq" v-html="item.summary"></div>
                        <div class="acts">
                            <Btn variant="mini-ok" icon="ti ti-check" @click="approve(item.id)">Approve</Btn>
                            <Btn variant="mini" :to="{ name: 'approvals.index' }">Review matrix</Btn>
                            <Btn variant="mini" class="mini-bad" @click="reject(item.id)">Reject</Btn>
                        </div>
                    </div>
                </Card>
            </div>
        </template>
    </AppLayout>
</template>
