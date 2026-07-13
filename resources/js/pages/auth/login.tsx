import { Form, Head, usePage } from '@inertiajs/react';
import { Building2, LogIn } from 'lucide-react';

export default function Login() {
    const { flash } = usePage<{ flash: { error?: string } }>().props;

    return <div className="auth-page"><Head title="Iniciar sesión" /><section className="login-card">
        <div className="brand login-brand"><Building2 /><div><b>AAUD</b><span>Sistema institucional de Paz y Salvo</span></div></div>
        <h1>Iniciar sesión</h1><p>Acceso exclusivo para personal autorizado.</p>
        {flash.error && <div className="notice error" role="alert">{flash.error}</div>}
        <Form action="/login" method="post" resetOnSuccess={['password']}>
            {({ processing, errors }) => <>
                <label>Correo electrónico<input type="email" name="email" autoComplete="email" required autoFocus /></label>
                <label>Contraseña<input type="password" name="password" autoComplete="current-password" required /></label>
                <label className="check"><input type="checkbox" name="remember" value="1" /> Recordarme</label>
                {errors.email && <div className="notice error login-error" role="alert">{errors.email}</div>}
                <button disabled={processing}><LogIn /> {processing ? 'Ingresando…' : 'Ingresar'}</button>
            </>}
        </Form>
    </section></div>;
}
