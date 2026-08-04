<?php
namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Models;
use Illuminate\Database\Eloquent\Casts\AsEncryptedArrayObject;
use Illuminate\Database\Eloquent\Model;
class Setting extends Model { public $timestamps = false; protected $table = 'iapm_settings'; protected $primaryKey = 'setting_key'; public $incrementing = false; protected $keyType = 'string'; protected $guarded = []; protected function casts(): array { return ['setting_value' => AsEncryptedArrayObject::class, 'updated_at' => 'datetime']; } }
