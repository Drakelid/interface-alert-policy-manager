<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Models;

use App\Models\Port;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Simulation extends Model
{
    protected $table = 'iapm_simulations';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'send_notifications' => 'boolean',
            'started_at' => 'datetime',
            'recover_at' => 'datetime',
            'recovered_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Incident, $this> */
    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    /** @return BelongsTo<Port, $this> */
    public function port(): BelongsTo
    {
        return $this->belongsTo(Port::class, 'port_id', 'port_id');
    }
}
