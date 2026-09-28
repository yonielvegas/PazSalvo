<?php

// Public verification uses the existing private model, database and storage.
return [
    'rate_limits' => [
        'manual' => (int) env('PUBLIC_RATE_LIMIT_MANUAL_PER_MINUTE', 10),
        'qr' => (int) env('PUBLIC_RATE_LIMIT_QR_PER_MINUTE', 60),
        'pdf' => (int) env('PUBLIC_RATE_LIMIT_PDF_PER_MINUTE', 30),
    ],
    'security_headers' => [
        'csp' => "default-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'; object-src 'none'; script-src 'self'; style-src 'self'; img-src 'self' data:; font-src 'self' data:; connect-src 'self'",
        'upgrade_insecure_requests' => env('PUBLIC_CSP_UPGRADE_INSECURE_REQUESTS', false),
        'hsts' => env('PUBLIC_ENABLE_HSTS', false),
    ],
];
