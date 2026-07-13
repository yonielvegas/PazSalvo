import { router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { FileText, X, Loader2 } from 'lucide-react';

export function InvoiceNumberModal({ queryToken, onClose }: { queryToken: string; onClose: () => void }) {
    const { errors } = usePage<{ errors: Record<string, string> }>().props;
    const [numeroFactura, setNumeroFactura] = useState('');
    const [error, setError] = useState<string | null>(null);
    const [processing, setProcessing] = useState(false);

    useEffect(() => {
        const close = (event: KeyboardEvent) => event.key === 'Escape' && onClose();
        document.addEventListener('keydown', close);
        return () => document.removeEventListener('keydown', close);
    }, [onClose]);

    useEffect(() => {
        if (errors.generation || errors.numero_factura) {
            setError(errors.generation || errors.numero_factura || 'Error al generar el certificado.');
            setProcessing(false);
        }
    }, [errors.generation, errors.numero_factura]);

    const handleInputChange = (e: React.ChangeEvent<HTMLInputElement>) => {
        const raw = e.target.value.replace(/\D/g, '').slice(0, 6);
        setNumeroFactura(raw);
        setError(null);
    };

    const handleGenerate = () => {
        if (numeroFactura.length !== 6) {
            setError('El número de factura debe contener exactamente 6 dígitos numéricos.');
            return;
        }
        setError(null);
        setProcessing(true);
        router.post('/paz-salvos/generar', {
            query_token: queryToken,
            numero_factura: numeroFactura,
        }, {
            preserveState: true,
            preserveScroll: true,
            onError: (errs) => {
                setError(errs.generation || errs.numero_factura || 'Error al generar el certificado.');
                setProcessing(false);
            },
        });
    };

    return (
        <div className="modal-backdrop" role="presentation" onMouseDown={(event) => event.target === event.currentTarget && !processing && onClose()}>
            <section className="debt-modal" role="dialog" aria-modal="true" aria-labelledby="invoice-modal-title">
                <button className="close-button" onClick={onClose} aria-label="Cerrar" disabled={processing}><X /></button>
                <div className="warning-icon"><FileText /></div>
                <h2 id="invoice-modal-title">Número de factura</h2>
                <p>Ingrese el número de factura del Paz y Salvo para continuar con la generación.</p>

                <div className="form-group" style={{ marginTop: 20, textAlign: 'left' }}>
                    <label htmlFor="numero-factura">Número de factura</label>
                    <input
                        id="numero-factura"
                        type="text"
                        inputMode="numeric"
                        placeholder="000000"
                        maxLength={6}
                        value={numeroFactura}
                        onChange={handleInputChange}
                        disabled={processing}
                        autoFocus
                    />
                    {error && <p className="field-error" role="alert">{error}</p>}
                </div>

                <div className="modal-actions">
                    <button className="secondary-button" onClick={onClose} disabled={processing}>Cancelar</button>
                    <button className="primary-button" onClick={handleGenerate} disabled={processing}>
                        {processing ? <><Loader2 className="animate-spin" /> Generando…</> : 'Generar Paz y Salvo'}
                    </button>
                </div>
            </section>
        </div>
    );
}
