import { Form, Head, Link, usePage } from '@inertiajs/react';
import { Building2, SearchCheck } from 'lucide-react';
import { useEffect, useState } from 'react';

export default function PublicValidate() {
    const { flash } = usePage<{ flash: { validation_not_found?: string; validation_not_found_id?: string } }>().props;
    const [folio, setFolio] = useState('');
    const [folioError, setFolioError] = useState('');
    const [issuedDate, setIssuedDate] = useState('');
    const [issuedDateError, setIssuedDateError] = useState('');
    const [showNotFound, setShowNotFound] = useState(Boolean(flash.validation_not_found));

    useEffect(() => {
        if (flash.validation_not_found && flash.validation_not_found_id) {
            setShowNotFound(true);
        }
    }, [flash.validation_not_found_id]);

    const formatFolio = (value: string) => {
        const digits = value.replace(/\D/g, '').slice(0, 10);
        const numberPart = digits.slice(0, 6);
        const yearPart = digits.slice(6, 10);

        return `CC-${numberPart}${yearPart ? `-${yearPart}` : ''}`;
    };
    const validateFolio = (value: string) => {
        const isValid = /^CC-\d{6}-\d{4}$/.test(value);
        setFolioError(value && !isValid ? 'Ingrese el folio con el formato CC-000000-2026.' : '');

        return isValid;
    };
    const formatDate = (value: string) => {
        const digits = value.replace(/\D/g, '').slice(0, 8);
        const day = digits.slice(0, 2);
        const month = digits.slice(2, 4);
        const year = digits.slice(4, 8);

        return [day, month, year].filter(Boolean).join('/');
    };
    const isoDateFromDisplay = (value: string) => {
        const match = value.match(/^(\d{2})\/(\d{2})\/(\d{4})$/);
        if (!match) return '';

        const [, day, month, year] = match;
        const date = new Date(Number(year), Number(month) - 1, Number(day));
        if (date.getFullYear() !== Number(year) || date.getMonth() !== Number(month) - 1 || date.getDate() !== Number(day)) {
            return '';
        }

        return `${year}-${month}-${day}`;
    };
    const validateIssuedDate = (value: string) => {
        const isValid = Boolean(isoDateFromDisplay(value));
        setIssuedDateError(value && !isValid ? 'Ingrese la fecha de emisión con el formato día/mes/año.' : '');

        return isValid;
    };

    return (
        <div className="public-page">
            <Head title="Validar Paz y Salvo" />
            <header>
                <Link href="/" className="brand"><Building2 /><div><b>AAUD</b><span>Validación pública</span></div></Link>
                <div className="public-header-actions">
                    <Link className="public-secondary-link" href="/">Inicio</Link>
                </div>
            </header>
            <main className="public-main">
                <section className="manual-validate-card">
                    <p className="eyebrow">CONSULTA MANUAL</p>
                    <h1>Validar Paz y Salvo</h1>
                    <p>Ingrese el folio y la fecha de emisión tal como aparecen en el documento.</p>
                    <Form
                        action="/validar-paz-salvo"
                        method="post"
                        className="manual-validate-form"
                        onSubmit={(event) => {
                            const folioOk = validateFolio(folio);
                            const dateOk = validateIssuedDate(issuedDate);
                            if (!folioOk || !dateOk) {
                                event.preventDefault();
                            }
                        }}
                    >
                        {({ processing, errors }) => (
                            <>
                                <label htmlFor="folio">Folio</label>
                                <input
                                    id="folio"
                                    name="folio"
                                    type="text"
                                    value={folio}
                                    onChange={(event) => {
                                        const next = formatFolio(event.target.value);
                                        setFolio(next);
                                        if (folioError) validateFolio(next);
                                    }}
                                    onFocus={() => { if (!folio) setFolio('CC-'); }}
                                    onBlur={() => validateFolio(folio)}
                                    autoComplete="off"
                                    maxLength={14}
                                    placeholder="CC-000000-2XXX"
                                    required
                                    disabled={processing}
                                />
                                <p className="field-help">Ejemplo: CC-000000-2026</p>
                                {folioError && <p className="field-error" role="alert">{folioError}</p>}
                                {errors.folio && <p className="field-error" role="alert">{errors.folio}</p>}

                                <label htmlFor="fecha_emision">Fecha de emisión</label>
                                <input
                                    id="fecha_emision"
                                    type="text"
                                    inputMode="numeric"
                                    value={issuedDate}
                                    onChange={(event) => {
                                        const next = formatDate(event.target.value);
                                        setIssuedDate(next);
                                        if (issuedDateError) validateIssuedDate(next);
                                    }}
                                    onBlur={() => validateIssuedDate(issuedDate)}
                                    placeholder="dd/mm/aaaa"
                                    maxLength={10}
                                    required
                                    disabled={processing}
                                />
                                <input type="hidden" name="fecha_emision" value={isoDateFromDisplay(issuedDate)} />
                                {issuedDateError && <p className="field-error" role="alert">{issuedDateError}</p>}
                                {errors.fecha_emision && <p className="field-error" role="alert">{errors.fecha_emision}</p>}

                                <button type="submit" disabled={processing}><SearchCheck /> {processing ? 'Validando…' : 'Validar'}</button>
                            </>
                        )}
                    </Form>
                </section>
            </main>
            {showNotFound && (
                <div className="modal-backdrop" role="presentation">
                    <section className="not-found-dialog" role="alertdialog" aria-modal="true" aria-labelledby="not-found-title">
                        <h2 id="not-found-title">Paz y Salvo no encontrado</h2>
                        <p>{flash.validation_not_found}</p>
                        <button type="button" onClick={() => setShowNotFound(false)}>Aceptar</button>
                    </section>
                </div>
            )}
        </div>
    );
}
