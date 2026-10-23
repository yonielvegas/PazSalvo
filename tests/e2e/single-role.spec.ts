import { test, expect } from '@playwright/test';

test('administración sustituye un único rol sin selección múltiple', async ({ page }) => {
    await page.goto('/login');
    await page.getByLabel('Correo electrónico').fill('admin@aaud.gob.pa');
    await page.locator('#login-password').fill(process.env.E2E_PASSWORD || 'e2e-test-password');
    await page.getByRole('button', { name: 'Ingresar' }).click();
    await page.waitForURL('**/paz-salvos/consultar');
    await page.goto('/admin/users');
    await page.getByRole('button', { name: 'Nuevo usuario', exact: true }).click();
    const email = `single-role-${Date.now()}@example.test`;
    await page.locator('#create-name').fill('Single Role E2E');
    await page.locator('#create-email').fill(email);
    const agency = await page.locator('#create-agency option').nth(1).getAttribute('value');
    await page.locator('#create-agency').selectOption(agency!);
    await expect(page.locator('#create-role')).not.toHaveAttribute('multiple');
    await page.locator('#create-role').selectOption('operador');
    await page.getByRole('button', { name: 'Crear usuario', exact: true }).click();
    const row = page.locator('tr').filter({ hasText: email });
    await expect(row.locator('.role-badge')).toHaveText('operador');
    await row.getByRole('button', { name: 'Editar usuario', exact: true }).click();
    await expect(page.locator('#edit-role')).not.toHaveAttribute('multiple');
    await page.locator('#edit-role').selectOption('consulta');
    await page.getByRole('dialog').getByRole('button', { name: 'Guardar cambios', exact: true }).click();
    await expect(row.locator('.role-badge')).toHaveCount(1);
    await expect(row.locator('.role-badge')).toHaveText('consulta');
});
