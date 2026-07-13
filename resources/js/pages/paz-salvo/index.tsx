import { Head, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { AppLayout } from '@/components/app-layout';
import { ClientResultCard } from '@/components/paz-salvo/client-result-card';
import { DebtModal } from '@/components/paz-salvo/debt-modal';
import { EnergyWarningModal } from '@/components/paz-salvo/energy-warning-modal';
import { InvoiceNumberModal } from '@/components/paz-salvo/invoice-number-modal';
import { NotSanMiguelitoModal } from '@/components/paz-salvo/not-san-miguelito-modal';
import { PdfViewer } from '@/components/paz-salvo/pdf-viewer';
import { SearchClientForm } from '@/components/paz-salvo/search-client-form';
import type { PageProps } from '@/types/paz-salvo';

export default function Index() {
    const { flash, errors } = usePage<PageProps>().props;
    const result = flash.result;
    const [showNotSanMiguelito, setShowNotSanMiguelito] = useState(false);
    const [showDebt, setShowDebt] = useState(false);
    const [showEnergyWarning, setShowEnergyWarning] = useState(false);
    const [showResult, setShowResult] = useState(false);
    const [showInvoiceModal, setShowInvoiceModal] = useState(false);
    const [showMessage, setShowMessage] = useState(Boolean(flash.message));

    useEffect(() => {
        if (!result) {
            setShowNotSanMiguelito(false);
            setShowDebt(false);
            setShowEnergyWarning(false);
            setShowResult(false);
            setShowInvoiceModal(false);
            return;
        }
        if (result.status === 'not_san_miguelito') {
            setShowNotSanMiguelito(true);
            setShowDebt(false);
            setShowEnergyWarning(false);
            setShowResult(false);
        } else if (result.status === 'has_aseo_debt') {
            setShowNotSanMiguelito(false);
            setShowDebt(true);
            setShowEnergyWarning(false);
            setShowResult(false);
        } else if (result.status === 'debt_free_aseo_with_energy_debt') {
            setShowNotSanMiguelito(false);
            setShowDebt(false);
            setShowEnergyWarning(true);
            setShowResult(false);
        } else {
            setShowNotSanMiguelito(false);
            setShowDebt(false);
            setShowEnergyWarning(false);
            setShowResult(true);
        }
    }, [result?.query_token]);
    useEffect(() => {
        setShowMessage(Boolean(flash.message));

        if (!flash.message) {
            return;
        }

        const timer = window.setTimeout(() => setShowMessage(false), 6000);

        return () => window.clearTimeout(timer);
    }, [flash.message]);
    useEffect(() => setShowMessage(false), [result?.query_token]);
    useEffect(() => {
        if (!errors.generation && !errors.numero_factura) return;
        setShowInvoiceModal(true);
    }, [errors.generation, errors.numero_factura]);

    const handleContinue = () => {
        setShowEnergyWarning(false);
        setShowResult(true);
    };

    const handleOpenInvoiceModal = () => setShowInvoiceModal(true);
    const handleCloseInvoiceModal = () => {
        setShowInvoiceModal(false);
    };

    return (
        <>
            <Head title="Consulta de Paz y Salvo" />
            <AppLayout>
                <section className="hero"><p className="eyebrow">CONSULTA DE CLIENTES</p><h1>Paz y Salvo</h1><p>Ingrese el NAC para consultar el estado de cuenta y generar el documento.</p><SearchClientForm /></section>
                {flash.error && <div className="notice error" role="alert">{flash.error}</div>}
                {showResult && result && <ClientResultCard result={result} onGenerate={handleOpenInvoiceModal} />}
                {result?.status === 'not_found' && <div className="notice error">No se encontró información suficiente para el NAC consultado.</div>}
                {errors.generation && !showInvoiceModal && <div className="notice error" role="alert">{errors.generation}</div>}
                {flash.message && showMessage && <div className="notice success dismissible"><span>{flash.message}</span><button type="button" onClick={() => setShowMessage(false)} aria-label="Cerrar mensaje">×</button></div>}
                {flash.document && <PdfViewer document={flash.document} />}
            </AppLayout>
            {result && showNotSanMiguelito && <NotSanMiguelitoModal result={result} onClose={() => setShowNotSanMiguelito(false)} />}
            {result && showDebt && <DebtModal result={result} onClose={() => setShowDebt(false)} />}
            {result && showEnergyWarning && <EnergyWarningModal result={result} onContinue={handleContinue} onClose={() => setShowEnergyWarning(false)} />}
            {result && showInvoiceModal && <InvoiceNumberModal queryToken={result.query_token} onClose={handleCloseInvoiceModal} />}
        </>
    );
}
