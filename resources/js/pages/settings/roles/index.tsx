import { Head, Link, router, usePage } from '@inertiajs/react';
import { Pencil, Plus, Search, ShieldCheck, SlidersHorizontal, UserCheck, UserX } from 'lucide-react';
import { useMemo, useState } from 'react';
import { AppLayout } from '@/components/app-layout';
import { Modal } from '@/components/ui/modal';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';

type Role = { id: number; name: string; is_active: boolean; users_count: number; permissions_count: number; permissions: string[]; is_reserved: boolean };
type Permission = { name: string; group: string; label: string };

export default function RolesIndex({ roles, permissions, filters }: { roles: Role[]; permissions: Permission[]; filters: { search: string } }) {
    const { auth, flash } = usePage<{ auth: { user: { permissions: string[] } }; flash: { message?: string } }>().props;
    const can = (permission: string) => auth.user.permissions.includes(permission);
    const [search, setSearch] = useState(filters.search);
    const [permissionSearch, setPermissionSearch] = useState('');
    const [editing, setEditing] = useState<Role | 'new' | null>(null);
    const [permissionRole, setPermissionRole] = useState<Role | null>(null);
    const [toggling, setToggling] = useState<Role | null>(null);
    const [name, setName] = useState('');
    const [selected, setSelected] = useState<string[]>([]);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);
    const groups = useMemo(() => [...new Set(permissions.map((permission) => permission.group))], [permissions]);

    const openCreate = () => { setEditing('new'); setName(''); setSelected([]); setPermissionSearch(''); setErrors({}); };
    const openEdit = (role: Role) => { setEditing(role); setName(role.name); setErrors({}); };
    const openPermissions = (role: Role) => { setPermissionRole(role); setSelected([...role.permissions]); setPermissionSearch(''); setErrors({}); };
    const togglePermission = (value: string) => setSelected((current) => current.includes(value) ? current.filter((item) => item !== value) : [...current, value]);
    const toggleGroup = (items: Permission[]) => setSelected((current) => {
        const names = items.map((item) => item.name);
        return names.every((item) => current.includes(item)) ? current.filter((item) => !names.includes(item)) : [...new Set([...current, ...names])];
    });
    const saveRole = (event: React.FormEvent) => {
        event.preventDefault(); setProcessing(true);
        const options = { preserveScroll: true, onSuccess: () => setEditing(null), onError: (value: Record<string, string>) => setErrors(value), onFinish: () => setProcessing(false) };
        if (editing === 'new') router.post('/settings/roles', { name, permissions: selected }, options);
        else if (editing) router.put(`/settings/roles/${editing.id}`, { name }, options);
    };
    const savePermissions = () => {
        if (!permissionRole) return;
        setProcessing(true);
        router.put(`/settings/roles/${permissionRole.id}/permissions`, { permissions: selected }, { preserveScroll: true, onSuccess: () => setPermissionRole(null), onError: (value) => setErrors(value), onFinish: () => setProcessing(false) });
    };
    const toggleRole = () => {
        if (!toggling) return;
        setProcessing(true);
        router.patch(`/settings/roles/${toggling.id}/toggle`, {}, { preserveScroll: true, onSuccess: () => setToggling(null), onError: (value) => setErrors(value), onFinish: () => setProcessing(false) });
    };

    const picker = <div className="settings-permission-picker"><div className="settings-permission-toolbar"><label htmlFor="permission-search">Buscar permiso</label><input id="permission-search" value={permissionSearch} onChange={(event) => setPermissionSearch(event.target.value)} placeholder="Buscar por nombre o módulo" /><span>{selected.length} seleccionados</span></div>{groups.map((group) => {
        const items = permissions.filter((permission) => permission.group === group);
        const visible = items.filter((permission) => `${permission.label} ${permission.name} ${group}`.toLowerCase().includes(permissionSearch.toLowerCase()));
        if (!visible.length) return null;
        return <section className="settings-permission-group" key={group}><div><h3>{group}</h3><button type="button" className="btn-secondary" onClick={() => toggleGroup(items)}>{items.every((item) => selected.includes(item.name)) ? 'Quitar todos' : 'Seleccionar módulo'}</button></div><div className="settings-permission-options">{visible.map((permission) => <label key={permission.name}><input type="checkbox" checked={selected.includes(permission.name)} onChange={() => togglePermission(permission.name)} /><span>{permission.label}<small>{permission.name}</small></span></label>)}</div></section>;
    })}</div>;

    return <AppLayout><Head title="Roles y Permisos" />
        <nav className="settings-breadcrumb" aria-label="Ruta"><Link href="/settings">Configuración</Link><span>›</span>Roles y Permisos</nav>
        <section className="page-heading settings-heading"><div><p className="eyebrow">CONFIGURACIÓN</p><h1>Roles y Permisos</h1><p>Administre roles y sus permisos de acceso.</p></div>{can('settings.roles.create') && <button onClick={openCreate}><Plus size={17} /> Crear rol</button>}</section>
        {flash.message && <div className="notice success" role="status">{flash.message}</div>}
        <form className="panel settings-role-search" onSubmit={(event) => { event.preventDefault(); router.get('/settings/roles', { search }, { preserveState: true, replace: true }); }}><label htmlFor="role-search">Buscar rol</label><div className="settings-search-row"><input id="role-search" value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Nombre del rol" /><button><Search size={16} /> Buscar</button></div></form>
        <section className="settings-role-list">{roles.map((role) => <article key={role.id} data-role-id={role.id} className="panel settings-role-row"><div className="settings-role-icon"><ShieldCheck /></div><div className="settings-role-info"><h2>{role.name}</h2><p>{role.users_count} usuarios · {role.permissions_count} permisos</p><span className={`settings-state ${role.is_active ? 'active' : ''}`}>{role.is_active ? 'Activo' : 'Inactivo'}</span></div><div className="settings-actions">{can('settings.roles.update') && <button className="btn-secondary" onClick={() => openEdit(role)}><Pencil size={15} /> Editar</button>}{can('settings.roles.permissions') && role.name !== 'admin' && <button className="btn-secondary" onClick={() => openPermissions(role)}><SlidersHorizontal size={15} /> Permisos</button>}{can('settings.roles.disable') && role.name !== 'admin' && <button className="btn-secondary" onClick={() => { setToggling(role); setErrors({}); }} disabled={role.is_active && role.users_count > 0}>{role.is_active ? <UserX size={15} /> : <UserCheck size={15} />}{role.is_active ? 'Desactivar' : 'Reactivar'}</button>}</div></article>)}{roles.length === 0 && <div className="history-empty-state"><ShieldCheck /><h2>No hay roles para mostrar</h2><p>Pruebe otro término de búsqueda.</p></div>}</section>
        <Modal open={editing !== null} onClose={() => setEditing(null)} title={editing === 'new' ? 'Crear rol' : 'Editar rol'}><form className="modal-form" onSubmit={saveRole}><div className="form-group"><label htmlFor="role-name">Nombre</label><input id="role-name" value={name} onChange={(event) => setName(event.target.value)} disabled={!!editing && editing !== 'new' && editing.is_reserved} required maxLength={100} />{errors.name && <p className="field-error">{errors.name}</p>}</div>{editing === 'new' && can('settings.roles.permissions') && picker}<div className="modal-footer"><button type="button" className="btn-secondary" onClick={() => setEditing(null)}>Cancelar</button><button disabled={processing}>{processing ? 'Guardando…' : 'Guardar rol'}</button></div></form></Modal>
        <Modal open={permissionRole !== null} onClose={() => setPermissionRole(null)} title={`Permisos de ${permissionRole?.name ?? ''}`}>{picker}{errors.permissions && <p className="field-error">{errors.permissions}</p>}<div className="modal-footer"><button className="btn-secondary" onClick={() => setPermissionRole(null)}>Cancelar</button><button onClick={savePermissions} disabled={processing}>{processing ? 'Guardando…' : 'Guardar permisos'}</button></div></Modal>
        <ConfirmDialog open={toggling !== null} onClose={() => setToggling(null)} onConfirm={toggleRole} title={toggling?.is_active ? '¿Desactivar rol?' : '¿Reactivar rol?'} message={toggling?.is_active ? `El rol ${toggling.name} dejará de estar disponible para nuevas asignaciones. Sus relaciones se conservarán.` : `El rol ${toggling?.name} volverá a estar disponible.`} confirmLabel={toggling?.is_active ? 'Desactivar' : 'Reactivar'} processing={processing} />{errors.role && <p className="field-error" role="alert">{errors.role}</p>}
    </AppLayout>;
}
