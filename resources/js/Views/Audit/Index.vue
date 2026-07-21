<script setup>
import { ref, computed, onMounted } from 'vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import Card from '../../Components/Card.vue';
import Avatar from '../../Components/Avatar.vue';
import EmptyState from '../../Components/EmptyState.vue';
import { auditApi } from '../../api.js';

// NOTE (assumption): GET api/audit takes no documented query params, so
// developer/text filtering is applied client-side over the full result set
// rather than as server-side filters.
const loading = ref(true);
const error = ref(null);
const queries = ref([]);

const developer = ref('All developers');
const q = ref('');

const developers = computed(() => {
    const names = new Set(['All developers']);
    queries.value.forEach((item) => names.add(item.developer));
    return Array.from(names);
});

const filtered = computed(() => queries.value.filter((item) => {
    const matchesDeveloper = developer.value === 'All developers' || item.developer === developer.value;
    const matchesText = !q.value || item.statement.toLowerCase().includes(q.value.toLowerCase());
    return matchesDeveloper && matchesText;
}));

async function load() {
    loading.value = true;
    error.value = null;
    try {
        const data = await auditApi.list();
        queries.value = data.queries ?? [];
    } catch (e) {
        error.value = 'Could not load the query log. Please try again.';
    } finally {
        loading.value = false;
    }
}

onMounted(load);
</script>

<template>
    <AppLayout :breadcrumb="['Audit', 'Query log']">
        <div class="page-head">
            <div>
                <h1>Query log</h1>
                <p>Every statement executed under a vault-issued session, ingested from the RDS audit plugin</p>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-3 mb-5">
            <div class="field w-[200px]">
                <select v-model="developer">
                    <option v-for="d in developers" :key="d" :value="d">{{ d }}</option>
                </select>
            </div>
            <div class="field flex-1 min-w-[240px] max-w-[360px]">
                <input v-model="q" type="text" placeholder="Search statements…">
            </div>
        </div>

        <div v-if="loading" class="text-ink-3 text-[13px] py-10 text-center">Loading query log…</div>
        <div v-else-if="error" class="note" style="background:#fef2f2;border-color:#fecaca;color:#dc2626;">
            <i class="ti ti-alert-triangle"></i>
            <div>{{ error }}</div>
        </div>
        <Card v-else>
            <table v-if="filtered.length" class="tbl">
                <thead>
                    <tr>
                        <th>Time</th>
                        <th>Developer</th>
                        <th>Statement</th>
                        <th>Rows</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="item in filtered" :key="item.id">
                        <td class="mono whitespace-nowrap">{{ item.at }}</td>
                        <td>
                            <div class="u">
                                <Avatar :name="item.developer" :size="26" />
                                <div>
                                    <b>{{ item.developer }}</b>
                                    <span>{{ item.username }}</span>
                                </div>
                            </div>
                        </td>
                        <td class="mono max-w-[520px] truncate">{{ item.statement }}</td>
                        <td class="mono">{{ item.rows }}</td>
                    </tr>
                </tbody>
            </table>
            <EmptyState v-else icon="ti ti-history" title="No queries recorded" message="Try widening your filters." />
        </Card>
    </AppLayout>
</template>
