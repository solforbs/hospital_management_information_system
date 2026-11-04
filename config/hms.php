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

    'payment_methods' => [
        'cash' => 'Cash',
        'mpesa' => 'M-Pesa',
        'card' => 'Card',
        'bank_transfer' => 'Bank transfer',
        'insurance' => 'Insurance',
        'waiver' => 'Waiver',
    ],
];
