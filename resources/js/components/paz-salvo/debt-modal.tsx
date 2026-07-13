import { AlertTriangle, X } from 'lucide-react';
import { useEffect } from 'react';
import type { QueryResult } from '@/types/paz-salvo';

const money = (value: number) => new Intl.NumberFormat('es-PA', { style: 'currency', currency: 'PAB' }).format(value);

export function DebtModal({ result, onClose }: { result: QueryResult; onClose: () => void }) {
    useEffect(() => {
        const close = (event: KeyboardEvent) => event.key === 'Escape' && onClose();
        document.addEventListener('keydown', close);
        return () => document.removeEventListener('keydown', close);
    }, [onClose]);
    const aseo_debts = result.debts.filter((debt) => debt.amount > 0 && debt.document_type?.toLowerCase().includes('aseo'));
    return (
        <div className="modal-backdrop" role="presentation" onMouseDown={(event) => event.target === event.currentTarget && onClose()}>
            <section className="debt-modal" role="dialog" aria-modal="true" aria-labelledby="debt-title">
                <button className="close-button" onClick={onClose} aria-label="Cerrar"><X /></button>
                <div className="danger-icon"><AlertTriangle /></div>
                <h2 id="debt-title">No está paz y salvo</h2>
                <p>El cliente mantiene saldo pendiente de Aseo.</p>
                <p>No se puede generar paz y salvo de la Autoridad de Aseo.</p>
                <strong className="debt-total">{money(result.balances.aseo_balance)}</strong>
                {aseo_debts.length > 0 && <div className="debt-list">{aseo_debts.map((debt, index) => <div key={index}><span>{debt.document_type || debt.period || 'Saldo pendiente'}</span><b>{money(debt.amount)}</b></div>)}</div>}
                <button className="secondary-button" onClick={onClose}>Cerrar</button>
            </section>
        </div>
    );
}
