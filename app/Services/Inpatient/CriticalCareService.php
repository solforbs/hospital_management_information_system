<?php

namespace App\Services\Inpatient;

use App\Models\Admission;
use App\Models\CriticalCareObservation;
use App\Services\Audit\AuditLogger;
use App\Services\Notifications\DepartmentNotifier;
use App\Support\FacilityLevel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * ICU / HDU / NICU flow chart: timed observations, derived MAP, 24-hour
 * fluid balance, and escalation of dangerous values to the admitting doctor.
 */
class CriticalCareService
{
    public function __construct(protected AuditLogger $audit, protected DepartmentNotifier $notifier) {}

    public static function isCriticalCare(Admission $admission): bool
    {
        return in_array($admission->ward?->type, FacilityLevel::CRITICAL_CARE_WARD_TYPES, true);
    }

    public function record(Admission $admission, array $data): CriticalCareObservation
    {
        if ($admission->status !== 'admitted') {
            throw ValidationException::withMessages(['obs' => __('Patient is not currently admitted.')]);
        }

        if (! self::isCriticalCare($admission)) {
            throw ValidationException::withMessages(['obs' => __('The critical-care chart is only used in ICU, HDU and NICU beds.')]);
        }

        $data = array_map(fn ($v) => $v === '' ? null : $v, $data);
        $recordedAt = isset($data['recorded_at']) ? Carbon::parse($data['recorded_at']) : now();

        // Times are entered to the minute: compare at minute precision, with a
        // small allowance for clock differences on the ward device.
        $admittedAt = ($admission->admitted_at ?? $admission->requested_at)->copy()->startOfMinute();
        if ($recordedAt->gt(now()->addMinutes(2)) || $recordedAt->lt($admittedAt)) {
            throw ValidationException::withMessages(['obs.recorded_at' => __('Observation time must be during this admission and not in the future.')]);
        }

        if (($data['map'] ?? null) === null && isset($data['systolic'], $data['diastolic'])) {
            $data['map'] = (int) round(((int) $data['systolic'] + 2 * (int) $data['diastolic']) / 3);
        }

        $fields = ['heart_rate', 'systolic', 'diastolic', 'map', 'resp_rate', 'spo2', 'temperature', 'gcs', 'fio2', 'ventilation', 'peep', 'blood_glucose', 'intake_ml', 'output_ml', 'urine_ml', 'sedation_score', 'pain_score', 'notes'];

        $obs = CriticalCareObservation::create([
            'tenant_id' => $admission->tenant_id,
            'admission_id' => $admission->id,
            'recorded_by' => Auth::id(),
            'recorded_at' => $recordedAt,
        ] + array_intersect_key($data, array_flip($fields)));

        if ($alerts = $obs->alerts()) {
            $this->notifier->notifyUser(
                $admission->doctor,
                __('Critical-care alert: :patient', ['patient' => $admission->patient->full_name]),
                __(':ward bed :bed — abnormal :values', ['ward' => $admission->ward?->name, 'bed' => $admission->bed?->bed_number, 'values' => implode(', ', array_map(fn ($a) => str_replace('_', ' ', $a), $alerts))]),
                route('critical-care.chart', $admission),
                'danger',
            );
            $this->audit->log('critical_care.alert', $admission, ['alerts' => $alerts]);
        }

        return $obs;
    }

    /**
     * Intake, output and balance over the 24 hours ending at $until.
     */
    public function fluidBalance(Admission $admission, ?Carbon $until = null): array
    {
        $until ??= now();
        $rows = $admission->criticalCareObservations()->whereBetween('recorded_at', [$until->copy()->subDay(), $until])->get();
        $in = (int) $rows->sum('intake_ml');
        $out = (int) $rows->sum('output_ml');
        $urine = (int) $rows->sum('urine_ml');

        return ['intake' => $in, 'output' => $out, 'urine' => $urine, 'balance' => $in - $out, 'entries' => $rows->count()];
    }
}
