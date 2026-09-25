import { Head, Link, router, usePage } from '@inertiajs/react';
import { Building2, Pencil, Plus, Search, UserCheck, UserX } from 'lucide-react';
import { useState } from 'react';
import { AppLayout } from '@/components/app-layout';
import { Modal } from '@/components/ui/modal';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';

type Agency = { id: number; code: string; name: string; address: string | null; is_active: boolean; users_count: number; created_at: string };
type Form = { code: string; name: string; address: string };
const blank: Form = { code: '', name: '', address: '' };

export default function AgenciesIndex({ agencies, filters }: { agencies: Agency[]; filters: { search: string; status: string } }) {
    const { auth, flash } = usePage<{ auth: { user: { permissions: string[] } }; flash: { message?: string } }>().props;
    const can = (permission: string) => auth.user.permissions.includes(permission);
    const [search, setSearch] = useState(filters.search);
    const [status, setStatus] = useState(filters.status);
    const [editing, setEditing] = useState<Agency | 'new' | null>(null);
    const [form, setForm] = useState<Form>(blank);
    const [toggling, setToggling] = useState<Agency | null>(null);
    const [processing, setProcessing] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});

    const filter = (nextSearch = search, nextStatus = status) => router.get('/settings/agencies', { search: nextSearch, status: nextStatus }, { preserveState: true, replace: true });
    const open = (agency?: Agency) => { setEditing(agency ?? 'new'); setForm(agency ? { code: agency.code, name: agency.name, address: agency.address ?? '' } : blank); setErrors({}); };
    const save = (event: React.FormEvent) => {
        event.preventDefault(); setProcessing(true);
        const options = { preserveScroll: true, onSuccess: () => setEditing(null), onError: (value: Record<string, string>) => setErrors(value), onFinish: () => setProcessing(false) };
        if (editing === 'new') router.post('/settings/agencies', form, options);
        else if (editing) router.put(`/settings/agencies/${editing.id}`, form, options);
    };
    const toggle = () => {
        if (!toggling) return;
        setProcessing(true);
        router.patch(`/settings/agencies/${toggling.id}/toggle`, {}, { preserveScroll: true, onSuccess: () => setToggling(null), onFinish: () => setProcessing(false) });
    };

    return <AppLayout><Head title="Agencias" />
        <nav className="settings-breadcrumb" aria-label="Ruta"><Link href="/settings">Configuración</Link><span>›</span>Agencias</nav>
        <section className="page-heading settings-heading"><div><p className="eyebrow">CONFIGURACIÓN</p><h1>Agencias</h1><p>Administre las agencias disponibles en el sistema.</p></div>{can('settings.agencies.create') && <button onClick={() => open()}><Plus size={17} /> Crear Agencia</button>}</section>
        {flash.message && <div className="notice success" role="status">{flash.message}</div>}
        <section className="panel settings-filters"><form onSubmit={(event) => { event.preventDefault(); filter(); }}><label htmlFor="agency-search">Buscar por código o nombre</label><div className="settings-search-row"><input id="agency-search" value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Buscar agencia" /><button><Search size={16} /> Buscar</button></div></form><div><label htmlFor="agency-status">Estado</label><select id="agency-status" value={status} onChange={(event) => { setStatus(event.target.value); filter(search, event.target.value); }}><option value="all">Todas</option><option value="active">Activas</option><option value="inactive">Inactivas</option></select></div></section>
        <section className="table-card settings-table"><table><thead><tr><th>Código</th><th>Nombre</th><th>Estado</th><th>Creada</th><th>Usuarios</th><th>Acciones</th></tr></thead><tbody>{agencies.map((agency) => <tr key={agency.id} data-agency-id={agency.id}><td><b>{agency.code}</b></td><td>{agency.name}</td><td><span className={`settings-state ${agency.is_active ? 'active' : ''}`}>{agency.is_active ? 'Activa' : 'Inactiva'}</span></td><td>{agency.created_at}</td><td>{agency.users_count}</td><td><div className="settings-actions">{can('settings.agencies.update') && <button className="btn-secondary" onClick={() => open(agency)}><Pencil size={15} /> Editar</button>}{can('settings.agencies.disable') && <button className="btn-secondary" onClick={() => setToggling(agency)}>{agency.is_active ? <UserX size={15} /> : <UserCheck size={15} />}{agency.is_active ? 'Desactivar' : 'Reactivar'}</button>}</div></td></tr>)}</tbody></table>{agencies.length === 0 && <div className="history-empty-state"><Building2 /><h2>No hay agencias para mostrar</h2><p>Pruebe otro término o estado.</p></div>}</section>
        <Modal open={editing !== null} onClose={() => setEditing(null)} title={editing === 'new' ? 'Crear Agencia' : 'Editar agencia'}><form className="modal-form" onSubmit={save}><div className="form-group"><label htmlFor="agency-code">Código</label><input id="agency-code" value={form.code} onChange={(event) => setForm({ ...form, code: event.target.value.toUpperCase() })} required maxLength={100} />{errors.code && <p className="field-error">{errors.code}</p>}</div><div className="form-group"><label htmlFor="agency-name">Nombre</label><input id="agency-name" value={form.name} onChange={(event) => setForm({ ...form, name: event.target.value })} required maxLength={255} />{errors.name && <p className="field-error">{errors.name}</p>}</div><div className="form-group"><label htmlFor="agency-address">Dirección (opcional)</label><input id="agency-address" value={form.address} onChange={(event) => setForm({ ...form, address: event.target.value })} maxLength={1000} /></div><div className="modal-footer"><button type="button" className="btn-secondary" onClick={() => setEditing(null)}>Cancelar</button><button disabled={processing}>{processing ? 'Guardando…' : 'Guardar'}</button></div></form></Modal>
        <ConfirmDialog open={toggling !== null} onClose={() => setToggling(null)} onConfirm={toggle} title={toggling?.is_active ? '¿Desactivar agencia?' : '¿Reactivar agencia?'} message={toggling?.is_active ? `La agencia ${toggling.name} dejará de estar disponible para nuevas asignaciones, pero se conservarán sus relaciones e historial.` : `La agencia ${toggling?.name} volverá a estar disponible para nuevas asignaciones.`} confirmLabel={toggling?.is_active ? 'Desactivar' : 'Reactivar'} processing={processing} />
    </AppLayout>;
}
