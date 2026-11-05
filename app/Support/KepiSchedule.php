<?php

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * Kenya Expanded Programme on Immunisation (KEPI) routine schedule.
 * Ages in days from birth. Malaria (RTS,S) and yellow fever are given only
 * in designated counties — toggled per institution in settings.
 */
class KepiSchedule
{
    public const VACCINES = [
        'bcg' => ['BCG', 0, 'Birth'],
        'opv_0' => ['OPV 0 (birth dose)', 0, 'Birth'],
        'opv_1' => ['OPV 1', 42, '6 weeks'],
        'penta_1' => ['DPT-HepB-Hib 1 (Penta)', 42, '6 weeks'],
        'pcv_1' => ['Pneumococcal (PCV10) 1', 42, '6 weeks'],
        'rota_1' => ['Rotavirus 1', 42, '6 weeks'],
        'opv_2' => ['OPV 2', 70, '10 weeks'],
        'penta_2' => ['DPT-HepB-Hib 2 (Penta)', 70, '10 weeks'],
        'pcv_2' => ['Pneumococcal (PCV10) 2', 70, '10 weeks'],
        'rota_2' => ['Rotavirus 2', 70, '10 weeks'],
        'opv_3' => ['OPV 3', 98, '14 weeks'],
        'penta_3' => ['DPT-HepB-Hib 3 (Penta)', 98, '14 weeks'],
        'pcv_3' => ['Pneumococcal (PCV10) 3', 98, '14 weeks'],
        'ipv' => ['IPV', 98, '14 weeks'],
        'vit_a_6m' => ['Vitamin A (100,000 IU)', 182, '6 months'],
        'mr_1' => ['Measles-Rubella 1', 274, '9 months'],
        'yellow_fever' => ['Yellow fever', 274, '9 months', true],
        'malaria_1' => ['Malaria (RTS,S) 1', 182, '6 months', true],
        'malaria_2' => ['Malaria (RTS,S) 2', 213, '7 months', true],
        'malaria_3' => ['Malaria (RTS,S) 3', 274, '9 months', true],
        'mr_2' => ['Measles-Rubella 2', 548, '18 months'],
        'malaria_4' => ['Malaria (RTS,S) 4', 730, '24 months', true],
    ];

    public static function label(string $key): string
    {
        return self::VACCINES[$key][0] ?? $key;
    }

    /** Vaccines that apply to this institution (regional vaccines are opt-in). */
    public static function applicable(bool $includeRegional = false): array
    {
        return array_filter(self::VACCINES, fn ($v) => $includeRegional || empty($v[3]));
    }

    /**
     * Schedule for a child: each vaccine with due date and status
     * (given, overdue, due, upcoming).
     *
     * @param  array<string, CarbonInterface>  $given  vaccine key => date given
     */
    public static function forChild(CarbonInterface $dob, array $given, bool $includeRegional = false, ?CarbonInterface $today = null): array
    {
        $today ??= now();
        $out = [];

        foreach (self::applicable($includeRegional) as $key => $v) {
            $due = $dob->copy()->addDays($v[1]);
            $status = match (true) {
                isset($given[$key]) => 'given',
                $due->lt($today->copy()->subDays(14)) => 'overdue',
                $due->lte($today->copy()->addDays(7)) => 'due',
                default => 'upcoming',
            };

            $out[$key] = ['label' => $v[0], 'age' => $v[2], 'due' => $due, 'status' => $status, 'given_on' => $given[$key] ?? null];
        }

        return $out;
    }

    /** MUAC-based acute malnutrition classification for 6–59 months. */
    public static function nutritionStatus(?float $muacCm): ?string
    {
        return match (true) {
            $muacCm === null => null,
            $muacCm < 11.5 => 'sam',
            $muacCm < 12.5 => 'mam',
            default => 'normal',
        };
    }
}
