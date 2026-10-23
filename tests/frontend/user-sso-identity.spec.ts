import { test, expect, type Page, type Request } from '@playwright/test';
import { readFile } from 'node:fs/promises';
import { resolve, sep } from 'node:path';

const issuer = 'https://keycloak.example.test/realms/aaud';
const createdAt = '2026-10-19T14:15:00Z';
const linkedAt = '2026-10-20T15:35:00Z';
const verified = { username: 'directory-user', name: 'Directory User', email: 'directory@example.test' };

async function body(request: Request): Promise<Record<string, unknown>> {
    if (request.headers()['content-type']?.includes('multipart/form-data')) {
        const form = await new globalThis.Request(request.url(), { method: 'POST', headers: { 'Content-Type': request.headers()['content-type'] }, body: request.postDataBuffer()! }).formData();
        return Object.fromEntries(form.entries());
    }
    return request.postDataJSON();
}

async function fixture(page: Page, options: { sync?: 'pending' | 'error' | 'confirmed'; linked?: boolean; available?: boolean; conflict?: boolean; unavailable?: boolean; networkError?: boolean; fallback?: boolean; missingEmail?: boolean; legacy?: boolean; empty?: boolean; paged?: boolean } = {}) {
    const build = process.env.SSO_UI_BUILD_DIR;
    if (!build || !build.startsWith('/tmp/')) throw new Error('Build into /tmp and set SSO_UI_BUILD_DIR first.');
    const manifest = JSON.parse(await readFile(resolve(build, 'manifest.json'), 'utf8'));
    const profile = { username: verified.username, ...(!options.fallback ? { name: verified.name } : {}), ...(!options.missingEmail ? { email: verified.email } : {}) };
    let linked = options.linked ?? false;
    const mutations: { method: string; path: string; body: Record<string, unknown> }[] = [];
    const unexpected: string[] = [];
    const browserErrors: string[] = [];
    page.on('pageerror', (error) => browserErrors.push(error.message));
    const identity = () => linked ? { id: 9, issuer, subject: 'verified-subject', linked_at: linkedAt, verified_profile: options.legacy ? null : profile } : null;
    const user = { id: 42, name: linked && !options.legacy ? profile.name ?? 'Local User' : 'Local User',
        username: linked && !options.legacy ? profile.username : 'local.user',
        email: linked && !options.legacy ? profile.email ?? 'local@example.test' : 'local@example.test', agency_id: 1, agency: 'Fixture', role: 'operador',
        is_active: true, created_at: createdAt, has_active_general_admin_signature: false, has_any_general_admin_signature: false, login_attempts: 0,
        is_login_blocked: false, must_change_password: false, has_active_session: false, active_session_count: 0, active_session_last_activity: null };
    let extraUser: Record<string, unknown> | null = null;
    function inertia(errors = {}) {
        const sync = options.sync === 'error' ? 'Cambio local guardado. Error de sincronización que requiere atención.' : options.sync === 'pending' ? 'Cambio local guardado, sincronización con AAUD AUTH pendiente.' : options.sync === 'confirmed' ? 'Sincronizado correctamente con AAUD AUTH.' : null;
        const current = { ...user, sso_linked: linked, sso_identity: identity(), sso_sync: sync };
        return { component: 'admin/users/index', url: '/admin/users', version: null, clearHistory: false, encryptHistory: false,
            props: { errors, flash: {}, auth: { user: { name: 'Admin Fixture', agency: null, permissions: ['administrar usuarios'] } },
                globalStatistics: null, sso_directory_available: options.available ?? true,
                agencies: [{ id: 1, name: 'Fixture' }], roles: ['operador', 'consulta', 'administrador_general'], temporary_password: 'fixture-only',
                users: extraUser ? [current, extraUser] : [current] } };
    }
    await page.route('**/*', async (route) => {
        const request = route.request(); const url = new URL(request.url());
        if (url.origin !== 'http://sso-ui.example.test') { unexpected.push(request.url()); return route.abort(); }
        if (url.pathname.startsWith('/build/')) {
            const file = resolve(build, url.pathname.slice('/build/'.length));
            if (!file.startsWith(resolve(build) + sep)) throw new Error('Unsafe asset path');
            return route.fulfill({ body: await readFile(file), contentType: file.endsWith('.css') ? 'text/css' : 'application/javascript' });
        }
        if (url.pathname === '/admin/users' && request.method() === 'GET') {
            if (request.headers()['x-inertia']) return route.fulfill({ headers: { 'X-Inertia': 'true' }, json: inertia() });
            const json = JSON.stringify(inertia()).replaceAll('<', '\\u003c');
            const styles = [manifest['resources/css/app.css'].file, ...(manifest['resources/js/app.tsx'].css ?? [])].map((file: string) => `<link rel="stylesheet" href="/build/${file}">`).join('');
            return route.fulfill({ contentType: 'text/html', body: `<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">${styles}</head><body><script type="application/json" data-page="app">${json}</script><div id="app"></div><script type="module" src="/build/${manifest['resources/js/app.tsx'].file}"></script></body></html>` });
        }
        if (url.pathname === '/admin/sso-directory' && request.method() === 'GET') {
            if (options.unavailable) return route.fulfill({ status: 503, json: { message: 'Unavailable' } });
            const candidates = [
                { issuer, subject: 'verified-subject', username: verified.username, name: options.fallback ? verified.username : verified.name, name_is_fallback: !!options.fallback,
                    email: options.missingEmail ? null : verified.email, enabled: true, verified_profile: profile },
                { issuer, subject: 'disabled-subject', username: 'disabled', name: 'Disabled User', name_is_fallback: false, email: null, enabled: false, verified_profile: { username: 'disabled' } }];
            const pageNumber = Number(url.searchParams.get('page'));
            const rows = options.empty ? [] : options.paged ? Array.from({ length: 8 }, (_, i) => ({ ...candidates[0],
                subject: `page-${pageNumber}-subject-${i}`, username: `directory-${pageNumber}-${i}`, name: `Directory Person ${i}`,
                verified_profile: { ...profile, username: `directory-${pageNumber}-${i}`, name: `Directory Person ${i}` } })) : candidates;
            return route.fulfill({ json: { data: rows, page: pageNumber, limit: 20, has_more: !!options.paged && pageNumber === 1 } });
        }
        if (request.method() === 'POST' && ['/admin/users', '/admin/users/42'].includes(url.pathname)) {
            const data = await body(request); mutations.push({ method: 'POST', path: url.pathname, body: data });
            if (!options.conflict) {
                if (url.pathname === '/admin/users') extraUser = { ...user, id: 43, name: data.name, email: data.email, username: data.username, sso_linked: !!data['sso_identity[subject]'], sso_identity: null };
                else { linked = data.sso_action === 'unlink' ? false : data.sso_action === 'link' ? true : linked;
                    Object.assign(user, { name: data.name, email: data.email, username: data.username }); }
            }
            if (options.networkError) return route.abort('failed');
            return route.fulfill({ headers: { 'X-Inertia': 'true' }, json: inertia(options.conflict ? { username: 'El username ya pertenece a otro usuario.' } : {}) });
        }
        unexpected.push(`${request.method()} ${request.url()}`); return route.abort();
    });
    await page.goto('/admin/users');
    await expect(page.getByRole('button', { name: 'Nuevo usuario', exact: true })).toBeVisible();
    return { mutations, unexpected, browserErrors };
}

const toggle = (page: Page) => page.getByRole('switch', { name: 'Vincular con AAUD AUTH' });
async function selectIdentity(page: Page) {
    if (await toggle(page).getAttribute('aria-checked') === 'false') await toggle(page).click();
    await page.getByLabel('Buscar usuario en Active Directory').fill('directory');
    await page.getByLabel('Buscar usuario en Active Directory').press('Enter');
    await expect(page.locator('.sso-candidate')).toHaveCount(2);
    await expect(page.locator('.sso-candidate').nth(1)).toBeDisabled();
    await page.locator('.sso-candidate').first().click();
}
async function fillAgencyRole(page: Page) { await page.locator('#create-agency').selectOption('1'); await page.locator('#create-role').selectOption('operador'); }
function safe(f: Awaited<ReturnType<typeof fixture>>) { expect(f.unexpected).toEqual([]); expect(f.browserErrors).toEqual([]); }

test('informational SSO badges remain in actions without old chain button', async ({ page }) => {
    const f = await fixture(page);
    await expect(page.locator('.actions-cell .sso-badge')).toHaveText('SSO: No');
    await expect(page.locator('.actions-cell .sso-badge')).toHaveCSS('background-color', 'rgb(254, 226, 226)');
    await expect(page.locator('tbody tr').first().locator('td').nth(4)).not.toContainText('SSO:');
    await expect(page.getByRole('button', { name: 'Identidad SSO', exact: true })).toHaveCount(0);
    expect(f.mutations).toEqual([]); safe(f);
});

test('create switch defaults on, verified fields preload and lock without redundant confirmation controls', async ({ page }) => {
    const f = await fixture(page);
    await page.getByRole('button', { name: 'Nuevo usuario', exact: true }).click();
    await expect(toggle(page)).toHaveAttribute('aria-checked', 'true');
    await expect(toggle(page)).toHaveCSS('background-color', 'rgb(22, 139, 90)');
    await selectIdentity(page);
    for (const field of ['username', 'name', 'email'] as const) {
        await expect(page.locator(`#create-${field}`)).toHaveValue(verified[field]);
        await expect(page.locator(`#create-${field}`)).toHaveAttribute('readonly');
    }
    await expect(page.locator('.sso-compact input[type=checkbox]')).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Vincular identidad seleccionada' })).toHaveCount(0);
    expect(f.mutations).toEqual([]);
    await fillAgencyRole(page);
    await page.getByRole('button', { name: 'Crear usuario y vincular SSO', exact: true }).click();
    await expect(page.getByRole('dialog')).toHaveCount(0);
    expect(f.mutations).toHaveLength(1);
    expect(f.mutations[0]).toMatchObject({ path: '/admin/users', body: { ...verified, 'sso_identity[subject]': 'verified-subject', 'sso_identity[confirmed]': '1' } });
    safe(f);
});

test('switch off restores manually entered draft and hides search entirely', async ({ page }) => {
    const f = await fixture(page);
    await page.getByRole('button', { name: 'Nuevo usuario', exact: true }).click();
    await toggle(page).click();
    await expect(toggle(page)).toHaveCSS('background-color', 'rgb(220, 38, 38)');
    for (const [field, value] of Object.entries({ username: 'manual.user', name: 'Manual Name', email: 'manual@example.test' })) await page.locator(`#create-${field}`).fill(value);
    await selectIdentity(page); await toggle(page).click();
    await expect(page.getByLabel('Buscar usuario en Active Directory')).toHaveCount(0);
    await expect(page.locator('#create-name')).toHaveValue('Manual Name');
    await expect(page.locator('#create-email')).toHaveValue('manual@example.test');
    await expect(page.locator('#create-username')).toHaveValue('manual.user');
    await expect(page.locator('#create-name')).not.toHaveAttribute('readonly');
    await fillAgencyRole(page); await page.getByRole('button', { name: 'Crear usuario', exact: true }).click();
    await expect(page.getByRole('dialog')).toHaveCount(0);
    expect(Object.keys(f.mutations[0].body).some((key) => key.startsWith('sso_identity'))).toBe(false); safe(f);
});

test('fallback name and missing email stay editable and do not become institutional fields', async ({ page }) => {
    const f = await fixture(page, { fallback: true, missingEmail: true });
    await page.getByRole('button', { name: 'Nuevo usuario', exact: true }).click(); await selectIdentity(page);
    await expect(page.locator('#create-name')).toHaveValue('');
    await expect(page.locator('#create-name')).not.toHaveAttribute('readonly');
    await expect(page.locator('#create-email')).not.toHaveAttribute('readonly');
    await expect(page.locator('#create-username')).toHaveAttribute('readonly');
    await page.locator('#create-name').fill('Completed Locally'); await page.locator('#create-email').fill('completed@example.test');
    await fillAgencyRole(page); await page.getByRole('button', { name: 'Crear usuario y vincular SSO', exact: true }).click();
    await expect(page.getByRole('dialog')).toHaveCount(0);
    expect(f.mutations[0].body).toMatchObject({ name: 'Completed Locally', email: 'completed@example.test' }); safe(f);
});

test('unlinked edit defaults off and selecting directory data replaces the profile only when saved', async ({ page }) => {
    const f = await fixture(page);
    await page.getByRole('button', { name: 'Editar usuario', exact: true }).click();
    await expect(toggle(page)).toHaveAttribute('aria-checked', 'false');
    await expect(page.locator('#edit-name')).toHaveValue('Local User');
    await selectIdentity(page);
    await expect(page.getByText(/Al guardar, los datos locales disponibles/)).toBeVisible();
    await expect(page.locator('#edit-name')).toHaveValue('Directory User');
    await expect(page.locator('#edit-name')).toHaveAttribute('readonly');
    expect(f.mutations).toEqual([]);
    await page.getByRole('button', { name: 'Guardar cambios', exact: true }).click();
    await expect(page.getByRole('dialog')).toHaveCount(0);
    expect(f.mutations).toHaveLength(1);
    expect(f.mutations[0]).toMatchObject({ path: '/admin/users/42', body: { ...verified, _method: 'PUT', sso_action: 'link', 'sso_identity[subject]': 'verified-subject' } }); safe(f);
});

test('canceling edit after selection never persists profile or identity', async ({ page }) => {
    const f = await fixture(page);
    await page.getByRole('button', { name: 'Editar usuario', exact: true }).click(); await selectIdentity(page);
    await page.getByRole('button', { name: 'Cancelar', exact: true }).click();
    await expect(page.getByRole('dialog')).toHaveCount(0);
    await expect(page.locator('.actions-cell .sso-badge')).toHaveText('SSO: No');
    expect(f.mutations).toEqual([]); safe(f);
});

test('saved identity defaults on with stored provenance, compact identity and dates in Panama', async ({ page }) => {
    const f = await fixture(page, { linked: true });
    await expect(page.locator('.actions-cell .sso-badge')).toHaveText('SSO: SI');
    await page.getByRole('button', { name: 'Editar usuario', exact: true }).click();
    await expect(toggle(page)).toHaveAttribute('aria-checked', 'true');
    await expect(page.locator('.sso-summary')).toContainText('directory-user');
    await expect(page.locator('#edit-username')).toHaveAttribute('readonly');
    await expect(page.getByText(/Vinculado al SSO desde:/)).toHaveText('Vinculado al SSO desde: 20/10/2026, 10:35 a. m.');
    await expect(page.getByText(/Usuario creado en Paz y Salvo:/)).toContainText('19/10/2026, 9:15');
    await expect(page.getByLabel('Buscar usuario en Active Directory')).toHaveCount(0);
    expect(f.mutations).toEqual([]); safe(f);
});

test('unlink is pending until save and explicit confirmation, with local username preserved', async ({ page }) => {
    const f = await fixture(page, { linked: true });
    await page.getByRole('button', { name: 'Editar usuario', exact: true }).click(); await toggle(page).click();
    await expect(page.locator('#edit-username')).toHaveValue('directory-user');
    expect(f.mutations).toEqual([]);
    await page.getByRole('button', { name: 'Guardar cambios', exact: true }).click();
    await expect(page.getByRole('dialog', { name: 'Guardar desvinculación de AAUD AUTH' })).toBeVisible();
    expect(f.mutations).toEqual([]);
    await page.getByRole('button', { name: 'Cancelar', exact: true }).click();
    await expect(toggle(page)).toHaveAttribute('aria-checked', 'false');
    await page.getByRole('button', { name: 'Guardar cambios', exact: true }).click();
    await page.getByRole('button', { name: 'Confirmar y guardar', exact: true }).click();
    await expect(page.getByRole('dialog')).toHaveCount(0);
    expect(f.mutations).toHaveLength(1);
    expect(f.mutations[0]).toMatchObject({ path: '/admin/users/42', body: { sso_action: 'unlink', sso_identity_id: '9', sso_unlink_confirmed: '1', username: 'directory-user' } }); safe(f);
});

test('canceling pending unlink keeps the persisted badge linked', async ({ page }) => {
    const f = await fixture(page, { linked: true });
    await page.getByRole('button', { name: 'Editar usuario', exact: true }).click(); await toggle(page).click();
    await page.getByRole('button', { name: 'Cancelar', exact: true }).click();
    await expect(page.locator('.actions-cell .sso-badge')).toHaveText('SSO: SI'); expect(f.mutations).toEqual([]); safe(f);
});

test('directory outage permits deliberate local creation by switching off', async ({ page }) => {
    const f = await fixture(page, { unavailable: true });
    await page.getByRole('button', { name: 'Nuevo usuario', exact: true }).click();
    await page.getByLabel('Buscar usuario en Active Directory').fill('directory'); await page.getByRole('button', { name: 'Buscar', exact: true }).click();
    await expect(page.getByRole('alert')).toContainText('No se pudo consultar'); await toggle(page).click();
    await page.locator('#create-name').fill('Local'); await page.locator('#create-email').fill('localnew@example.test');
    await fillAgencyRole(page); await page.getByRole('button', { name: 'Crear usuario', exact: true }).click();
    await expect(page.getByRole('dialog')).toHaveCount(0); expect(f.mutations).toHaveLength(1); safe(f);
});

test('username collision preserves the draft and does not repeat creation', async ({ page }) => {
    const f = await fixture(page, { conflict: true });
    await page.getByRole('button', { name: 'Nuevo usuario', exact: true }).click(); await selectIdentity(page); await fillAgencyRole(page);
    await page.getByRole('button', { name: 'Crear usuario y vincular SSO', exact: true }).click();
    await expect(page.getByText('El username ya pertenece a otro usuario.', { exact: true })).toBeVisible();
    await expect(page.locator('#create-username')).toHaveValue('directory-user');
    expect(f.mutations).toHaveLength(1); safe(f);
});

test('lost creation response blocks resubmission and reconciles only by reading the list', async ({ page }) => {
    const f = await fixture(page, { networkError: true });
    await page.getByRole('button', { name: 'Nuevo usuario', exact: true }).click(); await selectIdentity(page); await fillAgencyRole(page);
    await page.getByRole('button', { name: 'Crear usuario y vincular SSO', exact: true }).click();
    await expect(page.getByRole('alert')).toContainText('No se pudo confirmar el resultado');
    await expect(page.getByRole('button', { name: 'Crear usuario y vincular SSO', exact: true })).toBeDisabled();
    await page.getByRole('button', { name: 'Actualizar listado y comprobar' }).click(); await expect(page.getByRole('dialog')).toHaveCount(0);
    expect(f.mutations).toHaveLength(1); safe(f);
});

test('lost unified edit response blocks repeated save until list is refreshed', async ({ page }) => {
    const f = await fixture(page, { networkError: true });
    await page.getByRole('button', { name: 'Editar usuario', exact: true }).click(); await selectIdentity(page);
    await page.getByRole('button', { name: 'Guardar cambios', exact: true }).click();
    await expect(page.getByRole('alert')).toContainText('No se pudo confirmar el resultado');
    await expect(page.getByRole('button', { name: 'Guardar cambios', exact: true })).toBeDisabled();
    await page.getByRole('button', { name: 'Actualizar listado y comprobar' }).click(); await expect(page.getByRole('dialog')).toHaveCount(0);
    expect(f.mutations).toHaveLength(1); safe(f);
});

test('legacy identity does not guess provenance from local data', async ({ page }) => {
    const f = await fixture(page, { linked: true, legacy: true });
    await page.getByRole('button', { name: 'Editar usuario', exact: true }).click();
    await expect(page.locator('#edit-name')).not.toHaveAttribute('readonly');
    await expect(page.getByText(/Vinculación anterior sin perfil verificado/)).toBeVisible();
    await selectIdentity(page); await expect(page.locator('#edit-name')).toHaveAttribute('readonly');
    expect(f.mutations).toEqual([]); safe(f);
});

test('changing selection restores draft without writing', async ({ page }) => {
    const f = await fixture(page);
    await page.getByRole('button', { name: 'Nuevo usuario', exact: true }).click(); await selectIdentity(page);
    await page.getByRole('button', { name: 'Cambiar selección', exact: true }).click();
    await expect(page.locator('#create-name')).toHaveValue(''); await expect(page.getByLabel('Buscar usuario en Active Directory')).toBeVisible();
    expect(f.mutations).toEqual([]); safe(f);
});

test('mobile form remains usable with a compact SSO section and accessible save actions', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 740 }); const f = await fixture(page, { linked: true });
    await page.getByRole('button', { name: 'Editar usuario', exact: true }).click();
    expect(await page.getByRole('dialog').evaluate((el) => el.scrollWidth <= el.clientWidth + 1)).toBe(true);
    await page.getByText(/Usuario creado en Paz y Salvo:/).scrollIntoViewIfNeeded();
    await expect(page.getByRole('button', { name: 'Guardar cambios', exact: true })).toBeInViewport(); safe(f);
});

for (const mobile of [false, true]) {
    for (const mode of ['create', 'edit'] as const) {
        test(`switch geometry and keyboard ${mode} ${mobile ? 'mobile' : 'desktop'}`, async ({ page }, testInfo) => {
            if (mobile) await page.setViewportSize({ width: 390, height: 740 });
            const f = await fixture(page);
            await page.getByRole('button', { name: mode === 'create' ? 'Nuevo usuario' : 'Editar usuario', exact: true }).click();
            await page.getByRole('dialog').evaluate(async (el) => { await Promise.all(el.getAnimations({ subtree: true }).map((animation) => animation.finished)); });
            const measurements = [];
            for (const enabled of [true, false]) {
                if (await toggle(page).getAttribute('aria-checked') !== String(enabled)) await toggle(page).click();
                await expect(toggle(page).locator('span')).toHaveCSS('transform', enabled ? 'matrix(1, 0, 0, 1, 24, 0)' : 'matrix(1, 0, 0, 1, 0, 0)');
                const geometry = await toggle(page).evaluate((el) => {
                    const a = el.getBoundingClientRect(); const b = el.firstElementChild!.getBoundingClientRect();
                    return { track: [a.width, a.height], thumb: [b.width, b.height], left: b.left - a.left, right: a.right - b.right, top: b.top - a.top, bottom: a.bottom - b.bottom };
                });
                expect(geometry).toEqual({ track: [52, 28], thumb: [22, 22], left: enabled ? 27 : 3, right: enabled ? 3 : 27, top: 3, bottom: 3 });
                measurements.push({ enabled, ...geometry });
            }
            await toggle(page).focus(); await toggle(page).press('Space');
            await expect(toggle(page)).toHaveAttribute('aria-checked', 'true');
            await expect(toggle(page)).toBeFocused();
            await expect(toggle(page)).toHaveCSS('outline-style', 'solid');
            await toggle(page).press('Enter'); await expect(toggle(page)).toHaveAttribute('aria-checked', 'false');
            await testInfo.attach('switch-geometry', { body: JSON.stringify(measurements, null, 2), contentType: 'application/json' });
            expect(f.mutations).toEqual([]); safe(f);
        });
    }
    test(`compact directory rows, scrolling, pagination and selection ${mobile ? 'mobile' : 'desktop'}`, async ({ page }, testInfo) => {
        if (mobile) await page.setViewportSize({ width: 390, height: 740 });
        const f = await fixture(page, { paged: true });
        await page.getByRole('button', { name: 'Nuevo usuario', exact: true }).click();
        const search = page.getByLabel('Buscar usuario en Active Directory');
        await search.fill('directory'); await search.press('Enter');
        await expect(page.locator('.sso-results-count')).toHaveText('8 resultados en esta página');
        await expect(page.locator('.sso-candidate')).toHaveCount(8);
        await page.getByRole('dialog').evaluate(async (el) => { await Promise.all(el.getAnimations({ subtree: true }).map((animation) => animation.finished)); });
        const rows = await page.locator('.sso-candidate').evaluateAll((els) => els.map((el) => ({ height: el.getBoundingClientRect().height, align: getComputedStyle(el).textAlign })));
        expect(rows.every((row) => row.height === 60 && row.align === 'left')).toBe(true);
        await expect(page.locator('.sso-candidate').first().locator('strong')).toHaveText('Directory Person 0');
        await expect(page.locator('.sso-candidate').first().locator('small')).toHaveText('@directory-1-0');
        await expect(page.locator('.sso-candidate').first().locator('.sso-avatar')).toHaveText('D0');
        await expect(page.locator('.sso-results')).not.toContainText('subject');
        expect(await page.locator('.sso-result-list').evaluate((el) => el.clientHeight <= 258 && el.scrollHeight > el.clientHeight && el.scrollWidth <= el.clientWidth)).toBe(true);
        expect(await page.getByRole('dialog').evaluate((el) => el.scrollWidth <= el.clientWidth + 1)).toBe(true);
        await page.locator('.sso-result-list').evaluate((el) => { el.scrollTop = el.scrollHeight; });
        expect(await page.locator('.sso-result-list').evaluate((el) => el.scrollTop)).toBeGreaterThan(0);
        await page.getByRole('button', { name: 'Siguiente', exact: true }).click();
        await expect(page.locator('.sso-candidate').first()).toContainText('@directory-2-0');
        await expect(page.getByRole('button', { name: 'Siguiente', exact: true })).toBeDisabled();
        await page.getByRole('button', { name: 'Anterior', exact: true }).click();
        await expect(page.locator('.sso-candidate').first()).toContainText('@directory-1-0');
        await page.locator('.sso-results').scrollIntoViewIfNeeded();
        await page.screenshot({ path: testInfo.outputPath('directory-results.png') });
        await page.locator('.sso-candidate').first().focus(); await page.locator('.sso-candidate').first().press('Enter');
        await expect(page.locator('.sso-selected-row')).toHaveCSS('border-color', 'rgb(22, 139, 90)');
        await expect(page.locator('.sso-selected-row .sso-choice svg')).toBeVisible();
        await expect(page.locator('#create-username')).toHaveValue('directory-1-0');
        await page.screenshot({ path: testInfo.outputPath('directory-selected.png') });
        await page.getByRole('button', { name: 'Cambiar selección', exact: true }).click();
        await expect(search).toBeVisible(); expect(f.mutations).toEqual([]); safe(f);
    });
}

test('empty directory results explain how to search again', async ({ page }) => {
    const f = await fixture(page, { empty: true });
    await page.getByRole('button', { name: 'Nuevo usuario', exact: true }).click();
    await page.getByLabel('Buscar usuario en Active Directory').fill('nobody');
    await page.getByRole('button', { name: 'Buscar', exact: true }).click();
    await expect(page.locator('.sso-results-count')).toHaveText('0 resultados en esta página');
    await expect(page.locator('.sso-empty')).toContainText('Pruebe con otro nombre o username.');
    await expect(page.locator('.sso-candidate')).toHaveCount(0);
    expect(f.mutations).toEqual([]); safe(f);
});

for (const sync of ['pending', 'error', 'confirmed'] as const) {
    test(`delivery indicator ${sync} remains separate from identity linkage`, async ({ page }) => {
        await fixture(page, { linked: true, sync });
        await expect(page.getByText('SSO: SI', { exact: true })).toBeVisible();
        if (sync === 'pending') await expect(page.getByText('SSO pendiente', { exact: true })).toBeVisible();
        if (sync === 'error') await expect(page.getByText('SSO: revisar', { exact: true })).toBeVisible();
        if (sync === 'confirmed') {
            await expect(page.getByText('SSO pendiente', { exact: true })).toHaveCount(0);
            await expect(page.getByText('SSO: revisar', { exact: true })).toHaveCount(0);
        }
    });
}
