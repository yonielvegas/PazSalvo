import { Head, Link, usePage } from '@inertiajs/react';
import { ArrowRight, Building2, ShieldCheck } from 'lucide-react';
import { AppLayout } from '@/components/app-layout';

export default function SettingsIndex() {
    const { auth } = usePage<{ auth: { user: { permissions: string[] } } }>().props;
    const permissions = auth.user.permissions;
    return <AppLayout><Head title="Configuración" />
        <section className="page-heading"><p className="eyebrow">ADMINISTRACIÓN</p><h1>Configuración</h1><p>Administre los catálogos y accesos del sistema.</p></section>
        <div className="settings-card-grid">
            {permissions.includes('settings.agencies.view') && <Link className="panel settings-card" href="/settings/agencies"><Building2 /><div><h2>Agencias</h2><p>Administrar las agencias disponibles en el sistema.</p></div><ArrowRight className="settings-card-arrow" /></Link>}
            {permissions.includes('settings.roles.view') && <Link className="panel settings-card" href="/settings/roles"><ShieldCheck /><div><h2>Roles y Permisos</h2><p>Administrar roles del sistema y los permisos asociados a cada rol.</p></div><ArrowRight className="settings-card-arrow" /></Link>}
        </div>
    </AppLayout>;
}
