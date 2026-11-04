<?php

namespace App\Services\Hie;

use RuntimeException;

/**
 * Raised when the HIE is unreachable, misconfigured or rejects a call.
 * $outcomeUnknown marks a write whose result could not be confirmed
 * (timeout after sending) — the caller must re-check, never blindly retry.
 */
class HieException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $status = null,
        public readonly array $body = [],
        public readonly bool $outcomeUnknown = false,
    ) {
        parent::__construct($message);
    }

    public static function notConfigured(): self
    {
        return new self(__('The national HIE integration is not configured or not enabled for this hospital.'));
    }
}
