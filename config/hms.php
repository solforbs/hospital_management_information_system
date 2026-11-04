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
        // Shared Health Record (FHIR R4). Path relative to the HIE base URL; confirm with DHA.
        'shr_path' => env('HIE_SHR_PATH', '/shared-health-record/fhir'),
        'shr_auto_push' => env('HIE_SHR_AUTO_PUSH', true),
        'fhir' => [
            'facility_system' => env('FHIR_FACILITY_SYSTEM', 'https://fr.dha.go.ke/fid'),
            'mfl_system' => env('FHIR_MFL_SYSTEM', 'https://kmhfr.health.go.ke/mfl'),
            'cr_system' => env('FHIR_CR_SYSTEM', 'https://cr.dha.go.ke/id'),
            'sha_system' => env('FHIR_SHA_SYSTEM', 'https://sha.go.ke/member'),
            'national_id_system' => env('FHIR_NATIONAL_ID_SYSTEM', 'https://iprs.go.ke/national-id'),
            'mrn_system' => env('FHIR_MRN_SYSTEM', rtrim(env('APP_URL', 'http://localhost'), '/').'/fhir/mrn'),
            'encounter_system' => env('FHIR_ENCOUNTER_SYSTEM', rtrim(env('APP_URL', 'http://localhost'), '/').'/fhir/encounter'),
        ],
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
