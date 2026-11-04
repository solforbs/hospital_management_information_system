<?php

namespace App\Services\Billing;

use App\Contracts\ClaimGateway;
use App\Models\InsuranceClaim;

/**
 * Claims are marked submitted locally and sent to the payer through their
 * own portal by the insurance officer; responses are recorded by hand.
 */
class ManualClaimGateway implements ClaimGateway
{
    public function key(): string
    {
        return 'manual';
    }

    public function submit(InsuranceClaim $claim, array $context = []): ?string
    {
        return null;
    }

    public function status(InsuranceClaim $claim): ?array
    {
        return null;
    }
}
