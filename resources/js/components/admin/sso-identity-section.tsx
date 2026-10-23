import { Check } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

export type VerifiedProfile = Partial<Record<'username' | 'name' | 'email', string>>;
export type SsoLink = { id: number; issuer: string; subject: string; linked_at: string; verified_profile: VerifiedProfile | null };
export type SsoSelection = { issuer: string; subject: string; query: string; page: number; confirmed: boolean };
type Identity = { issuer: string; subject: string; username: string; name: string; name_is_fallback: boolean; enabled: boolean; verified_profile: VerifiedProfile };
type Results = { data: Identity[]; page: number; has_more: boolean };

function initials(name: string): string {
    const words = name.trim().split(/\s+/);
    return (words.length > 1 ? words[0][0] + words[words.length - 1][0] : name.slice(0, 2)).toLocaleUpperCase('es');
}

function DirectoryLabel({ name, username, selected = false, disabled = false }: { name: string; username?: string; selected?: boolean; disabled?: boolean }) {
    return <>
        <span className="sso-avatar" aria-hidden="true">{initials(name)}</span>
        <span className="sso-directory-text"><strong title={name}>{name}</strong>
            {username && <small title={`@${username}`}>@{username}{disabled && ' · Deshabilitado'}</small>}</span>
        <span className={`sso-choice${selected ? ' is-selected' : ''}`} aria-hidden="true">{selected && <Check size={14} />}</span>
    </>;
}

export function panamaDate(value: string): string {
    const parts = Object.fromEntries(new Intl.DateTimeFormat('es-PA', { timeZone: 'America/Panama', day: '2-digit', month: '2-digit', year: 'numeric',
        hour: 'numeric', minute: '2-digit', hour12: true }).formatToParts(new Date(value)).map(({ type, value: part }) => [type, part]));
    return `${parts.day}/${parts.month}/${parts.year}, ${parts.hour}:${parts.minute} ${parts.dayPeriod}`;
}

export function SsoIdentitySection({ enabled, onEnabledChange, onSelect, selection, profile, identity, available, disabled = false, error, mode }: {
    mode: 'create' | 'edit'; enabled: boolean; onEnabledChange: (value: boolean) => void;
    onSelect: (selection: SsoSelection | null, profile: VerifiedProfile) => void; selection: SsoSelection | null;
    profile: VerifiedProfile; identity?: SsoLink | null; available: boolean; disabled?: boolean; error?: string;
}) {
    const [query, setQuery] = useState('');
    const [results, setResults] = useState<Results | null>(null);
    const [searchedQuery, setSearchedQuery] = useState('');
    const [failure, setFailure] = useState('');
    const [searching, setSearching] = useState(false);
    const request = useRef<AbortController | null>(null);
    const sequence = useRef(0);
    useEffect(() => () => { request.current?.abort(); sequence.current++; }, []);

    function reset() { sequence.current++; request.current?.abort(); setSearching(false); setResults(null); setFailure(''); setQuery(''); }
    async function search(page = 1) {
        if (!enabled || disabled || query.trim().length < 3) return;
        request.current?.abort();
        const abort = new AbortController(); request.current = abort;
        const current = ++sequence.current;
        setSearching(true); setFailure(''); setResults(null);
        try {
            const term = query.trim();
            const response = await fetch(`/admin/sso-directory?${new URLSearchParams({ query: term, page: String(page) })}`,
                { credentials: 'same-origin', headers: { Accept: 'application/json' }, signal: abort.signal });
            if (!response.ok) throw new Error('No se pudo consultar AAUD AUTH. Intente nuevamente o desactive la vinculación para continuar localmente.');
            const data = await response.json() as Results;
            if (current === sequence.current) { setResults(data); setSearchedQuery(term); }
        } catch (e) { if (!abort.signal.aborted && current === sequence.current) setFailure((e as Error).message); }
        finally { if (current === sequence.current) setSearching(false); }
    }

    const saved = identity?.verified_profile;
    const showSearch = enabled && !selection && (!identity || !saved);
    return <section className="sso-section sso-compact" aria-label="AAUD AUTH">
        <div className="sso-switch-row"><strong id={`${mode}-sso-label`}>Vincular con AAUD AUTH</strong>
            <button type="button" role="switch" aria-labelledby={`${mode}-sso-label`} aria-checked={enabled}
                className={`sso-switch ${enabled ? 'is-on' : 'is-off'}`} disabled={disabled}
                onClick={() => { reset(); onEnabledChange(!enabled); }}><span aria-hidden="true" /></button>
        </div>
        {error && <p className="field-error" role="alert">{error}</p>}
        {enabled && <>
            {(selection || identity) && <div className="sso-summary">
                <div className="sso-directory-row sso-selected-row" aria-label="Identidad seleccionada">
                    <DirectoryLabel name={profile.name || saved?.name || profile.username || saved?.username || 'Identidad institucional vinculada'}
                        username={profile.username || saved?.username} selected />
                </div>
                <small title={`${selection?.issuer ?? identity?.issuer} · ${selection?.subject ?? identity?.subject}`}>
                    Identidad: {(selection?.subject ?? identity?.subject ?? '').slice(0, 8)}…</small>
                {selection && <button type="button" className="btn-secondary" disabled={disabled} onClick={() => { reset(); onSelect(null, {}); }}>Cambiar selección</button>}
            </div>}
            {showSearch && <>
                {!available ? <p className="sso-help">La vinculación no está disponible. Puede desactivarla y guardar el usuario local.</p> : <>
                    <div className="form-group"><label htmlFor={`${mode}-sso-query`}>Buscar usuario en Active Directory</label>
                        <div className="sso-search"><input id={`${mode}-sso-query`} value={query} maxLength={80} disabled={disabled}
                            placeholder="Nombre o username" autoComplete="off" onChange={(e) => { sequence.current++; request.current?.abort(); setSearching(false); setQuery(e.target.value); setResults(null); setFailure(''); }}
                            onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); void search(); } }} />
                            <button type="button" disabled={disabled || searching || query.trim().length < 3} onClick={() => void search()}>{searching ? 'Buscando…' : 'Buscar'}</button></div>
                    </div>
                    {failure && <p className="field-error" role="alert">{failure}</p>}
                    {results && <div className="sso-results" aria-label="Resultados del directorio">
                        <p className="sso-results-count" role="status">{results.data.length} {results.data.length === 1 ? 'resultado' : 'resultados'} en esta página</p>
                        {results.data.length === 0 && <p className="sso-empty">No se encontraron usuarios verificables. Pruebe con otro nombre o username.</p>}
                        {results.data.length > 0 && <div className="sso-result-list">
                        {results.data.map((row) => {
                            const otherIdentity = !!identity && (identity.issuer !== row.issuer || identity.subject !== row.subject);
                            return <button key={row.subject} type="button" className="sso-candidate sso-directory-row" disabled={disabled || !row.enabled || otherIdentity}
                                title={otherIdentity ? 'Guarde primero la desvinculación actual.' : undefined}
                                onClick={() => { onSelect({ issuer: row.issuer, subject: row.subject, query: searchedQuery, page: results.page, confirmed: true }, row.verified_profile); setResults(null); }}>
                                <DirectoryLabel name={!row.name_is_fallback && row.name ? row.name : row.username} username={row.username} disabled={!row.enabled} />
                            </button>;
                        })}</div>}
                        {(results.page > 1 || results.has_more) && <div className="sso-buttons"><button type="button" className="btn-secondary" disabled={disabled || searching || results.page <= 1} onClick={() => void search(results.page - 1)}>Anterior</button>
                            <button type="button" className="btn-secondary" disabled={disabled || searching || !results.has_more} onClick={() => void search(results.page + 1)}>Siguiente</button></div>}
                    </div>}
                </>}
            </>}
            {selection && mode === 'edit' && <p className="sso-help">Al guardar, los datos locales disponibles se sustituirán por los institucionales seleccionados.</p>}
            {identity && !saved && !selection && <p className="sso-help">Vinculación anterior sin perfil verificado. Seleccione nuevamente esta identidad para verificar sus datos.</p>}
        </>}
        {!enabled && identity && <p className="sso-help">Al guardar se desvinculará AAUD AUTH. El usuario, username, contraseña, rol, agencia e historial se conservarán.</p>}
        {identity && <p className="sso-date">Vinculado al SSO desde: {panamaDate(identity.linked_at)}</p>}
    </section>;
}
