import { Form, Head } from '@inertiajs/react';
import { Building2, LogIn } from 'lucide-react';

export default function Login() {
    return <div className="auth-page"><Head title="Iniciar sesión" /><section className="login-card">
        <div className="brand login-brand"><Building2 /><div><b>AAUD</b><span>Sistema institucional de Paz y Salvo</span></div></div>
        <h1>Iniciar sesión</h1><p>Acceso exclusivo para personal autorizado.</p>
        <Form action="/login" method="post" resetOnSuccess={['password']}>
            {({ processing, errors }) => <>
                <label>Correo electrónico<input type="email" name="email" autoComplete="email" required autoFocus /></label>
                <label>Contraseña<input type="password" name="password" autoComplete="current-password" required /></label>
                <label className="check"><input type="checkbox" name="remember" value="1" /> Recordarme</label>
                {errors.email && <p className="field-error">{errors.email}</p>}
                <button disabled={processing}><LogIn /> {processing ? 'Ingresando…' : 'Ingresar'}</button>
            </>}
        </Form>
    </section></div>;
}
