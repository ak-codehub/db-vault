import '../css/app.css';

import { createApp } from 'vue';
import App from './App.vue';
import router from './router.js';
import { apiClient, basePath } from './api.js';

if (typeof window !== 'undefined' && !window.DbVault) {
    // eslint-disable-next-line no-console
    console.warn(
        '[db-vault] window.DbVault was not found. The package boot view is ' +
        'expected to set window.DbVault = { basePath, apiBase, csrf } before ' +
        'this bundle loads. Falling back to defaults.',
    );
}

// 401 responses mean the session cookie is missing/expired — bounce to the
// login route rather than letting views hang on a rejected request.
apiClient.interceptors.response.use(
    (response) => response,
    (error) => {
        if (error?.response?.status === 401) {
            const loginPath = `${basePath.replace(/\/$/, '')}/login`;
            if (typeof window !== 'undefined' && window.location.pathname !== loginPath) {
                router
                    .push({ name: 'login', query: { redirect: window.location.pathname } })
                    .catch(() => {});
            }
        }
        return Promise.reject(error);
    },
);

createApp(App)
    .use(router)
    .mount('#db-vault-app');
