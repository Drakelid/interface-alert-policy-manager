<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Enums\Severity;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Policy extends Model
{
    protected $table = 'iapm_policies';
    protected $fillable = ['name', 'description', 'enabled', 'priority', 'severity', 'default_receiver', 'notifications_enabled', 'trigger_after_seconds', 'failed_poll_count', 'recovery_after_seconds', 'repeat_seconds', 'maximum_repeats', 'notify_recovery', 'suppress_device_down', 'suppress_admin_down', 'suppress_ignored_port', 'suppress_disabled_port', 'suppress_deleted_port', 'suppress_maintenance', 'suppress_parent_down', 'suppress_uplink_down', 'flap_threshold', 'flap_window_seconds', 'flap_settle_seconds', 'business_schedule_id', 'created_by', 'updated_by'];
    protected static function booted(): void { $clear = function (): void { try { \Illuminate\Support\Facades\DB::table('iapm_interface_policy_cache')->delete(); } catch (\Throwable) {} }; static::saved($clear); static::deleted($clear); }

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'notifications_enabled' => 'boolean', 'notify_recovery' => 'boolean',
            'suppress_device_down' => 'boolean', 'suppress_admin_down' => 'boolean', 'suppress_ignored_port' => 'boolean',
            'suppress_disabled_port' => 'boolean', 'suppress_deleted_port' => 'boolean', 'suppress_maintenance' => 'boolean',
            'suppress_parent_down' => 'boolean', 'suppress_uplink_down' => 'boolean', 'severity' => Severity::class];
    }

    public function assignments(): HasMany { return $this->hasMany(Assignment::class); }
    public function actions(): HasMany { return $this->hasMany(PolicyAction::class); }
    public function incidents(): HasMany { return $this->hasMany(Incident::class); }
    public function schedule(): BelongsTo { return $this->belongsTo(Schedule::class, 'business_schedule_id'); }
}
