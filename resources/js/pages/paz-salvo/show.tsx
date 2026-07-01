import { Head, Link, usePage } from '@inertiajs/react';
import { Copy, Download, FileText } from 'lucide-react';
import { AppLayout } from '@/components/app-layout';

type Doc = { id:number; folio:string; verification_token:string; status:string; effective_status:string; client_number:string; holder_name:string; full_address:string; total_balance:string; issued_at:string; expires_at:string; agency_name_snapshot:string; generated_by_name_snapshot:string; authorized_by_name:string; cancelled_at:string|null; cancel_reason:string|null; cancelled_by?:{name:string}|null; certificate_snapshot:Record<string,unknown> };
export default function Show({ document:d }: {document:Doc}) {
    const { flash } = usePage<{flash:{message?:string}}>().props;
    const publicUrl = `${window.location.origin}/verificar/${d.verification_token}`;
    return <AppLayout><Head title={d.folio}/><section className="detail-heading"><div><p className="eyebrow">CERTIFICADO OFICIAL</p><h1>{d.folio}</h1><span className={`status ${d.effective_status}`}>{d.effective_status}</span></div><div className="detail-actions">{d.status!=='error'&&<><a href={`/paz-salvos/${d.id}/pdf`} target="_blank"><FileText/> Ver PDF</a><a href={`/paz-salvos/${d.id}/download`}><Download/> Descargar</a></>}<button onClick={()=>navigator.clipboard.writeText(publicUrl)}><Copy/> Copiar validación</button></div></section>
        {flash.message&&<div className="notice success">{flash.message}</div>}
        <section className="detail-grid"><div className="panel"><h2>Datos del certificado</h2><dl><div><dt>NAC</dt><dd>{d.client_number}</dd></div><div><dt>Cliente</dt><dd>{d.holder_name}</dd></div><div><dt>Dirección</dt><dd>{d.full_address}</dd></div><div><dt>Balance</dt><dd>B/. {Number(d.total_balance).toFixed(2)}</dd></div><div><dt>Agencia</dt><dd>{d.agency_name_snapshot}</dd></div><div><dt>Elaborado por</dt><dd>{d.generated_by_name_snapshot}</dd></div><div><dt>Emisión</dt><dd>{new Date(d.issued_at).toLocaleString('es-PA')}</dd></div><div><dt>Expiración</dt><dd>{new Date(d.expires_at).toLocaleDateString('es-PA')}</dd></div></dl></div>
        {d.status==='cancelled'&&<div className="panel cancelled-panel"><h2>Certificado anulado</h2><p><b>Fecha:</b> {d.cancelled_at&&new Date(d.cancelled_at).toLocaleString('es-PA')}</p><p><b>Usuario:</b> {d.cancelled_by?.name}</p><p><b>Motivo:</b> {d.cancel_reason}</p></div>}</section>
        <Link href="/paz-salvos" className="back-link">← Volver al historial</Link>
    </AppLayout>;
}
