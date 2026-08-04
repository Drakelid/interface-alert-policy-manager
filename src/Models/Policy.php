<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Enums\Severity;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Policy extends Model
{
    protected $table = 'iapm_policies';
    protected $guarded = [];
    protected static function booted(): void { $clear = function (): void { try { \Illuminate\Support\Facades\DB::table('iapm_interface_policy_cache')->delete(); } catch (\Throwable) {} }; static::saved($clear); static::deleted($clear); }

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'notifications_enabled' => 'boolean', 'notify_recovery' => 'boolean',
            'suppress_device_down' => 'boolean', 'suppress_admin_down' => 'boolean', 'suppress_ignored_port' => 'boolean',
            'suppress_disabled_port' => 'boolean', 'suppress_deleted_port' => 'boolean', 'suppress_maintenance' => 'boolean',
            'suppress_parent_down' => 'boolean', 'severity' => Severity::class];
    }

    public function assignments(): HasMany { return $this->hasMany(Assignment::class); }
    public function actions(): HasMany { return $this->hasMany(PolicyAction::class); }
    public function incidents(): HasMany { return $this->hasMany(Incident::class); }
    public function schedule(): BelongsTo { return $this->belongsTo(Schedule::class, 'business_schedule_id'); }
}
