<?php

namespace App\Support;

/**
 * Kenya Essential Package for Health (KEPH) facility levels. The level
 * drives which modules a facility offers by default, where referrals go,
 * and the SHA per-diem tier for inpatient and critical care.
 */
class FacilityLevel
{
    public const LEVELS = [
        1 => 'Level 1 — Community unit',
        2 => 'Level 2 — Dispensary / clinic',
        3 => 'Level 3 — Health centre',
        4 => 'Level 4 — Sub-county / primary hospital',
        5 => 'Level 5 — County referral hospital',
        6 => 'Level 6 — National referral hospital',
    ];

    public const SHORT = [
        1 => 'Community unit',
        2 => 'Dispensary / clinic',
        3 => 'Health centre',
        4 => 'Sub-county hospital',
        5 => 'County referral hospital',
        6 => 'National referral hospital',
    ];

    public static function options(): array
    {
        return self::LEVELS;
    }

    public static function label(?int $level): string
    {
        return $level ? (self::LEVELS[$level] ?? "Level {$level}") : __('Level not set');
    }

    public static function short(?int $level): string
    {
        return $level ? 'L'.$level.' · '.(self::SHORT[$level] ?? '') : __('Level not set');
    }

    /**
     * Parse "Level 4", "LEVEL_4", "4", "Level 5 Hospital" from the Facility Registry.
     */
    public static function parse(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 1 && $value <= 6 ? $value : null;
        }

        if (is_string($value) && preg_match('/([1-6])/', $value, $m)) {
            return (int) $m[1];
        }

        return null;
    }

    /** Ward types that are critical care, charted on the ICU flow sheet. */
    public const CRITICAL_CARE_WARD_TYPES = ['icu', 'hdu', 'nicu'];

    /** Minimum KEPH level at which each critical-care unit type is expected. */
    public const CRITICAL_CARE_MIN_LEVEL = ['hdu' => 4, 'icu' => 5, 'nicu' => 4];
}
