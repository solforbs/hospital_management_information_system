<?php

namespace App\Contracts;

use App\Models\InsuranceClaim;

/**
 * Integration point for electronic claim submission (SHA, private insurers).
 */
interface ClaimGateway
{
    /**
     * Gateway key stored on the claim (manual, sha, ...).
     */
    public function key(): string;

    /**
     * Submit the claim and return the payer's reference, if any.
     * $context carries submission inputs such as the member's discharge OTP.
     */
    public function submit(InsuranceClaim $claim, array $context = []): ?string;

    /**
     * Pull the payer's current view of the claim. Returns
     * ['status' => local status|null, 'external_status' => ..., 'approved' => float|null, 'reason' => ...]
     * or null when the gateway cannot report status.
     */
    public function status(InsuranceClaim $claim): ?array;
}
