<?php

namespace App\Services\Hie;

use App\Models\Diagnosis;
use App\Models\Encounter;
use App\Models\Facility;
use App\Models\Patient;
use App\Models\PrescriptionItem;
use App\Models\VitalSign;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;

/**
 * Maps a visit to an HL7 FHIR R4 Bundle for the national Shared Health
 * Record: Organization, Patient, Encounter, Condition (ICD-11),
 * Observation (vital signs, LOINC) and MedicationRequest.
 *
 * Identifier systems are configurable (config/hms.php → hie.fhir) so they
 * can be aligned with the DHA Kenya FHIR implementation guide.
 */
class FhirMapper
{
    public const ICD11 = 'http://id.who.int/icd/release/11/mms';

    public const LOINC = 'http://loinc.org';

    public const UCUM = 'http://unitsofmeasure.org';

    /** LOINC codes for the vital signs profile. */
    protected const VITALS = [
        'pulse' => ['8867-4', 'Heart rate', '/min'],
        'respiratory_rate' => ['9279-1', 'Respiratory rate', '/min'],
        'temperature' => ['8310-5', 'Body temperature', 'Cel'],
        'spo2' => ['59408-5', 'Oxygen saturation in Arterial blood by Pulse oximetry', '%'],
        'weight_kg' => ['29463-7', 'Body weight', 'kg'],
        'height_cm' => ['8302-2', 'Body height', 'cm'],
        'bmi' => ['39156-5', 'Body mass index (BMI) [Ratio]', 'kg/m2'],
    ];

    public function encounterBundle(Encounter $encounter): array
    {
        $encounter->loadMissing(['patient', 'diagnoses', 'doctor']);
        $patient = $encounter->patient;
        $facility = $encounter->facility_id ? Facility::find($encounter->facility_id) : null;

        $ids = [
            'org' => $this->uuid('org', $facility?->id ?? 0),
            'patient' => $this->uuid('patient', $patient->id),
            'encounter' => $this->uuid('encounter', $encounter->id),
        ];

        $entries = [];
        $entries[] = $this->entry($ids['org'], $this->organization($facility));
        $entries[] = $this->entry($ids['patient'], $this->patient($patient, $ids['org']));
        $entries[] = $this->entry($ids['encounter'], $this->encounter($encounter, $ids));

        foreach ($encounter->diagnoses as $dx) {
            $entries[] = $this->entry($this->uuid('condition', $dx->id), $this->condition($dx, $ids));
        }

        foreach (VitalSign::where('encounter_id', $encounter->id)->get() as $v) {
            foreach (self::VITALS as $field => [$code, $display, $unit]) {
                if ($v->{$field} !== null) {
                    $entries[] = $this->entry($this->uuid('obs-'.$field, $v->id), $this->vital($v, $code, $display, (float) $v->{$field}, $unit, $ids));
                }
            }
            if ($v->systolic_bp && $v->diastolic_bp) {
                $entries[] = $this->entry($this->uuid('obs-bp', $v->id), $this->bloodPressure($v, $ids));
            }
        }

        $items = PrescriptionItem::with('medicine')->whereHas('prescription', fn ($q) => $q->where('encounter_id', $encounter->id))->get();
        foreach ($items as $item) {
            $entries[] = $this->entry($this->uuid('medreq', $item->id), $this->medicationRequest($item, $ids));
        }

        return [
            'resourceType' => 'Bundle',
            'id' => (string) Str::uuid(),
            'meta' => ['lastUpdated' => now()->toIso8601String()],
            'type' => 'transaction',
            'timestamp' => now()->toIso8601String(),
            'entry' => $entries,
        ];
    }

    protected function entry(string $uuid, array $resource): array
    {
        return [
            'fullUrl' => 'urn:uuid:'.$uuid,
            'resource' => $resource,
            'request' => ['method' => 'PUT', 'url' => $resource['resourceType'].'/'.$resource['id']],
        ];
    }

    protected function uuid(string $type, int $id): string
    {
        // Stable per tenant + record, so re-sending updates instead of duplicating.
        $tenant = app(TenantContext::class)->id() ?? 0;

        $namespace = Uuid::uuid5(Uuid::NAMESPACE_URL, (string) config('app.url'));

        return Uuid::uuid5($namespace, "{$tenant}:{$type}:{$id}")->toString();
    }

    protected function organization(?Facility $facility): array
    {
        $tenant = app(TenantContext::class)->tenant();

        return array_filter([
            'resourceType' => 'Organization',
            'id' => $this->uuid('org', $facility?->id ?? 0),
            'identifier' => array_values(array_filter([
                $facility?->fr_code ? ['system' => config('hms.hie.fhir.facility_system'), 'value' => $facility->fr_code] : null,
                $facility?->mfl_code ? ['system' => config('hms.hie.fhir.mfl_system'), 'value' => $facility->mfl_code] : null,
            ])),
            'name' => trim(($tenant?->name ?? '').($facility && $facility->name !== $tenant?->name ? ' — '.$facility->name : '')),
        ]);
    }

    public function patient(Patient $p, ?string $orgUuid = null): array
    {
        $identifiers = array_values(array_filter([
            ['system' => config('hms.hie.fhir.mrn_system'), 'value' => $p->mrn, 'type' => ['text' => 'MRN']],
            $p->cr_number ? ['system' => config('hms.hie.fhir.cr_system'), 'value' => $p->cr_number, 'type' => ['text' => 'Client Registry ID']] : null,
            $p->sha_number ? ['system' => config('hms.hie.fhir.sha_system'), 'value' => $p->sha_number, 'type' => ['text' => 'SHA number']] : null,
            $p->national_id ? ['system' => config('hms.hie.fhir.national_id_system'), 'value' => $p->national_id, 'type' => ['text' => $p->identification_type ?: 'National ID']] : null,
        ]));

        return array_filter([
            'resourceType' => 'Patient',
            'id' => $this->uuid('patient', $p->id),
            'identifier' => $identifiers,
            'name' => [array_filter(['use' => 'official', 'family' => $p->last_name, 'given' => array_values(array_filter([$p->first_name, $p->middle_name]))])],
            'gender' => match ($p->sex) {
                'male' => 'male', 'female' => 'female', default => 'unknown'
            },
            'birthDate' => $p->date_of_birth?->toDateString(),
            'telecom' => $p->phone ? [['system' => 'phone', 'value' => $p->phone]] : null,
            'address' => $p->county ? [['district' => $p->county, 'country' => 'KE']] : null,
            'managingOrganization' => $orgUuid ? ['reference' => 'urn:uuid:'.$orgUuid] : null,
        ], fn ($v) => $v !== null && $v !== []);
    }

    protected function encounter(Encounter $e, array $ids): array
    {
        $class = match ($e->type) {
            'inpatient' => ['code' => 'IMP', 'display' => 'inpatient encounter'],
            'emergency' => ['code' => 'EMER', 'display' => 'emergency'],
            default => ['code' => 'AMB', 'display' => 'ambulatory'],
        };

        return array_filter([
            'resourceType' => 'Encounter',
            'id' => $ids['encounter'],
            'identifier' => [['system' => config('hms.hie.fhir.encounter_system'), 'value' => $e->encounter_number]],
            'status' => match ($e->status) {
                'completed' => 'finished', 'cancelled' => 'cancelled', default => 'in-progress'
            },
            'class' => ['system' => 'http://terminology.hl7.org/CodeSystem/v3-ActCode'] + $class,
            'subject' => ['reference' => 'urn:uuid:'.$ids['patient']],
            'serviceProvider' => ['reference' => 'urn:uuid:'.$ids['org']],
            'period' => array_filter(['start' => $e->started_at?->toIso8601String(), 'end' => $e->completed_at?->toIso8601String()]),
            'reasonCode' => $e->chief_complaint ? [['text' => $e->chief_complaint]] : null,
            'participant' => $e->doctor ? [['individual' => ['display' => $e->doctor->name], 'type' => [['text' => 'attending']]]] : null,
        ], fn ($v) => $v !== null && $v !== []);
    }

    protected function condition(Diagnosis $dx, array $ids): array
    {
        $coding = $dx->code && ($dx->code_system ?? 'icd11') === 'icd11'
            ? [['system' => self::ICD11, 'code' => $dx->code, 'display' => $dx->description]]
            : [];

        return array_filter([
            'resourceType' => 'Condition',
            'id' => $this->uuid('condition', $dx->id),
            'clinicalStatus' => ['coding' => [['system' => 'http://terminology.hl7.org/CodeSystem/condition-clinical', 'code' => 'active']]],
            'verificationStatus' => ['coding' => [['system' => 'http://terminology.hl7.org/CodeSystem/condition-ver-status', 'code' => $dx->certainty === 'confirmed' ? 'confirmed' : 'provisional']]],
            'category' => [['coding' => [['system' => 'http://terminology.hl7.org/CodeSystem/condition-category', 'code' => 'encounter-diagnosis']]]],
            'code' => array_filter(['coding' => $coding, 'text' => $dx->description]),
            'subject' => ['reference' => 'urn:uuid:'.$ids['patient']],
            'encounter' => ['reference' => 'urn:uuid:'.$ids['encounter']],
            'recordedDate' => $dx->created_at?->toIso8601String(),
        ]);
    }

    protected function vital(VitalSign $v, string $code, string $display, float $value, string $unit, array $ids): array
    {
        return [
            'resourceType' => 'Observation',
            'id' => $this->uuid('obs-'.$code, $v->id),
            'status' => 'final',
            'category' => [['coding' => [['system' => 'http://terminology.hl7.org/CodeSystem/observation-category', 'code' => 'vital-signs']]]],
            'code' => ['coding' => [['system' => self::LOINC, 'code' => $code, 'display' => $display]]],
            'subject' => ['reference' => 'urn:uuid:'.$ids['patient']],
            'encounter' => ['reference' => 'urn:uuid:'.$ids['encounter']],
            'effectiveDateTime' => ($v->recorded_at ?? $v->created_at)?->toIso8601String(),
            'valueQuantity' => ['value' => $value, 'unit' => $unit, 'system' => self::UCUM, 'code' => $unit],
        ];
    }

    protected function bloodPressure(VitalSign $v, array $ids): array
    {
        $component = fn ($code, $display, $val) => [
            'code' => ['coding' => [['system' => self::LOINC, 'code' => $code, 'display' => $display]]],
            'valueQuantity' => ['value' => (float) $val, 'unit' => 'mm[Hg]', 'system' => self::UCUM, 'code' => 'mm[Hg]'],
        ];

        return [
            'resourceType' => 'Observation',
            'id' => $this->uuid('obs-bp', $v->id),
            'status' => 'final',
            'category' => [['coding' => [['system' => 'http://terminology.hl7.org/CodeSystem/observation-category', 'code' => 'vital-signs']]]],
            'code' => ['coding' => [['system' => self::LOINC, 'code' => '85354-9', 'display' => 'Blood pressure panel']]],
            'subject' => ['reference' => 'urn:uuid:'.$ids['patient']],
            'encounter' => ['reference' => 'urn:uuid:'.$ids['encounter']],
            'effectiveDateTime' => ($v->recorded_at ?? $v->created_at)?->toIso8601String(),
            'component' => [$component('8480-6', 'Systolic blood pressure', $v->systolic_bp), $component('8462-4', 'Diastolic blood pressure', $v->diastolic_bp)],
        ];
    }

    protected function medicationRequest(PrescriptionItem $item, array $ids): array
    {
        return array_filter([
            'resourceType' => 'MedicationRequest',
            'id' => $this->uuid('medreq', $item->id),
            'status' => match ($item->status) {
                'cancelled' => 'cancelled', 'dispensed' => 'completed', default => 'active'
            },
            'intent' => 'order',
            'medicationCodeableConcept' => ['text' => $item->medicine?->displayName() ?? $item->medicine?->generic_name],
            'subject' => ['reference' => 'urn:uuid:'.$ids['patient']],
            'encounter' => ['reference' => 'urn:uuid:'.$ids['encounter']],
            'dosageInstruction' => [array_filter(['text' => trim("{$item->dose} {$item->frequency} {$item->route} for {$item->duration_days} day(s)"), 'patientInstruction' => $item->instructions])],
            'dispenseRequest' => ['quantity' => ['value' => (float) $item->quantity]],
        ]);
    }
}
