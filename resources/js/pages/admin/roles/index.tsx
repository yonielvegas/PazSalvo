import { Form, Head, usePage } from '@inertiajs/react';
import { AppLayout } from '@/components/app-layout';

type Role = { id:number; name:string; permissions:string[] };

export default function RolesIndex({ roles, permissions }: { roles:Role[]; permissions:string[] }) {
    const { flash } = usePage<{flash:{message?:string}}>().props;

    return <AppLayout><Head title="Roles y permisos" />
        <section className="page-heading"><p className="eyebrow">ADMINISTRACIÓN</p><h1>Roles y permisos</h1><p>Edita los permisos existentes por rol.</p></section>
        {flash.message&&<div className="notice success">{flash.message}</div>}
        <section className="admin-role-grid">{roles.map(role=><div key={role.id} className="panel role-card"><h2>{role.name}</h2><Form action={`/admin/roles/${role.id}/permissions`} method="put">{({processing})=><><div className="permission-list">{permissions.map(permission=><label key={permission}><input type="checkbox" name="permissions[]" value={permission} defaultChecked={role.permissions.includes(permission)} /> {permission}</label>)}</div><button disabled={processing}>Guardar permisos</button></>}</Form></div>)}</section>
    </AppLayout>;
}
