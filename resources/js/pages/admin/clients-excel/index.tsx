import { Head, router, usePage } from '@inertiajs/react';
import { Download, FileSpreadsheet, Loader2, Trash2, UploadCloud, X } from 'lucide-react';
import { useRef, useState } from 'react';
import { AppLayout } from '@/components/app-layout';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';

type ExcelFile = { id: number; original_name: string; size: number; uploaded_by: string; date: string; time: string; is_current: boolean; will_be_replaced: boolean };
type Flash = { message?: string; error?: string };

const sizeLabel = (bytes: number) => `${(bytes / 1024 / 1024).toFixed(2)} MB`;

export default function ClientExcelIndex({ files, maxSizeMb }: { files: ExcelFile[]; maxSizeMb: number }) {
    const { auth, flash } = usePage<{ auth: { user: { permissions: string[] } }; flash: Flash }>().props;
    const [selected, setSelected] = useState<File | null>(null);
    const [error, setError] = useState('');
    const [busy, setBusy] = useState(false);
    const [deleting, setDeleting] = useState<ExcelFile | null>(null);
    const [dragging, setDragging] = useState(false);
    const input = useRef<HTMLInputElement>(null);
    const canDownload = auth.user.permissions.includes('clients-excel.download');
    const canDelete = auth.user.permissions.includes('clients-excel.delete');

    const choose = (file?: File) => {
        setError('');
        if (!file) return;
        if (!/\.(xlsx|xls)$/i.test(file.name) || file.size > maxSizeMb * 1024 * 1024) {
            setSelected(null);
            setError(`Seleccione un archivo .xlsx o .xls de hasta ${maxSizeMb} MB.`);
            return;
        }
        setSelected(file);
    };

    const upload = () => {
        if (!selected || busy) return;
        const data = new FormData();
        data.append('file', selected);
        setBusy(true);
        setError('');
        router.post('/admin/clients-excel', data, {
            preserveScroll: true,
            onSuccess: () => { setSelected(null); if (input.current) input.current.value = ''; },
            onError: (errors) => setError(errors.file || 'No se pudo cargar el archivo.'),
            onFinish: () => setBusy(false),
        });
    };

    const remove = () => {
        if (!deleting || busy) return;
        setBusy(true);
        router.delete(`/admin/clients-excel/${deleting.id}`, {
            preserveScroll: true,
            onSuccess: () => setDeleting(null),
            onFinish: () => setBusy(false),
        });
    };

    return <AppLayout><Head title="Excel de Clientes" />
        <section className="page-heading"><p className="eyebrow">ADMINISTRACIÓN</p><h1>Excel de Clientes</h1><p>Administre los archivos usados para consultar clientes.</p></section>
        {flash.message && <div className="notice success" role="status">{flash.message}</div>}
        {flash.error && <div className="notice error" role="alert">{flash.error}</div>}
        <section className="panel client-excel-panel" aria-labelledby="excel-upload-title">
            <h2 id="excel-upload-title">Cargar Excel</h2><p>Se conservan los tres archivos más recientes. Tamaño máximo: {maxSizeMb} MB.</p>
            <input ref={input} id="client-excel-input" className="client-excel-input" type="file" accept=".xlsx,.xls" onChange={(event) => choose(event.target.files?.[0])} disabled={busy} />
            <div className={`client-excel-drop ${dragging ? 'is-dragging' : ''}`} role="button" tabIndex={0}
                onClick={() => input.current?.click()}
                onKeyDown={(event) => { if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); input.current?.click(); } }}
                onDragOver={(event) => { event.preventDefault(); setDragging(true); }}
                onDragLeave={() => setDragging(false)}
                onDrop={(event) => { event.preventDefault(); setDragging(false); choose(event.dataTransfer.files[0]); }}>
                <UploadCloud size={30} /><strong>Arrastre un Excel aquí o haga clic para buscarlo</strong><span>Formatos .xlsx y .xls</span>
            </div>
            {selected && <div className="client-excel-selected"><FileSpreadsheet size={20} /><span title={selected.name}><strong>{selected.name}</strong><small>{sizeLabel(selected.size)}</small></span><button type="button" className="btn-secondary" onClick={() => input.current?.click()} disabled={busy}>Reemplazar</button><button type="button" className="icon-btn" aria-label="Cancelar selección" onClick={() => { setSelected(null); if (input.current) input.current.value = ''; }} disabled={busy}><X size={18} /></button></div>}
            {error && <p className="field-error" role="alert">{error}</p>}
            {files.length === 3 && <div className="client-excel-warning"><strong>Próximo reemplazo</strong><p>Al cargar un nuevo Excel se eliminará automáticamente <b title={files[2].original_name}>{files[2].original_name}</b>, subido por {files[2].uploaded_by} el {files[2].date} a las {files[2].time}.</p></div>}
            <button type="button" onClick={upload} disabled={!selected || busy}>{busy && !deleting ? <Loader2 className="animate-spin" size={17} /> : <UploadCloud size={17} />}{busy && !deleting ? 'Cargando…' : 'Subir archivo'}</button>
        </section>
        <section className="client-excel-history" aria-labelledby="excel-history-title"><div className="client-excel-history-heading"><h2 id="excel-history-title">Historial de archivos</h2><span>{files.length} de 3</span></div>
            {files.length === 0 ? <div className="history-empty-state"><FileSpreadsheet /><h2>No hay archivos cargados</h2><p>Los archivos Excel cargados aparecerán aquí.</p></div> :
                <div className="client-excel-list">{files.map((file) => <article className="panel client-excel-row" key={file.id}>
                    <div className="client-excel-file-icon"><FileSpreadsheet /></div>
                    <div className="client-excel-file-info"><strong title={file.original_name}>{file.original_name}</strong><span>Subido por: {file.uploaded_by} · Fecha: {file.date} · Hora: {file.time} · {sizeLabel(file.size)}</span><div className="client-excel-badges">{file.is_current && <span className="client-excel-current">Actual</span>}{file.will_be_replaced && <span className="client-excel-next">Será reemplazado con la próxima carga</span>}</div></div>
                    <div className="client-excel-actions">{canDownload && <a className="btn-secondary" href={`/admin/clients-excel/${file.id}/download`} download><Download size={16} /> Descargar</a>}{canDelete && <button type="button" className="danger-button" onClick={() => setDeleting(file)}><Trash2 size={16} /> Eliminar</button>}</div>
                </article>)}</div>}
        </section>
        <ConfirmDialog open={deleting !== null} onClose={() => setDeleting(null)} onConfirm={remove} title="¿Eliminar archivo?" message={<>Está por eliminar <strong>{deleting?.original_name}</strong>. Esta acción eliminará tanto el registro como el archivo almacenado.</>} confirmLabel="Eliminar" processing={busy} />
    </AppLayout>;
}
