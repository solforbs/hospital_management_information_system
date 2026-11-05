<?php

namespace App\Services\Maternity;

use App\Models\AncVisit;
use App\Models\Labour;
use App\Models\Newborn;
use App\Models\PartographEntry;
use App\Models\Patient;
use App\Models\PncVisit;
use App\Models\Pregnancy;
use App\Services\Audit\AuditLogger;
use App\Services\Billing\BillingService;
use App\Services\Clinical\EncounterService;
use App\Services\Notifications\DepartmentNotifier;
use App\Services\Numbering\NumberSequenceService;
use App\Services\Patients\PatientRegistrationService;
use App\Services\Tenancy\TenantContext;
use App\Support\EncounterStage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Maternal and newborn care along the Kenya MCH pathway:
 * pregnancy registration with risk screening → focused ANC visits →
 * labour on the WHO partograph → delivery and newborn records (babies are
 * registered as patients) → postnatal visits for mother and baby.
 */
class MaternityService
{
    public const DANGER_SIGNS = [
        'vaginal_bleeding' => 'Vaginal bleeding',
        'severe_headache' => 'Severe headache / blurred vision',
        'convulsions' => 'Convulsions / fits',
        'fever' => 'Fever',
        'severe_abdominal_pain' => 'Severe abdominal pain',
        'reduced_fetal_movement' => 'Reduced or absent fetal movement',
        'leaking_liquor' => 'Leaking liquor (draining)',
        'swelling' => 'Swelling of face / hands',
        'breathing_difficulty' => 'Difficulty breathing',
        'foul_discharge' => 'Foul-smelling discharge',
    ];

    public function __construct(
        protected NumberSequenceService $numbers,
        protected EncounterService $encounters,
        protected BillingService $billing,
        protected PatientRegistrationService $registration,
        protected AuditLogger $audit,
        protected DepartmentNotifier $notifier,
        protected TenantContext $context,
    ) {}

    // ---------------------------------------------------------------- ANC

    public function registerPregnancy(Patient $patient, array $data): Pregnancy
    {
        if ($patient->sex !== 'female') {
            throw ValidationException::withMessages(['patient' => __('Only a female patient can be registered for antenatal care.')]);
        }

        if ($patient->pregnancies()->where('status', 'active')->exists()) {
            throw ValidationException::withMessages(['patient' => __('This patient already has an active pregnancy record.')]);
        }

        $lmp = ! empty($data['lmp']) ? Carbon::parse($data['lmp']) : null;
        $edd = ! empty($data['edd']) ? Carbon::parse($data['edd']) : $lmp?->copy()->addDays(280);

        if (! $edd) {
            throw ValidationException::withMessages(['lmp' => __('Enter the LMP or an ultrasound EDD.')]);
        }

        if ($lmp && ($lmp->isFuture() || $lmp->lt(now()->subWeeks(44)))) {
            throw ValidationException::withMessages(['lmp' => __('LMP must be within the last 44 weeks.')]);
        }

        $attributes = [
            'gravida' => (int) ($data['gravida'] ?? 1),
            'parity' => (int) ($data['parity'] ?? 0),
            'abortions' => (int) ($data['abortions'] ?? 0),
            'living_children' => (int) ($data['living_children'] ?? 0),
            'previous_caesareans' => (int) ($data['previous_caesareans'] ?? 0),
        ];

        if ($attributes['gravida'] <= $attributes['parity'] + $attributes['abortions']) {
            throw ValidationException::withMessages(['gravida' => __('Gravida must be greater than parity + abortions (this pregnancy counts).')]);
        }

        $pregnancy = new Pregnancy($attributes + [
            'tenant_id' => $patient->tenant_id,
            'facility_id' => $this->context->facilityId() ?? $patient->facility_id,
            'patient_id' => $patient->id,
            'anc_number' => $this->numbers->next('ANC', $patient->tenant_id),
            'lmp' => $lmp,
            'edd' => $edd,
            'edd_by_ultrasound' => (bool) ($data['edd_by_ultrasound'] ?? (! $lmp)),
            'blood_group' => $data['blood_group'] ?? null,
            'rhesus' => $data['rhesus'] ?? null,
            'hiv_status' => $data['hiv_status'] ?? null,
            'syphilis_status' => $data['syphilis_status'] ?? null,
            'hepatitis_b_status' => $data['hepatitis_b_status'] ?? null,
            'height_cm' => $data['height_cm'] ?? null,
            'birth_plan_facility' => $data['birth_plan_facility'] ?? null,
            'status' => 'active',
            'created_by' => Auth::id(),
        ]);

        $factors = $this->baselineRisks($patient, $pregnancy);
        $pregnancy->risk_factors = $factors ?: null;
        $pregnancy->risk_level = $factors ? 'high' : 'low';
        $pregnancy->save();

        $this->audit->log('maternity.pregnancy_registered', $pregnancy, ['risk' => $pregnancy->risk_level]);

        return $pregnancy;
    }

    /** Risk factors known at booking (Kenya ANC profile screening). */
    public function baselineRisks(Patient $patient, Pregnancy $p): array
    {
        $age = $patient->date_of_birth?->diffInYears(now());
        $f = [];

        if ($age !== null && $age < 18) {
            $f[] = __('Adolescent (under 18)');
        }
        if ($age !== null && $age >= 35) {
            $f[] = __('Age 35 or older');
        }
        if ($p->parity >= 5) {
            $f[] = __('Grand multipara (para 5+)');
        }
        if ($p->previous_caesareans > 0) {
            $f[] = __('Previous caesarean section');
        }
        if ($p->abortions >= 2) {
            $f[] = __('Recurrent pregnancy loss');
        }
        if ($p->hiv_status === 'positive') {
            $f[] = __('HIV positive — PMTCT');
        }
        if ($p->syphilis_status === 'positive') {
            $f[] = __('Syphilis positive');
        }
        if ($p->hepatitis_b_status === 'positive') {
            $f[] = __('Hepatitis B positive');
        }
        if ($p->rhesus === 'negative') {
            $f[] = __('Rhesus negative');
        }
        if ($p->height_cm !== null && (float) $p->height_cm < 150) {
            $f[] = __('Short stature (under 150 cm)');
        }

        return $f;
    }

    /**
     * Record a focused ANC contact. Returns the visit; clinical warnings
     * (e.g. pre-eclampsia features) are added to the pregnancy's risk factors.
     */
    public function recordAncVisit(Pregnancy $pregnancy, array $data): AncVisit
    {
        if ($pregnancy->status !== 'active') {
            throw ValidationException::withMessages(['visit' => __('ANC visits can only be added to an active pregnancy.')]);
        }

        $date = ! empty($data['visit_date']) ? Carbon::parse($data['visit_date']) : now();
        if ($date->isFuture()) {
            throw ValidationException::withMessages(['visit_date' => __('Visit date cannot be in the future.')]);
        }

        return DB::transaction(function () use ($pregnancy, $data, $date) {
            $encounter = $pregnancy->patient->activeEncounter();

            $visit = AncVisit::create(collect($data)->only([
                'weight_kg', 'systolic', 'diastolic', 'fundal_height_cm', 'fetal_heart_rate', 'presentation',
                'fetal_movement', 'urine_protein', 'hb', 'iptp_given', 'td_dose', 'ifas_given', 'deworming',
                'llin_given', 'danger_signs', 'notes', 'next_visit_date',
            ])->map(fn ($v) => $v === '' ? null : $v)->all() + [
                'tenant_id' => $pregnancy->tenant_id,
                'pregnancy_id' => $pregnancy->id,
                'encounter_id' => $encounter?->id,
                'visit_number' => (int) AncVisit::where('pregnancy_id', $pregnancy->id)->max('visit_number') + 1,
                'visit_date' => $date->toDateString(),
                'gestation_weeks' => $pregnancy->gestationWeeksOn($date),
                'recorded_by' => Auth::id(),
            ]);

            // Billed only when the mother came through a visit (many MCH services are free/SHA Linda Mama).
            if ($encounter) {
                $this->billing->chargeService($encounter, 'ANC-VISIT', 1, $visit);
            }

            $new = $this->visitRisks($visit);
            if ($new) {
                $pregnancy->update([
                    'risk_factors' => array_values(array_unique(array_merge($pregnancy->risk_factors ?? [], $new))),
                    'risk_level' => 'high',
                ]);
            }

            $this->audit->log('maternity.anc_visit', $visit, ['visit' => $visit->visit_number, 'new_risks' => $new]);

            return $visit;
        });
    }

    public function visitRisks(AncVisit $v): array
    {
        $f = [];
        $hypertensive = ($v->systolic ?? 0) >= 140 || ($v->diastolic ?? 0) >= 90;

        if ($hypertensive && in_array($v->urine_protein, ['++', '+++'], true)) {
            $f[] = __('Pre-eclampsia features (BP ≥140/90 with proteinuria)');
        } elseif ($hypertensive) {
            $f[] = __('Hypertension in pregnancy');
        }
        if ($v->hb !== null && (float) $v->hb < 11) {
            $f[] = (float) $v->hb < 7 ? __('Severe anaemia (Hb < 7)') : __('Anaemia (Hb < 11)');
        }
        if ($v->fetal_heart_rate !== null && ($v->fetal_heart_rate < 110 || $v->fetal_heart_rate > 160)) {
            $f[] = __('Abnormal fetal heart rate');
        }
        if ($v->gestation_weeks !== null && $v->gestation_weeks >= 36 && in_array($v->presentation, ['breech', 'transverse'], true)) {
            $f[] = __('Malpresentation at term');
        }
        if (! empty($v->danger_signs)) {
            $f[] = __('Danger signs reported');
        }

        return $f;
    }

    // ------------------------------------------------------------- Labour

    public function startLabour(Pregnancy $pregnancy, array $data): Labour
    {
        if ($pregnancy->status !== 'active') {
            throw ValidationException::withMessages(['labour' => __('Labour can only be started for an active pregnancy.')]);
        }

        if ($pregnancy->labours()->where('status', 'in_labour')->exists()) {
            throw ValidationException::withMessages(['labour' => __('This mother is already on the labour ward.')]);
        }

        return DB::transaction(function () use ($pregnancy, $data) {
            $patient = $pregnancy->patient;
            $encounter = $patient->activeEncounter() ?? $this->encounters->checkIn($patient, [
                'type' => 'inpatient',
                'stage' => EncounterStage::ADMITTED,
                'priority' => 'urgent',
                'payer_type' => $data['payer_type'] ?? 'cash',
                'chief_complaint' => __('In labour'),
            ]);

            // A mother who walked in through reception leaves the OPD queues.
            if ($encounter->stage !== EncounterStage::ADMITTED) {
                $encounter->update(['type' => 'inpatient']);
                $this->encounters->transition($encounter, EncounterStage::ADMITTED, __('Admitted to labour ward'));
            }

            $membranes = $data['membranes'] ?? 'intact';

            $labour = Labour::create([
                'tenant_id' => $pregnancy->tenant_id,
                'pregnancy_id' => $pregnancy->id,
                'encounter_id' => $encounter->id,
                'labour_number' => $this->numbers->next('LAB', $pregnancy->tenant_id),
                'admitted_at' => now(),
                'onset' => $data['onset'] ?? 'spontaneous',
                'membranes' => $membranes,
                'membranes_ruptured_at' => $membranes === 'ruptured' ? (! empty($data['membranes_ruptured_at']) ? Carbon::parse($data['membranes_ruptured_at']) : now()) : null,
                'gestation_weeks' => $pregnancy->gestationWeeksOn(now()),
                'status' => 'in_labour',
                'notes' => $data['notes'] ?? null,
                'created_by' => Auth::id(),
            ]);

            $this->audit->log('maternity.labour_started', $labour, ['gestation' => $labour->gestation_weeks]);

            return $labour;
        });
    }

    public function recordPartograph(Labour $labour, array $data): PartographEntry
    {
        if ($labour->status !== 'in_labour') {
            throw ValidationException::withMessages(['entry' => __('The partograph is closed for this labour.')]);
        }

        $at = ! empty($data['recorded_at']) ? Carbon::parse($data['recorded_at']) : now();
        if ($at->gt(now()->addMinutes(2)) || $at->lt($labour->admitted_at->copy()->startOfMinute())) {
            throw ValidationException::withMessages(['entry.recorded_at' => __('Time must be during this labour and not in the future.')]);
        }

        // reorder(): the relation is ascending by default; we need the latest exam.
        $last = $labour->entries()->whereNotNull('cervix_cm')->reorder('recorded_at', 'desc')->orderByDesc('id')->first();
        if ($last && isset($data['cervix_cm']) && $data['cervix_cm'] !== '' && (float) $data['cervix_cm'] < (float) $last->cervix_cm && ! $at->lt($last->recorded_at)) {
            throw ValidationException::withMessages(['entry.cervix_cm' => __('Cervical dilatation cannot be less than the previous examination (:cm cm).', ['cm' => $last->cervix_cm])]);
        }

        $entry = PartographEntry::create(collect($data)->only([
            'fetal_heart_rate', 'liquor', 'moulding', 'cervix_cm', 'descent_fifths', 'contractions_per_10',
            'contraction_strength', 'oxytocin_units', 'drugs', 'pulse', 'systolic', 'diastolic', 'temperature',
            'urine_protein', 'urine_volume_ml',
        ])->map(fn ($v) => $v === '' ? null : $v)->all() + [
            'tenant_id' => $labour->tenant_id,
            'labour_id' => $labour->id,
            'recorded_at' => $at,
            'recorded_by' => Auth::id(),
        ]);

        if (($data['liquor'] ?? null) && in_array($data['liquor'], ['C', 'M', 'B'], true) && $labour->membranes === 'intact') {
            $labour->update(['membranes' => 'ruptured', 'membranes_ruptured_at' => $at]);
        }

        $alerts = $this->partographAlerts($labour->fresh());
        if ($alerts['action'] || $alerts['critical']) {
            $this->notifier->notifyPermission(
                'maternity.deliver',
                __('Labour alert: :patient', ['patient' => $labour->pregnancy->patient->full_name]),
                implode('; ', array_merge($alerts['action'] ? [__('Partograph action line crossed')] : [], $alerts['critical'])),
                route('labour.show', $labour),
                'danger',
            );
        }

        return $entry;
    }

    /**
     * WHO partograph interpretation. Active phase starts at ≥4 cm; the alert
     * line expects 1 cm/hour from there; the action line is 4 hours to its right.
     *
     * @return array{active_phase: bool, alert: bool, action: bool, expected_cm: ?float, critical: array<int, string>}
     */
    public function partographAlerts(Labour $labour): array
    {
        $entries = $labour->entries()->get();
        $exams = $entries->whereNotNull('cervix_cm');
        $start = $exams->first(fn ($e) => (float) $e->cervix_cm >= 4);
        $latestExam = $exams->last();
        $alert = $action = false;
        $expected = null;

        if ($start && $latestExam) {
            $hours = $start->recorded_at->diffInMinutes($latestExam->recorded_at) / 60;
            $expected = min(10.0, (float) $start->cervix_cm + $hours);
            $actual = (float) $latestExam->cervix_cm;
            $alert = $actual < $expected - 0.01;
            $action = $actual < min(10.0, (float) $start->cervix_cm + max(0, $hours - 4)) - 0.01;
        }

        $critical = [];
        $last = $entries->last();
        if ($last) {
            if ($last->fetal_heart_rate !== null && ($last->fetal_heart_rate < 110 || $last->fetal_heart_rate > 160)) {
                $critical[] = __('Fetal heart rate :fhr', ['fhr' => $last->fetal_heart_rate]);
            }
            if ($last->liquor === 'M') {
                $critical[] = __('Meconium-stained liquor');
            }
            if ($last->moulding === '+++') {
                $critical[] = __('Severe moulding (+++)');
            }
            if (($last->systolic ?? 0) >= 160 || ($last->diastolic ?? 0) >= 110) {
                $critical[] = __('Severe hypertension :bp', ['bp' => $last->systolic.'/'.$last->diastolic]);
            }
            if ($last->temperature !== null && (float) $last->temperature >= 38) {
                $critical[] = __('Maternal fever');
            }
            if ($last->pulse !== null && $last->pulse > 120) {
                $critical[] = __('Maternal tachycardia');
            }
        }

        return ['active_phase' => (bool) $start, 'alert' => $alert, 'action' => $action, 'expected_cm' => $expected, 'critical' => $critical];
    }

    /**
     * Record the birth. Live-born babies are registered as patients (newborn
     * category) so they get their own record, immunisations and visits.
     *
     * @param  array<int, array>  $babies
     */
    public function recordDelivery(Labour $labour, array $data, array $babies): Labour
    {
        if ($labour->status !== 'in_labour') {
            throw ValidationException::withMessages(['delivery' => __('This labour is already closed.')]);
        }

        if (! array_key_exists($data['delivery_mode'] ?? '', Labour::MODES)) {
            throw ValidationException::withMessages(['delivery.delivery_mode' => __('Choose the mode of delivery.')]);
        }

        if (! $babies) {
            throw ValidationException::withMessages(['babies' => __('Record at least one baby.')]);
        }

        $deliveredAt = ! empty($data['delivered_at']) ? Carbon::parse($data['delivered_at']) : now();
        if ($deliveredAt->gt(now()->addMinutes(2)) || $deliveredAt->lt($labour->admitted_at->copy()->startOfMinute())) {
            throw ValidationException::withMessages(['delivery.delivered_at' => __('Delivery time must be during this labour.')]);
        }

        return DB::transaction(function () use ($labour, $data, $babies, $deliveredAt) {
            $pregnancy = $labour->pregnancy;
            $mother = $pregnancy->patient;

            $condition = $data['mother_condition'] ?? 'stable';
            if (($data['blood_loss_ml'] ?? 0) >= 500 && $condition === 'stable') {
                $condition = 'pph';
            }

            $labour->update([
                'status' => 'delivered',
                'delivered_at' => $deliveredAt,
                'delivery_mode' => $data['delivery_mode'],
                'amtsl' => (bool) ($data['amtsl'] ?? false),
                'uterotonic_given' => (bool) ($data['uterotonic_given'] ?? false),
                'placenta_complete' => isset($data['placenta_complete']) ? (bool) $data['placenta_complete'] : null,
                'blood_loss_ml' => ($data['blood_loss_ml'] ?? '') !== '' ? (int) $data['blood_loss_ml'] : null,
                'perineum' => $data['perineum'] ?? null,
                'mother_condition' => $condition,
                'complications' => $data['complications'] ?? null,
                'delivered_by' => Auth::id(),
            ]);

            foreach (array_values($babies) as $i => $b) {
                $outcome = $b['outcome'] ?? 'live';
                $baby = null;

                if ($outcome === 'live') {
                    $baby = $this->registration->register($mother->tenant, [
                        'first_name' => __('Baby'),
                        'middle_name' => __('of :name', ['name' => $mother->first_name]).(count($babies) > 1 ? ' '.($i + 1) : ''),
                        'last_name' => $mother->last_name,
                        'sex' => $b['sex'],
                        'date_of_birth' => $deliveredAt->toDateString(),
                        'category' => 'newborn',
                        'county' => $mother->county,
                        'phone' => $mother->phone,
                        'next_of_kin_name' => $mother->full_name,
                        'next_of_kin_phone' => $mother->phone,
                        'next_of_kin_relationship' => __('Mother'),
                        'registered_by' => Auth::id(),
                    ], $this->context->facility());
                }

                Newborn::create([
                    'tenant_id' => $labour->tenant_id,
                    'labour_id' => $labour->id,
                    'mother_patient_id' => $mother->id,
                    'baby_patient_id' => $baby?->id,
                    'birth_order' => $i + 1,
                    'sex' => $b['sex'],
                    'outcome' => $outcome,
                    'birth_weight_g' => ($b['birth_weight_g'] ?? '') !== '' ? (int) $b['birth_weight_g'] : null,
                    'apgar_1' => ($b['apgar_1'] ?? '') !== '' ? (int) $b['apgar_1'] : null,
                    'apgar_5' => ($b['apgar_5'] ?? '') !== '' ? (int) $b['apgar_5'] : null,
                    'apgar_10' => ($b['apgar_10'] ?? '') !== '' ? (int) $b['apgar_10'] : null,
                    'resuscitated' => (bool) ($b['resuscitated'] ?? false),
                    'vitamin_k' => (bool) ($b['vitamin_k'] ?? false),
                    'teo_given' => (bool) ($b['teo_given'] ?? false),
                    'bcg_given' => (bool) ($b['bcg_given'] ?? false),
                    'opv0_given' => (bool) ($b['opv0_given'] ?? false),
                    'breastfed_within_1h' => (bool) ($b['breastfed_within_1h'] ?? false),
                    'chlorhexidine_cord' => (bool) ($b['chlorhexidine_cord'] ?? false),
                    'notes' => $b['notes'] ?? null,
                ]);

                // Child welfare: birth-dose vaccines and HEI enrolment for HIV-exposed babies.
                if ($baby) {
                    app(ChildWelfareService::class)->onBirth($baby, $mother, $b, $pregnancy->hiv_status === 'positive');
                }
            }

            $pregnancy->update(['status' => 'delivered', 'outcome' => 'delivered', 'closed_at' => now()]);

            if ($labour->encounter) {
                $code = $data['delivery_mode'] === 'caesarean' ? 'DELIVERY-CS' : 'DELIVERY-SVD';
                $this->billing->chargeService($labour->encounter, $code, 1, $labour);
            }

            $this->audit->log('maternity.delivered', $labour, [
                'mode' => $data['delivery_mode'], 'babies' => count($babies), 'condition' => $condition,
            ]);

            return $labour;
        });
    }

    /** Mother leaves the maternity unit: the visit moves to billing. */
    public function dischargeMother(Labour $labour): void
    {
        if ($labour->status !== 'delivered') {
            throw ValidationException::withMessages(['discharge' => __('Record the delivery first.')]);
        }

        $encounter = $labour->encounter;
        if ($encounter && ! in_array($encounter->stage, array_merge(EncounterStage::terminal(), [EncounterStage::WAITING_BILLING]), true)) {
            $this->encounters->transition($encounter, EncounterStage::WAITING_BILLING, __('Discharged from maternity'));
        }

        $this->audit->log('maternity.mother_discharged', $labour);
    }

    public function referLabour(Labour $labour, string $reason): Labour
    {
        if ($labour->status !== 'in_labour') {
            throw ValidationException::withMessages(['labour' => __('Only an active labour can be referred.')]);
        }

        $labour->update(['status' => 'referred', 'complications' => trim(($labour->complications ? $labour->complications."\n" : '').__('Referred: :r', ['r' => $reason]))]);
        $this->audit->log('maternity.labour_referred', $labour, ['reason' => $reason]);

        return $labour;
    }

    // ---------------------------------------------------------------- PNC

    public function recordPnc(Pregnancy $pregnancy, array $data): PncVisit
    {
        if ($pregnancy->status !== 'delivered') {
            throw ValidationException::withMessages(['pnc' => __('Postnatal visits are recorded after delivery.')]);
        }

        if (! array_key_exists($data['timing'] ?? '', PncVisit::TIMINGS)) {
            throw ValidationException::withMessages(['pnc.timing' => __('Choose the PNC visit timing.')]);
        }

        if (! empty($data['newborn_id'])) {
            $ok = Newborn::whereKey($data['newborn_id'])->whereHas('labour', fn ($q) => $q->where('pregnancy_id', $pregnancy->id))->exists();
            if (! $ok) {
                throw ValidationException::withMessages(['pnc.newborn_id' => __('That baby does not belong to this pregnancy.')]);
            }
        }

        $visit = PncVisit::create(collect($data)->only([
            'newborn_id', 'timing', 'systolic', 'diastolic', 'temperature', 'uterus', 'lochia', 'breasts',
            'perineum_wound', 'baby_weight_kg', 'baby_temperature', 'cord', 'feeding', 'family_planning_counselled',
            'family_planning_method', 'danger_signs', 'notes',
        ])->map(fn ($v) => $v === '' ? null : $v)->all() + [
            'tenant_id' => $pregnancy->tenant_id,
            'pregnancy_id' => $pregnancy->id,
            'visit_date' => ! empty($data['visit_date']) ? Carbon::parse($data['visit_date'])->toDateString() : now()->toDateString(),
            'recorded_by' => Auth::id(),
        ]);

        $this->audit->log('maternity.pnc_visit', $visit, ['timing' => $visit->timing]);

        return $visit;
    }

    public function closePregnancy(Pregnancy $pregnancy, string $outcome): Pregnancy
    {
        if ($pregnancy->status !== 'active') {
            throw ValidationException::withMessages(['outcome' => __('Only an active pregnancy can be closed.')]);
        }

        if (! in_array($outcome, ['miscarriage', 'ectopic', 'transferred_out', 'lost_to_follow_up'], true)) {
            throw ValidationException::withMessages(['outcome' => __('Choose a valid outcome.')]);
        }

        $pregnancy->update(['status' => 'closed', 'outcome' => $outcome, 'closed_at' => now()]);
        $this->audit->log('maternity.pregnancy_closed', $pregnancy, ['outcome' => $outcome]);

        return $pregnancy;
    }
}
