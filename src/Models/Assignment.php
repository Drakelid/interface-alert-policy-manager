<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Enums\AssignmentType;

class Assignment extends Model
{
    protected $table = 'iapm_assignments';
    protected $fillable = ['policy_id', 'assignment_type', 'assignment_reference', 'match_expression', 'match_mode', 'priority', 'enabled', 'metadata_json'];
    protected static function booted(): void { $clear = function (): void { try { \Illuminate\Support\Facades\DB::table('iapm_interface_policy_cache')->delete(); } catch (\Throwable) {} }; static::saved($clear); static::deleted($clear); }
    protected function casts(): array { return ['assignment_type' => AssignmentType::class, 'enabled' => 'boolean', 'metadata_json' => 'array']; }
    public function policy(): BelongsTo { return $this->belongsTo(Policy::class); }
    public function deviceGroups(): HasMany { return $this->hasMany(AssignmentDeviceGroup::class); }
}
