<?php

return [
    // Enabled with the existing integration, independently stoppable for maintenance.
    'automatic' => env('AAUD_OAUTH_AUTOMATIC', env('AAUD_OAUTH_ENABLED', false)),
    'enabled' => env('AAUD_OAUTH_ENABLED', false),
    'api_url' => env('AAUD_OAUTH_API_URL', 'http://localhost:18000'),
    // Browser-facing applications page; may differ from the technical API address.
    'portal_url' => env('AAUD_OAUTH_PORTAL_URL', rtrim(env('AAUD_OAUTH_API_URL', 'http://localhost:18000'), '/').'/portal'),
    'issuer' => env('AAUD_OAUTH_ISSUER'),
    'token_url' => env('AAUD_OAUTH_TOKEN_URL', 'http://localhost:8080/realms/aaud/protocol/openid-connect/token'),
    'client_id' => env('AAUD_OAUTH_CLIENT_ID', 'pazysalvo-sync'),
    'client_secret' => env('AAUD_OAUTH_CLIENT_SECRET'),
    'connect_timeout' => env('AAUD_OAUTH_CONNECT_TIMEOUT', 5),
    'timeout' => env('AAUD_OAUTH_TIMEOUT', 10),
    'verify_tls' => env('AAUD_OAUTH_VERIFY_TLS', true),
    'ca_bundle' => env('AAUD_OAUTH_CA_BUNDLE'),
    'token_expiry_leeway' => env('AAUD_OAUTH_TOKEN_EXPIRY_LEEWAY', 30),
];
