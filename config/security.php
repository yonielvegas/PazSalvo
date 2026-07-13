<?php

return [
    'internal_network' => [
        // If the app runs behind a reverse proxy, configure Laravel trusted proxies
        // so $request->ip() receives the real client IP before enabling this.
        'enabled' => env('INTERNAL_NETWORK_RESTRICTION_ENABLED', false),
        'allowed_ips' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('INTERNAL_ALLOWED_IPS', ''))
        ))),
    ],

    'session_idle_timeout_minutes' => (int) env('SESSION_IDLE_TIMEOUT_MINUTES', 15),
];
