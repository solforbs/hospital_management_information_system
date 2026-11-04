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

    public static function isValid(?string $code): bool
    {
        return $code !== null && $code !== '' && static::where('code', strtoupper(trim($code)))->exists();
    }
}
