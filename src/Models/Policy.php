<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Enums\Severity;

class Policy extends Model
{
    protected $table = 'iapm_policies';

    protected $fillable = ['name', 'description', 'enabled', 'priority', 'severity', 'default_receiver', 'notifications_enabled', 'trigger_after_seconds', 'down_observations', 'recovery_after_seconds', 'repeat_seconds', 'maximum_repeats', 'notify_recovery', 'suppress_device_down', 'suppress_admin_down', 'suppress_ignored_port', 'suppress_disabled_port', 'suppress_deleted_port', 'suppress_maintenance', 'suppress_parent_down', 'suppress_uplink_down', 'flap_threshold', 'flap_window_seconds', 'flap_settle_seconds', 'business_schedule_id', 'created_by', 'updated_by'];

    /**
     * Defaults for a new policy, sized for LibreNMS's default five-minute poll
     * interval. Without a trigger delay a single poll sample both raises and
     * notifies an incident, so one transient blip pages an operator and then
     * sends a recovery. One poll of confirmation either way is the smallest
     * setting that makes the interval meaningful.
     *
     * Existing policies are untouched; these apply to newly created rows and
     * prefill the create form. `repeat_seconds` stays null (notify once) because
     * enabling reminders is a deliberate choice, not a safe default.
     */
    protected $attributes = [
        'trigger_after_seconds' => 300,
        'down_observations' => 1,
        'recovery_after_seconds' => 300,
    ];

    protected static function booted(): void
    {
        $clear = function (): void {
            try {
                DB::table('iapm_interface_policy_cache')->delete();
            } catch (\Throwable) {
            }
        };
        static::saved($clear);
        static::deleted($clear);
    }

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'notifications_enabled' => 'boolean', 'notify_recovery' => 'boolean',
            'suppress_device_down' => 'boolean', 'suppress_admin_down' => 'boolean', 'suppress_ignored_port' => 'boolean',
            'suppress_disabled_port' => 'boolean', 'suppress_deleted_port' => 'boolean', 'suppress_maintenance' => 'boolean',
            'suppress_parent_down' => 'boolean', 'suppress_uplink_down' => 'boolean', 'severity' => Severity::class];
    }

    /** @return HasMany<Assignment, $this> */
    public function assignments(): HasMany
    {
        return $this->hasMany(Assignment::class);
    }

    /** @return HasMany<PolicyAction, $this> */
    public function actions(): HasMany
    {
        return $this->hasMany(PolicyAction::class);
    }

    /** @return HasMany<Incident, $this> */
    public function incidents(): HasMany
    {
        return $this->hasMany(Incident::class);
    }

    /** @return BelongsTo<Schedule, $this> */
    public function schedule(): BelongsTo
    {
        return $this->belongsTo(Schedule::class, 'business_schedule_id');
    }
}
