import defaultTheme from 'tailwindcss/defaultTheme';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './resources/js/**/*.vue',
        './resources/js/**/*.js',
    ],
    theme: {
        extend: {
            fontFamily: {
                sans: ['Inter', ...defaultTheme.fontFamily.sans],
            },
            colors: {
                brand: {
                    DEFAULT: '#6366f1',
                    50: '#eef2ff',
                    100: '#e0e7ff',
                    600: '#4f46e5',
                },
                ink: {
                    DEFAULT: '#0f172a',
                    2: '#475569',
                    3: '#94a3b8',
                },
                line: {
                    DEFAULT: '#e7e9ee',
                    2: '#eef0f4',
                },
                page: '#f6f7f9',
                panel: '#ffffff',
                sidebar: {
                    DEFAULT: '#0f1729',
                    2: '#1a2338',
                    ink: '#c7cede',
                    ink2: '#7c88a6',
                },
                ok: {
                    DEFAULT: '#059669',
                    bg: '#ecfdf5',
                },
                warn: {
                    DEFAULT: '#b45309',
                    bg: '#fffbeb',
                },
                bad: {
                    DEFAULT: '#dc2626',
                    bg: '#fef2f2',
                },
            },
            borderRadius: {
                xl: '12px',
                '2xl': '14px',
            },
            boxShadow: {
                card: '0 1px 2px rgba(16,24,40,.04), 0 1px 3px rgba(16,24,40,.06)',
                'card-lg': '0 8px 24px rgba(16,24,40,.10), 0 2px 6px rgba(16,24,40,.06)',
            },
            fontSize: {
                xxs: ['11px', { lineHeight: '16px' }],
            },
        },
    },
    plugins: [],
};
