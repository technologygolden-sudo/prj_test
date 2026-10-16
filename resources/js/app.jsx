import '../css/app.css';
import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';

const pages = import.meta.glob('./Pages/**/*.jsx');

createInertiaApp({
    title: (titre) => (titre ? `${titre} · Gestion des kits` : 'Gestion des kits'),
    resolve: (nom) => {
        const page = pages[`./Pages/${nom}.jsx`];
        if (!page) {
            throw new Error(`Page introuvable : ${nom}`);
        }
        return page();
    },
    setup({ el, App, props }) {
        createRoot(el).render(<App {...props} />);
    },
    progress: { color: '#16a34a' },
});
