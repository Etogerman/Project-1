import { expect, test } from '@playwright/test';

import { getAdminCredentials, loginToAdmin } from './helpers/filament';

const admin = getAdminCredentials();

test.describe('Admin smoke', () => {
    test.skip(!admin.configured, 'Set PLAYWRIGHT_ADMIN_EMAIL and PLAYWRIGHT_ADMIN_PASSWORD to run admin smoke tests.');

    test('admin can sign in and open users resource', async ({ page }) => {
        await loginToAdmin(page, admin.email!, admin.password!);

        await page.goto('/admin/users');

        await expect(page).toHaveURL(/\/admin\/users(?:\?.*)?$/);
        await expect(page.getByRole('heading', { name: /^(Сотрудники|Пользователи)$/ })).toBeVisible();
        await expect(page.getByRole('button', { name: 'Добавить пользователя' })).toBeVisible();
        await expect(page.getByRole('table')).toBeVisible();
        await expect(page.getByRole('columnheader', { name: 'ID' })).toBeVisible();
        await expect(page.getByRole('columnheader', { name: 'Email' })).toBeVisible();
        await expect(page.getByRole('columnheader', { name: 'Активен' })).toBeVisible();
        await expect(page.getByRole('cell', { name: admin.email! })).toBeVisible();
    });
});
