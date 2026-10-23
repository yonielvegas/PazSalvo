<?php

return [
    'enabled' => env('AAUD_OIDC_ENABLED', false),
    'issuer' => env('AAUD_OIDC_ISSUER', env('AAUD_AUTH_ISSUER')),
    'client_id' => env('AAUD_OIDC_CLIENT_ID', 'pazysalvo-web'),
    'client_secret' => env('AAUD_OIDC_CLIENT_SECRET'),
    'redirect_uri' => env('AAUD_OIDC_REDIRECT_URI', rtrim((string) env('APP_URL', 'http://localhost:8000'), '/').'/auth/aaud/callback'),
];
