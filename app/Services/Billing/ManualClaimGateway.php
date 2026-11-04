<?php

namespace App\Services\Billing;

use App\Contracts\ClaimGateway;
use App\Models\InsuranceClaim;

/**
 * Default gateway: claims are marked submitted locally and sent to the
 * payer through their portal by the insurance officer. Replace with an
 * API-backed gateway when SHA / insurer credentials are available.
 */
class ManualClaimGateway implements ClaimGateway
{
    public function submit(InsuranceClaim $claim): ?string
    {
        return null;
    }
}
