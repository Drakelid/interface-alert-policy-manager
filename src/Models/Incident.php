<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Enums\IncidentState;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Enums\Severity;

class Incident extends Model
{
    protected $table = 'iapm_incidents';
    protected $guarded = [];
    protected function casts(): array { return ['state' => IncidentState::class, 'severity' => Severity::class, 'context_json' => 'array', 'first_seen_at' => 'datetime', 'triggered_at' => 'datetime', 'last_seen_at' => 'datetime', 'recovered_at' => 'datetime', 'acknowledged_at' => 'datetime', 'muted_until' => 'datetime', 'last_notification_at' => 'datetime']; }
    public static function key(int $deviceId, int $portId): string { return "interface-down:$deviceId:$portId"; }
    public function policy(): BelongsTo { return $this->belongsTo(Policy::class); }
    public function events(): HasMany { return $this->hasMany(IncidentEvent::class); }
    public function deliveries(): HasMany { return $this->hasMany(DeliveryLog::class); }
}
