import { Form, Head } from '@inertiajs/react';
import { KeyRound } from 'lucide-react';
import { AppLayout } from '@/components/app-layout';

export default function Password() {
    return <AppLayout><Head title="Cambiar contraseña"/><section className="page-heading"><p className="eyebrow">SEGURIDAD</p><h1>Cambiar contraseña</h1></section><section className="panel password-panel"><Form action="/user/password" method="put" resetOnSuccess>
        {({processing,errors,recentlySuccessful})=><>
            <label>Contraseña actual<input type="password" name="current_password" autoComplete="current-password" required /></label>{errors.current_password&&<p className="field-error">{errors.current_password}</p>}
            <label>Nueva contraseña<input type="password" name="password" autoComplete="new-password" required /></label>{errors.password&&<p className="field-error">{errors.password}</p>}
            <label>Confirmar nueva contraseña<input type="password" name="password_confirmation" autoComplete="new-password" required /></label>
            <button disabled={processing}><KeyRound/> Actualizar contraseña</button>{recentlySuccessful&&<span className="saved">Contraseña actualizada.</span>}
        </>}
    </Form></section></AppLayout>;
}
