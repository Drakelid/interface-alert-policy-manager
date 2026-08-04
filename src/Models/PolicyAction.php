<?php
namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Enums\ActionPhase;
class PolicyAction extends Model { protected $table = 'iapm_policy_actions'; protected $guarded = []; protected function casts(): array { return ['phase' => ActionPhase::class, 'enabled' => 'boolean', 'receivers_json' => 'array']; } public function destination(): BelongsTo { return $this->belongsTo(Destination::class); } public function policy(): BelongsTo { return $this->belongsTo(Policy::class); } }
