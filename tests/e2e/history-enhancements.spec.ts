import { test, expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';

let fixtures: Record<string, { id: number; folio: string }>;
test.beforeAll(() => {
    fixtures = JSON.parse(execFileSync('php', ['tests/e2e/seed-public-verification.php'], { encoding: 'utf8' }));
});

test.beforeEach(async ({ page }) => {
    await page.goto('/login');
    await page.getByLabel('Correo electrónico').fill('admin@aaud.gob.pa');
    await page.locator('#login-password').fill(process.env.E2E_PASSWORD || 'e2e-test-password');
    await page.getByRole('button', { name: 'Ingresar' }).click();
    await page.waitForURL('**/paz-salvos/consultar');
    await page.goto('/paz-salvos');
});

test('autor, criterios combinados y KPI globales independientes', async ({ page }) => {
    const errors: string[] = [];
    page.on('pageerror', (error) => errors.push(error.message));
    await expect(page.getByRole('heading', { name: 'Historial de certificados' })).toBeVisible();
    const global = page.getByLabel('Indicadores globales de Paz y Salvo');
    const initialGlobal = await global.innerText();
    const author = page.getByRole('combobox', { name: 'Elaborado por (usuario)' });
    await author.focus();
    const options = page.getByRole('listbox', { name: '' });
    await expect(options.getByRole('option', { name: /Zeta Operador E2E/ })).toBeVisible();
    const names = await options.getByRole('option').allInnerTexts();
    expect(names.findIndex((name) => name.includes('Zeta Operador E2E'))).toBeLessThan(names.findIndex((name) => name.includes('Alfa Supervisor E2E')));
    expect(names.findIndex((name) => name.includes('Alfa Supervisor E2E'))).toBeLessThan(names.findIndex((name) => name.includes('Beta Consulta E2E')));
    await author.fill('Zeta');
    await expect(options.getByRole('option', { name: /Alfa Supervisor E2E/ })).toHaveCount(0);
    await options.getByRole('option', { name: /Zeta Operador E2E/ }).click();
    await page.getByRole('button', { name: 'Aplicar filtros' }).click();
    await expect(page.getByText(fixtures.operator.folio, { exact: true })).toBeVisible();
    await expect(page.getByText(fixtures.supervisor.folio, { exact: true })).toHaveCount(0);
    await expect(page.getByLabel('Indicadores del historial').getByText('1', { exact: true }).first()).toBeVisible();
    expect(await global.innerText()).toBe(initialGlobal);

    await page.getByLabel('Folio', { exact: true }).fill(fixtures.operator.folio);
    await page.getByRole('button', { name: 'Aplicar filtros' }).click();
    await expect(page.getByText(fixtures.operator.folio, { exact: true })).toBeVisible();
    expect(await global.innerText()).toBe(initialGlobal);
    await page.getByRole('button', { name: 'Limpiar filtros' }).first().click();
    await expect(page.getByText(fixtures.supervisor.folio, { exact: true })).toBeVisible();
    expect(await global.innerText()).toBe(initialGlobal);
    expect(errors).toEqual([]);
});

test('rangos rápidos y edición manual en escritorio y móvil', async ({ page }, testInfo) => {
    for (const width of [1440, 768, 375]) {
        await page.setViewportSize({ width, height: 900 });
        if (width !== 768) {
            await page.waitForTimeout(350);
            const screenshot = testInfo.outputPath(`history-responsive-${width}.png`);
            await page.screenshot({ path: screenshot, fullPage: true });
            await testInfo.attach(`history-responsive-${width}`, { path: screenshot, contentType: 'image/png' });
        }
        await page.getByRole('button', { name: 'Hoy', exact: true }).click();
        await expect(page.getByRole('button', { name: 'Hoy', exact: true })).toHaveAttribute('aria-pressed', 'true');
        const today = await page.getByLabel('Hasta', { exact: true }).inputValue();
        await expect(page.getByLabel('Desde', { exact: true })).toHaveValue(today);
        await page.getByRole('button', { name: 'Este mes' }).click();
        await expect(page.getByLabel('Desde', { exact: true })).toHaveValue(`${today.slice(0, 7)}-01`);
        await page.getByRole('button', { name: 'Este año' }).click();
        await expect(page.getByLabel('Desde', { exact: true })).toHaveValue(`${today.slice(0, 4)}-01-01`);
        await page.getByLabel('Desde', { exact: true }).fill('2026-09-15');
        await expect(page.getByRole('button', { name: 'Este año' })).toHaveAttribute('aria-pressed', 'false');
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
        await page.getByRole('button', { name: 'Limpiar filtros' }).first().click();
    }
});

test('búsqueda por campos, rango inclusivo y paginación conservada', async ({ page }) => {
    const unexpected: string[] = [];
    page.on('response', (response) => { if (response.status() >= 500) unexpected.push(`${response.status()} ${response.url()}`); });
    for (const [label, value, parameter] of [
        ['Folio', fixtures.operator.folio, 'folio'],
        ['Número de Cliente', '1234564787', 'nac'],
        ['Número de factura', '654321', 'numero_factura'],
        ['Nombre del titular', 'Cliente Público E2E', 'titular'],
    ]) {
        await page.getByLabel(label, { exact: true }).fill(value);
        await page.getByRole('button', { name: 'Aplicar filtros' }).click();
        await expect(page).toHaveURL(new RegExp(`${parameter}=`));
        await expect(page.getByText(fixtures.operator.folio, { exact: true })).toBeVisible();
        await page.getByRole('button', { name: 'Limpiar filtros' }).first().click();
        await expect(page).toHaveURL(/\/paz-salvos$/);
    }
    await page.getByLabel('Desde', { exact: true }).fill('2026-09-15');
    await page.getByLabel('Hasta', { exact: true }).fill('2026-09-15');
    await page.getByRole('button', { name: 'Aplicar filtros' }).click();
    await expect(page).toHaveURL(/fecha_desde=2026-09-15/);
    await expect(page.getByText(fixtures.operator.folio, { exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Limpiar filtros' }).first().click();
    await expect(page).toHaveURL(/\/paz-salvos$/);
    await page.getByLabel('Número de factura', { exact: true }).fill('777777');
    await page.getByRole('button', { name: 'Aplicar filtros' }).click();
    await expect(page.getByLabel('Indicadores del historial')).toContainText('16');
    await page.getByRole('link', { name: '2', exact: true }).click();
    await expect(page).toHaveURL(/numero_factura=777777/);
    await expect(page).toHaveURL(/page=2/);
    expect(unexpected).toEqual([]);
});
