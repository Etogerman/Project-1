import { expect, test } from '@playwright/test';

test.describe('Public admin smoke', () => {
    test('guest is redirected from root to the Filament login page', async ({ page }) => {
        await page.goto('/');

        await expect(page).toHaveURL(/\/admin\/login(?:\?.*)?$/);
        await expect(page.getByRole('heading', { name: 'Войдите в свой аккаунт' })).toBeVisible();
    });

    test('login page renders core controls', async ({ page }) => {
        await page.goto('/admin/login');

        await expect(page.getByRole('heading', { name: 'Войдите в свой аккаунт' })).toBeVisible();
        await expect(page.getByRole('textbox', { name: /Адрес электронной почты/i })).toBeVisible();
        await expect(page.getByRole('textbox', { name: /^Пароль/i })).toBeVisible();
        await expect(page.getByRole('button', { name: 'Войти' })).toBeVisible();
    });
});
