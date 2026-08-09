<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Models;

use Illuminate\Database\Eloquent\Casts\AsEncryptedArrayObject;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Destination extends Model
{
    protected $table = 'iapm_destinations';

    protected $fillable = ['name', 'type', 'enabled', 'configuration_encrypted'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'configuration_encrypted' => AsEncryptedArrayObject::class];
    }

    /** @return HasMany<PolicyAction, $this> */
    public function actions(): HasMany
    {
        return $this->hasMany(PolicyAction::class);
    }
}
