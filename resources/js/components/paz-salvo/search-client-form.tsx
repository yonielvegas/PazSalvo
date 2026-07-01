import { Form } from '@inertiajs/react';
import { Search } from 'lucide-react';

export function SearchClientForm() {
    return (
        <Form action="/paz-salvos/consultar" method="post" resetOnSuccess={false} className="search-form">
            {({ processing, errors }) => (
                <>
                    <label htmlFor="client_number">Número de cliente</label>
                    <div className="search-row">
                        <input id="client_number" name="client_number" inputMode="numeric" autoComplete="off" maxLength={30} placeholder="Ingrese el NAC" autoFocus disabled={processing} />
                        <button type="submit" disabled={processing}><Search size={20} /> {processing ? 'Consultando…' : 'Buscar'}</button>
                    </div>
                    {errors.client_number && <p className="field-error" role="alert">{errors.client_number}</p>}
                </>
            )}
        </Form>
    );
}
