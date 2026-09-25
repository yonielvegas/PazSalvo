import { defineConfig, devices } from '@playwright/test';

export default defineConfig({
    testDir: './tests/e2e',
    timeout: 30_000,
    workers: 1,
    use: { baseURL: 'http://127.0.0.1:8012', ...devices['Desktop Chrome'] },
    webServer: {
        command: 'php artisan serve --host=127.0.0.1 --port=8012',
        url: 'http://127.0.0.1:8012/login',
        reuseExistingServer: !process.env.CI,
        timeout: 30_000,
    },
});
