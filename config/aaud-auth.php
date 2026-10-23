<?php

return [
    // Enabled with the existing integration, independently stoppable for maintenance.
    'automatic' => env('AAUD_AUTH_AUTOMATIC', env('AAUD_AUTH_ENABLED', false)),
    'enabled' => env('AAUD_AUTH_ENABLED', false),
    'api_url' => env('AAUD_AUTH_API_URL', 'http://localhost:18000'),
    'issuer' => env('AAUD_AUTH_ISSUER'),
    'token_url' => env('AAUD_AUTH_TOKEN_URL', 'http://localhost:8080/realms/aaud/protocol/openid-connect/token'),
    'client_id' => env('AAUD_AUTH_CLIENT_ID', 'pazysalvo-sync'),
    'client_secret' => env('AAUD_AUTH_CLIENT_SECRET'),
    'connect_timeout' => env('AAUD_AUTH_CONNECT_TIMEOUT', 5),
    'timeout' => env('AAUD_AUTH_TIMEOUT', 10),
    'verify_tls' => env('AAUD_AUTH_VERIFY_TLS', true),
    'ca_bundle' => env('AAUD_AUTH_CA_BUNDLE'),
    'token_expiry_leeway' => env('AAUD_AUTH_TOKEN_EXPIRY_LEEWAY', 30),
];
