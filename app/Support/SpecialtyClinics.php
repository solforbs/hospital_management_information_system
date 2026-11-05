<?php

namespace App\Support;

/**
 * Specialist clinics and their structured visit fields. Each clinic is a
 * module (gated by KEPH level), and each field renders as a form input and
 * is validated by type. Add a clinic here to make it available.
 *
 * Field types: text, number, select (options), textarea, date.
 */
class SpecialtyClinics
{
    public const CLINICS = [
        'oncology' => [
            'label' => 'Oncology',
            'icon' => 'beaker',
            'min_level' => 5,
            'service_code' => 'CLINIC-ONC',
            'fields' => [
                'cancer_type' => ['Cancer / primary site', 'text'],
                'icd11' => ['ICD-11 code', 'text'],
                'stage' => ['Stage', 'select', ['0', 'I', 'II', 'III', 'IV', 'Unstaged']],
                'ecog' => ['ECOG performance status', 'select', ['0', '1', '2', '3', '4']],
                'intent' => ['Treatment intent', 'select', ['curative', 'palliative', 'adjuvant', 'neoadjuvant']],
                'regimen' => ['Chemotherapy regimen', 'text'],
                'cycle' => ['Cycle number', 'number'],
                'bsa' => ['Body surface area (m²)', 'number'],
                'toxicity' => ['Toxicities (CTCAE)', 'textarea'],
            ],
        ],
        'physiotherapy' => [
            'label' => 'Physiotherapy',
            'icon' => 'hand-raised',
            'min_level' => 4,
            'service_code' => 'CLINIC-PHYSIO',
            'fields' => [
                'condition' => ['Condition treated', 'text'],
                'pain_score' => ['Pain score (0–10)', 'number'],
                'rom' => ['Range of motion / findings', 'textarea'],
                'treatment' => ['Treatment given', 'textarea'],
                'session_no' => ['Session number', 'number'],
                'sessions_planned' => ['Sessions planned', 'number'],
            ],
        ],
        'dental' => [
            'label' => 'Dental',
            'icon' => 'face-smile',
            'min_level' => 4,
            'service_code' => 'CLINIC-DENTAL',
            'fields' => [
                'complaint' => ['Complaint', 'text'],
                'teeth' => ['Teeth involved (FDI, e.g. 36, 46)', 'text'],
                'findings' => ['Findings', 'textarea'],
                'procedure' => ['Procedure', 'select', ['examination', 'extraction', 'filling', 'scaling_polishing', 'root_canal', 'incision_drainage', 'other']],
                'anaesthesia' => ['Local anaesthesia', 'select', ['none', 'infiltration', 'nerve_block']],
            ],
        ],
        'eye' => [
            'label' => 'Eye clinic',
            'icon' => 'eye',
            'min_level' => 4,
            'service_code' => 'CLINIC-EYE',
            'fields' => [
                'va_right' => ['Visual acuity — right (e.g. 6/9)', 'text'],
                'va_left' => ['Visual acuity — left', 'text'],
                'iop_right' => ['IOP right (mmHg)', 'number'],
                'iop_left' => ['IOP left (mmHg)', 'number'],
                'findings' => ['Anterior / posterior segment findings', 'textarea'],
                'refraction' => ['Refraction / spectacle prescription', 'text'],
            ],
        ],
        'ent' => [
            'label' => 'ENT',
            'icon' => 'speaker-wave',
            'min_level' => 5,
            'service_code' => 'CLINIC-ENT',
            'fields' => [
                'complaint' => ['Complaint', 'text'],
                'ear' => ['Ear examination', 'textarea'],
                'nose_throat' => ['Nose & throat examination', 'textarea'],
                'hearing' => ['Hearing assessment', 'text'],
            ],
        ],
        'psychiatry' => [
            'label' => 'Mental health',
            'icon' => 'chat-bubble-left-right',
            'min_level' => 4,
            'service_code' => 'CLINIC-MH',
            'fields' => [
                'presenting' => ['Presenting problem', 'textarea'],
                'mse' => ['Mental state examination', 'textarea'],
                'phq9' => ['PHQ-9 score', 'number'],
                'gad7' => ['GAD-7 score', 'number'],
                'risk' => ['Suicide / harm risk', 'select', ['low', 'moderate', 'high']],
            ],
        ],
    ];

    public static function find(string $clinic): ?array
    {
        return self::CLINICS[$clinic] ?? null;
    }

    /** Laravel validation rules for a clinic's fields (all optional). */
    public static function rules(string $clinic): array
    {
        $rules = [];

        foreach (self::CLINICS[$clinic]['fields'] ?? [] as $key => $f) {
            $rules["data.{$key}"] = match ($f[1]) {
                'number' => ['nullable', 'numeric', 'min:0', 'max:100000'],
                'select' => ['nullable', 'in:'.implode(',', $f[2])],
                'date' => ['nullable', 'date'],
                'textarea' => ['nullable', 'string', 'max:5000'],
                default => ['nullable', 'string', 'max:255'],
            };
        }

        return $rules;
    }
}
