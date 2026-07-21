<script setup>
import { ref, onMounted } from 'vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import Card from '../../Components/Card.vue';
import Btn from '../../Components/Btn.vue';
import StatusBadge from '../../Components/StatusBadge.vue';
import EmptyState from '../../Components/EmptyState.vue';
import { requestsApi } from '../../api.js';

const loading = ref(true);
const error = ref(null);
const requests = ref([]);

async function load() {
    loading.value = true;
    error.value = null;
    try {
        const data = await requestsApi.list();
        requests.value = data.requests ?? [];
    } catch (e) {
        error.value = 'Could not load your requests. Please try again.';
    } finally {
        loading.value = false;
    }
}

onMounted(load);
</script>

<template>
    <AppLayout :breadcrumb="['Workspace', 'My requests']">
        <div class="page-head">
            <div>
                <h1>My requests</h1>
                <p>Everything you've asked the vault for, and where it stands</p>
            </div>
            <Btn variant="primary" icon="ti ti-plus" :to="{ name: 'requests.create' }">New request</Btn>
        </div>

        <div v-if="loading" class="text-ink-3 text-[13px] py-10 text-center">Loading requests…</div>
        <div v-else-if="error" class="note" style="background:#fef2f2;border-color:#fecaca;color:#dc2626;">
            <i class="ti ti-alert-triangle"></i>
            <div>{{ error }}</div>
        </div>
        <Card v-else>
            <table v-if="requests.length" class="tbl">
                <thead>
                    <tr>
                        <th>Request</th>
                        <th>Scope</th>
                        <th>Duration</th>
                        <th>Status</th>
                        <th>Submitted</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="r in requests" :key="r.id">
                        <td>
                            <b class="block font-semibold text-[13px] text-ink">#{{ r.id }} · {{ r.database }}</b>
                            <span class="block text-ink-3 text-[11.5px] mt-[2px] max-w-xs truncate">{{ r.reason }}</span>
                        </td>
                        <td><span class="badge badge-mute">{{ r.scope }}</span></td>
                        <td class="mono">{{ r.duration }}</td>
                        <td><StatusBadge :status="r.status" /></td>
                        <td class="text-ink-3 text-xs">{{ r.createdAt }}</td>
                        <td>
                            <router-link :to="{ name: 'requests.show', params: { id: r.id } }" class="link-btn">
                                <i class="ti ti-arrow-right"></i>Details
                            </router-link>
                        </td>
                    </tr>
                </tbody>
            </table>
            <EmptyState
                v-else
                icon="ti ti-key"
                title="No requests yet"
                message="Request scoped, time-boxed access to the production database when you need it."
            >
                <template #action>
                    <Btn variant="primary" icon="ti ti-plus" :to="{ name: 'requests.create' }">New request</Btn>
                </template>
            </EmptyState>
        </Card>
    </AppLayout>
</template>
