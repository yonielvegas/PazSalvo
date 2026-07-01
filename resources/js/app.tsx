import '../css/app.css';
import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import type { ComponentType } from 'react';

const pages = import.meta.glob<{ default: ComponentType }>('./pages/**/*.tsx');

createInertiaApp({
    title: (title) => title ? `${title} — AAUD` : 'Paz y Salvo AAUD',
    resolve: async (name) => (await pages[`./pages/${name}.tsx`]()).default,
    setup({ el, App, props }) {
        createRoot(el).render(<App {...props} />);
    },
    progress: { color: '#0f766e' },
});
