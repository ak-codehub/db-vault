import axios from 'axios';

// window.DbVault is emitted by the package's Blade boot view, e.g.:
//   window.DbVault = { basePath: '/vault', apiBase: 'https://host/vault/api', csrf: '<token>' }
const bootstrap = typeof window !== 'undefined' ? (window.DbVault ?? {}) : {};

export const basePath = bootstrap.basePath ?? '/vault';
export const apiBase = bootstrap.apiBase ?? '/vault/api';

// Session-cookie auth: withCredentials sends the app's session cookie on
// every request, and the CSRF token guards mutating requests the same way
// Laravel's default `VerifyCsrfToken` middleware expects (mirrors what
// Fortify/Sanctum SPA auth needs without Inertia).
export const apiClient = axios.create({
    baseURL: apiBase,
    withCredentials: true,
    headers: {
        'X-Requested-With': 'XMLHttpRequest',
        // Accept: application/json makes Laravel's exception handler return
        // 422 JSON (with field errors) for validation failures, instead of a
        // 302 redirect — without it the SPA can't read validation messages
        // and shows a generic "could not submit" for every failure.
        Accept: 'application/json',
    },
});

// The CSRF token last handed to us — from the Blade boot page initially, then
// refreshed via setCsrfToken() whenever the backend rotates the session
// (login/logout return the new token in their JSON payload). This is the
// fallback source; the live XSRF-TOKEN cookie (below) takes precedence.
let currentCsrfToken = bootstrap.csrf ?? '';

/**
 * Read the value of a browser cookie by name (URL-decoded), or null.
 */
function readCookie(name) {
    if (typeof document === 'undefined') return null;
    const match = document.cookie.match(
        new RegExp('(?:^|; )' + name.replace(/([.$?*|{}()[\]\\/+^])/g, '\\$1') + '=([^;]*)')
    );
    return match ? decodeURIComponent(match[1]) : null;
}

/**
 * Update the fallback CSRF token, e.g. after the backend rotates it on
 * login/logout. The XSRF-TOKEN cookie is still preferred when present.
 */
export function setCsrfToken(token) {
    currentCsrfToken = token ?? '';
}

// Resolve the CSRF token FRESH on every mutating request. Laravel refreshes
// the encrypted `XSRF-TOKEN` cookie on every response, so reading it here
// keeps us in sync across session rotations (login regenerates the token) —
// the root cause of the intermittent "CSRF token mismatch" after login. We
// fall back to the last-known token (boot page / setCsrfToken) when the
// cookie is unavailable.
apiClient.interceptors.request.use((config) => {
    const cookieToken = readCookie('XSRF-TOKEN');
    if (cookieToken) {
        config.headers['X-XSRF-TOKEN'] = cookieToken;
    } else if (currentCsrfToken) {
        config.headers['X-CSRF-TOKEN'] = currentCsrfToken;
    }
    return config;
});

// ---------------------------------------------------------------------
// Typed endpoint helpers — one function per API contract entry. Every
// call resolves to `response.data` so views/composables never touch the
// axios response envelope directly.
// ---------------------------------------------------------------------

function unwrap(promise) {
    return promise.then((response) => response.data);
}

export const authApi = {
    login: (payload) => unwrap(apiClient.post('login', payload)),
    // {email, password} -> {status:'two-factor-required'} | {status:'authenticated', user}

    twoFactorChallenge: (payload) => unwrap(apiClient.post('two-factor-challenge', payload)),
    // {code | recovery_code} -> {status:'authenticated', user}

    twoFactorSetup: (payload) => unwrap(apiClient.post('two-factor-setup', payload)),
    // {code} -> {status:'authenticated', user} — confirms a forced enrollment

    logout: () => unwrap(apiClient.post('logout')),

    me: () => unwrap(apiClient.get('me')),
    // -> {user:{id,name,email,roles[]}, server:{label}, counts:{pendingApprovals,activeSessions}}
};

export const dashboardApi = {
    get: () => unwrap(apiClient.get('dashboard')),
    // -> {stats:{...}, activeSessions:[...], pendingApprovals:[...]}
};

export const requestsApi = {
    list: () => unwrap(apiClient.get('requests')),
    // -> {requests:[...]}

    create: (payload) => unwrap(apiClient.post('requests', payload)),
    // {target_database, duration_minutes, reason, grants:[{table, privileges[]}]} -> 201 {request}

    tables: (database) => unwrap(apiClient.get('requests/tables', database ? { params: { database } } : undefined)),
    // (database?) -> {database, tables:[...]} — candidate tables for the request matrix

    formOptions: () => unwrap(apiClient.get('requests/form-options')),
    // -> {databases:[...], durations:[{value,label}]} — request-form option lists

    show: (id) => unwrap(apiClient.get(`requests/${id}`)),
    // -> {request, matrix, approval, session, auditQueries}

    cancel: (id) => unwrap(apiClient.post(`requests/${id}/cancel`)),
};

export const approvalsApi = {
    list: () => unwrap(apiClient.get('approvals')),
    // -> {approvals:[...]}

    approve: (id) => unwrap(apiClient.post(`approvals/${id}/approve`)),

    reject: (id, note) => unwrap(apiClient.post(`approvals/${id}/reject`, note ? { note } : {})),
};

export const sessionsApi = {
    list: () => unwrap(apiClient.get('sessions')),
    // -> {sessions:[...]}

    launch: (id) => unwrap(apiClient.post(`sessions/${id}/launch`)),
    // -> {pma_url} — caller opens this in a new tab

    revoke: (id) => unwrap(apiClient.post(`sessions/${id}/revoke`)),
};

export const auditApi = {
    list: () => unwrap(apiClient.get('audit')),
    // -> {queries:[...]}
};

export const usersApi = {
    list: () => unwrap(apiClient.get('users')),
    // -> {users:[{id,name,email,roles[],is_active,two_factor_enabled}], roles:[...]}

    create: (payload) => unwrap(apiClient.post('users', payload)),
    // {name, email, password, roles[]} -> 201 {user}

    update: (id, payload) => unwrap(apiClient.patch(`users/${id}`, payload)),
    // {name?, roles?, is_active?, password?} -> {user}
};

export const devicesApi = {
    list: () => unwrap(apiClient.get('devices')),
    // -> {devices:[{id,user,label,cert_dn,cert_fingerprint,enrolled_at,revoked_at,is_revoked}]}

    create: (payload) => unwrap(apiClient.post('devices', payload)),
    // {user_id, cert_fingerprint, cert_dn, label?} -> 201 {device}

    issue: (payload) => unwrap(apiClient.post('devices/issue', payload)),
    // {user_id, label?} -> 201 {device, pkcs12_base64, password, filename}

    setRevoked: (id, revoked) => unwrap(apiClient.patch(`devices/${id}`, { revoked })),
    // (id, bool) -> {device}
};

export default apiClient;
