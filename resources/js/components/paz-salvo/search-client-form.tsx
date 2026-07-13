import { Form } from '@inertiajs/react';
import { Search } from 'lucide-react';
import { useState } from 'react';

export function SearchClientForm() {
    const [clientNumber, setClientNumber] = useState('');

    const handleClientNumberChange = (value: string) => {
        setClientNumber(value.replace(/\D/g, '').slice(0, 12));
    };

    return (
        <Form action="/paz-salvos/consultar" method="post" resetOnSuccess={false} className="search-form">
            {({ processing, errors }) => (
                <>
                    <label htmlFor="client_number">Número de cliente</label>
                    <div className="search-row">
                        <input
                            id="client_number"
                            name="client_number"
                            value={clientNumber}
                            onChange={(event) => handleClientNumberChange(event.target.value)}
                            inputMode="numeric"
                            pattern="[0-9]*"
                            autoComplete="off"
                            maxLength={12}
                            placeholder="Ingrese el NAC"
                            autoFocus
                            disabled={processing}
                        />
                        <button type="submit" disabled={processing}><Search size={20} /> {processing ? 'Consultando…' : 'Buscar'}</button>
                    </div>
                    {errors.client_number && <p className="field-error" role="alert">{errors.client_number}</p>}
                    {processing && <div className="consulting-card"><div className="spinner" /><div className="consulting-text"><strong>Consultando ENSA...</strong><p>Estamos verificando el estado del cliente. Espere un momento.</p></div></div>}
                </>
            )}
        </Form>
    );
}
