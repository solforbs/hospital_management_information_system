<?php

return [
    'currency' => env('HMS_CURRENCY', 'KES'),

    'lab' => [
        // A second person must verify a result before it can be released.
        'require_independent_verification' => env('HMS_LAB_INDEPENDENT_VERIFICATION', true),
    ],

    'pharmacy' => [
        // Do not dispense batches expiring within this many days.
        'min_days_to_expiry' => env('HMS_PHARMACY_MIN_DAYS_TO_EXPIRY', 0),
    ],

    'billing' => [
        // Require the invoice to be fully settled (or insurance-backed) before discharge.
        'require_settlement_before_discharge' => env('HMS_REQUIRE_SETTLEMENT_BEFORE_DISCHARGE', false),
    ],

    /*
     * DHA Health Information Exchange (AfyaLink) and SHA eClaims.
     * Per-tenant credentials live in hie_credentials; these are defaults.
     * The token endpoint is issued at DHA onboarding — set it per tenant
     * (or via env) once known. "simulator" answers locally with canned
     * UAT-shaped responses so workflows can be exercised without credentials.
     */
    'hie' => [
        'timeout' => env('HIE_TIMEOUT', 30),
        'token_cache_seconds' => env('HIE_TOKEN_CACHE_SECONDS', 3000),
        'facility_id_type' => 'fr-code',
        'environments' => [
            'simulator' => ['base_url' => 'https://hie-simulator.local/api/v1', 'token_url' => 'https://hie-simulator.local/token'],
            'uat' => ['base_url' => env('HIE_UAT_BASE_URL', 'https://ilm-dev.dha.go.ke/uat-middleware/api/v1'), 'token_url' => env('HIE_UAT_TOKEN_URL')],
            'production' => ['base_url' => env('HIE_PRODUCTION_BASE_URL'), 'token_url' => env('HIE_PRODUCTION_TOKEN_URL')],
        ],
        // Fallback intervention code when a billed item has no SHA mapping.
        'default_intervention' => env('SHA_DEFAULT_INTERVENTION', 'SHA-12-001'),
        'scheme_code' => env('SHA_SCHEME_CODE', 'UHC'),
    ],

    'payment_methods' => [
        'cash' => 'Cash',
        'mpesa' => 'M-Pesa',
        'card' => 'Card',
        'bank_transfer' => 'Bank transfer',
        'insurance' => 'Insurance',
        'waiver' => 'Waiver',
    ],
];
