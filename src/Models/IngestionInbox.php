<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Models;

use Illuminate\Database\Eloquent\Model;

class IngestionInbox extends Model
{
    protected $table = 'iapm_ingestion_inbox';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'payload_encrypted' => 'encrypted:array',
            'available_at' => 'datetime',
            'claimed_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }
}
