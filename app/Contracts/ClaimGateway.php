<?php

namespace App\Contracts;

use App\Models\InsuranceClaim;

/**
 * Integration point for electronic claim submission (SHA, private insurers).
 * Bind a real implementation in AppServiceProvider once credentials exist.
 */
interface ClaimGateway
{
    /**
     * Submit the claim and return the payer's reference, if any.
     */
    public function submit(InsuranceClaim $claim): ?string;
}
