import { expect, test } from '@playwright/test';

import { getAdminCredentials, loginToAdmin } from './helpers/filament';

const admin = getAdminCredentials();
const expectedAppRev = normalizeExpectedAppRev(process.env.PLAYWRIGHT_EXPECTED_APP_REV);

test.describe('Release revision smoke', () => {
    test.skip(!expectedAppRev, 'Set PLAYWRIGHT_EXPECTED_APP_REV to verify deployed app revision.');
    test.skip(!admin.configured, 'Set PLAYWRIGHT_ADMIN_EMAIL and PLAYWRIGHT_ADMIN_PASSWORD to run release revision smoke.');

    test('admin UI exposes the expected deployed revision', async ({ page }) => {
        test.setTimeout(10 * 60 * 1000);

        await loginToAdmin(page, admin.email!, admin.password!);

        const deadline = Date.now() + 9 * 60 * 1000;
        const seenVersions = new Set<string>();

        while (Date.now() < deadline) {
            await page.goto('/admin');

            const currentVersions = await page.locator('[data-role="environment-indicator"]').evaluateAll((nodes) => {
                return nodes
                    .map((node) => node.getAttribute('data-app-version'))
                    .filter((version): version is string => Boolean(version));
            });

            currentVersions.forEach((version) => seenVersions.add(version));

            if (currentVersions.includes(expectedAppRev!)) {
                await expect(
                    page.locator(`[data-role="environment-indicator"][data-app-version="${expectedAppRev}"]`).first(),
                ).toBeVisible();

                return;
            }

            await page.waitForTimeout(10_000);
        }

        throw new Error(
            `Expected deployed app revision ${expectedAppRev}, but saw ${formatSeenVersions(seenVersions)}.`,
        );
    });
});

function normalizeExpectedAppRev(value: string | undefined): string | undefined {
    const normalized = value?.trim();

    if (! normalized) {
        return undefined;
    }

    return /^[0-9a-f]{7,40}$/i.test(normalized)
        ? normalized.slice(0, 7).toLowerCase()
        : normalized;
}

function formatSeenVersions(seenVersions: Set<string>): string {
    if (seenVersions.size === 0) {
        return 'no app revision markers';
    }

    return Array.from(seenVersions).join(', ');
}
