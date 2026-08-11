<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\Feature;

use Illuminate\Support\Facades\Schema;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Assignment;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Destination;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Policy;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\PolicyAction;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Schedule;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\IntegrationTestCase;

class SchemaAndFillableTest extends IntegrationTestCase
{
    /**
     * The receiver columns and policy cache live in the initial migration; this
     * guards against a reintroduced second migration whose rollback dropped them.
     */
    public function test_the_consolidated_schema_contains_the_receiver_columns_and_policy_cache(): void
    {
        self::assertTrue(Schema::hasColumn('iapm_policies', 'default_receiver'));
        self::assertTrue(Schema::hasColumn('iapm_policy_actions', 'receivers_json'));
        self::assertTrue(Schema::hasTable('iapm_interface_policy_cache'));
    }

    public function test_a_policy_round_trips_every_mass_assignable_field(): void
    {
        $schedule = Schedule::create(['name' => 'Biz', 'timezone' => 'UTC', 'enabled' => true, 'schedule_json' => ['mode' => 'always']]);

        $attributes = [
            'name' => 'Round trip',
            'description' => 'desc',
            'enabled' => false,
            'priority' => 7,
            'severity' => 'warning',
            'default_receiver' => 'noc',
            'notifications_enabled' => false,
            'trigger_after_seconds' => 120,
            'down_observations' => 3,
            'recovery_after_seconds' => 60,
            'repeat_seconds' => 900,
            'maximum_repeats' => 4,
            'notify_recovery' => false,
            'suppress_device_down' => false,
            'suppress_admin_down' => false,
            'suppress_ignored_port' => false,
            'suppress_disabled_port' => false,
            'suppress_deleted_port' => false,
            'suppress_maintenance' => false,
            'suppress_parent_down' => false,
            'business_schedule_id' => $schedule->id,
            'created_by' => 11,
            'updated_by' => 12,
        ];

        $policy = Policy::create($attributes)->fresh();

        foreach ($attributes as $key => $expected) {
            $actual = $policy->getAttribute($key);
            $actual = $actual instanceof \BackedEnum ? $actual->value : $actual;
            self::assertEquals($expected, $actual, "Policy field '$key' was not persisted — is it in \$fillable?");
        }
    }

    public function test_the_other_request_facing_models_persist_their_fields(): void
    {
        $policy = $this->policy();
        $destination = Destination::create(['name' => 'D', 'type' => 'generic_webhook', 'enabled' => false, 'configuration_encrypted' => ['url' => 'https://e.example/h']]);
        self::assertFalse($destination->fresh()->enabled);
        self::assertSame('https://e.example/h', $destination->fresh()->configuration_encrypted['url']);

        $assignment = Assignment::create(['policy_id' => $policy->id, 'assignment_type' => 'ifname_regex', 'assignment_reference' => null, 'match_expression' => '/^xe-/', 'match_mode' => 'any', 'priority' => 3, 'enabled' => true, 'metadata_json' => ['receivers' => ['noc']]]);
        self::assertSame('/^xe-/', $assignment->fresh()->match_expression);
        self::assertSame(['noc'], $assignment->fresh()->metadata_json['receivers']);

        $action = PolicyAction::create(['policy_id' => $policy->id, 'destination_id' => $destination->id, 'phase' => 'reminder', 'delay_seconds' => 30, 'repeat_seconds' => 600, 'maximum_sends' => 2, 'receivers_json' => ['a'], 'message_template' => 'hi', 'enabled' => true, 'sort_order' => 5]);
        self::assertSame(600, (int) $action->fresh()->repeat_seconds);
        self::assertSame(['a'], $action->fresh()->receivers_json);

        $schedule = Schedule::create(['name' => 'S', 'timezone' => 'Europe/Oslo', 'enabled' => true, 'schedule_json' => ['mode' => 'business_hours']]);
        self::assertSame('Europe/Oslo', $schedule->fresh()->timezone);
    }
}
