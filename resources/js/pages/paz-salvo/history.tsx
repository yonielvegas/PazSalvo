import { Head, Link, router } from '@inertiajs/react';
import { Eye, Filter } from 'lucide-react';
import { AppLayout } from '@/components/app-layout';

type Doc = { id:number; folio:string; numero_factura:string|null; client_number:string; holder_name:string; agency_name:string; generated_by_name:string; issued_at:string; expires_at:string; status:string; effective_status:string };
type Page<T> = { data:T[]; links:{url:string|null;label:string;active:boolean}[]; from:number|null; to:number|null; total:number };

export default function History({ documents, filters, agencies, users }: { documents:Page<Doc>; filters:Record<string,string>; agencies:{id:number;name:string}[]; users:{id:number;name:string}[] }) {
    const submit = (event: React.FormEvent<HTMLFormElement>) => { event.preventDefault(); router.get('/paz-salvos', Object.fromEntries(new FormData(event.currentTarget) as never), { preserveState:true }); };
    const paginationLabel = (label: string) => {
        if (label.includes('previous')) return '← Anterior';
        if (label.includes('next')) return 'Siguiente →';
        return label.replace('&laquo;', '←').replace('&raquo;', '→');
    };

    return <AppLayout><Head title="Historial" /><section className="page-heading"><p className="eyebrow">DOCUMENTOS OFICIALES</p><h1>Historial de certificados</h1><p>{documents.total} registros encontrados</p></section>
        <form className="filter-card" onSubmit={submit}><input className="filter-search" name="search" defaultValue={filters.search} placeholder="NAC, folio o nombre" />
            <select name="agency_id" defaultValue={filters.agency_id || ''}><option value="">Todas las agencias</option>{agencies.map(a=><option key={a.id} value={a.id}>{a.name}</option>)}</select>
            <select name="generated_by" defaultValue={filters.generated_by || ''}><option value="">Todos los usuarios</option>{users.map(u=><option key={u.id} value={u.id}>{u.name}</option>)}</select>
            <select name="status" defaultValue={filters.status || ''}><option value="">Todos los estados</option><option value="generated">Generado</option><option value="cancelled">Anulado</option><option value="error">Error</option></select>
            <input type="date" name="from" defaultValue={filters.from} /><input type="date" name="to" defaultValue={filters.to} /><div className="filter-actions"><button><Filter /> Filtrar</button><Link href="/paz-salvos">Limpiar</Link></div>
        </form>
        <div className="table-card"><table><thead><tr><th>Folio</th><th>N° Factura</th><th>NAC / Cliente</th><th>Agencia</th><th>Elaborado por</th><th>Emisión</th><th>Estado</th><th /></tr></thead><tbody>{documents.data.map(d=><tr key={d.id}><td><b>{d.folio}</b></td><td>{d.numero_factura || '\u2014'}</td><td>{d.client_number}<small>{d.holder_name}</small></td><td>{d.agency_name}</td><td>{d.generated_by_name}</td><td>{new Date(d.issued_at).toLocaleString('es-PA')}</td><td><span className={`status ${d.effective_status}`}>{d.effective_status}</span></td><td><Link className="icon-link" href={`/paz-salvos/${d.id}`}><Eye /></Link></td></tr>)}</tbody></table>{!documents.data.length&&<p className="empty">No hay certificados para estos filtros.</p>}</div>
        <div className="pagination">{documents.links.map((l,i)=>l.url?<Link key={i} href={l.url} className={l.active?'active':''}>{paginationLabel(l.label)}</Link>:<span key={i}>{paginationLabel(l.label)}</span>)}</div>
    </AppLayout>;
}
