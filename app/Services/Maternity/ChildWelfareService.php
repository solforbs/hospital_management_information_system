<?php

namespace App\Services\Maternity;

use App\Models\GrowthMeasurement;
use App\Models\HeiEnrolment;
use App\Models\HeiTest;
use App\Models\Immunization;
use App\Models\Patient;
use App\Services\Audit\AuditLogger;
use App\Services\Numbering\NumberSequenceService;
use App\Support\KepiSchedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Child welfare clinic (CWC): KEPI immunisations, growth and nutrition
 * monitoring, and HIV-exposed infant (HEI) follow-up to final status.
 */
class ChildWelfareService
{
    public function __construct(protected NumberSequenceService $numbers, protected AuditLogger $audit) {}

    public function immunize(Patient $child, string $vaccine, array $data = []): Immunization
    {
        if (! array_key_exists($vaccine, KepiSchedule::VACCINES)) {
            throw ValidationException::withMessages(['vaccine' => __('Unknown vaccine.')]);
        }

        $on = isset($data['given_on']) && $data['given_on'] !== '' ? Carbon::parse($data['given_on']) : now();

        if ($on->isFuture() || ($child->date_of_birth && $on->lt($child->date_of_birth))) {
            throw ValidationException::withMessages(['given_on' => __('The date must be between birth and today.')]);
        }

        if (Immunization::where('patient_id', $child->id)->where('vaccine', $vaccine)->exists()) {
            throw ValidationException::withMessages(['vaccine' => __(':v is already recorded for this child.', ['v' => KepiSchedule::label($vaccine)])]);
        }

        $imm = Immunization::create([
            'tenant_id' => $child->tenant_id,
            'patient_id' => $child->id,
            'vaccine' => $vaccine,
            'given_on' => $on->toDateString(),
            'batch_number' => $data['batch_number'] ?? null,
            'site' => $data['site'] ?? null,
            'given_elsewhere' => (bool) ($data['given_elsewhere'] ?? false),
            'notes' => $data['notes'] ?? null,
            'given_by' => Auth::id(),
        ]);

        $this->audit->log('cwc.immunized', $imm, ['vaccine' => $vaccine]);

        return $imm;
    }

    public function recordGrowth(Patient $child, array $data): GrowthMeasurement
    {
        $on = isset($data['measured_on']) && $data['measured_on'] !== '' ? Carbon::parse($data['measured_on']) : now();
        $ageMonths = $child->date_of_birth ? $child->date_of_birth->diffInMonths($on) : null;
        $muac = isset($data['muac_cm']) && $data['muac_cm'] !== '' ? (float) $data['muac_cm'] : null;

        $status = $ageMonths !== null && $ageMonths >= 6 && $ageMonths < 60 ? KepiSchedule::nutritionStatus($muac) : null;

        return GrowthMeasurement::create([
            'tenant_id' => $child->tenant_id,
            'patient_id' => $child->id,
            'measured_on' => $on->toDateString(),
            'weight_kg' => $data['weight_kg'] ?: null,
            'height_cm' => $data['height_cm'] ?: null,
            'muac_cm' => $muac,
            'head_circumference_cm' => $data['head_circumference_cm'] ?: null,
            'nutrition_status' => $status,
            'vitamin_a' => (bool) ($data['vitamin_a'] ?? false),
            'deworming' => (bool) ($data['deworming'] ?? false),
            'notes' => $data['notes'] ?? null,
            'recorded_by' => Auth::id(),
        ]);
    }

    public function enrolHei(Patient $child, ?Patient $mother, array $data = []): HeiEnrolment
    {
        if ($existing = HeiEnrolment::where('patient_id', $child->id)->first()) {
            return $existing;
        }

        $enrolment = HeiEnrolment::create([
            'tenant_id' => $child->tenant_id,
            'patient_id' => $child->id,
            'mother_patient_id' => $mother?->id,
            'hei_number' => $this->numbers->next('HEI', $child->tenant_id),
            'enrolled_on' => now()->toDateString(),
            'infant_prophylaxis' => $data['infant_prophylaxis'] ?? 'AZT+NVP',
            'feeding' => $data['feeding'] ?? null,
            'status' => 'active',
        ]);

        $this->audit->log('hei.enrolled', $enrolment);

        return $enrolment;
    }

    public function addHeiTest(HeiEnrolment $hei, string $test, ?string $sampleDate = null): HeiTest
    {
        if ($hei->status !== 'active') {
            throw ValidationException::withMessages(['test' => __('This HEI record is closed.')]);
        }

        if (! array_key_exists($test, HeiEnrolment::TESTS)) {
            throw ValidationException::withMessages(['test' => __('Unknown test.')]);
        }

        return HeiTest::create([
            'tenant_id' => $hei->tenant_id,
            'hei_enrolment_id' => $hei->id,
            'test' => $test,
            'sample_date' => $sampleDate ?: now()->toDateString(),
            'result' => 'pending',
            'recorded_by' => Auth::id(),
        ]);
    }

    /**
     * Record a result. A positive result moves the infant to HIV care; a
     * negative 18-month antibody test (after cessation of breastfeeding)
     * completes HEI follow-up.
     */
    public function recordHeiResult(HeiTest $test, string $result): HeiTest
    {
        if (! in_array($result, ['negative', 'positive', 'rejected'], true)) {
            throw ValidationException::withMessages(['result' => __('Choose a valid result.')]);
        }

        $test->update(['result' => $result, 'result_date' => now()->toDateString()]);
        $hei = $test->enrolment;

        if ($result === 'positive') {
            $hei->update(['status' => 'positive_linked', 'closed_on' => now()->toDateString()]);
        } elseif ($result === 'negative' && $test->test === 'antibody_18mo' && $hei->feeding !== 'mixed') {
            $hei->update(['status' => 'negative_discharged', 'closed_on' => now()->toDateString()]);
        }

        $this->audit->log('hei.result', $test, ['result' => $result, 'test' => $test->test]);

        return $test;
    }

    public function closeHei(HeiEnrolment $hei, string $outcome): HeiEnrolment
    {
        if (! array_key_exists($outcome, HeiEnrolment::OUTCOMES)) {
            throw ValidationException::withMessages(['outcome' => __('Choose a valid outcome.')]);
        }

        $hei->update(['status' => $outcome, 'closed_on' => now()->toDateString()]);

        return $hei;
    }

    /** Called at delivery: birth doses and HEI enrolment for HIV-positive mothers. */
    public function onBirth(Patient $baby, Patient $mother, array $birth, bool $motherHivPositive): void
    {
        foreach (['bcg_given' => 'bcg', 'opv0_given' => 'opv_0'] as $flag => $vaccine) {
            if (! empty($birth[$flag])) {
                $this->immunize($baby, $vaccine, ['given_on' => $baby->date_of_birth?->toDateString()]);
            }
        }

        if ($motherHivPositive) {
            $this->enrolHei($baby, $mother);
        }
    }
}
