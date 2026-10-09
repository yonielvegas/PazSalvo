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
        command: 'php artisan serve --host=127.0.0.1 --port=8012',
        url: 'http://127.0.0.1:8012/login',
        reuseExistingServer: !process.env.CI,
        timeout: 30_000,
    },
});
