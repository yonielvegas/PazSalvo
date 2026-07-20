import { Form, Link, usePage } from '@inertiajs/react';
import { Building2, History, LogOut, Search, ShieldCheck, Users } from 'lucide-react';
import type { PropsWithChildren } from 'react';

type Auth = { user: null | { name: string; agency: { name: string } | null; permissions: string[] } };

export function AppLayout({ children }: PropsWithChildren) {
    const { auth } = usePage<{ auth: Auth }>().props;
    return <>
        <header className="app-header">
            <Link href="/paz-salvos/consultar" className="brand"><Building2 /><div><b>AAUD</b><span>Paz y Salvo institucional</span></div></Link>
            <nav>
                <Link href="/paz-salvos/consultar"><Search /> Consultar</Link>
                {auth.user?.permissions.includes('ver historial') && <Link href="/paz-salvos"><History /> Historial</Link>}
                {auth.user?.permissions.includes('administrar usuarios') && <Link href="/admin/users"><Users /> Usuarios</Link>}
                {auth.user?.permissions.includes('administrar roles') && <Link href="/admin/roles"><ShieldCheck /> Roles</Link>}
            </nav>
            <div className="user-menu"><span><b>{auth.user?.name}</b><small>{auth.user?.agency?.name}</small></span><Form action="/logout" method="post"><button title="Cerrar sesión"><LogOut /></button></Form></div>
        </header>
        <main>{children}</main>
        <footer>Uso institucional · Autoridad de Aseo Urbano y Domiciliario</footer>
    </>;
}
