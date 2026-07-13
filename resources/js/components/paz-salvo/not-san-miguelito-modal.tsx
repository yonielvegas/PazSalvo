import { MapPin, X } from 'lucide-react';
import { useEffect } from 'react';
import type { QueryResult } from '@/types/paz-salvo';

const FALLBACK_MESSAGE = 'El cliente consultado no pertenece al distrito de San Miguelito, por lo tanto no se puede emitir un Paz y Salvo de la Autoridad de Aseo para este NAC.';

export function NotSanMiguelitoModal({ result, onClose }: { result: QueryResult; onClose: () => void }) {
    useEffect(() => {
        const close = (event: KeyboardEvent) => event.key === 'Escape' && onClose();
        document.addEventListener('keydown', close);
        return () => document.removeEventListener('keydown', close);
    }, [onClose]);

    return (
        <div className="modal-backdrop" role="presentation" onMouseDown={(event) => event.target === event.currentTarget && onClose()}>
            <section className="debt-modal" role="dialog" aria-modal="true" aria-labelledby="not-sm-title">
                <button className="close-button" onClick={onClose} aria-label="Cerrar"><X /></button>
                <div className="info-icon"><MapPin /></div>
                <h2 id="not-sm-title">Cliente fuera de cobertura</h2>
                <p className="sm-description">{result.san_miguelito_validation?.message ?? FALLBACK_MESSAGE}</p>
                {result.san_miguelito_validation?.received_city && (
                    <div className="sm-city-box">
                        <span className="sm-city-label">Corregimiento reportado por ENSA</span>
                        <strong className="sm-city-value">{result.san_miguelito_validation.received_city}</strong>
                    </div>
                )}
                <p className="sm-footer">Verifique el número de cliente/NAC e intente nuevamente.</p>
                <button className="secondary-button" onClick={onClose}>Cerrar</button>
            </section>
        </div>
    );
}
