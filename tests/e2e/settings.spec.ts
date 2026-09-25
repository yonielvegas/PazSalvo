import { test, expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';

const password = process.env.E2E_PASSWORD || 'e2e-test-password';
test.beforeAll(() => { execFileSync('php', ['tests/e2e/seed-settings-viewer.php']); });

async function login(page: import('@playwright/test').Page, email: string) {
    await page.goto('/login');
    await page.getByLabel('Correo electrónico').fill(email);
    await page.locator('#login-password').fill(password);
    await page.getByRole('button', { name: 'Ingresar' }).click();
    await page.waitForURL('**/paz-salvos/consultar');
}

test('configuración y ciclo de vida de agencias', async ({ page }) => {
    const code = `QA-${Date.now()}`;
    await login(page, 'admin@aaud.gob.pa');
    await page.getByRole('link', { name: 'Configuración' }).click();
    await expect(page.getByRole('link', { name: /Agencias/ })).toBeVisible();
    await expect(page.getByRole('link', { name: /Roles y Permisos/ })).toBeVisible();
    await page.getByRole('link', { name: /Agencias/ }).click();
    await expect(page.getByRole('heading', { name: 'Agencias' })).toBeVisible();
    await page.getByRole('button', { name: 'Crear Agencia' }).click();
    await page.locator('#agency-code').fill(code);
    await page.locator('#agency-name').fill('Agencia Playwright');
    await page.getByRole('dialog').getByRole('button', { name: 'Guardar' }).click();
    const row = page.locator('tr').filter({ hasText: code });
    await expect(row).toContainText('Agencia Playwright');
    await row.getByRole('button', { name: 'Editar' }).click();
    await page.locator('#agency-name').fill('Agencia Playwright Editada');
    await page.getByRole('dialog').getByRole('button', { name: 'Guardar' }).click();
    await expect(row).toContainText('Agencia Playwright Editada');
    await page.locator('#agency-search').fill(code);
    await page.getByRole('button', { name: 'Buscar' }).click();
    await expect(row).toBeVisible();
    await row.getByRole('button', { name: 'Desactivar' }).click();
    await expect(page.getByRole('dialog')).toContainText('conservarán sus relaciones');
    await page.getByRole('dialog').getByRole('button', { name: 'Cancelar' }).click();
    await expect(row).toContainText('Activa');
    await row.getByRole('button', { name: 'Desactivar' }).click();
    await page.getByRole('dialog').getByRole('button', { name: 'Desactivar' }).click();
    await expect(row).toContainText('Inactiva');
    await page.locator('#agency-status').selectOption('inactive');
    await expect(row).toBeVisible();
    await row.getByRole('button', { name: 'Reactivar' }).click();
    await page.getByRole('dialog').getByRole('button', { name: 'Reactivar' }).click();
    await page.locator('#agency-status').selectOption('active');
    await expect(row).toContainText('Activa');
});

test('roles agrupa y conserva permisos específicos', async ({ page }) => {
    const roleName = `qa_role_${Date.now()}`;
    await login(page, 'admin@aaud.gob.pa');
    await page.goto('/settings/roles');
    await expect(page.getByRole('heading', { name: 'Roles y Permisos' })).toBeVisible();
    await page.getByRole('button', { name: 'Crear rol' }).click();
    await page.locator('#role-name').fill(roleName);
    await expect(page.getByRole('dialog').getByRole('heading', { name: 'Excel de Clientes' })).toBeVisible();
    await page.getByRole('dialog').locator('label').filter({ hasText: 'settings.view' }).locator('input').check();
    await page.getByRole('dialog').locator('label').filter({ hasText: 'clients-excel.view' }).locator('input').check();
    await expect(page.getByRole('dialog')).toContainText('2 seleccionados');
    await page.getByRole('dialog').getByRole('button', { name: 'Guardar rol' }).click();
    const row = page.locator('.settings-role-row').filter({ hasText: roleName });
    await expect(row).toContainText('2 permisos');
    await row.getByRole('button', { name: 'Permisos' }).click();
    const dialog = page.getByRole('dialog');
    await expect(dialog.locator('label').filter({ hasText: 'settings.view' }).locator('input')).toBeChecked();
    await expect(dialog.locator('label').filter({ hasText: 'clients-excel.view' }).locator('input')).toBeChecked();
    await expect(dialog.locator('input[type=checkbox]:checked')).toHaveCount(2);
    await dialog.locator('label').filter({ hasText: 'settings.view' }).locator('input').uncheck();
    await dialog.locator('label').filter({ hasText: 'clients-excel.download' }).locator('input').check();
    await dialog.getByRole('button', { name: 'Guardar permisos' }).click();
    await row.getByRole('button', { name: 'Permisos' }).click();
    await expect(dialog.locator('label').filter({ hasText: 'settings.view' }).locator('input')).not.toBeChecked();
    await expect(dialog.locator('label').filter({ hasText: 'clients-excel.download' }).locator('input')).toBeChecked();
    await expect(dialog.locator('input[type=checkbox]:checked')).toHaveCount(2);
    await dialog.getByRole('button', { name: 'Cancelar' }).click();
    await row.getByRole('button', { name: 'Editar' }).click();
    await page.locator('#role-name').fill(`${roleName}_edit`);
    await page.getByRole('dialog').getByRole('button', { name: 'Guardar rol' }).click();
    await expect(page.locator('.settings-role-row').filter({ hasText: `${roleName}_edit` })).toBeVisible();
});

test('usuario sin permisos no accede a configuración ni a acciones', async ({ page }) => {
    await login(page, 'multiplaza1@aaud.gob.pa');
    await expect(page.getByRole('link', { name: 'Configuración' })).toHaveCount(0);
    const settings = await page.goto('/settings');
    expect(settings?.status()).toBe(403);
    expect((await page.request.get('/settings/agencies')).status()).toBe(403);
    expect((await page.request.get('/settings/roles')).status()).toBe(403);
    const token = (await page.context().cookies()).find((cookie) => cookie.name === 'XSRF-TOKEN');
    const headers = { 'X-XSRF-TOKEN': decodeURIComponent(token?.value ?? '') };
    expect((await page.request.post('/settings/agencies', { headers, data: { code: 'FORBIDDEN', name: 'Forbidden' } })).status()).toBe(403);
    expect((await page.request.post('/settings/roles', { headers, data: { name: 'forbidden_role' } })).status()).toBe(403);
});

test('lector de configuración no puede crear, editar ni administrar permisos', async ({ page }) => {
    await login(page, 'settings-viewer-e2e@aaud.gob.pa');
    await page.goto('/settings');
    await expect(page.getByRole('link', { name: /Agencias/ })).toBeVisible();
    await page.goto('/settings/agencies');
    await expect(page.getByRole('button', { name: 'Crear Agencia' })).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Editar' })).toHaveCount(0);
    const agencyId = await page.locator('tbody tr').first().getAttribute('data-agency-id');
    await page.goto('/settings/roles');
    await expect(page.getByRole('button', { name: 'Crear rol' })).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Permisos' })).toHaveCount(0);
    const roleId = await page.locator('.settings-role-row').first().getAttribute('data-role-id');
    const token = (await page.context().cookies()).find((cookie) => cookie.name === 'XSRF-TOKEN');
    const headers = { 'X-XSRF-TOKEN': decodeURIComponent(token?.value ?? '') };
    expect((await page.request.post('/settings/agencies', { headers, data: { code: 'DENIED', name: 'Denied' } })).status()).toBe(403);
    expect((await page.request.put(`/settings/agencies/${agencyId}`, { headers, data: { code: 'DENIED', name: 'Denied' } })).status()).toBe(403);
    expect((await page.request.put(`/settings/roles/${roleId}/permissions`, { headers, data: { permissions: [] } })).status()).toBe(403);
});

test('configuración conserva sus acciones en móvil', async ({ page }) => {
    await page.setViewportSize({ width: 375, height: 812 });
    await login(page, 'admin@aaud.gob.pa');
    await page.goto('/settings');
    await expect(page.getByRole('link', { name: /Agencias/ })).toBeVisible();
    await expect(page.getByRole('link', { name: /Roles y Permisos/ })).toBeVisible();
    await page.goto('/settings/agencies');
    await expect(page.getByRole('button', { name: 'Crear Agencia' })).toBeVisible();
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await page.goto('/settings/roles');
    await expect(page.getByRole('button', { name: 'Crear rol' })).toBeVisible();
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
});
