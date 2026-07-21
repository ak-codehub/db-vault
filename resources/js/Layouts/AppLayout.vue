<script setup>
import { computed, ref } from 'vue';
import { useRoute } from 'vue-router';
import Avatar from '../Components/Avatar.vue';
import { useAuth } from '../composables/useAuth.js';

const props = defineProps({
    breadcrumb: {
        type: Array,
        default: () => [], // e.g. ['Workspace', 'Dashboard']
    },
});

const route = useRoute();
const { user, server, counts, hasRole, logout } = useAuth();

const authUser = computed(() => user.value ?? { name: '', roles: [] });
const primaryRole = computed(() => authUser.value.roles?.[0] ?? 'Member');

const menuOpen = ref(false);

async function signOut() {
    menuOpen.value = false;
    await logout();
    window.location.href = './login';
}

function isActive(routeName, exact = false) {
    if (exact) return route.name === routeName;
    return route.name === routeName || String(route.name ?? '').startsWith(`${routeName}.`);
}

const nav = computed(() => {
    const groups = [
        {
            label: 'Workspace',
            items: [
                { label: 'Dashboard', to: { name: 'dashboard' }, icon: 'ti ti-layout-dashboard', match: 'dashboard', exact: true },
                { label: 'Request access', to: { name: 'requests.create' }, icon: 'ti ti-key', match: 'requests.create' },
                { label: 'My requests', to: { name: 'requests.index' }, icon: 'ti ti-list-details', match: 'requests.index' },
                hasRole(['approver', 'admin'])
                    ? { label: 'Approvals', to: { name: 'approvals.index' }, icon: 'ti ti-checkbox', match: 'approvals.index', count: counts.value.pendingApprovals }
                    : null,
                { label: 'Active sessions', to: { name: 'sessions.index' }, icon: 'ti ti-plug-connected', match: 'sessions.index', count: counts.value.activeSessions },
            ].filter(Boolean),
        },
    ];

    if (hasRole(['auditor', 'admin'])) {
        groups.push({
            label: 'Audit',
            items: [
                { label: 'Query log', to: { name: 'audit.index' }, icon: 'ti ti-history', match: 'audit.index' },
            ],
        });
    }

    if (hasRole(['admin'])) {
        groups.push({
            label: 'Admin',
            items: [
                { label: 'Users & roles', to: { name: 'users.index' }, icon: 'ti ti-users', match: 'users.index' },
                { label: 'Devices', to: { name: 'devices.index' }, icon: 'ti ti-devices', match: 'devices.index' },
            ],
        });
    }

    return groups;
});

const crumbTrail = computed(() => (props.breadcrumb.length ? props.breadcrumb : ['Workspace', 'Dashboard']));
</script>

<template>
    <div class="grid min-h-screen" style="grid-template-columns: 248px 1fr;">
        <!-- SIDEBAR -->
        <aside class="bg-sidebar text-sidebar-ink flex flex-col p-[14px] pt-5">
            <div class="flex items-center gap-[10px] px-2 pb-[22px] pt-[6px]">
                <div class="w-8 h-8 rounded-[9px] bg-gradient-to-br from-brand to-violet-500 grid place-items-center text-white text-lg">
                    <i class="ti ti-shield-lock"></i>
                </div>
                <div>
                    <b class="block text-white font-semibold text-[15px] tracking-[-.01em]">DB Vault</b>
                    <span class="block text-sidebar-ink2 text-xxs font-medium">{{ server.label }}</span>
                </div>
            </div>

            <template v-for="group in nav" :key="group.label">
                <div class="nav-label">{{ group.label }}</div>
                <nav>
                    <router-link
                        v-for="item in group.items"
                        :key="item.match"
                        :to="item.to"
                        class="nav-link"
                        :class="{ active: isActive(item.match, item.exact) }"
                    >
                        <i :class="item.icon"></i>
                        {{ item.label }}
                        <span v-if="item.count" class="count">{{ item.count }}</span>
                    </router-link>
                </nav>
            </template>

            <div class="mt-auto relative">
                <div
                    class="flex items-center gap-[10px] p-[10px] border-t border-sidebar-2 pt-4 cursor-pointer"
                    @click="menuOpen = !menuOpen"
                >
                    <Avatar :name="authUser.name" :size="34" />
                    <div class="min-w-0">
                        <b class="block text-white font-semibold text-[13px] truncate">{{ authUser.name }}</b>
                        <span class="block text-sidebar-ink2 text-[11.5px] capitalize">{{ primaryRole }}</span>
                    </div>
                    <i class="ti ti-selector ml-auto text-sidebar-ink2"></i>
                </div>
                <div
                    v-if="menuOpen"
                    class="absolute bottom-[calc(100%+6px)] left-[10px] right-[10px] bg-panel border border-line rounded-[10px] shadow-card-lg overflow-hidden"
                >
                    <button
                        class="w-full text-left px-3 py-[10px] text-[13px] text-ink font-medium hover:bg-page flex items-center gap-2"
                        @click="signOut"
                    >
                        <i class="ti ti-logout text-ink-2"></i>
                        Sign out
                    </button>
                </div>
            </div>
        </aside>

        <!-- MAIN -->
        <div class="flex flex-col min-w-0">
            <div class="h-16 bg-panel border-b border-line flex items-center gap-4 px-7 sticky top-0 z-10">
                <div class="text-[13px] text-ink-3 font-medium whitespace-nowrap">
                    <template v-for="(crumb, i) in crumbTrail" :key="i">
                        <b v-if="i === crumbTrail.length - 1" class="text-ink font-semibold">{{ crumb }}</b>
                        <template v-else>{{ crumb }} / </template>
                    </template>
                </div>
                <div class="ml-2 flex-1 max-w-[360px] flex items-center gap-2 bg-page border border-line rounded-[10px] px-3 py-2 text-ink-3">
                    <i class="ti ti-search text-[17px]"></i>
                    <input
                        type="text"
                        placeholder="Search requests, users, tables…"
                        class="border-0 bg-transparent outline-none flex-1 font-sans text-[13px] text-ink placeholder:text-ink-3"
                    >
                </div>
                <div class="ml-auto flex items-center gap-[14px]">
                    <div class="w-[38px] h-[38px] rounded-[10px] border border-line bg-panel grid place-items-center text-ink-2 hover:bg-page cursor-pointer" title="mTLS verified">
                        <i class="ti ti-lock-check text-[19px]"></i>
                    </div>
                    <div class="relative w-[38px] h-[38px] rounded-[10px] border border-line bg-panel grid place-items-center text-ink-2 hover:bg-page cursor-pointer">
                        <i class="ti ti-bell text-[19px]"></i>
                        <span v-if="counts.pendingApprovals" class="absolute top-2 right-[9px] w-[7px] h-[7px] rounded-full bg-bad border-2 border-panel"></span>
                    </div>
                    <Avatar :name="authUser.name" :size="36" />
                </div>
            </div>

            <div class="p-7 overflow-auto flex-1">
                <slot />
            </div>
        </div>
    </div>
</template>
