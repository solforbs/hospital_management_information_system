<?php

namespace App\Services\Billing;

use App\Contracts\ClaimGateway;
use App\Models\InsuranceClaim;
use App\Services\Hie\HieClient;
use App\Services\Hie\ShaClaimGateway;

/**
 * Picks the gateway for a claim: SHA claims go electronically through the
 * HIE when the hospital has enabled it; everything else is manual.
 */
class ClaimGatewayResolver
{
    public function __construct(protected HieClient $hie) {}

    public function for(InsuranceClaim $claim): ClaimGateway
    {
        $type = $claim->insurance?->provider?->type;

        if ($type === 'sha' && $this->hie->isEnabled()) {
            return app(ShaClaimGateway::class);
        }

        return app(ManualClaimGateway::class);
    }

    public function isElectronic(InsuranceClaim $claim): bool
    {
        return $this->for($claim)->key() !== 'manual';
    }
}
