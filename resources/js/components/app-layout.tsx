import { Form, Link, usePage } from '@inertiajs/react';
import { Building2, FileSpreadsheet, History, LogOut, Menu, Search, Settings, UserRound, Users } from 'lucide-react';
import { useRef, type ComponentType, type PropsWithChildren } from 'react';

type Auth = { user: null | { name: string; agency: { name: string } | null; permissions: string[] } };
type Statistics = { total: number; expired: number; valid: number };
type NavigationItem = { label: string; href: string; icon: ComponentType<{ size?: number }>; visible: boolean; active: boolean };

export function AppLayout({ children }: PropsWithChildren) {
    const navMenu = useRef<HTMLDetailsElement>(null);
    const kpiMenu = useRef<HTMLDetailsElement>(null);
    const accountMenu = useRef<HTMLDetailsElement>(null);
    const closeOtherMenus = (opened: HTMLDetailsElement) => {
        for (const menu of [navMenu, kpiMenu, accountMenu]) {
            if (menu.current && menu.current !== opened) menu.current.removeAttribute('open');
        }
    };
    const page = usePage<{ auth: Auth; globalStatistics: Statistics | null }>();
    const { auth, globalStatistics } = page.props;
    const pathname = page.url.split(/[?#]/)[0];
    const permissions = auth.user?.permissions ?? [];
    const navigation: NavigationItem[] = [
        { label: 'Consultar', href: '/paz-salvos/consultar', icon: Search, visible: permissions.includes('consultar paz y salvo'), active: pathname === '/paz-salvos/consultar' },
        { label: 'Historial', href: '/paz-salvos', icon: History, visible: permissions.includes('ver historial'), active: pathname === '/paz-salvos' || (pathname.startsWith('/paz-salvos/') && pathname !== '/paz-salvos/consultar') },
        { label: 'Usuarios', href: '/admin/users', icon: Users, visible: permissions.includes('administrar usuarios'), active: pathname.startsWith('/admin/users') },
        { label: 'Configuración', href: '/settings', icon: Settings, visible: permissions.includes('settings.view'), active: pathname === '/settings' || pathname.startsWith('/settings/') },
        { label: 'Excel de Clientes', href: '/admin/clients-excel', icon: FileSpreadsheet, visible: permissions.includes('clients-excel.view'), active: pathname.startsWith('/admin/clients-excel') },
    ].filter((item) => item.visible);
    const metrics = [
        { label: 'Total de Paz y Salvo', short: 'Total', value: globalStatistics?.total },
        { label: 'Paz y Salvo vencidos', short: 'Vencidos', value: globalStatistics?.expired },
        { label: 'Paz y Salvo vigentes', short: 'Vigentes', value: globalStatistics?.valid },
    ];
    const navigationLinks = (mobile: boolean) => navigation.map(({ label, href, icon: Icon, active }) => (
        <Link key={href} href={href} aria-label={label} title={label} aria-current={active ? 'page' : undefined} className={active ? 'is-active' : undefined}
            onClick={mobile ? (event) => event.currentTarget.closest('details')?.removeAttribute('open') : undefined}>
            <Icon size={18} /><span>{label}</span>
        </Link>
    ));

    return <>
        <header className="app-header">
            <Link href="/paz-salvos/consultar" className="brand" aria-label="AAUD · Paz y Salvo institucional"><Building2 /><div><b>AAUD</b><span>Paz y Salvo institucional</span></div></Link>
            <div className="nav-zone">
                <nav className="desktop-nav" aria-label="Navegación principal">{navigationLinks(false)}</nav>
                <details ref={navMenu} className="mobile-menu nav-menu" onToggle={(event) => { if (event.currentTarget.open) closeOtherMenus(event.currentTarget); }}>
                    <summary aria-label="Abrir navegación"><Menu size={20} /></summary>
                    <nav aria-label="Navegación principal">{navigationLinks(true)}</nav>
                </details>
            </div>
            {permissions.includes('ver historial') && <div className="nav-kpis" aria-label="Indicadores globales de Paz y Salvo" aria-live="polite">
                <div className="desktop-kpis">{metrics.map(({ label, short, value }) => <div className="nav-kpi" key={label} aria-label={`${label}: ${value ?? 'no disponible'}`}>
                    <span className="nav-kpi-label">{label}</span><span className="nav-kpi-short">{short}</span><strong>{value ?? '—'}</strong>
                </div>)}</div>
                <details ref={kpiMenu} className="mobile-menu nav-kpi-details" onToggle={(event) => { if (event.currentTarget.open) closeOtherMenus(event.currentTarget); }}>
                    <summary aria-label="Ver indicadores globales" title="Ver indicadores globales">{metrics.map(({ short, value }) => <span key={short} aria-label={`${short}: ${value ?? 'no disponible'}`}>{value ?? '—'}</span>)}</summary>
                    <div className="nav-kpi-panel">{metrics.map(({ label, value }) => <div key={label}><span>{label}</span><strong>{value ?? 'No disponible'}</strong></div>)}</div>
                </details>
            </div>}
            <div className="account-zone">
                <div className="user-menu desktop-account"><span><b>{auth.user?.name}</b><small>{auth.user?.agency?.name}</small></span><Form action="/logout" method="post"><button title="Cerrar sesión" aria-label="Cerrar sesión"><LogOut /></button></Form></div>
                <details ref={accountMenu} className="mobile-menu account-menu" onToggle={(event) => { if (event.currentTarget.open) closeOtherMenus(event.currentTarget); }}>
                    <summary aria-label="Abrir cuenta"><UserRound size={20} /></summary>
                    <div className="account-panel"><b>{auth.user?.name}</b><small>{auth.user?.agency?.name}</small><Form action="/logout" method="post"><button aria-label="Cerrar sesión"><LogOut size={17} /> Cerrar sesión</button></Form></div>
                </details>
            </div>
        </header>
        <main>{children}</main>
        <footer>Uso institucional · Autoridad de Aseo Urbano y Domiciliario</footer>
    </>;
}
