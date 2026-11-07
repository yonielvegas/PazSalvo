import { defineConfig, devices } from '@playwright/test';
export default defineConfig({
    testDir: '../../tests/frontend', testMatch: ['user-sso-identity.spec.ts', 'oidc-login.spec.ts'],
    outputDir: './browser-results', reporter: 'list', workers: 1,
    use: { ...devices['Desktop Chrome'], baseURL: 'http://sso-ui.example.test', serviceWorkers: 'block', timezoneId: 'Asia/Tokyo' },
});
