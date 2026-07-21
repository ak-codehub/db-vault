import { computed, reactive } from 'vue';
import { authApi, setCsrfToken } from '../api.js';

// Module-level reactive state so every component/composable consumer
// shares the same singleton auth state (no Pinia dependency needed for a
// package this size).
const state = reactive({
    user: null, // {id, name, email, roles: []}
    server: { label: '' },
    counts: { pendingApprovals: 0, activeSessions: 0 },
    loaded: false, // has fetchMe() resolved at least once (success or 401)?
    loading: false,
    fetchPromise: null, // in-flight GET /me, so concurrent guards share one request
});

function applyMe(data) {
    state.user = data?.user ?? null;
    state.server = data?.server ?? { label: '' };
    state.counts = data?.counts ?? { pendingApprovals: 0, activeSessions: 0 };
    state.loaded = true;
}

function clear() {
    state.user = null;
    state.server = { label: '' };
    state.counts = { pendingApprovals: 0, activeSessions: 0 };
    state.loaded = true;
}

export function useAuth() {
    const isAuthenticated = computed(() => state.user !== null);

    function hasRole(role) {
        if (!state.user?.roles) return false;
        const roles = Array.isArray(role) ? role : [role];
        return state.user.roles.some((r) => roles.includes(r));
    }

    /**
     * Resolve GET /me once, caching the in-flight promise so the
     * router's beforeEach guard and any component mounted at the same
     * time don't fire duplicate requests. Pass `force` to re-check after
     * login/logout.
     */
    async function fetchMe({ force = false } = {}) {
        if (state.loaded && !force) return state.user;
        if (state.fetchPromise && !force) return state.fetchPromise;

        state.loading = true;
        state.fetchPromise = authApi
            .me()
            .then((data) => {
                applyMe(data);
                return state.user;
            })
            .catch(() => {
                clear();
                return null;
            })
            .finally(() => {
                state.loading = false;
                state.fetchPromise = null;
            });

        return state.fetchPromise;
    }

    async function login(credentials) {
        const data = await authApi.login(credentials);
        if (data.status === 'authenticated') {
            await fetchMe({ force: true });
        }
        return data; // {status: 'two-factor-required' | 'authenticated', user?}
    }

    async function verifyTwoFactor(payload) {
        const data = await authApi.twoFactorChallenge(payload);
        if (data.status === 'authenticated') {
            await fetchMe({ force: true });
        }
        return data;
    }

    async function logout() {
        try {
            await authApi.logout();
        } finally {
            clear();
            setCsrfToken('');
        }
    }

    return {
        state,
        user: computed(() => state.user),
        server: computed(() => state.server),
        counts: computed(() => state.counts),
        isAuthenticated,
        loaded: computed(() => state.loaded),
        hasRole,
        fetchMe,
        login,
        verifyTwoFactor,
        logout,
    };
}
