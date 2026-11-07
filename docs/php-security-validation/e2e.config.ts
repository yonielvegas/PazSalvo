import { defineConfig, devices } from '@playwright/test';
export default defineConfig({
    testDir: '../../tests/e2e', outputDir: './e2e-results', timeout: 30_000, workers: 1,
    reporter: [['list']],
    use: { baseURL: 'http://127.0.0.1:18012', ...devices['Desktop Chrome'], screenshot: 'only-on-failure', trace: 'off' },
    webServer: { cwd: '/var/www/PazSalvos/PazSalvo', command: 'php -S 127.0.0.1:18012 -t public tests/e2e/server.php',
        env: { APP_ENV: 'testing', E2E_SSO_DIRECTORY_FIXTURE: '1', E2E_SSO_RUN_ID: `${process.pid}-${Date.now()}` },
        url: 'http://127.0.0.1:18012/login', reuseExistingServer: false, timeout: 30_000 },
});
