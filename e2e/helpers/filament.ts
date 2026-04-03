import { expect, Page } from '@playwright/test';

export function getAdminCredentials(): { email?: string; password?: string; configured: boolean } {
    const email = process.env.PLAYWRIGHT_ADMIN_EMAIL;
    const password = process.env.PLAYWRIGHT_ADMIN_PASSWORD;

    return {
        email,
        password,
        configured: Boolean(email && password),
    };
}

export async function loginToAdmin(page: Page, email: string, password: string): Promise<void> {
    await page.goto('/admin/login');

    await expect(page.getByRole('heading', { name: 'Войдите в свой аккаунт' })).toBeVisible();

    await page.getByRole('textbox', { name: /Адрес электронной почты/i }).fill(email);
    await page.getByRole('textbox', { name: /^Пароль/i }).fill(password);
    await page.getByRole('button', { name: 'Войти' }).click();

    await expect(page).toHaveURL(/\/admin(?:\/)?$/);
    await expect(page.getByText('Инфопанель').first()).toBeVisible();
}
