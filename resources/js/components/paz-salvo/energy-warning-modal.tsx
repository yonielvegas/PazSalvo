import { AlertTriangle, X } from 'lucide-react';
import { useEffect } from 'react';
import type { QueryResult } from '@/types/paz-salvo';

const money = (value: number) => new Intl.NumberFormat('es-PA', { style: 'currency', currency: 'PAB' }).format(value);

export function EnergyWarningModal({ result, onContinue, onClose }: { result: QueryResult; onContinue: () => void; onClose: () => void }) {
    useEffect(() => {
        const close = (event: KeyboardEvent) => event.key === 'Escape' && onClose();
        document.addEventListener('keydown', close);
        return () => document.removeEventListener('keydown', close);
    }, [onClose]);

    return (
        <div className="modal-backdrop" role="presentation" onMouseDown={(event) => event.target === event.currentTarget && onClose()}>
            <section className="debt-modal energy-warning" role="dialog" aria-modal="true" aria-labelledby="energy-warning-title">
                <button className="close-button" onClick={onClose} aria-label="Cerrar"><X /></button>
                <div className="warning-icon"><AlertTriangle /></div>
                <h2 id="energy-warning-title">Saldo pendiente de energía</h2>
                <p>El cliente está paz y salvo con Aseo, pero mantiene saldo pendiente con ENSA por energía.</p>
                <p className="disclaimer">Este saldo no bloquea la generación del paz y salvo de la Autoridad de Aseo.</p>

                <div className="debt-list">
                    <div><span>Balance Aseo</span><b className="positive">{money(result.balances.aseo_balance)}</b></div>
                    <div><span>Balance Energía</span><b className="warning">{money(result.balances.energy_balance)}</b></div>
                    <div><span>Total ENSA</span><b>{money(result.balances.total_balance)}</b></div>
                </div>

                {(() => {
                    const energy_debts = result.debts.filter((debt) => debt.amount > 0 && debt.document_type?.toLowerCase().includes('energ'));
                    return energy_debts.length > 0 && (
                        <div className="debt-table-wrapper">
                            <table className="debt-table">
                                <thead>
                                    <tr>
                                        <th>Periodo</th>
                                        <th>Tipo</th>
                                        <th className="amount-col">Monto</th>
                                        <th>Estado</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {energy_debts.map((debt, index) => (
                                        <tr key={index}>
                                            <td>{debt.period || '-'}</td>
                                            <td>{debt.document_type || '-'}</td>
                                            <td className="amount-col">{money(debt.amount)}</td>
                                            <td>{debt.status || '-'}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    );
                })()}

                <div className="modal-actions">
                    <button className="secondary-button" onClick={onClose}>Cancelar</button>
                    <button className="primary-button" onClick={onContinue}>Continuar</button>
                </div>
            </section>
        </div>
    );
}
