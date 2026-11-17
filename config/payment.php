<?php

return [
    'token_fingerprint_key' => env('TOKEN_FINGERPRINT_KEY', ''),

    'transaction_expiry_minutes' => (int) env('TRANSACTION_EXPIRY_MINUTES', 60),

    'idempotency_ttl_hours' => (int) env('IDEMPOTENCY_TTL_HOURS', 24),
    'idempotency_stale_seconds' => (int) env('IDEMPOTENCY_STALE_SECONDS', 60),

    'blockchain' => [
        'driver' => env('BLOCKCHAIN_DRIVER', 'mock'),
        'max_attempts' => (int) env('BLOCKCHAIN_MAX_ATTEMPTS', 5),
        'http_timeout' => (int) env('BLOCKCHAIN_HTTP_TIMEOUT', 60),
        'service_url' => env('BLOCKCHAIN_SERVICE_URL', 'http://127.0.0.1:3001'),
        'service_token' => env('BLOCKCHAIN_SERVICE_TOKEN', ''),
        'cli_command' => env('BLOCKCHAIN_CLI_COMMAND'),
    ],

    'receipt' => [
        'network' => env('RECEIPT_NETWORK', 'sepolia'),
        'contract_address' => env('RECEIPT_CONTRACT_ADDRESS'),
        'signer_address' => env('RECEIPT_SIGNER_ADDRESS'),
    ],
];
