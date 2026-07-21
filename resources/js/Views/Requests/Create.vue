<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import AppLayout from '../../Layouts/AppLayout.vue';
import Card from '../../Components/Card.vue';
import Btn from '../../Components/Btn.vue';
import PrivilegeMatrix from '../../Components/PrivilegeMatrix.vue';
import { requestsApi } from '../../api.js';

// The candidate table list is fetched from GET requests/tables (backed by
// SchemaIntrospectionService). Databases/durations are still local defaults.
// The privilege matrix rows are user-managed — pick tables from the fetched
// list (or add one by name), then toggle each table's grantable privileges.
const PRIVILEGE_KEYS = ['select', 'insert', 'update', 'delete', 'alter', 'index'];

function blankRow(table) {
    return { table, select: true, insert: false, update: false, delete: false, alter: false, index: false };
}

// Option lists come from GET requests/form-options (config-driven), so the
// target databases reflect DBVAULT_TARGET_DATABASE + DBVAULT_ALLOWED_DATABASES
// rather than a hardcoded list.
const databases = ref([]);
const durations = ref([]);

const router = useRouter();

const form = reactive({
    database: '',
    duration: 60,
    reason: '',
});

async function loadFormOptions() {
    try {
        const opts = await requestsApi.formOptions();
        databases.value = opts.databases ?? [];
        durations.value = opts.durations ?? [];
        if (!form.database && databases.value.length) form.database = databases.value[0];
        if (durations.value.length && !durations.value.some((d) => d.value === form.duration)) {
            form.duration = durations.value[0].value;
        }
    } catch (e) {
        // Leave the selects empty; submit validation will still guard.
    }
}

// The matrix IS the table list: one row per table discovered in the target
// database. A row with no privileges ticked is simply not requested (see
// buildGrants). This replaces the earlier separate "pick tables" checklist.
const matrix = ref([]);

const newTableName = ref('');
const errors = ref({});
const processing = ref(false);
const submitError = ref(null);

const tablesLoading = ref(false);
const tablesError = ref(null);
const filter = ref('');

// Rows shown after applying the text filter — the matrix can be long (a real
// schema may have dozens of tables), so let the operator narrow it.
const visibleMatrix = computed(() => {
    const q = filter.value.trim().toLowerCase();
    if (!q) return matrix.value;
    return matrix.value.filter((r) => r.table.toLowerCase().includes(q));
});

const selectedTables = computed(() => new Set(matrix.value.map((r) => r.table)));

async function loadTables() {
    tablesLoading.value = true;
    tablesError.value = null;
    try {
        const { tables } = await requestsApi.tables(form.database);
        // Rebuild the matrix from the discovered tables, one row each.
        matrix.value = (tables ?? []).map((t) => blankRow(t));
    } catch (e) {
        tablesError.value = 'Could not load tables for this database.';
        matrix.value = [];
    } finally {
        tablesLoading.value = false;
    }
}

// Load option lists first; setting form.database triggers the watcher below
// which fetches that database's tables. If options don't change the default
// database, load tables explicitly.
onMounted(async () => {
    const before = form.database;
    await loadFormOptions();
    if (form.database === before) await loadTables();
});
watch(() => form.database, loadTables);

// Manually add a table not present in the introspected list (e.g. a table
// the introspection user cannot see). Prepended so it is easy to spot.
function addTable() {
    const name = newTableName.value.trim();
    if (!name || selectedTables.value.has(name)) return;
    matrix.value.unshift(blankRow(name));
    newTableName.value = '';
}

function removeTable(table) {
    matrix.value = matrix.value.filter((r) => r.table !== table);
}

function buildGrants() {
    return matrix.value
        .filter((row) => row.table)
        .map((row) => ({
            table: row.table,
            privileges: PRIVILEGE_KEYS.filter((key) => row[key]),
        }))
        .filter((grant) => grant.privileges.length > 0);
}

async function submit() {
    processing.value = true;
    errors.value = {};
    submitError.value = null;
    try {
        const { request } = await requestsApi.create({
            target_database: form.database,
            duration_minutes: form.duration,
            reason: form.reason,
            grants: buildGrants(),
        });
        router.push({ name: 'requests.show', params: { id: request.id } });
    } catch (e) {
        if (e?.response?.status === 422) {
            errors.value = e.response.data?.errors ?? {};
        } else {
            submitError.value = 'Could not submit the request. Please try again.';
        }
    } finally {
        processing.value = false;
    }
}
</script>

<template>
    <AppLayout :breadcrumb="['Workspace', 'Request access']">
        <div class="page-head">
            <div>
                <h1>Request access</h1>
                <p>Scope exactly what you need — an approver reviews before it's granted</p>
            </div>
        </div>

        <div class="tabs mb-[22px]">
            <router-link :to="{ name: 'dashboard' }">Dashboard</router-link>
            <router-link :to="{ name: 'requests.create' }" class="active">Request access</router-link>
        </div>

        <div v-if="submitError" class="note mb-4" style="background:#fef2f2;border-color:#fecaca;color:#dc2626;">
            <i class="ti ti-alert-triangle"></i>
            <div>{{ submitError }}</div>
        </div>

        <Card class="max-w-[1100px]">
            <template #header>
                <h3>New access request</h3>
            </template>

            <form class="p-[18px]" @submit.prevent="submit">
                <div class="grid grid-cols-2 gap-4 mb-[18px]">
                    <div class="field">
                        <label for="database">Target database</label>
                        <select id="database" v-model="form.database">
                            <option v-for="db in databases" :key="db" :value="db">{{ db }}</option>
                        </select>
                        <p v-if="errors.target_database" class="text-bad text-xxs mt-[6px]">{{ errors.target_database[0] }}</p>
                    </div>
                    <div class="field">
                        <label for="duration">Duration</label>
                        <select id="duration" v-model.number="form.duration">
                            <option v-for="d in durations" :key="d.value" :value="d.value">{{ d.label }}</option>
                        </select>
                        <p v-if="errors.duration_minutes" class="text-bad text-xxs mt-[6px]">{{ errors.duration_minutes[0] }}</p>
                    </div>
                </div>

                <div class="field mb-[18px]">
                    <label for="reason">Reason (recorded in audit)</label>
                    <textarea
                        id="reason"
                        v-model="form.reason"
                        rows="2"
                        placeholder="Hotfix for duplicated line items in checkout"
                    ></textarea>
                    <p v-if="errors.reason" class="text-bad text-xxs mt-[6px]">{{ errors.reason[0] }}</p>
                </div>

                <div class="flex items-center justify-between mb-[10px]">
                    <label class="block text-xs font-semibold text-ink-2">Privilege matrix</label>
                    <span class="text-ink-3 text-xxs">
                        <template v-if="tablesLoading">Loading tables…</template>
                        <template v-else>{{ matrix.length }} tables in {{ form.database }}</template>
                    </span>
                </div>

                <div v-if="tablesError" class="note mb-3" style="background:#fef2f2;border-color:#fecaca;color:#dc2626;">
                    <i class="ti ti-alert-triangle"></i>
                    <div>{{ tablesError }}</div>
                </div>

                <!-- Filter + manually add a table not in the discovered list. -->
                <div class="flex gap-2 mb-3">
                    <div class="flex-1 flex items-center gap-2 border border-line rounded-[9px] px-3 py-[9px] bg-panel">
                        <i class="ti ti-search text-ink-3 text-[15px]"></i>
                        <input
                            v-model="filter"
                            type="text"
                            placeholder="Filter tables…"
                            class="border-0 bg-transparent outline-none flex-1 font-sans text-[13px] text-ink placeholder:text-ink-3"
                        >
                    </div>
                    <input
                        v-model="newTableName"
                        type="text"
                        placeholder="Add a table by name"
                        class="border border-line rounded-[9px] px-3 py-[9px] font-sans text-[13px] text-ink bg-panel outline-none w-[200px]"
                        @keyup.enter.prevent="addTable"
                    >
                    <button type="button" class="btn btn-ghost" @click="addTable">
                        <i class="ti ti-plus"></i>Add
                    </button>
                </div>

                <!-- The matrix IS the table list: rows = discovered tables.
                     overflow-auto handles BOTH axes: the header stays put
                     vertically while long schemas scroll, and the 8 columns
                     scroll horizontally on narrow viewports. -->
                <div class="max-h-[420px] overflow-auto border border-line rounded-[9px]">
                    <PrivilegeMatrix
                        v-model="matrix"
                        :filter="filter"
                        removable
                        :scroll="false"
                        :show-note="false"
                        @remove="removeTable"
                    />
                </div>

                <p v-if="errors.grants" class="text-bad text-xxs mt-[6px]">{{ errors.grants[0] }}</p>

                <div class="note mt-3">
                    <i class="ti ti-shield-check"></i>
                    <div>
                        <b class="text-brand-600">Drop, Truncate and Trigger are never available.</b>
                        <span class="text-brand-600/90"> The vault cannot grant them — table drops and truncation are structurally impossible under any matrix.</span>
                    </div>
                </div>

                <div class="flex gap-[10px] justify-end mt-5">
                    <Btn variant="primary" type="submit" icon="ti ti-send" :disabled="processing">Submit for approval</Btn>
                </div>
            </form>
        </Card>
    </AppLayout>
</template>
