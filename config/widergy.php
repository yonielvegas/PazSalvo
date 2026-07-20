<?php

return [
    'complete_debts_url' => env('WIDERGY_COMPLETE_DEBTS_URL', 'https://utilitygo.widergy.com/api/v1/accounts/complete_debts'),
    'job_base_url' => env('WIDERGY_JOB_BASE_URL', 'https://utilitygo-api-4.widergy.com/async_request/jobs'),
    'utility_id' => env('WIDERGY_UTILITY_ID', '18'),
    'channel' => env('WIDERGY_CHANNEL', 'web'),
    'connect_timeout' => (int) env('WIDERGY_CONNECT_TIMEOUT', 5),
    'request_timeout' => (int) env('WIDERGY_REQUEST_TIMEOUT', 15),
    'poll_interval_ms' => (int) env('WIDERGY_POLL_INTERVAL_MS', 1000),
    'poll_attempts' => (int) env('WIDERGY_POLL_ATTEMPTS', 20),
    'allowed_hosts' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('WIDERGY_ALLOWED_HOSTS', 'utilitygo.widergy.com,utilitygo-api-4.widergy.com'))
    ))),
];
