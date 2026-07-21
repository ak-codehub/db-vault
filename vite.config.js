import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';

// This is a standalone (non-Laravel-plugin) Vite build: the compiled SPA is
// consumed by the db-vault Composer package as static assets served from
// `public/vendor/db-vault/` (see the package's asset publish/service
// provider). There is no PHP/Node runtime in this authoring environment, so
// this config cannot be executed here — running `npm install && npm run
// build` in a normal Node environment will produce `public/app.js` and
// `public/app.css` with stable, unhashed filenames so the package's Blade
// boot view can reference them directly.
export default defineConfig({
    plugins: [
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
    ],
    resolve: {
        alias: {
            '@': '/resources/js',
        },
    },
    // Relative base so the built app.js/app.css work regardless of the
    // configurable mount path (e.g. /vault, /db-vault, /admin/db-vault) —
    // the browser resolves asset URLs relative to the document the SPA
    // shell is served from (public/vendor/db-vault/index or the Blade boot
    // view), rather than baking an absolute path in at build time.
    base: './',
    build: {
        outDir: 'public',
        emptyOutDir: false,
        assetsDir: 'assets',
        cssCodeSplit: false,
        rollupOptions: {
            input: {
                app: 'resources/js/app.js',
            },
            output: {
                // Fixed, unhashed filenames so the Blade boot view can
                // reference them directly without a manifest.
                entryFileNames: 'app.js',
                chunkFileNames: 'app-[name].js',
                assetFileNames: (assetInfo) => {
                    if (assetInfo.name && assetInfo.name.endsWith('.css')) {
                        return 'app.css';
                    }
                    return 'assets/[name][extname]';
                },
            },
        },
    },
});
