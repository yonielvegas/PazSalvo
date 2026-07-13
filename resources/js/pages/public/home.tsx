import { Head, Link } from '@inertiajs/react';
import { Building2, LogIn, ShieldCheck } from 'lucide-react';

export default function PublicHome() {
    return (
        <div className="public-page">
            <Head title="Validación de Paz y Salvo" />
            <header>
                <div className="brand"><Building2 /><div><b>AAUD</b><span>Paz y Salvo</span></div></div>
            </header>
            <main className="public-main">
                <section className="public-hero">
                    <p className="eyebrow">VALIDACIÓN PÚBLICA</p>
                    <h1>Sistema de Paz y Salvo AAUD</h1>
                    <p>Verifique la autenticidad y vigencia de un Paz y Salvo emitido por la Autoridad de Aseo Urbano y Domiciliario.</p>
                    <div className="public-actions">
                        <Link className="public-primary-action" href="/validar-paz-salvo"><ShieldCheck /> Validar Paz y Salvo</Link>
                        <Link className="public-secondary-action" href="/acceso-institucional"><LogIn size={18} /> Acceso institucional</Link>
                    </div>
                </section>
            </main>
        </div>
    );
}
