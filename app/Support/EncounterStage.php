<?php

namespace App\Support;

/**
 * Patient-flow stages an encounter moves through. The stage tells each
 * department's queue whether the patient is waiting for it; department
 * orders (lab, radiology, pharmacy) run in parallel and carry their own
 * status, so an encounter never has to be "restarted" to visit a department.
 */
class EncounterStage
{
    public const WAITING_TRIAGE = 'waiting_triage';

    public const WAITING_DOCTOR = 'waiting_doctor';

    public const IN_CONSULTATION = 'in_consultation';

    public const AWAITING_RESULTS = 'awaiting_results';

    public const WAITING_PHARMACY = 'waiting_pharmacy';

    public const WAITING_BILLING = 'waiting_billing';

    public const ADMITTED = 'admitted';

    public const COMPLETED = 'completed';

    public const CANCELLED = 'cancelled';

    public static function labels(): array
    {
        return [
            self::WAITING_TRIAGE => 'Waiting for triage',
            self::WAITING_DOCTOR => 'Waiting for doctor',
            self::IN_CONSULTATION => 'In consultation',
            self::AWAITING_RESULTS => 'Awaiting results',
            self::WAITING_PHARMACY => 'Waiting at pharmacy',
            self::WAITING_BILLING => 'Waiting at billing',
            self::ADMITTED => 'Admitted',
            self::COMPLETED => 'Completed',
            self::CANCELLED => 'Cancelled',
        ];
    }

    public static function label(?string $stage): string
    {
        return self::labels()[$stage] ?? ucfirst(str_replace('_', ' ', (string) $stage));
    }

    public static function color(?string $stage): string
    {
        return match ($stage) {
            self::WAITING_TRIAGE => 'amber',
            self::WAITING_DOCTOR => 'sky',
            self::IN_CONSULTATION => 'indigo',
            self::AWAITING_RESULTS => 'purple',
            self::WAITING_PHARMACY => 'teal',
            self::WAITING_BILLING => 'orange',
            self::ADMITTED => 'rose',
            self::COMPLETED => 'lime',
            default => 'zinc',
        };
    }

    public static function terminal(): array
    {
        return [self::COMPLETED, self::CANCELLED];
    }

    public static function all(): array
    {
        return array_keys(self::labels());
    }
}
