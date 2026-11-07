import { defineConfig, devices } from '@playwright/test';

export default defineConfig({
    testDir: './tests/e2e',
    outputDir: './test-results',
    timeout: 30_000,
    workers: 1,
    reporter: [['list'], ['html', { outputFolder: 'playwright-report', open: 'never' }]],
    use: {
        baseURL: 'http://127.0.0.1:8012',
        screenshot: 'only-on-failure',
        video: 'retain-on-failure',
        trace: 'retain-on-failure',
        ...devices['Desktop Chrome'],
    },
    webServer: {
        command: 'php -S 127.0.0.1:8012 -t public tests/e2e/server.php',
        env: { APP_ENV: 'testing', E2E_SSO_DIRECTORY_FIXTURE: '1', E2E_SSO_RUN_ID: `${process.pid}-${Date.now()}` },
        url: 'http://127.0.0.1:8012/login',
        reuseExistingServer: false,
        timeout: 30_000,
    },
});
