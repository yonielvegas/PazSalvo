import { Head, router, usePage } from '@inertiajs/react';
import { AppLayout } from '@/components/app-layout';
import { Modal } from '@/components/ui/modal';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import { Plus, Pencil, UserCheck, UserX, X, AlertTriangle, Image as ImageIcon, Eye } from 'lucide-react';
import { useState, useCallback } from 'react';

type User = { id: number; name: string; email: string; is_active: boolean; agency_id: number; agency: string | null; roles: string[]; has_active_signature: boolean; has_any_signature: boolean };
type Agency = { id: number; name: string };

type PageErrors = Record<string, string>;

const initialCreate = { name: '', email: '', agency_id: '', role: '', password: '', password_confirmation: '', is_active: true };

export default function UsersIndex({ users, agencies, roles }: { users: User[]; agencies: Agency[]; roles: string[] }) {
    const { flash, errors: pageErrors } = usePage<{ flash: { message?: string }; errors: PageErrors & { needs_replace_confirmation?: string; current_supervisor_name?: string; activation_replace?: string } }>().props;

    const [showCreate, setShowCreate] = useState(false);
    const [editUser, setEditUser] = useState<User | null>(null);
    const [toggleUser, setToggleUser] = useState<User | null>(null);
    const [createForm, setCreateForm] = useState(initialCreate);
    const [editForm, setEditForm] = useState({ name: '', email: '', agency_id: '', role: '', password: '', password_confirmation: '', is_active: true });
    const [createErrors, setCreateErrors] = useState<PageErrors>({});
    const [editErrors, setEditErrors] = useState<PageErrors>({});
    const [toggleErrors, setToggleErrors] = useState<PageErrors>({});
    const [processing, setProcessing] = useState<'create' | 'edit' | 'toggle' | 'toggle-replace' | null>(null);
    const [dismissed, setDismissed] = useState(false);
    const [createNeedsReplaceConfirm, setCreateNeedsReplaceConfirm] = useState(false);
    const [editNeedsReplaceConfirm, setEditNeedsReplaceConfirm] = useState(false);
    const [createCurrentSupervisorName, setCreateCurrentSupervisorName] = useState('');
    const [editCurrentSupervisorName, setEditCurrentSupervisorName] = useState('');
    const [createSignatureFile, setCreateSignatureFile] = useState<File | null>(null);
    const [createSignatureName, setCreateSignatureName] = useState('');
    const [editSignatureFile, setEditSignatureFile] = useState<File | null>(null);
    const [editSignatureName, setEditSignatureName] = useState('');
    const [signaturePreviewUser, setSignaturePreviewUser] = useState<User | null>(null);
    const [toggleReplaceUser, setToggleReplaceUser] = useState<User | null>(null);
    const [toggleReplaceName, setToggleReplaceName] = useState('');

    const isSupervisorRole = (role: string) => role === 'supervisor';
    const canViewSignature = (u: User) => u.roles.includes('supervisor') || u.has_any_signature;

    const openCreate = useCallback(() => {
        setShowCreate(true);
        setCreateForm(initialCreate);
        setCreateErrors({});
        setCreateNeedsReplaceConfirm(false);
        setCreateCurrentSupervisorName('');
        setCreateSignatureFile(null);
        setCreateSignatureName('');
    }, []);

    const openEdit = useCallback((user: User) => {
        setEditUser(user);
        setEditForm({
            name: user.name,
            email: user.email,
            agency_id: String(user.agency_id),
            role: user.roles[0] || '',
            password: '',
            password_confirmation: '',
            is_active: user.is_active,
        });
        setEditErrors({});
        setEditNeedsReplaceConfirm(false);
        setEditCurrentSupervisorName('');
        setEditSignatureFile(null);
        setEditSignatureName('');
    }, []);

    const openToggle = useCallback((user: User) => {
        setToggleUser(user);
        setToggleErrors({});
        setToggleReplaceUser(null);
        setToggleReplaceName('');
    }, []);

    const openToggleReplace = useCallback((user: User, currentName: string) => {
        setToggleUser(user);
        setToggleReplaceUser(user);
        setToggleReplaceName(currentName);
        setToggleErrors({});
    }, []);

    const handleCreate = useCallback((e: React.FormEvent) => {
        e.preventDefault();
        setProcessing('create');

        const formData = new FormData();
        formData.append('name', createForm.name);
        formData.append('email', createForm.email);
        formData.append('agency_id', String(Number(createForm.agency_id)));
        formData.append('role', createForm.role);
        formData.append('password', createForm.password);
        formData.append('password_confirmation', createForm.password_confirmation);
        formData.append('is_active', createForm.is_active ? '1' : '0');
        if (createSignatureFile) formData.append('signature', createSignatureFile);
        if (createNeedsReplaceConfirm) formData.append('confirm_replace', '1');

        router.post('/admin/users', formData, {
            preserveState: true,
            preserveScroll: true,
            headers: { 'Content-Type': 'multipart/form-data' },
            onSuccess: () => {
                setShowCreate(false);
                setProcessing(null);
                setDismissed(false);
            },
            onError: (errs) => {
                if ((errs as Record<string, string>).needs_replace_confirmation) {
                    setCreateNeedsReplaceConfirm(true);
                    setCreateCurrentSupervisorName((errs as Record<string, string>).current_supervisor_name || '');
                }
                setCreateErrors(errs);
                setProcessing(null);
            },
        });
    }, [createForm, createSignatureFile, createNeedsReplaceConfirm]);

    const handleEdit = useCallback((e: React.FormEvent) => {
        e.preventDefault();
        if (!editUser) return;
        setProcessing('edit');

        const formData = new FormData();
        formData.append('name', editForm.name);
        formData.append('email', editForm.email);
        formData.append('agency_id', String(Number(editForm.agency_id)));
        formData.append('role', editForm.role);
        formData.append('is_active', editForm.is_active ? '1' : '0');
        if (editForm.password) {
            formData.append('password', editForm.password);
            formData.append('password_confirmation', editForm.password_confirmation);
        }
        if (editSignatureFile) formData.append('signature', editSignatureFile);
        if (editNeedsReplaceConfirm) formData.append('confirm_replace', '1');
        formData.append('_method', 'PUT');

        router.post(`/admin/users/${editUser.id}`, formData, {
            preserveState: true,
            preserveScroll: true,
            headers: { 'Content-Type': 'multipart/form-data' },
            onSuccess: () => {
                setEditUser(null);
                setProcessing(null);
                setDismissed(false);
            },
            onError: (errs) => {
                if ((errs as Record<string, string>).needs_replace_confirmation) {
                    setEditNeedsReplaceConfirm(true);
                    setEditCurrentSupervisorName((errs as Record<string, string>).current_supervisor_name || '');
                }
                setEditErrors(errs);
                setProcessing(null);
            },
        });
    }, [editUser, editForm, editSignatureFile, editNeedsReplaceConfirm]);

    const handleToggle = useCallback(() => {
        if (!toggleUser) return;
        setProcessing('toggle');
        router.patch(`/admin/users/${toggleUser.id}/toggle`, {}, {
            preserveState: true,
            preserveScroll: true,
            onSuccess: () => {
                setToggleUser(null);
                setToggleReplaceUser(null);
                setProcessing(null);
                setDismissed(false);
            },
            onError: (errs) => {
                const e = errs as Record<string, string>;
                if (e.needs_replace_confirmation && e.activation_replace) {
                    openToggleReplace(toggleUser, e.current_supervisor_name || '');
                    setToggleErrors({});
                } else {
                    setToggleErrors(errs);
                }
                setProcessing(null);
            },
        });
    }, [toggleUser]);

    const handleToggleReplace = useCallback(() => {
        if (!toggleUser) return;
        setProcessing('toggle-replace');
        router.patch(`/admin/users/${toggleUser.id}/toggle`, { confirm_replace: '1' }, {
            preserveState: true,
            preserveScroll: true,
            onSuccess: () => {
                setToggleUser(null);
                setToggleReplaceUser(null);
                setProcessing(null);
                setDismissed(false);
            },
            onError: (errs) => {
                setToggleErrors(errs);
                setProcessing(null);
            },
        });
    }, [toggleUser]);

    const showFlash = flash?.message && !dismissed;

    return (
        <AppLayout>
            <Head title="Usuarios" />

            <div className="mx-auto w-full max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <p className="eyebrow" style={{ margin: 0 }}>ADMINISTRACIÓN</p>
                        <h1 style={{ margin: '3px 0', fontSize: 36 }}>Usuarios</h1>
                        <p style={{ color: '#71817e', margin: 0 }}>Gestiona las cuentas del sistema, sus agencias, roles y estado.</p>
                    </div>
                    <button onClick={openCreate}><Plus size={18} /> Nuevo usuario</button>
                </div>

                {showFlash && (
                    <div className="notice success dismiss-notice">
                        <span>{flash.message}</span>
                        <button onClick={() => setDismissed(true)} aria-label="Cerrar"><X size={16} /></button>
                    </div>
                )}

                {toggleErrors.user && (
                    <div className="notice error dismiss-notice">
                        <span>{toggleErrors.user}</span>
                        <button onClick={() => setToggleErrors({})} aria-label="Cerrar"><X size={16} /></button>
                    </div>
                )}

                <div className="table-card users-table" style={{ marginTop: 24 }}>
                    <table>
                        <thead>
                            <tr>
                                <th>Nombre completo</th>
                                <th>Correo</th>
                                <th>Agencia</th>
                                <th>Rol</th>
                                <th>Estado</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            {users.length === 0 && (
                                <tr><td colSpan={6} className="empty">No hay usuarios registrados.</td></tr>
                            )}
                            {users.map((u) => (
                                <tr key={u.id}>
                                    <td><b>{u.name}</b></td>
                                    <td><small style={{ display: 'block', color: '#71817e', marginTop: 0 }}>{u.email}</small></td>
                                    <td>{u.agency ?? <small style={{ color: '#a0b3b0' }}>Sin agencia</small>}</td>
                                    <td>
                                        {u.roles.map((r) => (
                                            <span key={r} className={`role-badge ${r === 'supervisor' ? 'role-badge-supervisor' : ''}`} style={{ marginRight: 4 }}>
                                                {r}
                                                {r === 'supervisor' && u.has_active_signature && (
                                                    <span style={{ marginLeft: 4, fontSize: 10 }}>· Jefe de agencia</span>
                                                )}
                                            </span>
                                        ))}
                                    </td>
                                    <td>
                                        <span className={`status ${u.is_active ? 'valid' : 'cancelled'}`}>
                                            {u.is_active ? 'Activo' : 'Inactivo'}
                                        </span>
                                    </td>
                                    <td>
                                        <div className="actions-cell">
                                            {canViewSignature(u) && (
                                                <span className="tooltip" data-tip="Ver firma">
                                                    <button className="icon-btn" onClick={() => setSignaturePreviewUser(u)} aria-label="Ver firma">
                                                        <Eye size={15} />
                                                    </button>
                                                </span>
                                            )}
                                            <span className="tooltip" data-tip="Editar usuario">
                                                <button className="icon-btn" onClick={() => openEdit(u)} aria-label="Editar usuario">
                                                    <Pencil size={15} />
                                                </button>
                                            </span>
                                            <span className="tooltip" data-tip={u.is_active ? 'Desactivar usuario' : 'Activar usuario'}>
                                                <button
                                                    className={`icon-btn ${!u.is_active ? '' : 'icon-btn-danger'}`}
                                                    onClick={() => openToggle(u)}
                                                    aria-label={u.is_active ? 'Desactivar usuario' : 'Activar usuario'}
                                                >
                                                    {u.is_active ? <UserX size={15} /> : <UserCheck size={15} />}
                                                </button>
                                            </span>
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>

            <Modal
                open={showCreate}
                onClose={() => { if (processing !== 'create') setShowCreate(false); }}
                title="Nuevo usuario"
                description="Complete la informacion del usuario y asigne su agencia y rol."
            >
                <form onSubmit={handleCreate} className="modal-form">
                    {createNeedsReplaceConfirm && (
                        <div className="replace-alert">
                            <div className="replace-alert-icon">
                                <AlertTriangle size={20} />
                            </div>
                            <div>
                                <p className="replace-alert-title">Esta agencia ya tiene un jefe activo</p>
                                <p className="replace-alert-desc">
                                    <strong>{createCurrentSupervisorName}</strong> es el jefe actual. Solo puede existir un jefe activo por agencia. Si confirmas, el jefe actual será desactivado.
                                </p>
                            </div>
                        </div>
                    )}
                    <div className="form-group">
                        <label htmlFor="create-name">Nombre completo</label>
                        <input id="create-name" value={createForm.name} onChange={(e) => setCreateForm({ ...createForm, name: e.target.value })} required />
                        {createErrors.name && <p className="field-error">{createErrors.name}</p>}
                    </div>
                    <div className="form-group">
                        <label htmlFor="create-email">Correo electronico</label>
                        <input id="create-email" type="email" value={createForm.email} onChange={(e) => setCreateForm({ ...createForm, email: e.target.value })} required />
                        {createErrors.email && <p className="field-error">{createErrors.email}</p>}
                    </div>
                    <div className="form-row">
                        <div className="form-group">
                            <label htmlFor="create-agency">Agencia</label>
                            <select id="create-agency" value={createForm.agency_id} onChange={(e) => { setCreateForm({ ...createForm, agency_id: e.target.value }); setCreateNeedsReplaceConfirm(false); }} required>
                                <option value="">Seleccionar agencia</option>
                                {agencies.map((a) => <option key={a.id} value={a.id}>{a.name}</option>)}
                            </select>
                            {createErrors.agency_id && <p className="field-error">{createErrors.agency_id}</p>}
                        </div>
                        <div className="form-group">
                            <label htmlFor="create-role">Rol</label>
                            <select id="create-role" value={createForm.role} onChange={(e) => setCreateForm({ ...createForm, role: e.target.value })} required>
                                <option value="">Seleccionar rol</option>
                                {roles.map((r) => <option key={r} value={r}>{r}</option>)}
                            </select>
                            {createErrors.role && <p className="field-error">{createErrors.role}</p>}
                        </div>
                    </div>
                    <div className="form-row">
                        <div className="form-group">
                            <label htmlFor="create-password">Contrasena</label>
                            <input id="create-password" type="password" value={createForm.password} onChange={(e) => setCreateForm({ ...createForm, password: e.target.value })} required />
                            {createErrors.password && <p className="field-error">{createErrors.password}</p>}
                        </div>
                        <div className="form-group">
                            <label htmlFor="create-password-confirm">Confirmar contrasena</label>
                            <input id="create-password-confirm" type="password" value={createForm.password_confirmation} onChange={(e) => setCreateForm({ ...createForm, password_confirmation: e.target.value })} required />
                        </div>
                    </div>
                    <div className="form-group" style={{ display: 'flex', flexDirection: 'row', alignItems: 'center', gap: 8 }}>
                        <input
                            id="create-is-active"
                            type="checkbox"
                            checked={createForm.is_active}
                            onChange={(e) => setCreateForm({ ...createForm, is_active: e.target.checked })}
                            style={{ width: 'auto', flex: 'none' }}
                        />
                        <label htmlFor="create-is-active" style={{ fontWeight: 700, fontSize: 13, margin: 0 }}>Activo</label>
                    </div>
                    {isSupervisorRole(createForm.role) && (
                        <div className="form-group">
                            <label htmlFor="create-signature">Foto de firma</label>
                            <div className="file-input-wrapper">
                                <input id="create-signature" type="file" accept="image/jpeg,image/png,image/webp"
                                    onChange={(e) => {
                                        const file = e.target.files?.[0] ?? null;
                                        setCreateSignatureFile(file);
                                        setCreateSignatureName(file ? file.name : '');
                                    }}
                                />
                                <div className="file-input-fake">
                                    <span className="file-input-text">{createSignatureName || 'Seleccionar archivo'}</span>
                                    <span className="file-input-btn">Examinar</span>
                                </div>
                            </div>
                            <p className="file-help">JPG, PNG o WEBP, máximo 2MB</p>
                            {createErrors.signature && <p className="field-error">{createErrors.signature}</p>}
                        </div>
                    )}
                    <div className="modal-footer">
                        <button type="button" className="btn-secondary" onClick={() => setShowCreate(false)} disabled={processing === 'create'}>Cancelar</button>
                        <button type="submit" className={createNeedsReplaceConfirm ? 'btn-warning' : ''} disabled={processing === 'create'}>
                            {processing === 'create' ? 'Creando…' : createNeedsReplaceConfirm ? 'Sí, reemplazar jefe' : 'Crear usuario'}
                        </button>
                    </div>
                </form>
            </Modal>

            <Modal
                open={editUser !== null}
                onClose={() => { if (processing !== 'edit') setEditUser(null); }}
                title="Editar usuario"
                description="Actualiza la informacion, agencia, rol o estado del usuario."
            >
                <form onSubmit={handleEdit} className="modal-form">
                    {editNeedsReplaceConfirm && (
                        <div className="replace-alert">
                            <div className="replace-alert-icon">
                                <AlertTriangle size={20} />
                            </div>
                            <div>
                                <p className="replace-alert-title">Esta agencia ya tiene un jefe activo</p>
                                <p className="replace-alert-desc">
                                    <strong>{editCurrentSupervisorName}</strong> es el jefe actual. Solo puede existir un jefe activo por agencia. Si confirmas, el jefe actual será desactivado.
                                </p>
                            </div>
                        </div>
                    )}
                    <div className="form-group">
                        <label htmlFor="edit-name">Nombre completo</label>
                        <input id="edit-name" value={editForm.name} onChange={(e) => setEditForm({ ...editForm, name: e.target.value })} required />
                        {editErrors.name && <p className="field-error">{editErrors.name}</p>}
                    </div>
                    <div className="form-group">
                        <label htmlFor="edit-email">Correo electronico</label>
                        <input id="edit-email" type="email" value={editForm.email} onChange={(e) => setEditForm({ ...editForm, email: e.target.value })} required />
                        {editErrors.email && <p className="field-error">{editErrors.email}</p>}
                    </div>
                    <div className="form-row">
                        <div className="form-group">
                            <label htmlFor="edit-agency">Agencia</label>
                            <select id="edit-agency" value={editForm.agency_id} onChange={(e) => { setEditForm({ ...editForm, agency_id: e.target.value }); setEditNeedsReplaceConfirm(false); }} required>
                                {agencies.map((a) => <option key={a.id} value={a.id}>{a.name}</option>)}
                            </select>
                            {editErrors.agency_id && <p className="field-error">{editErrors.agency_id}</p>}
                        </div>
                        <div className="form-group">
                            <label htmlFor="edit-role">Rol</label>
                            <select id="edit-role" value={editForm.role} onChange={(e) => setEditForm({ ...editForm, role: e.target.value })} required>
                                {roles.map((r) => <option key={r} value={r}>{r}</option>)}
                            </select>
                            {editErrors.role && <p className="field-error">{editErrors.role}</p>}
                        </div>
                    </div>
                    <div className="form-row">
                        <div className="form-group">
                            <label htmlFor="edit-password">Nueva contrasena <small style={{ fontWeight: 400, color: '#71817e' }}>(opcional)</small></label>
                            <input id="edit-password" type="password" value={editForm.password} onChange={(e) => setEditForm({ ...editForm, password: e.target.value })} />
                            {editErrors.password && <p className="field-error">{editErrors.password}</p>}
                        </div>
                        <div className="form-group">
                            <label htmlFor="edit-password-confirm">Confirmar contrasena</label>
                            <input id="edit-password-confirm" type="password" value={editForm.password_confirmation} onChange={(e) => setEditForm({ ...editForm, password_confirmation: e.target.value })} />
                        </div>
                    </div>
                    <div className="form-group" style={{ display: 'flex', flexDirection: 'row', alignItems: 'center', gap: 8 }}>
                        <input
                            id="edit-is-active"
                            type="checkbox"
                            checked={editForm.is_active}
                            onChange={(e) => setEditForm({ ...editForm, is_active: e.target.checked })}
                            style={{ width: 'auto', flex: 'none' }}
                        />
                        <label htmlFor="edit-is-active" style={{ fontWeight: 700, fontSize: 13, margin: 0 }}>Activo</label>
                    </div>
                    {isSupervisorRole(editForm.role) && (
                        <div className="form-group">
                            <label>Firma del jefe de agencia</label>
                            {editUser?.has_any_signature && (
                                <div className="current-signature-info" style={{ marginBottom: 8 }}>
                                    <ImageIcon size={16} />
                                    <span>Firma actual: {editUser.has_active_signature ? 'cargada' : 'inactiva'}</span>
                                    <button type="button" className="icon-btn" onClick={() => editUser && setSignaturePreviewUser(editUser)} style={{ marginLeft: 'auto' }} title="Ver firma">
                                        <Eye size={15} />
                                    </button>
                                </div>
                            )}
                            <div className="file-input-wrapper">
                                <input id="edit-signature" type="file" accept="image/jpeg,image/png,image/webp"
                                    onChange={(e) => {
                                        const file = e.target.files?.[0] ?? null;
                                        setEditSignatureFile(file);
                                        setEditSignatureName(file ? file.name : '');
                                    }}
                                />
                                <div className="file-input-fake">
                                    <span className="file-input-text">{editSignatureName || (editUser?.has_active_signature ? 'Reemplazar firma (opcional)' : 'Seleccionar archivo')}</span>
                                    <span className="file-input-btn">Examinar</span>
                                </div>
                            </div>
                            <p className="file-help">JPG, PNG o WEBP, máximo 2MB. {editUser?.has_active_signature ? 'Si no selecciona archivo, se conserva la firma actual.' : 'La firma es obligatoria para el rol supervisor.'}</p>
                            {editErrors.signature && <p className="field-error">{editErrors.signature}</p>}
                        </div>
                    )}
                    <div className="modal-footer">
                        <button type="button" className="btn-secondary" onClick={() => setEditUser(null)} disabled={processing === 'edit'}>Cancelar</button>
                        <button type="submit" className={editNeedsReplaceConfirm ? 'btn-warning' : ''} disabled={processing === 'edit'}>
                            {processing === 'edit' ? 'Guardando…' : editNeedsReplaceConfirm ? 'Sí, reemplazar jefe' : 'Guardar cambios'}
                        </button>
                    </div>
                </form>
            </Modal>

            <ConfirmDialog
                open={toggleUser !== null && toggleReplaceUser === null}
                onClose={() => { if (processing !== 'toggle') setToggleUser(null); }}
                onConfirm={handleToggle}
                title={toggleUser?.is_active ? 'Desactivar usuario' : 'Activar usuario'}
                message={
                    toggleUser?.is_active
                        ? 'El usuario no podra acceder al sistema mientras este inactivo.'
                        : 'El usuario podra volver a acceder al sistema.'
                }
                confirmLabel={toggleUser?.is_active ? 'Desactivar' : 'Activar'}
                processing={processing === 'toggle'}
            />

            <ConfirmDialog
                open={toggleReplaceUser !== null}
                onClose={() => { if (processing !== 'toggle-replace') { setToggleUser(null); setToggleReplaceUser(null); } }}
                onConfirm={handleToggleReplace}
                title="Reemplazar jefe activo"
                message={
                    <>
                        Esta agencia ya tiene un jefe activo:<br />
                        <strong>{toggleReplaceName}</strong><br /><br />
                        ¿Deseas desactivarlo y activar este jefe?
                    </>
                }
                confirmLabel="Sí, reemplazar jefe"
                processing={processing === 'toggle-replace'}
            />

            <Modal
                open={signaturePreviewUser !== null}
                onClose={() => setSignaturePreviewUser(null)}
                title={signaturePreviewUser ? `Firma de ${signaturePreviewUser.name}` : ''}
                description={signaturePreviewUser ? `Agencia: ${signaturePreviewUser.agency || 'Sin agencia'}` : ''}
            >
                {signaturePreviewUser && (
                    <div style={{ textAlign: 'center' }}>
                        <p style={{ marginBottom: 16, color: '#71817e', fontSize: 14 }}>
                            Estado: <span style={{ fontWeight: 700, color: signaturePreviewUser.has_active_signature ? '#166534' : '#991b1b' }}>
                                {signaturePreviewUser.has_active_signature ? 'Activa' : 'Inactiva'}
                            </span>
                        </p>
                        <div style={{
                            maxWidth: 400,
                            margin: '0 auto',
                            padding: 20,
                            border: '1px solid #dbe7e4',
                            borderRadius: 12,
                            background: '#f8faf9',
                        }}>
                            <img
                                src={`/admin/users/${signaturePreviewUser.id}/signature`}
                                alt={`Firma de ${signaturePreviewUser.name}`}
                                style={{ maxWidth: '100%', maxHeight: 200, objectFit: 'contain' }}
                                onError={(e) => {
                                    (e.target as HTMLImageElement).style.display = 'none';
                                    (e.target as HTMLImageElement).parentElement!.innerHTML = '<p style="color:#71817e;padding:20px">No se pudo cargar la firma.</p>';
                                }}
                            />
                        </div>
                        <div className="modal-footer" style={{ justifyContent: 'center' }}>
                            <button type="button" className="btn-secondary" onClick={() => setSignaturePreviewUser(null)}>Cerrar</button>
                        </div>
                    </div>
                )}
            </Modal>
        </AppLayout>
    );
}
