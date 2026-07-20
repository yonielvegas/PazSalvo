import { Head, Link, router } from '@inertiajs/react';
import { Eye, Filter, Loader2, SearchX, X } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { AppLayout } from '@/components/app-layout';

type Doc = { id:number; folio:string; numero_factura:string|null; client_number:string; holder_name:string; agency_name:string; generated_by_name:string; issued_at:string; expires_at:string; status:string; effective_status:string };
type Page<T> = { data:T[]; links:{url:string|null;label:string;active:boolean}[]; from:number|null; to:number|null; total:number };
type Filters = { folio?: string; nac?: string; numero_factura?: string; titular?: string; fecha_desde?: string; fecha_hasta?: string };

const emptyFilters: Required<Filters> = { folio: '', nac: '', numero_factura: '', titular: '', fecha_desde: '', fecha_hasta: '' };

export default function History({ documents, filters }: { documents:Page<Doc>; filters:Filters }) {
    const [values, setValues] = useState({ ...emptyFilters, ...filters });
    const [loading, setLoading] = useState(false);

    useEffect(() => setValues({ ...emptyFilters, ...filters }), [filters]);

    const activeFilters = useMemo(() => Object.entries(filters).filter(([, value]) => value !== undefined && value !== ''), [filters]);
    const hasActiveFilters = activeFilters.length > 0;
    const dateRangeError = values.fecha_desde && values.fecha_hasta && values.fecha_desde > values.fecha_hasta
        ? 'La fecha desde no puede ser posterior a la fecha hasta.'
        : '';
    const resultSummary = documents.total === 0
        ? '0 resultados encontrados'
        : documents.from && documents.to
            ? `Mostrando ${documents.from}–${documents.to} de ${documents.total} resultados`
            : `${documents.total} resultados encontrados`;

    const paginationLabel = (label: string) => {
        if (label.includes('previous')) return '← Anterior';
        if (label.includes('next')) return 'Siguiente →';
        return label.replace('&laquo;', '←').replace('&raquo;', '→');
    };

    const setField = (field: keyof Required<Filters>, value: string) => {
        const next = field === 'folio'
            ? value.toUpperCase().slice(0, 30)
            : field === 'nac'
                ? value.replace(/\D/g, '').slice(0, 30)
                : field === 'numero_factura'
                    ? value.replace(/\D/g, '').slice(0, 6)
                    : field === 'titular'
                        ? value.replace(/\s+/g, ' ').slice(0, 150)
                        : value;

        setValues((current) => ({ ...current, [field]: next }));
    };

    const clean = (payload: Filters) => Object.fromEntries(
        Object.entries(payload)
            .map(([key, value]) => [key, typeof value === 'string' ? value.trim().replace(/\s+/g, ' ') : value])
            .filter(([, value]) => value !== undefined && value !== ''),
    );

    const visit = (payload: Filters) => {
        setLoading(true);
        router.get('/paz-salvos', clean(payload), {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onFinish: () => setLoading(false),
        });
    };

    const submit = (event: React.FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        if (dateRangeError || loading) return;
        visit(values);
    };

    const clearAll = () => {
        setValues(emptyFilters);
        visit(emptyFilters);
    };

    const removeFilter = (field: string) => {
        const next = { ...values, [field]: '' };
        setValues(next);
        visit(next);
    };

    const formatChip = (key: string, value: string) => {
        const labels: Record<string, string> = {
            folio: 'Folio',
            nac: 'NAC',
            numero_factura: 'Factura',
            titular: 'Titular',
            fecha_desde: 'Desde',
            fecha_hasta: 'Hasta',
        };

        if (key.startsWith('fecha_')) {
            return `${labels[key]}: ${new Date(`${value}T00:00:00`).toLocaleDateString('es-PA')}`;
        }

        return `${labels[key] ?? key}: ${value}`;
    };

    return (
        <AppLayout>
            <Head title="Historial" />
            <section className="page-heading history-heading">
                <p className="eyebrow">DOCUMENTOS OFICIALES</p>
                <h1>Historial de certificados</h1>
                <p>{hasActiveFilters ? 'Resultados de la búsqueda' : 'Mostrando los Paz y Salvo más recientes'}</p>
            </section>

            <section className="history-filter-panel" aria-labelledby="history-filter-title">
                <div className="history-filter-header">
                    <div>
                        <h2 id="history-filter-title">Filtros de búsqueda</h2>
                        <p>Encuentre documentos utilizando uno o varios criterios específicos.</p>
                    </div>
                    <span aria-live="polite">{resultSummary}</span>
                </div>

                <form onSubmit={submit} className="history-filter-grid" noValidate>
                    <div className="history-filter-field">
                        <label htmlFor="history-folio">Folio</label>
                        <input id="history-folio" value={values.folio} onChange={(event) => setField('folio', event.target.value)} placeholder="CC-000008-2026" maxLength={30} />
                    </div>
                    <div className="history-filter-field">
                        <label htmlFor="history-nac">NAC</label>
                        <input id="history-nac" value={values.nac} onChange={(event) => setField('nac', event.target.value)} inputMode="numeric" pattern="[0-9]*" placeholder="610479" maxLength={30} />
                    </div>
                    <div className="history-filter-field">
                        <label htmlFor="history-invoice">Número de factura</label>
                        <input id="history-invoice" value={values.numero_factura} onChange={(event) => setField('numero_factura', event.target.value)} inputMode="numeric" pattern="[0-9]*" placeholder="000123" maxLength={6} />
                    </div>
                    <div className="history-filter-field history-filter-field-wide">
                        <label htmlFor="history-holder">Nombre del titular</label>
                        <input id="history-holder" value={values.titular} onChange={(event) => setField('titular', event.target.value)} placeholder="Nombre o apellido" maxLength={150} />
                    </div>
                    <div className="history-filter-field">
                        <label htmlFor="history-from">Desde</label>
                        <input id="history-from" type="date" value={values.fecha_desde} onChange={(event) => setField('fecha_desde', event.target.value)} aria-invalid={Boolean(dateRangeError)} aria-describedby={dateRangeError ? 'history-date-error' : undefined} />
                    </div>
                    <div className="history-filter-field">
                        <label htmlFor="history-to">Hasta</label>
                        <input id="history-to" type="date" value={values.fecha_hasta} onChange={(event) => setField('fecha_hasta', event.target.value)} aria-invalid={Boolean(dateRangeError)} aria-describedby={dateRangeError ? 'history-date-error' : undefined} />
                    </div>

                    {dateRangeError && <p id="history-date-error" className="field-error history-filter-error" role="alert">{dateRangeError}</p>}

                    <div className="history-filter-actions">
                        <button type="button" className="btn-secondary" onClick={clearAll} disabled={!hasActiveFilters || loading}>Limpiar filtros</button>
                        <button type="submit" disabled={Boolean(dateRangeError) || loading}>
                            {loading ? <><Loader2 className="animate-spin" /> Buscando…</> : <><Filter /> Aplicar filtros</>}
                        </button>
                    </div>
                </form>

                {hasActiveFilters && (
                    <div className="active-filter-bar" aria-label="Filtros activos">
                        <strong>Filtros activos:</strong>
                        {activeFilters.map(([key, value]) => (
                            <button key={key} type="button" className="active-filter-chip" onClick={() => removeFilter(key)} disabled={loading}>
                                {formatChip(key, String(value))} <X />
                            </button>
                        ))}
                        {activeFilters.length > 1 && <button type="button" className="active-filter-clear" onClick={clearAll} disabled={loading}>Limpiar todos</button>}
                    </div>
                )}
            </section>

            <section className={`history-results ${loading ? 'is-loading' : ''}`} aria-live="polite" aria-busy={loading}>
                {loading && (
                    <div className="history-skeleton" aria-hidden="true">
                        {Array.from({ length: 5 }).map((_, row) => <span key={row} />)}
                    </div>
                )}
                <div className="history-result-summary">{resultSummary}</div>
                <div className="table-card history-table-card">
                    <table>
                        <thead><tr><th>Folio</th><th>N° Factura</th><th>NAC / Cliente</th><th>Agencia</th><th>Elaborado por</th><th>Emisión</th><th>Estado</th><th /></tr></thead>
                        <tbody>{documents.data.map((d) => <tr key={d.id}><td><b>{d.folio}</b></td><td>{d.numero_factura || '\u2014'}</td><td>{d.client_number}<small>{d.holder_name}</small></td><td>{d.agency_name}</td><td>{d.generated_by_name}</td><td>{new Date(d.issued_at).toLocaleString('es-PA')}</td><td><span className={`status ${d.effective_status}`}>{d.effective_status}</span></td><td><Link className="icon-link" href={`/paz-salvos/${d.id}`}><Eye /></Link></td></tr>)}</tbody>
                    </table>
                </div>
                {!documents.data.length && (
                    <div className="history-empty-state">
                        <SearchX />
                        <h2>No se encontraron Paz y Salvo</h2>
                        <p>Revise los filtros ingresados o elimine algunos criterios para ampliar la búsqueda.</p>
                        <button type="button" onClick={clearAll} disabled={!hasActiveFilters || loading}>Limpiar filtros</button>
                    </div>
                )}
            </section>

            {documents.links.length > 3 && <div className="pagination">{documents.links.map((l, i) => l.url ? <Link key={i} href={l.url} preserveState preserveScroll onClick={() => setLoading(true)} className={l.active ? 'active' : ''}>{paginationLabel(l.label)}</Link> : <span key={i}>{paginationLabel(l.label)}</span>)}</div>}
        </AppLayout>
    );
}
