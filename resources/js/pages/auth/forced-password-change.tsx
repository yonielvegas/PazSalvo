import { Head, router, usePage } from '@inertiajs/react';
import { Building2, Check, Circle, Eye, EyeOff, LockKeyhole } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';

type PageProps = {
    changed?: boolean;
    redirect_to?: string;
    errors: Record<string, string>;
};

export default function ForcedPasswordChange() {
    const { errors, changed = false, redirect_to = '/paz-salvos/consultar' } = usePage<PageProps>().props;
    const [password, setPassword] = useState('');
    const [passwordConfirmation, setPasswordConfirmation] = useState('');
    const [showPassword, setShowPassword] = useState(false);
    const [showConfirmation, setShowConfirmation] = useState(false);
    const [processing, setProcessing] = useState(false);

    const checks = useMemo(() => [
        { label: 'Mínimo 8 caracteres', ok: password.length >= 8 },
        { label: 'Una letra mayúscula', ok: /[A-ZÁÉÍÓÚÑ]/.test(password) },
        { label: 'Una letra minúscula', ok: /[a-záéíóúñ]/.test(password) },
        { label: 'Un número', ok: /\d/.test(password) },
        { label: 'Un carácter especial', ok: /[^A-Za-zÁÉÍÓÚÑáéíóúñ0-9]/.test(password) },
        { label: 'Ambas contraseñas coinciden', ok: password.length > 0 && password === passwordConfirmation },
    ], [password, passwordConfirmation]);

    const canSubmit = checks.every((check) => check.ok) && !processing && !changed;

    useEffect(() => {
        if (!changed) return;

        const timeout = window.setTimeout(() => router.visit(redirect_to, { replace: true }), 900);

        return () => window.clearTimeout(timeout);
    }, [changed, redirect_to]);

    const submit = (event: React.FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        if (!canSubmit) return;

        setProcessing(true);
        router.put('/cambiar-contrasena-obligatoria', {
            password,
            password_confirmation: passwordConfirmation,
        }, {
            preserveScroll: true,
            onFinish: () => setProcessing(false),
        });
    };

    return (
        <div className="forced-password-page">
            <Head title="Crear nueva contraseña" />
            <section className={`forced-password-card ${changed ? 'is-success' : ''}`} aria-live="polite">
                <div className="brand login-brand">
                    <Building2 />
                    <div><b>AAUD</b><span>Sistema institucional de Paz y Salvo</span></div>
                </div>

                {changed ? (
                    <div className="forced-success">
                        <div className="forced-success-icon"><Check /></div>
                        <h1>Contraseña actualizada correctamente</h1>
                        <p>Tu cuenta ya está lista. Ingresando al sistema…</p>
                    </div>
                ) : (
                    <>
                        <div className="forced-heading-icon"><LockKeyhole /></div>
                        <h1>Crea una nueva contraseña</h1>
                        <p>Estás utilizando una contraseña temporal. Antes de continuar, debes crear una contraseña personal y segura.</p>
                        <strong className="forced-required-note">Este paso es obligatorio y solo tendrás que realizarlo una vez.</strong>

                        <form onSubmit={submit} className="forced-password-form" noValidate>
                            <div className="forced-field">
                                <label htmlFor="forced-password">Nueva contraseña</label>
                                <p id="forced-password-help">Escribe una contraseña que solo tú conozcas.</p>
                                <div className="password-input-wrap">
                                    <input
                                        id="forced-password"
                                        type={showPassword ? 'text' : 'password'}
                                        value={password}
                                        onChange={(event) => setPassword(event.target.value)}
                                        autoComplete="new-password"
                                        aria-describedby="forced-password-help forced-password-error"
                                        aria-invalid={Boolean(errors.password)}
                                        autoFocus
                                    />
                                    <button type="button" className="password-visibility-button" onClick={() => setShowPassword((value) => !value)} aria-label={showPassword ? 'Ocultar nueva contraseña' : 'Mostrar nueva contraseña'}>
                                        {showPassword ? <EyeOff /> : <Eye />}
                                    </button>
                                </div>
                                {errors.password && <p id="forced-password-error" className="field-error" role="alert">{errors.password}</p>}
                            </div>

                            <div className="forced-field">
                                <label htmlFor="forced-password-confirmation">Confirmar nueva contraseña</label>
                                <p id="forced-password-confirmation-help">Escribe nuevamente la misma contraseña.</p>
                                <div className="password-input-wrap">
                                    <input
                                        id="forced-password-confirmation"
                                        type={showConfirmation ? 'text' : 'password'}
                                        value={passwordConfirmation}
                                        onChange={(event) => setPasswordConfirmation(event.target.value)}
                                        autoComplete="new-password"
                                        aria-describedby="forced-password-confirmation-help"
                                    />
                                    <button type="button" className="password-visibility-button" onClick={() => setShowConfirmation((value) => !value)} aria-label={showConfirmation ? 'Ocultar confirmación de contraseña' : 'Mostrar confirmación de contraseña'}>
                                        {showConfirmation ? <EyeOff /> : <Eye />}
                                    </button>
                                </div>
                            </div>

                            <div className="password-strength-meter" aria-hidden="true">
                                <span style={{ width: `${(checks.filter((check) => check.ok).length / checks.length) * 100}%` }} />
                            </div>

                            <ul className="password-requirements">
                                {checks.map((check) => (
                                    <li key={check.label} className={check.ok ? 'is-met' : ''}>
                                        {check.ok ? <Check /> : <Circle />}
                                        <span>{check.label}</span>
                                    </li>
                                ))}
                            </ul>

                            <button type="submit" disabled={!canSubmit} className="btn-full">
                                {processing ? 'Guardando contraseña…' : 'Guardar nueva contraseña'}
                            </button>
                        </form>
                    </>
                )}
            </section>
        </div>
    );
}
