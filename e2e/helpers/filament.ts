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

export async function findFirstResourceRecordPath(page: Page, resourcePath: string): Promise<string | null> {
    return await page.locator('a[href]').evaluateAll((anchors, pathPrefix) => {
        const normalizedPrefix = `${pathPrefix}/`;

        for (const anchor of anchors) {
            const href = anchor.getAttribute('href');

            if (! href) {
                continue;
            }

            const url = new URL(href, window.location.origin);

            if (! url.pathname.startsWith(normalizedPrefix)) {
                continue;
            }

            const recordKey = url.pathname.slice(normalizedPrefix.length);

            if (recordKey === '' || recordKey.includes('/')) {
                continue;
            }

            return `${url.pathname}${url.search}`;
        }

        return null;
    }, resourcePath);
}
