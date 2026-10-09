import { test, expect, type Page } from '@playwright/test';
import { execFileSync } from 'node:child_process';

let fixture: { valid: { id: number } };
test.beforeAll(() => {
    fixture = JSON.parse(execFileSync('php', ['tests/e2e/seed-public-verification.php'], { encoding: 'utf8' }));
});

async function login(page: Page, email = 'admin@aaud.gob.pa') {
    await page.goto('/login');
    await page.getByLabel('Correo electrónico').fill(email);
    await page.locator('#login-password').fill(process.env.E2E_PASSWORD || 'e2e-test-password');
    await page.getByRole('button', { name: 'Ingresar' }).click();
    await page.waitForURL('**/paz-salvos/consultar');
}

async function checkZones(page: Page, mobile: boolean) {
    await expect(page.locator('.app-header')).toBeVisible();
    const result = await page.evaluate(() => {
        const selectors = ['.app-header .brand', '.nav-zone', '.nav-kpis', '.account-zone'];
        const boxes = selectors.map((selector) => document.querySelector(selector)?.getBoundingClientRect());
        const header = document.querySelector('.app-header')!.getBoundingClientRect();
        return {
            boxes: boxes.map((box) => box && ({ left: box.left, right: box.right, top: box.top, bottom: box.bottom })),
            height: header.height,
            viewport: innerWidth,
            overflow: document.documentElement.scrollWidth > innerWidth,
        };
    });
    expect(result.overflow).toBe(false);
    expect(result.height).toBeLessThan(mobile ? 76 : 105);
    expect(result.boxes.at(-1)!.right).toBeLessThanOrEqual(result.viewport + 1);
    for (let index = 0; index < result.boxes.length - 1; index++) {
        const left = result.boxes[index]!;
        const right = result.boxes[index + 1]!;
        expect(left.right).toBeLessThanOrEqual(right.left + 1);
        expect(Math.abs(left.top - right.top)).toBeLessThan(20);
    }
}

test('cuatro zonas en una fila a siete anchos y tres niveles de zoom', async ({ page }, testInfo) => {
    await login(page);
    await page.goto('/paz-salvos');
    await expect(page.getByRole('heading', { name: 'Historial de certificados' })).toBeVisible();
    for (const width of [320, 375, 768, 1024, 1280, 1440, 1920]) {
        await page.setViewportSize({ width, height: 900 });
        await checkZones(page, width <= 900);
        const screenshot = testInfo.outputPath(`navbar-${width}.png`);
        await page.locator('.app-header').screenshot({ path: screenshot });
        await testInfo.attach(`navbar-${width}`, { path: screenshot, contentType: 'image/png' });
    }
    const cdp = await page.context().newCDPSession(page);
    for (const zoom of [1, 1.25, 1.5]) {
        // Keep a 1440 px physical viewport while increasing device scale and reducing CSS width.
        const cssWidth = Math.round(1440 / zoom);
        await cdp.send('Emulation.setDeviceMetricsOverride', { width: cssWidth, height: 900, deviceScaleFactor: zoom, mobile: false });
        expect(await page.evaluate(() => innerWidth)).toBe(cssWidth);
        await checkZones(page, cssWidth <= 900);
    }
    await cdp.send('Emulation.clearDeviceMetricsOverride');
    await cdp.detach();
    for (const zoom of [1, 1.25, 1.5]) {
        await page.setViewportSize({ width: Math.round(1440 / zoom), height: 900 });
        const screenshot = testInfo.outputPath(`navbar-zoom-${Math.round(zoom * 100)}.png`);
        await page.locator('.app-header').screenshot({ path: screenshot });
        await testInfo.attach(`navbar-zoom-${Math.round(zoom * 100)}`, { path: screenshot, contentType: 'image/png' });
    }
});

test('resalta la ruta actual y mantiene las rutas secundarias', async ({ page }) => {
    await login(page);
    for (const [url, label] of [
        ['/paz-salvos/consultar', 'Consultar'],
        ['/paz-salvos', 'Historial'],
        [`/paz-salvos/${fixture.valid.id}`, 'Historial'],
        ['/admin/users', 'Usuarios'],
        ['/settings', 'Configuración'],
        ['/settings/agencies', 'Configuración'],
        ['/settings/roles', 'Configuración'],
        ['/admin/clients-excel', 'Excel de Clientes'],
    ]) {
        await page.goto(url);
        const navigation = page.locator('.desktop-nav');
        await expect(navigation.getByRole('link', { name: label, exact: true })).toHaveAttribute('aria-current', 'page');
        await expect(navigation.locator('[aria-current="page"]')).toHaveCount(1);
    }
    await page.setViewportSize({ width: 375, height: 812 });
    await page.getByLabel('Abrir navegación').click();
    await expect(page.locator('.nav-menu nav').getByRole('link', { name: 'Excel de Clientes' })).toHaveAttribute('aria-current', 'page');
    await page.locator('.nav-menu nav').getByRole('link', { name: 'Historial' }).click();
    await expect(page).toHaveURL(/\/paz-salvos$/);
    await page.getByLabel('Abrir navegación').click();
    await expect(page.locator('.nav-menu nav').getByRole('link', { name: 'Historial' })).toHaveAttribute('aria-current', 'page');
});

test('menús móviles muestran KPI, permisos y salida', async ({ page }, testInfo) => {
    await page.setViewportSize({ width: 320, height: 812 });
    await login(page);
    await page.getByLabel('Ver indicadores globales').click();
    const panel = page.locator('.nav-kpi-panel');
    await expect(panel).toContainText('Total de Paz y Salvo');
    await expect(panel).toContainText('Paz y Salvo vencidos');
    await expect(panel).toContainText('Paz y Salvo vigentes');
    const panelBox = await panel.boundingBox();
    expect(panelBox).not.toBeNull();
    expect(panelBox!.x).toBeGreaterThanOrEqual(0);
    expect(panelBox!.x + panelBox!.width).toBeLessThanOrEqual(320);
    const screenshot = testInfo.outputPath('navbar-kpi-menu-320.png');
    await page.screenshot({ path: screenshot });
    await testInfo.attach('navbar-kpi-menu-320', { path: screenshot, contentType: 'image/png' });
    await page.getByLabel('Abrir navegación').focus();
    await page.keyboard.press('Enter');
    await expect(panel).toBeHidden();
    await expect(page.locator('.nav-menu nav').getByRole('link')).toHaveCount(5);
    await page.getByLabel('Abrir cuenta').click();
    await expect(page.locator('.nav-menu nav')).toBeHidden();
    await expect(page.locator('.account-panel')).toContainText('Admin AAUD');
    await expect(page.locator('.account-panel')).toContainText('PH Multiplaza');
    await page.locator('.account-panel').getByRole('button', { name: 'Cerrar sesión' }).click();
    await page.waitForURL('**/login');

    await login(page, 'multiplaza1@aaud.gob.pa');
    await page.getByLabel('Abrir navegación').click();
    const navigation = page.locator('.nav-menu nav');
    await expect(navigation.getByRole('link', { name: 'Consultar' })).toBeVisible();
    await expect(navigation.getByRole('link', { name: 'Historial' })).toBeVisible();
    await expect(navigation.getByRole('link', { name: 'Usuarios' })).toHaveCount(0);
    await expect(navigation.getByRole('link', { name: 'Configuración' })).toHaveCount(0);
    await expect(navigation.getByRole('link', { name: 'Excel de Clientes' })).toHaveCount(0);
});

test('modal de factura muestra el placeholder completo en móvil', async ({ page }, testInfo) => {
    await page.setViewportSize({ width: 320, height: 812 });
    await login(page);
    await page.goto('/paz-salvos');
    await page.route('**/paz-salvos/consultar', async (route) => {
        if (route.request().method() !== 'GET' || route.request().headers()['x-inertia'] !== 'true') return route.continue();
        const response = await route.fetch();
        const data = await response.json();
        data.props.flash.result = {
            query_token: 'e2e-modal-only', status: 'debt_free', client_number: '123456', holder_name: 'Cliente E2E',
            address: 'Prueba', city: 'San Miguelito', rate: 'Prueba', balances: { total_balance: 0 }, debts: [],
            can_generate_paz_salvo: true, requires_energy_warning: false,
        };
        await route.fulfill({ response, json: data });
    });
    await page.getByLabel('Abrir navegación').click();
    await page.locator('.nav-menu nav').getByRole('link', { name: 'Consultar' }).click();
    await page.getByRole('button', { name: 'Generar certificado oficial' }).click();
    const invoice = page.getByRole('dialog').getByPlaceholder('Introduzca el Numero de la factura ejem: 000000');
    await expect(invoice).toBeVisible();
    await expect(invoice).toHaveAttribute('maxlength', '6');
    const screenshot = testInfo.outputPath('invoice-modal-320.png');
    await page.getByRole('dialog').screenshot({ path: screenshot });
    await testInfo.attach('invoice-modal-320', { path: screenshot, contentType: 'image/png' });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
});
