<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationOutbox extends Model
{
    protected $table = 'iapm_notification_outbox';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'receiver_encrypted' => 'encrypted',
            'message_encrypted' => 'encrypted',
            'incident_ids_encrypted' => 'encrypted:array',
            'available_at' => 'datetime',
            'claimed_at' => 'datetime',
            'delivered_at' => 'datetime',
            'finalized_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Incident, $this> */
    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    /** @return BelongsTo<Destination, $this> */
    public function destination(): BelongsTo
    {
        return $this->belongsTo(Destination::class);
    }

    /** @return BelongsTo<PolicyAction, $this> */
    public function action(): BelongsTo
    {
        return $this->belongsTo(PolicyAction::class, 'policy_action_id');
    }
}
