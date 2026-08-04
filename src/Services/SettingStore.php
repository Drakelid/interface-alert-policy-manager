<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Services;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class SettingStore
{
    public function get(string $key, mixed $default = null): mixed
    {
        // Tolerate the table not existing yet (plugin enabled before migrating):
        // the nav reads a setting on every page, so this must never throw.
        try { $value = DB::table('iapm_settings')->where('setting_key', $key)->value('setting_value'); } catch (\Throwable) { return $default; }
        if ($value === null) return $default;
        try { return json_decode(Crypt::decryptString($value), true, 32, JSON_THROW_ON_ERROR); } catch (\Throwable) { return $default; }
    }

    public function put(string $key, mixed $value): void
    {
        DB::table('iapm_settings')->updateOrInsert(['setting_key' => $key], ['setting_value' => Crypt::encryptString(json_encode($value, JSON_THROW_ON_ERROR)), 'updated_at' => now()]);
    }
}
