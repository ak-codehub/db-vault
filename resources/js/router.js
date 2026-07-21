import { createRouter, createWebHistory } from 'vue-router';
import { useAuth } from './composables/useAuth.js';
import { basePath } from './api.js';

const routes = [
    {
        path: '/login',
        name: 'login',
        component: () => import('./Views/Auth/Login.vue'),
        meta: { guestOnly: true },
    },
    {
        path: '/two-factor',
        name: 'two-factor',
        component: () => import('./Views/Auth/TwoFactor.vue'),
        meta: { guestOnly: true },
    },
    {
        path: '/two-factor-setup',
        name: 'two-factor-setup',
        component: () => import('./Views/Auth/TwoFactorSetup.vue'),
        meta: { guestOnly: true },
    },
    {
        path: '/',
        name: 'dashboard',
        component: () => import('./Views/Dashboard.vue'),
        meta: { requiresAuth: true },
    },
    {
        path: '/requests',
        name: 'requests.index',
        component: () => import('./Views/Requests/Index.vue'),
        meta: { requiresAuth: true },
    },
    {
        path: '/requests/create',
        name: 'requests.create',
        component: () => import('./Views/Requests/Create.vue'),
        meta: { requiresAuth: true },
    },
    {
        path: '/requests/:id',
        name: 'requests.show',
        component: () => import('./Views/Requests/Show.vue'),
        meta: { requiresAuth: true },
        props: true,
    },
    {
        path: '/approvals',
        name: 'approvals.index',
        component: () => import('./Views/Approvals/Index.vue'),
        meta: { requiresAuth: true, roles: ['approver', 'admin'] },
    },
    {
        path: '/sessions',
        name: 'sessions.index',
        component: () => import('./Views/Sessions/Index.vue'),
        meta: { requiresAuth: true },
    },
    {
        path: '/audit',
        name: 'audit.index',
        component: () => import('./Views/Audit/Index.vue'),
        meta: { requiresAuth: true, roles: ['auditor', 'admin'] },
    },
    {
        path: '/users',
        name: 'users.index',
        component: () => import('./Views/Users/Index.vue'),
        meta: { requiresAuth: true, roles: ['admin'] },
    },
    {
        path: '/devices',
        name: 'devices.index',
        component: () => import('./Views/Devices/Index.vue'),
        meta: { requiresAuth: true, roles: ['admin'] },
    },
];

export const router = createRouter({
    history: createWebHistory(basePath),
    routes,
});

router.beforeEach(async (to) => {
    const auth = useAuth();

    // Cached after the first resolution — subsequent navigations reuse
    // state.user without re-hitting GET /me every time.
    await auth.fetchMe();

    if (to.meta.requiresAuth && !auth.isAuthenticated.value) {
        return { name: 'login', query: { redirect: to.fullPath } };
    }

    if (to.meta.guestOnly && auth.isAuthenticated.value) {
        return { name: 'dashboard' };
    }

    if (to.meta.roles && !auth.hasRole(to.meta.roles)) {
        // Not privileged enough for this section — send back to the
        // dashboard rather than rendering a 403 view.
        return { name: 'dashboard' };
    }

    return true;
});

export default router;
