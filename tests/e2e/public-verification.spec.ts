import { test, expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';

type Fixture = { id: number; token: string; folio: string; date: string };
let fixtures: Record<string, Fixture>;
test.beforeAll(() => {
    fixtures = JSON.parse(execFileSync('php', ['tests/e2e/seed-public-verification.php'], { encoding: 'utf8' }));
});

test('consulta pública por folio y fecha sin login y con NAC enmascarado', async ({ page }) => {
    await page.goto('/verificar');
    await expect(page.getByRole('heading', { name: 'Validar Paz y Salvo' })).toBeVisible();
    await page.getByLabel('Folio', { exact: true }).fill(fixtures.valid.folio.replace(/\D/g, ''));
    await page.getByLabel('Fecha de emisión', { exact: true }).fill(fixtures.valid.date.replace(/\D/g, ''));
    await page.getByRole('button', { name: 'Validar', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'CERTIFICADO VIGENTE' })).toBeVisible();
    await expect(page.getByText('******4787', { exact: true })).toBeVisible();
    await expect(page.getByText('DIRECCION PRIVADA E2E')).toHaveCount(0);
    await expect(page.getByRole('link', { name: 'Ver PDF', exact: true })).toBeVisible();
    expect((await page.request.get(`/verificar/${fixtures.valid.token}/pdf`)).status()).toBe(200);
    await page.screenshot({ path: '/tmp/pazsalvo-public-result.png', fullPage: true });
});

test('QR muestra vigente, expirado, anulado e inexistente sin sesión', async ({ page }) => {
    for (const [state, title] of [
        ['valid', 'CERTIFICADO VIGENTE'], ['expired', 'CERTIFICADO EXPIRADO'],
        ['cancelled', 'CERTIFICADO ANULADO'], ['error', 'Certificado no encontrado'],
    ]) {
        await page.goto(`/verificar/${fixtures[state].token}`);
        await expect(page.getByRole('heading', { name: title, exact: true })).toBeVisible();
        if (state !== 'valid') {
            await expect(page.getByRole('link', { name: 'Ver PDF', exact: true })).toHaveCount(0);
            expect((await page.request.get(`/verificar/${fixtures[state].token}/pdf`)).status()).toBe(404);
        }
    }
    await page.goto('/verificar/71268739-a0ef-4519-8cf6-814576c83b99');
    await expect(page.getByRole('heading', { name: 'Certificado no encontrado' })).toBeVisible();
    await page.goto('/verificar/token-invalido');
    await expect(page.getByRole('heading', { name: 'Certificado no encontrado' })).toBeVisible();
});

test('consulta inexistente conserva el mensaje y la página funciona en móvil', async ({ page }) => {
    await page.setViewportSize({ width: 375, height: 812 });
    await page.goto('/verificar');
    await page.getByLabel('Folio', { exact: true }).fill('0000002026');
    await page.getByLabel('Fecha de emisión', { exact: true }).fill('20072026');
    await page.getByRole('button', { name: 'Validar', exact: true }).click();
    await expect(page.getByRole('alertdialog')).toContainText('Paz y Salvo no encontrado');
    await page.getByRole('button', { name: 'Aceptar', exact: true }).click();
    await expect(page.getByRole('alertdialog')).toBeHidden();
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await page.screenshot({ path: '/tmp/pazsalvo-public-mobile.png', fullPage: true });
});

test('historial omite errores antiguos y el detalle maneja PDF faltante', async ({ page }) => {
    await page.goto('/login');
    await page.getByLabel('Correo electrónico').fill('admin@aaud.gob.pa');
    await page.locator('#login-password').fill(process.env.E2E_PASSWORD || 'e2e-test-password');
    await page.getByRole('button', { name: 'Ingresar' }).click();
    await page.waitForURL('**/paz-salvos/consultar');
    await page.goto('/paz-salvos');
    await expect(page.getByText(fixtures.valid.folio, { exact: true })).toBeVisible();
    await expect(page.getByText(fixtures.error.folio, { exact: true })).toHaveCount(0);
    await page.goto(`/paz-salvos/${fixtures.missing_pdf.id}`);
    await expect(page.getByRole('alert')).toContainText('El PDF del certificado no está disponible');
    await expect(page.getByRole('link', { name: 'Ver PDF', exact: true })).toHaveCount(0);
    await page.goto(`/paz-salvos/${fixtures.valid.id}`);
    await expect(page.getByRole('heading', { name: fixtures.valid.folio })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Descargar PDF', exact: true })).toBeVisible();
    expect((await page.request.get(`/paz-salvos/${fixtures.valid.id}/download`)).status()).toBe(200);
});
