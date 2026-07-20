<?php

return [
    'allowed_hosts' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('APP_ALLOWED_HOSTS', ''))
    ))),

    'internal_network' => [
        // If the app runs behind a reverse proxy, configure Laravel trusted proxies
        // so $request->ip() receives the real client IP before enabling this.
        'enabled' => env('INTERNAL_NETWORK_ENABLED', env('INTERNAL_NETWORK_RESTRICTION_ENABLED', false)),
        'allowed_ips' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('INTERNAL_ALLOWED_CIDRS', env('INTERNAL_ALLOWED_IPS', '')))
        ))),
    ],

    'session_idle_timeout_minutes' => (int) env('SESSION_IDLE_TIMEOUT_MINUTES', 15),
    'session_regenerate_interval_minutes' => (int) env('SESSION_REGENERATE_INTERVAL_MINUTES', 15),
    'session_absolute_timeout_minutes' => env('SESSION_ABSOLUTE_TIMEOUT_MINUTES') === null
        ? null
        : (int) env('SESSION_ABSOLUTE_TIMEOUT_MINUTES'),

    'temporary_user_password' => env('USER_TEMPORARY_PASSWORD'),
];
