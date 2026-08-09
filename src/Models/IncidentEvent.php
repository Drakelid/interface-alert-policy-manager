<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Models;

use Illuminate\Database\Eloquent\Model;

class IncidentEvent extends Model
{
    protected $table = 'iapm_incident_events';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['event_data' => 'array'];
    }
}
