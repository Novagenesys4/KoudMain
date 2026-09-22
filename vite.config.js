import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';

// Règles 7 et 15 : en production, le JavaScript envoyé aux visiteurs ne contient ni console.* (rien ne s'affiche dans la console du
// navigateur : erreurs internes, adresses, objets d'erreur), ni « debugger », ni cartes de sources (le code d'origine n'est pas
// publié). Seules les variables VITE_* sont copiées dans le JavaScript : n'y mettez jamais de secret (voir SECURITE.md).
export default defineConfig(({ mode }) => ({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
        react(),
        tailwindcss(),
    ],
    esbuild: mode === 'production' ? { drop: ['console', 'debugger'], legalComments: 'none' } : {},
    build: {
        sourcemap: false,
        rollupOptions: {
            output: {
                // React et Motion dans leurs propres fichiers : mis en cache séparément du code de l'application.
                manualChunks(id) {
                    if (id.includes('node_modules/motion') || id.includes('node_modules/framer-motion') || id.includes('node_modules/motion-')) return 'motion';
                    if (id.includes('node_modules/react')) return 'react';
                },
            },
        },
    },
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
}));
