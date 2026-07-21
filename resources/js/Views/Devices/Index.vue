<script setup>
import { onMounted, reactive, ref } from 'vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import Card from '../../Components/Card.vue';
import Btn from '../../Components/Btn.vue';
import Modal from '../../Components/Modal.vue';
import Avatar from '../../Components/Avatar.vue';
import { devicesApi, usersApi } from '../../api.js';

const devices = ref([]);
const users = ref([]);
const loading = ref(true);
const loadError = ref(null);
const canIssue = ref(false);

const modalOpen = ref(false);
const saving = ref(false);
const formErrors = ref({});
const formError = ref(null);

const blankForm = () => ({ user_id: '', label: '', cert_dn: '', cert_fingerprint: '' });
const form = reactive(blankForm());

// Issue-certificate flow state.
const issueOpen = ref(false);
const issuing = ref(false);
const issueForm = reactive({ user_id: '', label: '' });
const issueError = ref(null);
const issued = ref(null); // {device, pkcs12_base64, password, filename}

async function load() {
    loading.value = true;
    loadError.value = null;
    try {
        const [d, u] = await Promise.all([devicesApi.list(), usersApi.list()]);
        devices.value = d.devices ?? [];
        users.value = u.users ?? [];
        canIssue.value = !!d.can_issue;
    } catch (e) {
        loadError.value = 'Could not load devices.';
    } finally {
        loading.value = false;
    }
}

function openIssue() {
    issueForm.user_id = '';
    issueForm.label = '';
    issueError.value = null;
    issued.value = null;
    issueOpen.value = true;
}

async function submitIssue() {
    issuing.value = true;
    issueError.value = null;
    try {
        issued.value = await devicesApi.issue({
            user_id: issueForm.user_id,
            label: issueForm.label || null,
        });
        await load();
    } catch (e) {
        issueError.value = e?.response?.data?.message ?? 'Could not issue the certificate.';
    } finally {
        issuing.value = false;
    }
}

// Trigger a browser download of the issued .p12 from its base64 payload.
function downloadP12() {
    if (!issued.value) return;
    const bytes = Uint8Array.from(atob(issued.value.pkcs12_base64), (c) => c.charCodeAt(0));
    const blob = new Blob([bytes], { type: 'application/x-pkcs12' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = issued.value.filename;
    a.click();
    URL.revokeObjectURL(url);
}

onMounted(load);

function openEnroll() {
    Object.assign(form, blankForm());
    formErrors.value = {};
    formError.value = null;
    modalOpen.value = true;
}

async function submit() {
    saving.value = true;
    formErrors.value = {};
    formError.value = null;
    try {
        await devicesApi.create({
            user_id: form.user_id,
            cert_fingerprint: form.cert_fingerprint,
            cert_dn: form.cert_dn,
            label: form.label || null,
        });
        modalOpen.value = false;
        await load();
    } catch (e) {
        if (e?.response?.status === 422) {
            formErrors.value = e.response.data?.errors ?? {};
            formError.value = e.response.data?.message ?? null;
        } else {
            formError.value = 'Could not enroll the device. Please try again.';
        }
    } finally {
        saving.value = false;
    }
}

async function toggleRevoked(device) {
    try {
        const { device: updated } = await devicesApi.setRevoked(device.id, !device.is_revoked);
        Object.assign(device, updated);
    } catch (e) {
        await load();
    }
}
</script>

<template>
    <AppLayout :breadcrumb="['Admin', 'Devices']">
        <div class="page-head">
            <div>
                <h1>Devices</h1>
                <p>Enrolled client certificates (mTLS) that may reach the vault</p>
            </div>
            <div class="flex gap-2">
                <Btn v-if="canIssue" variant="primary" icon="ti ti-certificate" @click="openIssue">Issue certificate</Btn>
                <Btn variant="ghost" icon="ti ti-plus" @click="openEnroll">Enroll existing</Btn>
            </div>
        </div>

        <div v-if="loadError" class="note mb-4" style="background:#fef2f2;border-color:#fecaca;color:#dc2626;">
            <i class="ti ti-alert-triangle"></i>
            <div>{{ loadError }}</div>
        </div>

        <div class="note mb-4">
            <i class="ti ti-shield-lock"></i>
            <div>
                <b class="text-brand-600">mTLS device binding.</b>
                <span class="text-brand-600/90">
                    <template v-if="canIssue"><b>Issue certificate</b> generates a client cert, enrolls the device, and gives you a <code>.p12</code> for the user to install.</template>
                    <template v-else>No CA configured — run <code>php artisan dbvault:make-ca</code> to enable in-app certificate issuance.</template>
                    Enrolled, non-revoked devices bind a certificate to a user; enforcement turns on with <code>DBVAULT_MTLS_REQUIRE_ENROLLED_DEVICE=true</code>.
                </span>
            </div>
        </div>

        <Card>
            <table class="tbl">
                <thead>
                    <tr>
                        <th class="text-left">User</th>
                        <th class="text-left">Label</th>
                        <th class="text-left">Certificate</th>
                        <th class="text-left">Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="loading">
                        <td colspan="5" class="text-ink-3 text-center py-6">Loading…</td>
                    </tr>
                    <tr v-else-if="devices.length === 0">
                        <td colspan="5" class="text-ink-3 text-center py-6">No devices enrolled yet.</td>
                    </tr>
                    <tr v-for="d in devices" :key="d.id">
                        <td>
                            <div v-if="d.user" class="flex items-center gap-[10px]">
                                <Avatar :name="d.user.name" :size="30" />
                                <div>
                                    <b class="font-semibold text-[13px] block">{{ d.user.name }}</b>
                                    <span class="text-ink-3 text-[11.5px]">{{ d.user.email }}</span>
                                </div>
                            </div>
                            <span v-else class="text-ink-3">—</span>
                        </td>
                        <td class="text-[12.5px]">{{ d.label || '—' }}</td>
                        <td>
                            <div class="text-[12.5px] font-mono text-ink-2 truncate max-w-[280px]" :title="d.cert_dn">{{ d.cert_dn }}</div>
                            <div class="text-ink-3 text-xxs font-mono truncate max-w-[280px]" :title="d.cert_fingerprint">{{ d.cert_fingerprint }}</div>
                        </td>
                        <td>
                            <span class="badge" :class="d.is_revoked ? 'badge-bad' : 'badge-ok'">
                                {{ d.is_revoked ? 'revoked' : 'active' }}
                            </span>
                        </td>
                        <td class="text-right">
                            <button
                                type="button"
                                class="link-btn"
                                :class="d.is_revoked ? 'text-brand-600' : 'text-bad'"
                                @click="toggleRevoked(d)"
                            >
                                {{ d.is_revoked ? 'Reactivate' : 'Revoke' }}
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </Card>

        <Modal v-model="modalOpen" title="Enroll device" width="500px">
            <form id="enroll-device-form" @submit.prevent="submit">
                <div v-if="formError" class="note mb-3" style="background:#fef2f2;border-color:#fecaca;color:#dc2626;">
                    <i class="ti ti-alert-triangle"></i>
                    <div>{{ formError }}</div>
                </div>

                <div class="field mb-3">
                    <label for="d-user">User</label>
                    <select id="d-user" v-model="form.user_id">
                        <option value="" disabled>Select a user…</option>
                        <option v-for="u in users" :key="u.id" :value="u.id">{{ u.name }} ({{ u.email }})</option>
                    </select>
                    <p v-if="formErrors.user_id" class="text-bad text-xxs mt-[6px]">{{ formErrors.user_id[0] }}</p>
                </div>

                <div class="field mb-3">
                    <label for="d-label">Label</label>
                    <input id="d-label" v-model="form.label" type="text" placeholder="Arun's MacBook">
                    <p v-if="formErrors.label" class="text-bad text-xxs mt-[6px]">{{ formErrors.label[0] }}</p>
                </div>

                <div class="field mb-3">
                    <label for="d-dn">Certificate DN</label>
                    <input id="d-dn" v-model="form.cert_dn" type="text" placeholder="CN=arun,OU=eng,O=stellar">
                    <p v-if="formErrors.cert_dn" class="text-bad text-xxs mt-[6px]">{{ formErrors.cert_dn[0] }}</p>
                </div>

                <div class="field">
                    <label for="d-fp">Certificate fingerprint</label>
                    <input id="d-fp" v-model="form.cert_fingerprint" type="text" placeholder="SHA256:ab:cd:…">
                    <p class="text-ink-3 text-xxs mt-[6px]">The fingerprint the reverse proxy forwards for this client certificate.</p>
                    <p v-if="formErrors.cert_fingerprint" class="text-bad text-xxs mt-[6px]">{{ formErrors.cert_fingerprint[0] }}</p>
                </div>
            </form>

            <template #footer>
                <Btn variant="ghost" @click="modalOpen = false">Cancel</Btn>
                <Btn variant="primary" type="submit" form="enroll-device-form" icon="ti ti-check" :disabled="saving">
                    Enroll device
                </Btn>
            </template>
        </Modal>

        <!-- Issue a fresh client certificate -->
        <Modal v-model="issueOpen" title="Issue client certificate" width="520px">
            <div v-if="issueError" class="note mb-3" style="background:#fef2f2;border-color:#fecaca;color:#dc2626;">
                <i class="ti ti-alert-triangle"></i>
                <div>{{ issueError }}</div>
            </div>

            <!-- Step 1: pick the user -->
            <form v-if="!issued" id="issue-cert-form" @submit.prevent="submitIssue">
                <div class="field mb-3">
                    <label for="i-user">User</label>
                    <select id="i-user" v-model="issueForm.user_id" required>
                        <option value="" disabled>Select a user…</option>
                        <option v-for="u in users" :key="u.id" :value="u.id">{{ u.name }} ({{ u.email }})</option>
                    </select>
                </div>
                <div class="field">
                    <label for="i-label">Label</label>
                    <input id="i-label" v-model="issueForm.label" type="text" placeholder="Arun's MacBook">
                </div>
                <p class="text-ink-3 text-xxs mt-3">
                    The vault will generate a certificate signed by its CA, enroll it as a device, and produce a
                    password-protected <code>.p12</code>. The private key is shown to you once and never stored.
                </p>
            </form>

            <!-- Step 2: hand over the bundle -->
            <div v-else>
                <div class="note mb-3" style="background:#ecfdf5;border-color:#a7f3d0;color:#059669;">
                    <i class="ti ti-check"></i>
                    <div>Certificate issued and device enrolled for <b>{{ issued.device.user?.email }}</b>.</div>
                </div>
                <div class="field mb-3">
                    <label>Import password (one-time — copy it now)</label>
                    <input type="text" :value="issued.password" readonly class="font-mono" @focus="$event.target.select()">
                </div>
                <p class="text-ink-3 text-xxs mb-3">
                    Send the <code>.p12</code> file and this password to the user out-of-band. They import it into their
                    browser / OS keychain; the password is required only at import.
                </p>
            </div>

            <template #footer>
                <template v-if="!issued">
                    <Btn variant="ghost" @click="issueOpen = false">Cancel</Btn>
                    <Btn variant="primary" type="submit" form="issue-cert-form" icon="ti ti-certificate" :disabled="issuing">
                        Issue &amp; enroll
                    </Btn>
                </template>
                <template v-else>
                    <Btn variant="ghost" @click="issueOpen = false">Done</Btn>
                    <Btn variant="primary" icon="ti ti-download" @click="downloadP12">Download .p12</Btn>
                </template>
            </template>
        </Modal>
    </AppLayout>
</template>
