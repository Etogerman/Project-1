import { expect, test } from '@playwright/test';

import { findFirstResourceRecordPath, getAdminCredentials, loginToAdmin } from './helpers/filament';

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
        await expect(page.getByRole('columnheader', { name: /^(Статус|Активен)$/ })).toBeVisible();
        await expect(page.getByRole('table')).toContainText(admin.email!);
    });

    test('admin can open contacts and dialogs resources', async ({ page }) => {
        await loginToAdmin(page, admin.email!, admin.password!);

        await page.goto('/admin/contacts');

        await expect(page).toHaveURL(/\/admin\/contacts(?:\?.*)?$/);
        await expect(page.getByRole('navigation', { name: 'Хлебные крошки' })).toContainText('Контакты');
        await expect(page.getByRole('table')).toBeVisible();
        await expect(page.getByRole('columnheader', { name: 'Контакт' })).toBeVisible();

        await page.goto('/admin/dialogs');

        await expect(page).toHaveURL(/\/admin\/dialogs(?:\?.*)?$/);
        await expect(page.getByRole('navigation', { name: 'Хлебные крошки' })).toContainText('Диалоги');
        await expect(page.getByRole('table')).toBeVisible();
        await expect(page.getByRole('columnheader', { name: 'Контакт' })).toBeVisible();
    });

    test('selected theme persists across admin navigation', async ({ page }) => {
        await loginToAdmin(page, admin.email!, admin.password!);

        await page.evaluate(() => window.localStorage.setItem('theme', 'light'));
        await page.reload();

        await expect(page.locator('html')).not.toHaveClass(/\bdark\b/);

        await page.getByRole('button', { name: 'Переключить тему' }).click();

        await expect.poll(() => page.evaluate(() => window.localStorage.getItem('theme'))).toBe('dark');
        await expect(page.locator('html')).toHaveClass(/\bdark\b/);

        await page.getByRole('link', { name: 'Контакты', exact: true }).click();

        await expect(page).toHaveURL(/\/admin\/contacts(?:\?.*)?$/);
        await expect(page.locator('html')).toHaveClass(/\bdark\b/);
        await expect.poll(() => page.evaluate(() => window.localStorage.getItem('theme'))).toBe('dark');
    });

    test('admin can open a dialog workspace from the dialogs list', async ({ page }) => {
        await loginToAdmin(page, admin.email!, admin.password!);

        await page.goto('/admin/dialogs');

        await expect(page).toHaveURL(/\/admin\/dialogs(?:\?.*)?$/);
        await expect(page.getByRole('navigation', { name: 'Хлебные крошки' })).toContainText('Диалоги');

        const emptyState = page.getByText('Диалогов ещё нет');

        if (await emptyState.isVisible().catch(() => false)) {
            await expect(page.getByText('Диалоги появятся после первых входящих сообщений от внешней аудитории.')).toBeVisible();

            return;
        }

        await expect(page.getByRole('table')).toBeVisible();

        const dialogPath = await findFirstResourceRecordPath(page, '/admin/dialogs', ['kanban']);

        expect(dialogPath).not.toBeNull();

        await page.goto(dialogPath!);

        await expect(page).toHaveURL(/\/admin\/dialogs\/[^/?#]+(?:\?.*)?$/);
        await expect(page.locator('[data-role="dialog-page"]')).toBeVisible();
        await expect(page.locator('[data-role="dialog-overview"]')).toBeVisible();
        await expect(page.locator('[data-role="dialog-header"]')).toBeVisible();
        await expect(page.locator('[data-role="dialog-contact-avatar"]')).toBeVisible();
        await expect(page.locator('[data-role="dialog-route-status"]')).toBeVisible();
        await expect(page.locator('[data-role="dialog-history"]')).toBeVisible();
        await expect(page.locator('[data-role="conversation-thread"]')).toBeVisible();
        await expect(page.locator('[data-role="conversation-reply-form"]')).toBeVisible();
        await expect(page.getByRole('link', { name: 'Открыть контакт' })).toBeVisible();
        await expect(page.locator('[data-role="conversation-reply-submit"]')).toBeVisible();

        const emptyConversation = page.locator('[data-role="conversation-empty"]');

        if (await emptyConversation.isVisible().catch(() => false)) {
            await expect(emptyConversation).toBeVisible();
        } else {
            await expect(page.locator('[data-role="conversation-message"]').first()).toBeVisible();
        }
    });
});
