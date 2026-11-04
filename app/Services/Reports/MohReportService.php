<?php

namespace App\Services\Reports;

use App\Models\Admission;
use App\Models\AncVisit;
use App\Models\Diagnosis;
use App\Models\Encounter;
use App\Models\LabOrderItem;
use App\Models\Labour;
use App\Models\Newborn;
use App\Models\PncVisit;
use App\Models\Pregnancy;
use App\Models\PrescriptionItem;
use App\Models\RadiologyOrder;
use App\Models\Referral;
use App\Models\Surgery;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Kenya Ministry of Health monthly summaries submitted to KHIS (DHIS2):
 *  - MOH 705A / 705B  Outpatient morbidity, under 5 / 5 years and over (by day)
 *  - MOH 717          Service workload
 *  - MOH 405          ANC (antenatal care)
 *  - MOH 333          Maternity
 *  - MOH 406          Postnatal care
 *
 * Diagnoses are grouped by ICD-11 prefix into the MOH 705 condition rows.
 * Each indicator carries a stable key that maps to a KHIS data element UID
 * (config/moh.php) for the DHIS2 dataValueSet export.
 */
class MohReportService
{
    /** MOH 705 condition rows → ICD-11 code prefixes. Order is the register order. */
    public const MOH705_ROWS = [
        'diarrhoea' => ['Diarrhoea', ['ME05.1', '1A40', '1A03']],
        'cholera' => ['Cholera', ['1A00']],
        'typhoid' => ['Typhoid fever', ['1A07']],
        'tuberculosis' => ['Tuberculosis', ['1B1']],
        'malaria' => ['Malaria (confirmed)', ['1F4']],
        'urti' => ['Upper respiratory tract infections', ['CA00', 'CA02', 'CA03']],
        'pneumonia' => ['Pneumonia', ['CA40']],
        'asthma' => ['Asthma', ['CA23']],
        'hiv' => ['HIV disease', ['1C6']],
        'uti' => ['Urinary tract infection', ['GC08']],
        'skin' => ['Diseases of the skin', ['EA', '1F28', '1B70']],
        'eye' => ['Eye infections / conjunctivitis', ['9A60']],
        'anaemia' => ['Anaemia', ['3A']],
        'hypertension' => ['Hypertension', ['BA00']],
        'diabetes' => ['Diabetes', ['5A1']],
        'mental' => ['Mental disorders', ['6A', '6B', '6C']],
        'epilepsy' => ['Epilepsy', ['8A6']],
        'gastric' => ['Gastritis / peptic ulcer', ['DA4', 'DA6']],
        'arthritis' => ['Arthritis / joint pains', ['FA']],
    ];

    public const REPORTS = [
        '705A' => 'MOH 705A — Outpatient morbidity, under 5 years',
        '705B' => 'MOH 705B — Outpatient morbidity, 5 years and over',
        '717' => 'MOH 717 — Service workload',
        '405' => 'MOH 405 — Antenatal care (ANC)',
        '333' => 'MOH 333 — Maternity',
        '406' => 'MOH 406 — Postnatal care (PNC)',
    ];

    /** @return array{0: Carbon, 1: Carbon} */
    public function period(string $month): array
    {
        $start = Carbon::createFromFormat('Y-m', $month)->startOfMonth()->startOfDay();

        return [$start, $start->copy()->endOfMonth()];
    }

    public function build(string $report, string $month, ?int $facilityId = null): array
    {
        return match ($report) {
            '705A' => $this->moh705($month, $facilityId, under5: true),
            '705B' => $this->moh705($month, $facilityId, under5: false),
            '717' => $this->moh717($month, $facilityId),
            '405' => $this->moh405($month),
            '333' => $this->moh333($month),
            '406' => $this->moh406($month),
            default => throw new \InvalidArgumentException("Unknown report {$report}"),
        };
    }

    public function rowFor(?string $code): string
    {
        $code = strtoupper((string) $code);

        foreach (self::MOH705_ROWS as $key => [, $prefixes]) {
            foreach ($prefixes as $prefix) {
                if ($code !== '' && str_starts_with($code, $prefix)) {
                    return $key;
                }
            }
        }

        return 'other';
    }

    /** Outpatient morbidity matrix: condition × day of month. */
    public function moh705(string $month, ?int $facilityId, bool $under5): array
    {
        [$from, $to] = $this->period($month);
        $days = (int) $from->daysInMonth;

        $diagnoses = Diagnosis::query()
            ->with(['encounter:id,patient_id,started_at,type,facility_id', 'encounter.patient:id,date_of_birth'])
            ->whereIn('type', ['primary', 'secondary'])
            ->whereHas('encounter', fn ($q) => $q->whereBetween('started_at', [$from, $to])->where('type', '!=', 'inpatient')
                ->when($facilityId, fn ($w) => $w->where('facility_id', $facilityId)))
            ->get()
            ->filter(function (Diagnosis $d) use ($under5) {
                $dob = $d->encounter?->patient?->date_of_birth;
                $isUnder5 = $dob && $dob->diffInYears($d->encounter->started_at) < 5;

                return $under5 ? $isUnder5 : ! $isUnder5;
            });

        $rows = collect(self::MOH705_ROWS)->map(fn ($r) => ['label' => $r[0], 'days' => array_fill(1, $days, 0)])->all();
        $rows['other'] = ['label' => 'All other diseases', 'days' => array_fill(1, $days, 0)];

        foreach ($diagnoses as $d) {
            $rows[$this->rowFor($d->code)]['days'][(int) $d->encounter->started_at->format('j')]++;
        }

        foreach ($rows as $k => $r) {
            $rows[$k]['total'] = array_sum($r['days']);
        }

        $attendances = Encounter::whereBetween('started_at', [$from, $to])->where('type', '!=', 'inpatient')
            ->when($facilityId, fn ($w) => $w->where('facility_id', $facilityId))->with('patient:id,date_of_birth')->get()
            ->filter(function ($e) use ($under5) {
                $isUnder5 = $e->patient?->date_of_birth && $e->patient->date_of_birth->diffInYears($e->started_at) < 5;

                return $under5 ? $isUnder5 : ! $isUnder5;
            })->count();

        return [
            'type' => 'matrix',
            'title' => self::REPORTS[$under5 ? '705A' : '705B'],
            'month' => $month,
            'days' => $days,
            'rows' => $rows,
            'footer' => ['label' => 'Total new attendances', 'value' => $attendances],
        ];
    }

    public function moh717(string $month, ?int $facilityId): array
    {
        [$from, $to] = $this->period($month);
        $enc = fn () => Encounter::whereBetween('started_at', [$from, $to])->when($facilityId, fn ($q) => $q->where('facility_id', $facilityId));
        $opd = $enc()->where('type', '!=', 'inpatient')->with('patient:id,registered_at')->get();
        $newPatients = $opd->filter(fn ($e) => $e->patient?->registered_at && $e->patient->registered_at->between($from, $to))->count();

        $admissions = Admission::whereBetween('admitted_at', [$from, $to]);
        $discharges = Admission::whereBetween('discharged_at', [$from, $to]);
        $bedDays = (int) Admission::whereBetween('discharged_at', [$from, $to])->get()->sum(fn ($a) => $a->lengthOfStayDays());

        return $this->table('717', $month, [
            'Outpatient services' => [
                'opd_total' => ['Total outpatient attendances', $opd->count()],
                'opd_new' => ['New attendances', $newPatients],
                'opd_revisit' => ['Re-attendances', $opd->count() - $newPatients],
                'emergency' => ['Emergency / casualty attendances', $enc()->where('type', 'emergency')->count()],
            ],
            'Inpatient services' => [
                'admissions' => ['Admissions', (clone $admissions)->count()],
                'discharges' => ['Discharges', (clone $discharges)->count()],
                'deaths' => ['Inpatient deaths', (clone $discharges)->where('discharge_type', 'deceased')->count()],
                'bed_days' => ['Bed days (discharged patients)', $bedDays],
            ],
            'Diagnostics & pharmacy' => [
                'lab_tests' => ['Laboratory tests performed', LabOrderItem::whereIn('status', ['resulted', 'verified', 'released'])->whereBetween('updated_at', [$from, $to])->count()],
                'imaging' => ['Imaging examinations', RadiologyOrder::whereIn('status', ['performed', 'reported', 'verified'])->whereBetween('updated_at', [$from, $to])->count()],
                'prescriptions' => ['Prescription items dispensed', PrescriptionItem::where('quantity_dispensed', '>', 0)->whereBetween('updated_at', [$from, $to])->count()],
            ],
            'Maternal & surgical' => [
                'anc_visits' => ['ANC visits', AncVisit::whereBetween('visit_date', [$from, $to])->count()],
                'deliveries' => ['Deliveries', Labour::where('status', 'delivered')->whereBetween('delivered_at', [$from, $to])->count()],
                'caesarean' => ['Caesarean sections', Labour::where('delivery_mode', 'caesarean')->whereBetween('delivered_at', [$from, $to])->count()],
                'operations' => ['Theatre operations', Surgery::where('status', 'completed')->whereBetween('updated_at', [$from, $to])->count()],
            ],
            'Referrals' => [
                'referrals_out' => ['Referrals out', Referral::where('direction', 'outgoing')->whereNotIn('status', ['draft', 'cancelled'])->whereBetween('sent_at', [$from, $to])->count()],
                'referrals_in' => ['Referrals in', Referral::where('direction', 'incoming')->whereBetween('created_at', [$from, $to])->count()],
            ],
        ]);
    }

    public function moh405(string $month): array
    {
        [$from, $to] = $this->period($month);
        $visits = AncVisit::with('pregnancy')->whereBetween('visit_date', [$from, $to])->get();
        $newClients = Pregnancy::whereBetween('created_at', [$from, $to]);

        return $this->table('405', $month, [
            'ANC attendance' => [
                'anc_new' => ['New ANC clients (1st visit)', $visits->where('visit_number', 1)->count()],
                'anc_revisit' => ['ANC re-visits', $visits->where('visit_number', '>', 1)->count()],
                'anc_4th' => ['Clients completing 4th visit', $visits->where('visit_number', 4)->count()],
                'anc_8th' => ['Clients completing 8th contact', $visits->where('visit_number', 8)->count()],
                'anc_first_trimester' => ['1st visit before 12 weeks', $visits->where('visit_number', 1)->filter(fn ($v) => $v->gestation_weeks !== null && $v->gestation_weeks < 12)->count()],
                'adolescents' => ['New clients aged 10–19', (clone $newClients)->whereHas('patient', fn ($q) => $q->where('date_of_birth', '>', now()->subYears(20)))->count()],
            ],
            'Prevention' => [
                'iptp' => ['IPTp-SP doses given', $visits->where('iptp_given', true)->count()],
                'td' => ['Td doses given', $visits->whereNotNull('td_dose')->count()],
                'ifas' => ['IFAS issued', $visits->where('ifas_given', true)->count()],
                'llin' => ['LLINs issued', $visits->where('llin_given', true)->count()],
                'deworming' => ['Dewormed', $visits->where('deworming', true)->count()],
            ],
            'Screening (new clients)' => [
                'hiv_tested' => ['Tested for HIV', (clone $newClients)->whereIn('hiv_status', ['negative', 'positive'])->count()],
                'hiv_positive' => ['HIV positive', (clone $newClients)->where('hiv_status', 'positive')->count()],
                'syphilis_tested' => ['Tested for syphilis', (clone $newClients)->whereIn('syphilis_status', ['negative', 'positive'])->count()],
                'syphilis_positive' => ['Syphilis positive', (clone $newClients)->where('syphilis_status', 'positive')->count()],
                'anaemia' => ['Hb < 11 g/dL at visit', $visits->filter(fn ($v) => $v->hb !== null && (float) $v->hb < 11)->count()],
                'high_risk' => ['High-risk pregnancies registered', (clone $newClients)->where('risk_level', 'high')->count()],
            ],
        ]);
    }

    public function moh333(string $month): array
    {
        [$from, $to] = $this->period($month);
        $labours = Labour::where('status', 'delivered')->whereBetween('delivered_at', [$from, $to])->get();
        $babies = Newborn::whereIn('labour_id', $labours->pluck('id'))->get();

        return $this->table('333', $month, [
            'Deliveries' => [
                'deliveries' => ['Total deliveries', $labours->count()],
                'svd' => ['Normal (SVD)', $labours->where('delivery_mode', 'svd')->count()],
                'assisted' => ['Assisted vaginal (vacuum / forceps)', $labours->whereIn('delivery_mode', ['vacuum', 'forceps'])->count()],
                'breech' => ['Assisted breech', $labours->where('delivery_mode', 'breech')->count()],
                'caesarean' => ['Caesarean section', $labours->where('delivery_mode', 'caesarean')->count()],
                'amtsl' => ['AMTSL given', $labours->where('amtsl', true)->count()],
            ],
            'Birth outcomes' => [
                'live_births' => ['Live births', $babies->where('outcome', 'live')->count()],
                'fresh_stillbirths' => ['Fresh stillbirths', $babies->where('outcome', 'fresh_stillbirth')->count()],
                'macerated_stillbirths' => ['Macerated stillbirths', $babies->where('outcome', 'macerated_stillbirth')->count()],
                'lbw' => ['Low birth weight (< 2,500 g)', $babies->filter(fn ($b) => $b->isLowBirthWeight())->count()],
                'low_apgar' => ['Apgar < 7 at 5 minutes', $babies->filter(fn ($b) => $b->apgar_5 !== null && $b->apgar_5 < 7)->count()],
                'resuscitated' => ['Babies resuscitated', $babies->where('resuscitated', true)->count()],
                'early_bf' => ['Breastfed within 1 hour', $babies->where('breastfed_within_1h', true)->count()],
                'teo' => ['Tetracycline eye ointment', $babies->where('teo_given', true)->count()],
                'vit_k' => ['Vitamin K given', $babies->where('vitamin_k', true)->count()],
            ],
            'Maternal complications' => [
                'pph' => ['Postpartum haemorrhage', $labours->where('mother_condition', 'pph')->count()],
                'eclampsia' => ['Eclampsia', $labours->where('mother_condition', 'eclampsia')->count()],
                'sepsis' => ['Maternal sepsis', $labours->where('mother_condition', 'sepsis')->count()],
                'ruptured_uterus' => ['Ruptured uterus', $labours->where('mother_condition', 'ruptured_uterus')->count()],
                'maternal_deaths' => ['Maternal deaths', $labours->where('mother_condition', 'deceased')->count()],
                'referred_labour' => ['Mothers referred in labour', Labour::where('status', 'referred')->whereBetween('updated_at', [$from, $to])->count()],
            ],
        ]);
    }

    public function moh406(string $month): array
    {
        [$from, $to] = $this->period($month);
        $v = PncVisit::whereBetween('visit_date', [$from, $to])->get();

        return $this->table('406', $month, [
            'Postnatal visits' => [
                'pnc_48h' => ['Mothers seen within 48 hours', $v->where('timing', '48h')->whereNull('newborn_id')->count()],
                'pnc_1_2wk' => ['Mothers seen at 1–2 weeks', $v->where('timing', '1-2wk')->whereNull('newborn_id')->count()],
                'pnc_4_6wk' => ['Mothers seen at 4–6 weeks', $v->where('timing', '4-6wk')->whereNull('newborn_id')->count()],
                'pnc_babies' => ['Babies seen', $v->whereNotNull('newborn_id')->count()],
                'pnc_babies_48h' => ['Babies seen within 48 hours', $v->whereNotNull('newborn_id')->where('timing', '48h')->count()],
            ],
            'Services' => [
                'fp_counselled' => ['Counselled on family planning', $v->where('family_planning_counselled', true)->count()],
                'fp_method' => ['Received a FP method', $v->whereNotNull('family_planning_method')->count()],
                'exclusive_bf' => ['Babies exclusively breastfed', $v->where('feeding', 'exclusive_breastfeeding')->count()],
                'danger_signs' => ['Visits with danger signs', $v->filter(fn ($x) => ! empty($x->danger_signs))->count()],
                'cord_infection' => ['Cord infections', $v->where('cord', 'infected')->count()],
            ],
        ]);
    }

    protected function table(string $report, string $month, array $sections): array
    {
        return ['type' => 'table', 'title' => self::REPORTS[$report], 'month' => $month, 'sections' => $sections];
    }

    /** Flatten a report into [key, label, value] rows (CSV / DHIS2). */
    public function flatten(array $report): Collection
    {
        if ($report['type'] === 'matrix') {
            return collect($report['rows'])->map(fn ($r, $k) => [$k, $r['label'], $r['total']])->values();
        }

        return collect($report['sections'])->flatMap(fn ($rows) => collect($rows)->map(fn ($r, $k) => [$k, $r[0], $r[1]]))->values();
    }

    /**
     * DHIS2 dataValueSet for import into KHIS (Import/Export app, or the
     * /api/dataValueSets endpoint). Data element UIDs and the org unit come
     * from config/moh.php and the facility's KHIS org unit.
     */
    public function dhis2(string $report, array $built, ?string $orgUnit): array
    {
        $map = config("moh.data_elements.{$report}", []);
        $period = str_replace('-', '', $built['month']);

        $values = $this->flatten($built)
            ->map(fn ($r) => ['dataElement' => $map[$r[0]] ?? 'MOH'.$report.'_'.$r[0], 'period' => $period, 'orgUnit' => $orgUnit ?? 'SET_KHIS_ORG_UNIT', 'value' => (string) $r[2]])
            ->values()->all();

        return array_filter([
            'dataSet' => config("moh.data_sets.{$report}"),
            'period' => $period,
            'orgUnit' => $orgUnit,
            'completeDate' => now()->toDateString(),
            'dataValues' => $values,
        ], fn ($v) => $v !== null);
    }
}
