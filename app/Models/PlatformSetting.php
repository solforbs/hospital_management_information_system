<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Key/value settings that apply to the whole platform (not one hospital).
 * Values are JSON and cached; writes clear the cache.
 */
class PlatformSetting extends Model
{
    protected $fillable = ['key', 'value'];

    protected function casts(): array
    {
        return ['value' => 'array'];
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        try {
            $all = Cache::rememberForever('platform_settings', fn () => static::query()->pluck('value', 'key')->all());
        } catch (\Throwable) {
            return $default; // table not migrated yet, or DB unavailable
        }

        return array_key_exists($key, $all) && $all[$key] !== null ? $all[$key] : $default;
    }

    public static function put(string $key, mixed $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value]);
        Cache::forget('platform_settings');
    }
}
