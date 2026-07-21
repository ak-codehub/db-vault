<script setup>
import { ref, onMounted } from 'vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import Card from '../../Components/Card.vue';
import Btn from '../../Components/Btn.vue';
import Avatar from '../../Components/Avatar.vue';
import PrivilegeMatrix from '../../Components/PrivilegeMatrix.vue';
import SlideOver from '../../Components/SlideOver.vue';
import Modal from '../../Components/Modal.vue';
import EmptyState from '../../Components/EmptyState.vue';
import { approvalsApi } from '../../api.js';

const loading = ref(true);
const error = ref(null);
const approvals = ref([]);

const reviewing = ref(null);
const showReview = ref(false);
const rejecting = ref(null);
const showReject = ref(false);
const rejectNote = ref('');
const busy = ref(false);

async function load() {
    loading.value = true;
    error.value = null;
    try {
        const data = await approvalsApi.list();
        approvals.value = data.approvals ?? [];
    } catch (e) {
        error.value = 'Could not load the approval queue. Please try again.';
    } finally {
        loading.value = false;
    }
}

function openReview(item) {
    reviewing.value = item;
    showReview.value = true;
}

function openReject(item) {
    rejecting.value = item;
    rejectNote.value = '';
    showReject.value = true;
}

async function approve(id) {
    busy.value = true;
    try {
        await approvalsApi.approve(id);
        approvals.value = approvals.value.filter((a) => a.id !== id);
    } finally {
        busy.value = false;
    }
}

async function confirmReject() {
    if (rejecting.value) {
        busy.value = true;
        try {
            await approvalsApi.reject(rejecting.value.id, rejectNote.value || undefined);
            approvals.value = approvals.value.filter((a) => a.id !== rejecting.value.id);
        } finally {
            busy.value = false;
        }
    }
    showReject.value = false;
}

onMounted(load);
</script>

<template>
    <AppLayout :breadcrumb="['Workspace', 'Approvals']">
        <div class="page-head">
            <div>
                <h1>Approvals</h1>
                <p>Requests waiting on a decision</p>
            </div>
            <span class="badge badge-warn">{{ approvals.length }} pending</span>
        </div>

        <div v-if="loading" class="text-ink-3 text-[13px] py-10 text-center">Loading approvals…</div>
        <div v-else-if="error" class="note" style="background:#fef2f2;border-color:#fecaca;color:#dc2626;">
            <i class="ti ti-alert-triangle"></i>
            <div>{{ error }}</div>
        </div>
        <template v-else>
            <Card v-if="approvals.length">
                <div v-for="item in approvals" :key="item.id" class="appr">
                    <div class="top">
                        <Avatar :name="item.developer" :size="30" />
                        <b>{{ item.developer }}</b>
                        <span>{{ item.requestedAgo }}</span>
                    </div>
                    <div class="rq">
                        <span class="badge badge-mute mr-2">{{ item.database }}</span>
                        <span class="badge badge-mute mr-2">{{ item.duration }}</span>
                        "{{ item.reason }}"
                    </div>
                    <div class="acts">
                        <Btn variant="mini-ok" icon="ti ti-check" :disabled="busy" @click="approve(item.id)">Approve</Btn>
                        <Btn variant="mini" :disabled="busy" @click="openReview(item)">Review matrix</Btn>
                        <Btn variant="mini" class="mini-bad" :disabled="busy" @click="openReject(item)">Reject</Btn>
                    </div>
                </div>
            </Card>

            <Card v-else>
                <EmptyState
                    icon="ti ti-checks"
                    title="Queue is empty"
                    message="There is nothing waiting on your approval right now."
                />
            </Card>
        </template>

        <SlideOver
            v-model="showReview"
            width="760px"
            :title="reviewing ? `Review request #${reviewing.id}` : ''"
            :subtitle="reviewing ? `${reviewing.developer} · ${reviewing.database} · ${reviewing.duration}` : ''"
        >
            <template v-if="reviewing">
                <p class="text-[13px] text-ink-2 mb-5">"{{ reviewing.reason }}"</p>
                <label class="block text-xs font-semibold text-ink-2 mb-[10px]">
                    Requested privileges
                    <span class="text-ink-3 font-medium">· {{ reviewing.matrix?.length ?? 0 }} tables</span>
                </label>
                <div class="overflow-auto border border-line rounded-[9px] max-h-[60vh]">
                    <PrivilegeMatrix :model-value="reviewing.matrix" readonly :scroll="false" :show-note="false" />
                </div>
            </template>
            <template #footer>
                <Btn variant="ghost" @click="showReview = false">Close</Btn>
                <Btn
                    variant="primary"
                    icon="ti ti-check"
                    @click="() => { approve(reviewing.id); showReview = false; }"
                >
                    Approve
                </Btn>
            </template>
        </SlideOver>

        <Modal v-model="showReject" title="Reject request">
            <p class="text-[13px] text-ink-2 mb-3">
                Reject request from <b>{{ rejecting?.developer }}</b>? This is recorded in the audit log and the developer is notified.
            </p>
            <div class="field">
                <label for="reject-note">Note (optional)</label>
                <textarea id="reject-note" v-model="rejectNote" rows="2" placeholder="Reason for rejection"></textarea>
            </div>
            <template #footer>
                <Btn variant="ghost" @click="showReject = false">Cancel</Btn>
                <Btn variant="danger" icon="ti ti-x" @click="confirmReject">Reject request</Btn>
            </template>
        </Modal>
    </AppLayout>
</template>
