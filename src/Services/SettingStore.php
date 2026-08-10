<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Services;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class SettingStore
{
    /** @var array<string, array{value: mixed, expires: float}> */
    private array $cache = [];

    public function get(string $key, mixed $default = null): mixed
    {
        $cached = $this->cache[$key] ?? null;
        if ($cached !== null && $cached['expires'] >= microtime(true)) {
            return $cached['value'];
        }

        // Tolerate the table not existing yet (plugin enabled before migrating):
        // the nav reads a setting on every page, so this must never throw.
        try {
            $value = DB::table('iapm_settings')->where('setting_key', $key)->value('setting_value');
        } catch (\Throwable) {
            return $default;
        }
        if ($value === null) {
            return $this->remember($key, $default);
        }
        try {
            return $this->remember($key, json_decode(Crypt::decryptString($value), true, 32, JSON_THROW_ON_ERROR));
        } catch (\Throwable) {
            return $default;
        }
    }

    public function put(string $key, mixed $value): void
    {
        DB::table('iapm_settings')->updateOrInsert(['setting_key' => $key], ['setting_value' => Crypt::encryptString(json_encode($value, JSON_THROW_ON_ERROR)), 'updated_at' => now()]);
        $this->remember($key, $value);
    }

    public function putThrottled(string $key, mixed $value, int $seconds): bool
    {
        $now = now();
        $attributes = ['setting_value' => Crypt::encryptString(json_encode($value, JSON_THROW_ON_ERROR)), 'updated_at' => $now];
        $updated = DB::table('iapm_settings')->where('setting_key', $key)->where(fn ($query) => $query->whereNull('updated_at')->orWhere('updated_at', '<=', $now->copy()->subSeconds(max(1, $seconds))))->update($attributes);
        if ($updated === 0) {
            $updated = DB::table('iapm_settings')->insertOrIgnore(['setting_key' => $key] + $attributes);
        }
        if ($updated > 0) {
            $this->remember($key, $value);
        }

        return $updated > 0;
    }

    public function forget(string $key): void
    {
        unset($this->cache[$key]);
    }

    private function remember(string $key, mixed $value): mixed
    {
        $ttl = max(0.0, (float) config('iapm.settings_cache_seconds', 5));
        $this->cache[$key] = ['value' => $value, 'expires' => microtime(true) + $ttl];

        return $value;
    }
}
