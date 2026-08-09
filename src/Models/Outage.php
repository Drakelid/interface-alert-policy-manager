<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Outage extends Model
{
    protected $table = 'iapm_outages';

    protected $fillable = ['incident_id', 'episode_uuid', 'device_id', 'port_id', 'policy_id', 'severity', 'started_at', 'triggered_at', 'recovered_at', 'detect_seconds', 'duration_seconds', 'notification_count', 'was_flapping', 'suppression_reason'];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'triggered_at' => 'datetime', 'recovered_at' => 'datetime', 'was_flapping' => 'boolean'];
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(Policy::class);
    }
}
