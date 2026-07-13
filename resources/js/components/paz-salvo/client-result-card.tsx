import { CheckCircle2, FileText } from 'lucide-react';
import type { QueryResult } from '@/types/paz-salvo';

export function ClientResultCard({ result, onGenerate }: { result: QueryResult; onGenerate: () => void }) {
    const location = [result.city, result.address].filter((value) => value?.trim()).join(' - ');

    return (
        <section className="result-card">
            <div className="success-heading"><CheckCircle2 /><div><span>Estado</span><h2>Paz y salvo</h2></div></div>
            <dl>
                <div><dt>NAC</dt><dd>{result.client_number}</dd></div>
                <div><dt>Titular</dt><dd>{result.holder_name || 'No informado'}</dd></div>
                <div><dt>Dirección</dt><dd>{location || 'No informada'}</dd></div>
                <div><dt>Tarifa</dt><dd>{result.rate || 'No informada'}</dd></div>
            </dl>
            <button type="button" className="generate-button" onClick={onGenerate}><FileText /> Generar certificado oficial</button>
        </section>
    );
}
