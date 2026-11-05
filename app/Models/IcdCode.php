<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * ICD-11 MMS entry (global reference data — not tenant-scoped).
 */
class IcdCode extends Model
{
    protected $fillable = ['code', 'title', 'chapter', 'system', 'is_leaf', 'is_common'];

    protected function casts(): array
    {
        return ['is_leaf' => 'boolean', 'is_common' => 'boolean'];
    }

    /** Code-prefix or title search; common conditions and exact code hits first. */
    public function scopeSearch(Builder $q, string $term): Builder
    {
        $term = trim($term);
        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';

        return $q->where(fn ($w) => $w->where('code', 'like', strtoupper($term).'%')->orWhere('title', 'like', $like))
            ->orderByRaw('case when code = ? then 0 when code like ? then 1 else 2 end', [strtoupper($term), strtoupper($term).'%'])
            ->orderByDesc('is_common')
            ->orderBy('code');
    }

    /**
     * ICD-11 chapters that cannot stand alone as a diagnosis: X holds extension
     * codes (severity, laterality, agents…) and V functioning assessment.
     */
    public const NON_DIAGNOSTIC_CHAPTERS = ['X', 'V'];

    public const CHAPTERS = [
        '01' => 'Certain infectious or parasitic diseases',
        '02' => 'Neoplasms',
        '03' => 'Diseases of the blood or blood-forming organs',
        '04' => 'Diseases of the immune system',
        '05' => 'Endocrine, nutritional or metabolic diseases',
        '06' => 'Mental, behavioural or neurodevelopmental disorders',
        '07' => 'Sleep-wake disorders',
        '08' => 'Diseases of the nervous system',
        '09' => 'Diseases of the visual system',
        '10' => 'Diseases of the ear or mastoid process',
        '11' => 'Diseases of the circulatory system',
        '12' => 'Diseases of the respiratory system',
        '13' => 'Diseases of the digestive system',
        '14' => 'Diseases of the skin',
        '15' => 'Diseases of the musculoskeletal system or connective tissue',
        '16' => 'Diseases of the genitourinary system',
        '17' => 'Conditions related to sexual health',
        '18' => 'Pregnancy, childbirth or the puerperium',
        '19' => 'Certain conditions originating in the perinatal period',
        '20' => 'Developmental anomalies',
        '21' => 'Symptoms, signs or clinical findings, not elsewhere classified',
        '22' => 'Injury, poisoning or certain other consequences of external causes',
        '23' => 'External causes of morbidity or mortality',
        '24' => 'Factors influencing health status or contact with health services',
        '25' => 'Codes for special purposes',
        '26' => 'Supplementary chapter: traditional medicine conditions',
        'V' => 'Supplementary section for functioning assessment',
        'X' => 'Extension codes',
    ];

    /** Codes that may be recorded as a diagnosis on their own. */
    public function scopeDiagnosable(Builder $q): Builder
    {
        return $q->where(fn ($w) => $w->whereNull('chapter')->orWhereNotIn('chapter', self::NON_DIAGNOSTIC_CHAPTERS));
    }

    public static function isValid(?string $code): bool
    {
        return $code !== null && $code !== '' && static::diagnosable()->where('code', strtoupper(trim($code)))->exists();
    }
}
