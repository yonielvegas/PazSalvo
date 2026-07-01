import { Head, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { AppLayout } from '@/components/app-layout';
import { ClientResultCard } from '@/components/paz-salvo/client-result-card';
import { DebtModal } from '@/components/paz-salvo/debt-modal';
import { PdfViewer } from '@/components/paz-salvo/pdf-viewer';
import { SearchClientForm } from '@/components/paz-salvo/search-client-form';
import type { PageProps } from '@/types/paz-salvo';

export default function Index() {
    const { flash, errors } = usePage<PageProps>().props;
    const [showDebt, setShowDebt] = useState(flash.result?.status === 'has_debt');
    const [showMessage, setShowMessage] = useState(Boolean(flash.message));

    useEffect(() => setShowDebt(flash.result?.status === 'has_debt'), [flash.result?.query_token]);
    useEffect(() => {
        setShowMessage(Boolean(flash.message));

        if (!flash.message) {
            return;
        }

        const timer = window.setTimeout(() => setShowMessage(false), 6000);

        return () => window.clearTimeout(timer);
    }, [flash.message]);
    useEffect(() => setShowMessage(false), [flash.result?.query_token]);

    return (
        <>
            <Head title="Consulta de Paz y Salvo" />
            <AppLayout>
                <section className="hero"><p className="eyebrow">CONSULTA DE CLIENTES</p><h1>Paz y Salvo</h1><p>Ingrese el NAC para consultar el estado de cuenta y generar el documento.</p><SearchClientForm /></section>
                {flash.result?.status === 'debt_free' && !flash.document && <ClientResultCard result={flash.result} />}
                {flash.result?.status === 'not_found' && <div className="notice error">No se encontró información suficiente para el NAC consultado.</div>}
                {errors.generation && <div className="notice error" role="alert">{errors.generation}</div>}
                {flash.message && showMessage && <div className="notice success dismissible"><span>{flash.message}</span><button type="button" onClick={() => setShowMessage(false)} aria-label="Cerrar mensaje">×</button></div>}
                {flash.document && <PdfViewer document={flash.document} />}
            </AppLayout>
            {flash.result?.status === 'has_debt' && showDebt && <DebtModal result={flash.result} onClose={() => setShowDebt(false)} />}
        </>
    );
}
