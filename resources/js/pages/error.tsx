import { Head } from '@inertiajs/react';
import { AlertTriangle } from 'lucide-react';

type ErrorPageProps = {
    status: number;
    title: string;
    message: string;
    fallback: string;
};

export default function ErrorPage({ status, title, message, fallback }: ErrorPageProps) {
    const handleAccept = () => {
        if (window.history.length > 1 && document.referrer) {
            window.history.back();
            window.setTimeout(() => {
                if (document.visibilityState === 'visible') {
                    window.location.assign(fallback);
                }
            }, 250);

            return;
        }

        window.location.assign(fallback);
    };

    return (
        <>
            <Head title={title} />
            <div className="error-page-shell">
                <section className="error-card">
                    <div className="error-icon"><AlertTriangle /></div>
                    <span className="error-code">{status}</span>
                    <h1>{title}</h1>
                    <p>{message}</p>
                    <button onClick={handleAccept}>Aceptar</button>
                </section>
            </div>
        </>
    );
}
