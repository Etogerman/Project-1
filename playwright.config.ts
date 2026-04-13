import { existsSync, readFileSync } from 'node:fs';

import { defineConfig, devices } from '@playwright/test';

function loadLocalPlaywrightEnv(): void {
    const envFilePath = '.env.playwright.local';

    if (! existsSync(envFilePath)) {
        return;
    }

    const contents = readFileSync(envFilePath, 'utf8');
    const lines = contents.split(/\r?\n/u);

    for (const rawLine of lines) {
        const line = rawLine.trim();

        if (line === '' || line.startsWith('#')) {
            continue;
        }

        const separatorIndex = line.indexOf('=');

        if (separatorIndex <= 0) {
            continue;
        }

        const key = line.slice(0, separatorIndex).trim();

        if (key === '' || process.env[key] !== undefined) {
            continue;
        }

        let value = line.slice(separatorIndex + 1).trim();

        if (
            (value.startsWith('"') && value.endsWith('"'))
            || (value.startsWith("'") && value.endsWith("'"))
        ) {
            value = value.slice(1, -1);
        }

        process.env[key] = value;
    }
}

loadLocalPlaywrightEnv();

const baseURL = process.env.PLAYWRIGHT_BASE_URL ?? process.env.APP_URL ?? 'http://127.0.0.1:8000';

export default defineConfig({
    testDir: './e2e',
    fullyParallel: false,
    timeout: 60_000,
    expect: {
        timeout: 15_000,
    },
    reporter: [
        ['list'],
        ['html', { open: 'never' }],
    ],
    use: {
        baseURL,
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
        video: 'retain-on-failure',
        actionTimeout: 15_000,
        navigationTimeout: 60_000,
    },
    projects: [
        {
            name: 'chromium',
            use: {
                ...devices['Desktop Chrome'],
            },
        },
    ],
});
