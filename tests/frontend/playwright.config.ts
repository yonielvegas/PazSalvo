import { defineConfig, devices } from '@playwright/test';

// No Laravel server or database. Every request is fulfilled by the browser fixture.
export default defineConfig({
    testDir: '.',
    testMatch: ['user-sso-identity.spec.ts', 'oidc-login.spec.ts'],
    outputDir: '/tmp/pazsalvo-sso-ui-results',
    reporter: 'list',
    workers: 1,
    use: { ...devices['Desktop Chrome'], baseURL: 'http://sso-ui.example.test', serviceWorkers: 'block', timezoneId: 'Asia/Tokyo' },
});
