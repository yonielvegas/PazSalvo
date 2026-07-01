import '../css/app.css';
import { createInertiaApp } from '@inertiajs/react';
import type { GlobalEvent } from '@inertiajs/core';
import { createRoot } from 'react-dom/client';
import { AlertTriangle } from 'lucide-react';
import type { ComponentType, PropsWithChildren } from 'react';
import { useEffect, useState } from 'react';

const pages = import.meta.glob<{ default: ComponentType }>('./pages/**/*.tsx');
const fallbackPath = '/paz-salvos/consultar';

function ForbiddenModal({ open, onAccept }: { open: boolean; onAccept: () => void }) {
    if (!open) {
        return null;
    }

    return (
        <div className="modal-backdrop forbidden-overlay" role="presentation">
            <section className="forbidden-dialog" role="alertdialog" aria-modal="true" aria-labelledby="forbidden-title">
                <div className="forbidden-icon"><AlertTriangle /></div>
                <p className="eyebrow">ACCESO NO AUTORIZADO</p>
                <h2 id="forbidden-title">No tienes permisos para realizar esta accion.</h2>
                <p>No tienes permisos para acceder a esta seccion o realizar esta accion.</p>
                <button onClick={onAccept}>Aceptar</button>
            </section>
        </div>
    );
}

function AppShell({ children }: PropsWithChildren) {
    const [showForbidden, setShowForbidden] = useState(false);

    useEffect(() => {
        const handleHttpException = (event: GlobalEvent<'httpException'>) => {
            if (event.detail.response.status !== 403) {
                return;
            }

            event.preventDefault();
            setShowForbidden(true);
        };

        document.addEventListener('inertia:httpException', handleHttpException);

        return () => document.removeEventListener('inertia:httpException', handleHttpException);
    }, []);

    const handleAccept = () => {
        setShowForbidden(false);

        if (window.history.length > 1 && document.referrer) {
            window.history.back();
            window.setTimeout(() => {
                if (document.visibilityState === 'visible') {
                    window.location.assign(fallbackPath);
                }
            }, 250);

            return;
        }

        window.location.assign(fallbackPath);
    };

    return (
        <>
            {children}
            <ForbiddenModal open={showForbidden} onAccept={handleAccept} />
        </>
    );
}

createInertiaApp({
    title: (title) => title ? `${title} — AAUD` : 'Paz y Salvo AAUD',
    resolve: async (name) => (await pages[`./pages/${name}.tsx`]()).default,
    setup({ el, App, props }) {
        createRoot(el).render(<AppShell><App {...props} /></AppShell>);
    },
    progress: { color: '#0f766e' },
});
