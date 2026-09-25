import { test, expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const password = process.env.E2E_PASSWORD || 'e2e-test-password';
const fixture = join(tmpdir(), 'clients-e2e.xlsx');

async function login(page: import('@playwright/test').Page, email: string) {
    await page.goto('/login');
    await page.getByLabel('Correo electrónico').fill(email);
    await page.getByLabel('Contraseña').fill(password);
    await page.getByRole('button', { name: 'Ingresar' }).click();
}

test.beforeAll(() => { execFileSync('php', ['tests/e2e/create-fixture.php']); });

test('admin carga, reemplaza, descarga y elimina Excel', async ({ page }) => {
    await login(page, 'admin@aaud.gob.pa');
    await page.getByRole('link', { name: 'Excel de Clientes' }).click();
    await expect(page.getByRole('heading', { name: 'Excel de Clientes' })).toBeVisible();

    for (let index = 1; index <= 3; index++) {
        await page.locator('#client-excel-input').setInputFiles({ name: `clientes_${index}.xlsx`, mimeType: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', buffer: (await import('node:fs')).readFileSync(fixture) });
        await page.getByRole('button', { name: 'Subir archivo' }).click();
        await expect(page.getByRole('status')).toContainText('cargado correctamente');
    }
    await expect(page.locator('.client-excel-row')).toHaveCount(3);
    await expect(page.locator('.client-excel-row').first()).toContainText('Actual');
    await expect(page.locator('.client-excel-row').last()).toContainText('Será reemplazado con la próxima carga');
    await expect(page.locator('.client-excel-row').first()).toContainText('Admin AAUD');
    await expect(page.locator('.client-excel-row').first()).toContainText(/Fecha: \d{2}\/\d{2}\/\d{4}/);
    await expect(page.locator('.client-excel-warning')).toContainText('clientes_1.xlsx');

    await page.locator('#client-excel-input').setInputFiles({ name: 'clientes_4.xlsx', mimeType: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', buffer: (await import('node:fs')).readFileSync(fixture) });
    await page.getByRole('button', { name: 'Subir archivo' }).click();
    await expect(page.locator('.client-excel-row')).toHaveCount(3);
    await expect(page.locator('.client-excel-row').first()).toContainText('clientes_4.xlsx');
    await expect(page.locator('.client-excel-list')).not.toContainText('clientes_1.xlsx');
    await expect(page.locator('.client-excel-row').last()).toContainText('clientes_2.xlsx');

    const downloadPromise = page.waitForEvent('download');
    await page.locator('.client-excel-row').first().getByRole('link', { name: 'Descargar' }).click();
    const download = await downloadPromise;
    expect(download.suggestedFilename()).toBe('clientes_4.xlsx');
    expect(await download.path()).toBeTruthy();

    await page.locator('.client-excel-row').first().getByRole('button', { name: 'Eliminar' }).click();
    await expect(page.getByRole('dialog')).toContainText('clientes_4.xlsx');
    await page.getByRole('dialog').getByRole('button', { name: 'Cancelar' }).click();
    await expect(page.locator('.client-excel-row').first()).toContainText('clientes_4.xlsx');
    await page.locator('.client-excel-row').first().getByRole('button', { name: 'Eliminar' }).click();
    await page.getByRole('dialog').getByRole('button', { name: 'Eliminar' }).click();
    await expect(page.locator('.client-excel-row')).toHaveCount(2);
});

test('usuario sin permiso no ve el módulo ni puede abrirlo', async ({ page }) => {
    await login(page, 'multiplaza1@aaud.gob.pa');
    await expect(page.getByRole('link', { name: 'Excel de Clientes' })).toHaveCount(0);
    await page.goto('/admin/clients-excel');
    await expect(page.getByRole('heading', { name: 'Excel de Clientes' })).toHaveCount(0);
});

test('acciones de carga disponibles en pantalla pequeña', async ({ page }) => {
    await page.setViewportSize({ width: 375, height: 812 });
    await login(page, 'admin@aaud.gob.pa');
    await page.goto('/admin/clients-excel');
    await expect(page.getByRole('button', { name: 'Subir archivo' })).toBeVisible();
    await expect(page.locator('.client-excel-drop')).toBeVisible();
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
});
