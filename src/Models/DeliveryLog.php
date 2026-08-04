<?php
namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Models;
use Illuminate\Database\Eloquent\Model;
class DeliveryLog extends Model { protected $table = 'iapm_delivery_logs'; protected $guarded = []; protected function casts(): array { return ['sent_at' => 'datetime']; } }
