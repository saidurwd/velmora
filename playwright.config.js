import { defineConfig, devices } from '@playwright/test';

const port = process.env.E2E_PORT || '8010';
const baseURL = process.env.E2E_BASE_URL || `http://127.0.0.1:${port}`;

// Browser tests for the storefront: accessibility (axe), keyboard-only use, no-JavaScript use, fonts and the consent flow.
// Prepare data first: e2e/prepare.sh   then:  npm run e2e
export default defineConfig({
    testDir: './e2e',
    timeout: 60_000,
    fullyParallel: true,
    workers: process.env.CI ? 2 : 3,
    retries: process.env.CI ? 1 : 0,
    reporter: process.env.CI ? [['github'], ['html', { open: 'never' }]] : [['list']],
    use: { baseURL, trace: 'retain-on-failure', ...devices['Pixel 5'], viewport: { width: 360, height: 800 }, locale: 'en-US' },
    projects: [{ name: 'mobile-chromium', use: { browserName: 'chromium' } }],
    webServer: process.env.E2E_BASE_URL ? undefined : {
        command: `php artisan serve --host=127.0.0.1 --port=${port}`,
        url: `${baseURL}/healthz`,
        reuseExistingServer: true,
        timeout: 60_000,
        env: { APP_ENV: 'e2e', PHP_CLI_SERVER_WORKERS: '4' }, // everything else comes from .env.e2e (written by e2e/prepare.sh)
    },
});
