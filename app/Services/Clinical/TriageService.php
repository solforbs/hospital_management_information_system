<?php

namespace App\Services\Clinical;

use App\Models\Encounter;
use App\Models\VitalSign;
use App\Support\EncounterStage;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Records vital signs, derives BMI and an early-warning triage suggestion,
 * and hands the patient on to the doctor's queue.
 */
class TriageService
{
    public function __construct(protected EncounterService $encounters) {}

    public function record(Encounter $encounter, array $vitals, ?string $triageCategory = null, ?string $chiefComplaint = null, bool $sendToDoctor = true): VitalSign
    {
        return DB::transaction(function () use ($encounter, $vitals, $triageCategory, $chiefComplaint, $sendToDoctor) {
            $vitals = array_map(fn ($v) => $v === '' ? null : $v, $vitals);

            if (! empty($vitals['weight_kg']) && ! empty($vitals['height_cm'])) {
                $metres = $vitals['height_cm'] / 100;
                $vitals['bmi'] = round($vitals['weight_kg'] / ($metres * $metres), 1);
            }

            $record = VitalSign::create(array_merge($vitals, [
                'tenant_id' => $encounter->tenant_id,
                'patient_id' => $encounter->patient_id,
                'encounter_id' => $encounter->id,
                'recorded_by' => Auth::id(),
                'recorded_at' => now(),
            ]));

            $category = $triageCategory ?: self::suggestCategory($record);

            $encounter->update([
                'triage_category' => $category,
                'priority' => in_array($category, ['red', 'orange'], true) ? 'emergency' : ($category === 'yellow' ? 'urgent' : $encounter->priority),
                'chief_complaint' => $chiefComplaint ?: $encounter->chief_complaint,
            ]);

            if ($sendToDoctor && $encounter->stage === EncounterStage::WAITING_TRIAGE) {
                $this->encounters->transition($encounter, EncounterStage::WAITING_DOCTOR, __('Triage completed'));
            }

            return $record;
        });
    }

    /**
     * Simplified early-warning scoring used only as a suggestion; the
     * triage nurse can always override the category.
     */
    public static function suggestCategory(VitalSign $v): string
    {
        $score = 0;

        if ($v->spo2 !== null) {
            $score += $v->spo2 < 90 ? 3 : ($v->spo2 < 94 ? 1 : 0);
        }
        if ($v->systolic_bp !== null) {
            $score += ($v->systolic_bp < 90 || $v->systolic_bp >= 200) ? 3 : ($v->systolic_bp >= 180 ? 2 : 0);
        }
        if ($v->pulse !== null) {
            $score += ($v->pulse < 40 || $v->pulse > 130) ? 3 : ($v->pulse > 110 ? 1 : 0);
        }
        if ($v->respiratory_rate !== null) {
            $score += ($v->respiratory_rate < 8 || $v->respiratory_rate > 30) ? 3 : ($v->respiratory_rate > 24 ? 2 : 0);
        }
        if ($v->temperature !== null) {
            $score += ($v->temperature < 35 || $v->temperature >= 40) ? 2 : ($v->temperature >= 38.5 ? 1 : 0);
        }

        return match (true) {
            $score >= 6 => 'red',
            $score >= 4 => 'orange',
            $score >= 2 => 'yellow',
            default => 'green',
        };
    }
}
