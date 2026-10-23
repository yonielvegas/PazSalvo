import { test, expect, type Page } from '@playwright/test';
import { readFile } from 'node:fs/promises';
import { resolve, sep } from 'node:path';

async function fixture(page: Page, enabled: boolean) {
    const build = process.env.SSO_UI_BUILD_DIR;
    if (!build?.startsWith('/tmp/')) throw new Error('A temporary fixture build is required.');
    const manifest = JSON.parse(await readFile(resolve(build, 'manifest.json'), 'utf8'));
    const requests: { method: string; inertia: boolean }[] = [];
    await page.route('**/*', async (route) => {
        const request = route.request();
        const url = new URL(request.url());
        if (url.origin !== 'http://sso-ui.example.test') throw new Error('Unexpected external browser request');
        if (url.pathname.startsWith('/build/')) {
            const file = resolve(build, url.pathname.slice('/build/'.length));
            if (!file.startsWith(resolve(build) + sep)) throw new Error('Unsafe asset path');
            return route.fulfill({ body: await readFile(file), contentType: file.endsWith('.css') ? 'text/css' : 'application/javascript' });
        }
        if (url.pathname === '/auth/aaud/redirect') {
            requests.push({ method: request.method(), inertia: !!request.headers()['x-inertia'] });
            return route.fulfill({ contentType: 'text/html', body: '<h1>OIDC iniciado</h1>' });
        }
        if (url.pathname !== '/login' || request.method() !== 'GET') throw new Error('Unexpected login write');
        const data = { component: 'auth/login', url: '/login', version: null, clearHistory: false, encryptHistory: false,
            props: { auth: { user: null }, errors: {}, flash: {}, oidc_login_enabled: enabled } };
        const json = JSON.stringify(data).replaceAll('<', '\\u003c');
        const styles = [manifest['resources/css/app.css'].file, ...(manifest['resources/js/app.tsx'].css ?? [])].map((file: string) => `<link rel="stylesheet" href="/build/${file}">`).join('');
        return route.fulfill({ contentType: 'text/html', body: `<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1">${styles}</head><body><script type="application/json" data-page="app">${json}</script><div id="app"></div><script type="module" src="/build/${manifest['resources/js/app.tsx'].file}"></script></body></html>` });
    });
    await page.goto('/login');
    return requests;
}

test('SSO and email/password login remain separately available', async ({ page }) => {
    await fixture(page, true);
    await expect(page.getByRole('link', { name: 'Iniciar sesión con AAUD AUTH' })).toHaveAttribute('href', '/auth/aaud/redirect');
    await expect(page.locator('input[name="email"]')).toBeVisible();
    await expect(page.locator('input[name="password"]')).toBeVisible();
    await expect(page.getByRole('button', { name: 'Ingresar', exact: true })).toBeVisible();
    await expect(page.locator('form')).toHaveAttribute('action', '/login');
});

test('disabled SSO leaves the existing local form available', async ({ page }) => {
    await fixture(page, false);
    await expect(page.getByRole('link', { name: 'Iniciar sesión con AAUD AUTH' })).toHaveCount(0);
    await expect(page.locator('input[name="email"]')).toBeVisible();
    await expect(page.getByRole('button', { name: 'Ingresar', exact: true })).toBeEnabled();
});

for (const mobile of [false, true]) {
    test(`OIDC starts a normal navigation by keyboard ${mobile ? 'mobile' : 'desktop'}`, async ({ page }) => {
        await page.setViewportSize(mobile ? { width: 390, height: 844 } : { width: 1280, height: 900 });
        const requests = await fixture(page, true);
        const button = page.getByRole('link', { name: 'Iniciar sesión con AAUD AUTH' });
        await button.focus();
        await expect(button).toBeFocused();
        const box = await button.boundingBox();
        expect(box).not.toBeNull();
        expect(box!.x).toBeGreaterThanOrEqual(0);
        expect(box!.x + box!.width).toBeLessThanOrEqual(mobile ? 390 : 1280);
        await button.press('Enter');
        await expect(page.getByRole('heading', { name: 'OIDC iniciado' })).toBeVisible();
        expect(requests).toEqual([{ method: 'GET', inertia: false }]);
    });
}
