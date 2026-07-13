import { Head, Link, usePage } from '@inertiajs/react';
import { Copy, Download, FileText } from 'lucide-react';
import { AppLayout } from '@/components/app-layout';

type Doc = { id:number; folio:string; numero_factura:string|null; verification_token:string; status:string; effective_status:string; client_number:string; holder_name:string; full_address:string; total_balance:string; issued_at:string; expires_at:string; agency_name:string; generated_by_name:string; authorized_by_name:string|null; cancelled_at:string|null; cancel_reason:string|null; cancelled_by?:{name:string}|null };

export default function Show({ document:d }: {document:Doc}) {
    const { flash } = usePage<{flash:{message?:string}}>().props;
    const publicUrl = `${window.location.origin}/verificar/${d.verification_token}`;

    return <AppLayout><Head title={d.folio} />
        <div className="mx-auto flex w-full max-w-6xl flex-col gap-6 px-4 py-2 sm:px-6 lg:px-8">
            <section className="rounded-[28px] border border-[#dbe7e4] bg-white/95 p-6 shadow-[0_24px_60px_rgba(22,68,61,0.08)] sm:p-8">
                <div className="flex flex-col gap-6 xl:flex-row xl:items-start xl:justify-between">
                    <div className="max-w-3xl">
                        <p className="eyebrow">CERTIFICADO OFICIAL</p>
                        <h1 className="mt-2 text-4xl font-medium tracking-[-0.04em] text-[#173d38] sm:text-5xl">{d.folio}</h1>
                        <div className="mt-4 flex flex-wrap items-center gap-3">
                            <span className={`status ${d.effective_status}`}>{d.effective_status}</span>
                            <span className="rounded-full border border-[#dbe7e4] bg-[#f8faf9] px-3 py-1 text-xs font-semibold uppercase tracking-[0.16em] text-[#5f716d]">NAC {d.client_number}</span>
                        </div>
                    </div>

                    <div className="flex w-full justify-start xl:w-auto xl:justify-end">
                        <div className="flex h-20 w-full items-center justify-center rounded-2xl border border-[#dbe7e4] bg-[#f8faf9] px-6 shadow-sm sm:h-24 sm:px-8 xl:w-[220px]">
                            <img src="/Img/AAUD.jpg" alt="Logo AAUD" className="h-16 w-auto object-contain sm:h-20" />
                        </div>
                    </div>
                </div>
            </section>

            {flash.message && <div className="w-full rounded-2xl border border-[#bce8cd] bg-[#eaf9f0] px-5 py-4 text-sm font-semibold text-[#126c39] shadow-sm">{flash.message}</div>}

            <section className="grid gap-6 xl:grid-cols-[minmax(0,2fr)_minmax(320px,1fr)]">
                <div className="rounded-[28px] border border-[#dbe7e4] bg-white p-6 shadow-sm sm:p-8">
                    <div className="flex flex-col gap-2 border-b border-[#e7efec] pb-5">
                        <h2 className="text-2xl font-semibold tracking-[-0.03em] text-[#173d38]">Datos del certificado</h2>
                        <p className="text-sm text-[#667773]">Resumen oficial del paz y salvo emitido.</p>
                    </div>

                    <div className="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
                        <div className="rounded-2xl border border-[#e3ece9] bg-[#f8faf9] p-4"><dt className="text-xs font-semibold uppercase tracking-[0.14em] text-[#70817d]">NAC</dt><dd className="mt-2 text-sm font-semibold text-[#173d38]">{d.client_number}</dd></div>
                        <div className="rounded-2xl border border-[#e3ece9] bg-[#f8faf9] p-4"><dt className="text-xs font-semibold uppercase tracking-[0.14em] text-[#70817d]">N° Factura</dt><dd className="mt-2 text-sm font-semibold text-[#173d38]">{d.numero_factura || '\u2014'}</dd></div>
                        <div className="rounded-2xl border border-[#e3ece9] bg-[#f8faf9] p-4"><dt className="text-xs font-semibold uppercase tracking-[0.14em] text-[#70817d]">Cliente</dt><dd className="mt-2 text-sm font-semibold text-[#173d38]">{d.holder_name}</dd></div>
                        <div className="rounded-2xl border border-[#e3ece9] bg-[#f8faf9] p-4"><dt className="text-xs font-semibold uppercase tracking-[0.14em] text-[#70817d]">Estado</dt><dd className="mt-2"><span className={`status ${d.effective_status}`}>{d.effective_status}</span></dd></div>
                        <div className="rounded-2xl border border-[#e3ece9] bg-[#f8faf9] p-4 sm:col-span-2 xl:col-span-3"><dt className="text-xs font-semibold uppercase tracking-[0.14em] text-[#70817d]">Direccion</dt><dd className="mt-2 text-sm font-semibold text-[#173d38]">{d.full_address}</dd></div>
                        <div className="rounded-2xl border border-[#e3ece9] bg-[#f8faf9] p-4"><dt className="text-xs font-semibold uppercase tracking-[0.14em] text-[#70817d]">Balance</dt><dd className="mt-2 text-sm font-semibold text-[#173d38]">B/. {Number(d.total_balance).toFixed(2)}</dd></div>
                        <div className="rounded-2xl border border-[#e3ece9] bg-[#f8faf9] p-4"><dt className="text-xs font-semibold uppercase tracking-[0.14em] text-[#70817d]">Agencia</dt><dd className="mt-2 text-sm font-semibold text-[#173d38]">{d.agency_name}</dd></div>
                        <div className="rounded-2xl border border-[#e3ece9] bg-[#f8faf9] p-4"><dt className="text-xs font-semibold uppercase tracking-[0.14em] text-[#70817d]">Elaborado por</dt><dd className="mt-2 text-sm font-semibold text-[#173d38]">{d.generated_by_name}</dd></div>
                        
                        <div className="rounded-2xl border border-[#e3ece9] bg-[#f8faf9] p-4"><dt className="text-xs font-semibold uppercase tracking-[0.14em] text-[#70817d]">Autorizado por</dt><dd className="mt-2 text-sm font-semibold text-[#173d38]">{d.authorized_by_name || 'No configurado'}</dd></div>
                        <div className="rounded-2xl border border-[#e3ece9] bg-[#f8faf9] p-4"><dt className="text-xs font-semibold uppercase tracking-[0.14em] text-[#70817d]">Fecha de emision</dt><dd className="mt-2 text-sm font-semibold text-[#173d38]">{new Date(d.issued_at).toLocaleString('es-PA')}</dd></div>
                        <div className="rounded-2xl border border-[#e3ece9] bg-[#f8faf9] p-4"><dt className="text-xs font-semibold uppercase tracking-[0.14em] text-[#70817d]">Fecha de expiracion</dt><dd className="mt-2 text-sm font-semibold text-[#173d38]">{new Date(d.expires_at).toLocaleDateString('es-PA')}</dd></div>
                    </div>
                </div>

                <div className="flex flex-col gap-6">
                    <div className="rounded-[28px] border border-[#dbe7e4] bg-white p-6 shadow-sm sm:p-8">
                        <h2 className="text-xl font-semibold tracking-[-0.03em] text-[#173d38]">Acciones rapidas</h2>
                        <p className="mt-2 text-sm text-[#667773]">Abre, descarga o comparte la validacion publica del documento.</p>
                        <div className="mt-5 flex flex-col gap-3">
                            {d.status !== 'error' && <>
                                <a className="inline-flex min-h-12 items-center justify-center gap-2 rounded-xl bg-[#0f766e] px-5 py-3 text-sm font-bold text-white shadow-sm transition hover:-translate-y-0.5 hover:bg-[#0b625c]" href={`/paz-salvos/${d.id}/pdf`} target="_blank" rel="noreferrer"><FileText className="h-4 w-4" /> Ver PDF</a>
                                <a className="inline-flex min-h-12 items-center justify-center gap-2 rounded-xl bg-[#134e4a] px-5 py-3 text-sm font-bold text-white shadow-sm transition hover:-translate-y-0.5 hover:bg-[#0f3f3b]" href={`/paz-salvos/${d.id}/download`}><Download className="h-4 w-4" /> Descargar PDF</a>
                            </>}
                            <button className="min-h-12 rounded-xl border border-[#b9cbc7] bg-white px-5 py-3 text-sm font-bold text-[#173d38] shadow-sm transition hover:-translate-y-0.5 hover:border-[#0f766e] hover:bg-[#f3fbf8]" onClick={() => navigator.clipboard.writeText(publicUrl)}><Copy className="h-4 w-4" /> Copiar validacion</button>
                        </div>
                    </div>

                    {d.status === 'cancelled' && <div className="rounded-[28px] border border-[#fecaca] bg-[#fff7f7] p-6 shadow-sm sm:p-8">
                        <h2 className="text-xl font-semibold tracking-[-0.03em] text-[#991b1b]">Certificado anulado</h2>
                        <div className="mt-5 grid gap-4 sm:grid-cols-2">
                            <div className="rounded-2xl border border-[#fde2e2] bg-white/70 p-4"><dt className="text-xs font-semibold uppercase tracking-[0.14em] text-[#b45353]">Fecha</dt><dd className="mt-2 text-sm font-semibold text-[#7f1d1d]">{d.cancelled_at && new Date(d.cancelled_at).toLocaleString('es-PA')}</dd></div>
                            <div className="rounded-2xl border border-[#fde2e2] bg-white/70 p-4"><dt className="text-xs font-semibold uppercase tracking-[0.14em] text-[#b45353]">Usuario</dt><dd className="mt-2 text-sm font-semibold text-[#7f1d1d]">{d.cancelled_by?.name || 'No disponible'}</dd></div>
                            <div className="rounded-2xl border border-[#fde2e2] bg-white/70 p-4 sm:col-span-2"><dt className="text-xs font-semibold uppercase tracking-[0.14em] text-[#b45353]">Motivo</dt><dd className="mt-2 text-sm font-semibold text-[#7f1d1d]">{d.cancel_reason || 'No disponible'}</dd></div>
                        </div>
                    </div>}
                </div>
            </section>

            <div className="flex justify-start">
                <Link href="/paz-salvos" className="inline-flex items-center rounded-full border border-[#dbe7e4] bg-white px-4 py-2 text-sm font-semibold text-[#0f766e] shadow-sm transition hover:-translate-y-0.5 hover:bg-[#f5fbfa]">← Volver al historial</Link>
            </div>
        </div>
    </AppLayout>;
}
