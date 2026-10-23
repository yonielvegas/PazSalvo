import { Form, Head, usePage } from '@inertiajs/react';
import { Building2, Eye, EyeOff, LogIn, ShieldCheck } from 'lucide-react';
import { useState } from 'react';

export default function Login() {
    const { flash, oidc_login_enabled = false } = usePage<{ flash: { error?: string }; oidc_login_enabled?: boolean }>().props;
    const [passwordVisible, setPasswordVisible] = useState(false);

    return <div className="auth-page"><Head title="Iniciar sesión" /><section className="login-card">
        <div className="brand login-brand"><Building2 /><div><b>AAUD</b><span>Sistema institucional de Paz y Salvo</span></div></div>
        <h1>Iniciar sesión</h1><p>Acceso exclusivo para personal autorizado.</p>
        {flash.error && <div className="notice error" role="alert">{flash.error}</div>}
        <Form action="/login" method="post" resetOnSuccess={['password']}>
            {({ processing, errors }) => <>
                <label>Correo electrónico<input type="email" name="email" autoComplete="email" required autoFocus /></label>
                <label htmlFor="login-password">Contraseña</label><span className="login-password-field">
                    <input id="login-password" type={passwordVisible ? 'text' : 'password'} name="password" autoComplete="current-password" required />
                    <button type="button" className="login-password-toggle" aria-label={passwordVisible ? 'Ocultar contraseña' : 'Mostrar contraseña'} aria-pressed={passwordVisible} onClick={() => setPasswordVisible(!passwordVisible)}>
                        {passwordVisible ? <EyeOff aria-hidden="true" /> : <Eye aria-hidden="true" />}
                    </button>
                </span>
                <label className="check"><input type="checkbox" name="remember" value="1" /> Recordarme</label>
                {errors.email && <div className="notice error login-error" role="alert">{errors.email}</div>}
                <button disabled={processing}><LogIn /> {processing ? 'Ingresando…' : 'Ingresar'}</button>
            </>}
        </Form>
        {oidc_login_enabled && <a href="/auth/aaud/redirect" className="login-sso-button"><ShieldCheck aria-hidden="true" />Iniciar sesión con AAUD AUTH</a>}
        <span className="login-watermark" aria-hidden="true">by: Informatica AAUD</span>
    </section></div>;
}
