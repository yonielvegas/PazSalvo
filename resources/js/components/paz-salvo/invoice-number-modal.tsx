import { router, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { AlertTriangle, FileText, X, Loader2 } from 'lucide-react';

export function InvoiceNumberModal({ queryToken, onClose }: { queryToken: string; onClose: () => void }) {
    const { errors } = usePage<{ errors: Record<string, string> }>().props;
    const [numeroFactura, setNumeroFactura] = useState('');
    const [numeroFacturaConfirmado, setNumeroFacturaConfirmado] = useState('');
    const [showConfirmation, setShowConfirmation] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [processing, setProcessing] = useState(false);
    const inputRef = useRef<HTMLInputElement>(null);
    const confirmButtonRef = useRef<HTMLButtonElement>(null);
    const submittingRef = useRef(false);

    useEffect(() => {
        const close = (event: KeyboardEvent) => {
            if (event.key !== 'Escape' || processing) {
                return;
            }

            if (showConfirmation) {
                setShowConfirmation(false);
                window.setTimeout(() => inputRef.current?.focus(), 0);
                return;
            }

            onClose();
        };
        document.addEventListener('keydown', close);
        return () => document.removeEventListener('keydown', close);
    }, [onClose, processing, showConfirmation]);

    useEffect(() => {
        if (showConfirmation) {
            confirmButtonRef.current?.focus();
        }
    }, [showConfirmation]);

    useEffect(() => {
        if (errors.generation || errors.numero_factura) {
            setError(errors.generation || errors.numero_factura || 'Error al generar el certificado.');
            setShowConfirmation(false);
            setProcessing(false);
        }
    }, [errors.generation, errors.numero_factura]);

    const handleInputChange = (e: React.ChangeEvent<HTMLInputElement>) => {
        const raw = e.target.value.replace(/\D/g, '').slice(0, 6);
        setNumeroFactura(raw);
        setError(null);
    };

    const handleContinue = () => {
        if (numeroFactura.length !== 6) {
            setError('El número de factura debe contener exactamente 6 dígitos numéricos.');
            return;
        }

        setNumeroFacturaConfirmado(numeroFactura);
        setError(null);
        setShowConfirmation(true);
    };

    const handleBack = () => {
        if (processing) {
            return;
        }

        setShowConfirmation(false);
        window.setTimeout(() => inputRef.current?.focus(), 0);
    };

    const handleGenerate = () => {
        if (processing || submittingRef.current || numeroFacturaConfirmado.length !== 6) {
            return;
        }

        submittingRef.current = true;
        setProcessing(true);
        router.post('/paz-salvos/generar', {
            query_token: queryToken,
            numero_factura: numeroFacturaConfirmado,
        }, {
            preserveState: true,
            preserveScroll: true,
            onError: (errs) => {
                setError(errs.generation || errs.numero_factura || 'Error al generar el certificado.');
                setShowConfirmation(false);
                submittingRef.current = false;
                setProcessing(false);
            },
        });
    };
    const canContinue = numeroFactura.length === 6 && !processing;

    return (
        <div className="modal-backdrop" role="presentation" onMouseDown={(event) => event.target === event.currentTarget && !processing && (showConfirmation ? setShowConfirmation(false) : onClose())}>
            <section className="debt-modal" role="dialog" aria-modal="true" aria-labelledby="invoice-modal-title">
                <button className="close-button" onClick={showConfirmation ? handleBack : onClose} aria-label={showConfirmation ? 'Volver al número de factura' : 'Cerrar'} disabled={processing}><X /></button>
                {!showConfirmation ? (
                    <>
                        <div className="warning-icon"><FileText /></div>
                        <h2 id="invoice-modal-title">Número de factura</h2>
                        <p>Ingrese el número de factura del Paz y Salvo para continuar con la generación.</p>

                        <div className="form-group" style={{ marginTop: 20, textAlign: 'left' }}>
                            <label htmlFor="numero-factura">Número de factura</label>
                            <input
                                ref={inputRef}
                                id="numero-factura"
                                type="text"
                                inputMode="numeric"
                                pattern="[0-9]*"
                                placeholder="000000"
                                maxLength={6}
                                value={numeroFactura}
                                onChange={handleInputChange}
                                disabled={processing}
                                autoFocus
                            />
                            <p className="field-help">Ingrese un número de factura de exactamente 6 dígitos.</p>
                            {error && <p className="field-error" role="alert">{error}</p>}
                        </div>

                        <div className="modal-actions">
                            <button className="secondary-button" onClick={onClose} disabled={processing}>Cancelar</button>
                            <button className="primary-button" onClick={handleContinue} disabled={!canContinue}>
                                Continuar
                            </button>
                        </div>
                    </>
                ) : (
                    <>
                        <div className="warning-icon"><AlertTriangle /></div>
                        <h2 id="invoice-modal-title">Confirmar número de factura</h2>
                        <p className="invoice-confirm-question">
                            ¿SEGURO QUE DESEAS GUARDAR EL NÚMERO <strong>"{numeroFacturaConfirmado}"</strong> DE FACTURA?
                        </p>
                        <p>Verifique cuidadosamente el número antes de continuar. Este dato quedará asociado al Paz y Salvo.</p>
                        {error && <p className="field-error" role="alert">{error}</p>}

                        <div className="modal-actions">
                            <button className="secondary-button" onClick={handleBack} disabled={processing}>Volver</button>
                            <button ref={confirmButtonRef} className="primary-button" onClick={handleGenerate} disabled={processing}>
                                {processing ? <><Loader2 className="animate-spin" /> Generando Paz y Salvo…</> : 'Confirmar y generar'}
                            </button>
                        </div>
                    </>
                )}
            </section>
        </div>
    );
}
