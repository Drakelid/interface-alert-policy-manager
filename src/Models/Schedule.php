<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Schedule extends Model
{
    protected $table = 'iapm_schedules';

    protected $fillable = ['name', 'timezone', 'enabled', 'schedule_json'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'schedule_json' => 'array'];
    }

    /** @return HasMany<Policy, $this> */
    public function policies(): HasMany
    {
        return $this->hasMany(Policy::class, 'business_schedule_id');
    }
}
