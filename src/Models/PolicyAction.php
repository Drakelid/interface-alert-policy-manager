<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Enums\ActionPhase;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\RecoveryActionScheduler;

class PolicyAction extends Model
{
    protected $table = 'iapm_policy_actions';

    protected $fillable = ['policy_id', 'destination_id', 'phase', 'delay_seconds', 'repeat_seconds', 'maximum_sends', 'receivers_json', 'message_template', 'enabled', 'sort_order'];

    protected static function booted(): void
    {
        $reschedule = function (PolicyAction $action): void {
            if ($action->phase !== ActionPhase::Recovery && $action->getRawOriginal('phase') !== 'recovery') {
                return;
            }
            app(RecoveryActionScheduler::class)->reschedulePolicy((int) $action->policy_id);
            if ($action->getOriginal('policy_id') && (int) $action->getOriginal('policy_id') !== (int) $action->policy_id) {
                app(RecoveryActionScheduler::class)->reschedulePolicy((int) $action->getOriginal('policy_id'));
            }
        };
        static::saved(function (PolicyAction $action) use ($reschedule): void {
            if ($action->wasRecentlyCreated || $action->wasChanged(['policy_id', 'destination_id', 'phase', 'delay_seconds', 'repeat_seconds', 'maximum_sends', 'receivers_json', 'message_template', 'enabled'])) {
                $reschedule($action);
            }
        });
        static::deleted($reschedule);
    }

    protected function casts(): array
    {
        return ['phase' => ActionPhase::class, 'enabled' => 'boolean', 'receivers_json' => 'array'];
    }

    /** @return BelongsTo<Destination, $this> */
    public function destination(): BelongsTo
    {
        return $this->belongsTo(Destination::class);
    }

    /** @return BelongsTo<Policy, $this> */
    public function policy(): BelongsTo
    {
        return $this->belongsTo(Policy::class);
    }
}
