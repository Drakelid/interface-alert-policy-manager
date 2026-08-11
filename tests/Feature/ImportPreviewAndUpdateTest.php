<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\Feature;

use App\Models\Device;
use Illuminate\Http\UploadedFile;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Destination;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Policy;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Schedule;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\IntegrationTestCase;

/**
 * P1-8: import advertised "promotion between installs" but was paste-only,
 * create-only and preview-less. Items matched by name were silently skipped, so
 * promoting a changed policy a second time did nothing at all.
 */
class ImportPreviewAndUpdateTest extends IntegrationTestCase
{
    private const BASE = '/plugin/interface-alert-policy-manager';

    public function test_a_preview_writes_nothing(): void
    {
        $document = $this->document();

        $this->actingAs($this->admin())
            ->post(self::BASE.'/import', ['document' => $document, 'action' => 'preview'])
            ->assertOk()
            ->assertSee('nothing has been written yet', false);

        self::assertNull(Policy::where('name', 'Imported policy')->first());
        self::assertNull(Schedule::where('name', 'Imported schedule')->first());
    }

    public function test_the_preview_lists_each_item_with_a_planned_action(): void
    {
        $body = (string) $this->actingAs($this->admin())
            ->post(self::BASE.'/import', ['document' => $this->document(), 'action' => 'preview'])
            ->assertOk()
            ->getContent();

        self::assertStringContainsString('Imported schedule', $body);
        self::assertStringContainsString('Imported policy', $body);
        self::assertStringContainsString('data-iapm-plan-action="create"', $body);
        self::assertStringContainsString('does not exist here yet', $body);
    }

    public function test_applying_the_plan_creates_the_configuration(): void
    {
        $this->applyDocument($this->document());

        $policy = Policy::where('name', 'Imported policy')->firstOrFail();
        self::assertSame(600, $policy->trigger_after_seconds);
        self::assertCount(1, $policy->actions);
        self::assertCount(1, $policy->assignments);
        self::assertNotNull(Schedule::where('name', 'Imported schedule')->first());
    }

    /** The core failure: a second promotion of a changed policy did nothing. */
    public function test_without_the_opt_in_an_existing_policy_is_skipped_with_a_reason(): void
    {
        $this->applyDocument($this->document());

        $body = (string) $this->actingAs($this->admin())
            ->post(self::BASE.'/import', ['document' => $this->document(['trigger_after_seconds' => 900]), 'action' => 'preview'])
            ->assertOk()
            ->getContent();

        self::assertStringContainsString('data-iapm-plan-action="skip"', $body);
        self::assertStringContainsString('enable &quot;update existing items&quot; to overwrite it', $body);

        $this->applyDocument($this->document(['trigger_after_seconds' => 900]));
        self::assertSame(600, Policy::where('name', 'Imported policy')->firstOrFail()->trigger_after_seconds);
    }

    public function test_with_the_opt_in_an_existing_policy_is_updated(): void
    {
        $this->applyDocument($this->document());

        $this->applyDocument($this->document(['trigger_after_seconds' => 900]), updateExisting: true);

        self::assertSame(900, Policy::where('name', 'Imported policy')->firstOrFail()->trigger_after_seconds);
    }

    /** Updating must not duplicate the children it already matched. */
    public function test_updating_matches_actions_and_assignments_instead_of_duplicating_them(): void
    {
        $this->applyDocument($this->document());
        $this->applyDocument($this->document(['trigger_after_seconds' => 900]), updateExisting: true);

        $policy = Policy::where('name', 'Imported policy')->firstOrFail();
        self::assertCount(1, $policy->actions()->get());
        self::assertCount(1, $policy->assignments()->get());
    }

    /** An import must not remove alerting the document simply does not mention. */
    public function test_updating_leaves_local_only_actions_alone(): void
    {
        $this->applyDocument($this->document());
        $policy = Policy::where('name', 'Imported policy')->firstOrFail();
        $extra = $this->triggerAction($policy, $this->smsDestination(), ['phase' => 'recovery', 'sort_order' => 9]);

        $this->applyDocument($this->document(['trigger_after_seconds' => 900]), updateExisting: true);

        self::assertNotNull($policy->actions()->whereKey($extra->id)->first(), 'A local-only action was deleted by the import.');
    }

    public function test_a_file_upload_is_accepted(): void
    {
        $file = UploadedFile::fake()->createWithContent('config.json', $this->document());

        $this->actingAs($this->admin())
            ->post(self::BASE.'/import', ['file' => $file, 'action' => 'apply'])
            ->assertOk();

        self::assertNotNull(Policy::where('name', 'Imported policy')->first());
    }

    /** Choosing a file after pasting should import the file, not the stale paste. */
    public function test_an_uploaded_file_takes_precedence_over_pasted_text(): void
    {
        $file = UploadedFile::fake()->createWithContent('config.json', $this->document(['name' => 'From the file']));

        $this->actingAs($this->admin())->post(self::BASE.'/import', [
            'file' => $file,
            'document' => $this->document(['name' => 'From the textarea']),
            'action' => 'apply',
        ])->assertOk();

        self::assertNotNull(Policy::where('name', 'From the file')->first());
        self::assertNull(Policy::where('name', 'From the textarea')->first());
    }

    /**
     * Safe by default: a POST that does not explicitly ask to apply is a dry
     * run, so no accidental or replayed submission can write.
     */
    public function test_a_post_without_an_explicit_apply_writes_nothing(): void
    {
        $this->actingAs($this->admin())
            ->post(self::BASE.'/import', ['document' => $this->document()])
            ->assertOk();

        self::assertNull(Policy::where('name', 'Imported policy')->first());
    }

    public function test_submitting_neither_a_file_nor_text_is_reported(): void
    {
        $this->actingAs($this->admin())
            ->post(self::BASE.'/import', ['action' => 'preview'])
            ->assertRedirect()
            ->assertSessionHasErrors();
    }

    public function test_invalid_json_is_reported_without_writing(): void
    {
        $this->actingAs($this->admin())
            ->post(self::BASE.'/import', ['document' => '{not json', 'action' => 'apply'])
            ->assertRedirect()
            ->assertSessionHasErrors();

        self::assertSame(0, Policy::count());
    }

    /** Validation runs on preview, so problems surface before the commit step. */
    public function test_a_document_referencing_a_missing_destination_fails_at_preview(): void
    {
        $document = json_decode($this->document(), true);
        $document['policies'][0]['actions'][0]['destination'] = 'No such gateway';

        $this->actingAs($this->admin())
            ->post(self::BASE.'/import', ['document' => json_encode($document), 'action' => 'preview'])
            ->assertRedirect()
            ->assertSessionHasErrors();
    }

    public function test_the_report_names_every_item_and_what_happened_to_it(): void
    {
        $body = (string) $this->actingAs($this->admin())
            ->post(self::BASE.'/import', ['document' => $this->document(), 'action' => 'apply'])
            ->assertOk()
            ->getContent();

        self::assertStringContainsString('Import complete', $body);
        self::assertStringContainsString('Imported policy', $body);
        self::assertStringContainsString('created', $body);
    }

    private function applyDocument(string $document, bool $updateExisting = false): void
    {
        $this->actingAs($this->admin())->post(self::BASE.'/import', array_filter([
            'document' => $document,
            'action' => 'apply',
            'update_existing' => $updateExisting ? '1' : null,
        ]))->assertOk();
    }

    /**
     * A minimal but complete version-1 export document.
     *
     * Idempotent: the referenced destination and device are created once and
     * reused, so calling this twice produces a document that really does refer
     * to the same entities — which is the whole point of the re-import tests.
     */
    private function document(array $policyOverrides = []): string
    {
        $destination = Destination::firstWhere('name', 'Gateway')
            ?? tap($this->smsDestination(), fn (Destination $d) => $d->update(['name' => 'Gateway']));
        $device = Device::first() ?? $this->device();

        return json_encode([
            'version' => 1,
            'schedules' => [[
                'name' => 'Imported schedule', 'timezone' => 'UTC', 'enabled' => true,
                'schedule_json' => ['mode' => 'always', 'days' => []],
            ]],
            'policies' => [array_merge([
                'name' => 'Imported policy', 'description' => null, 'enabled' => true, 'priority' => 0,
                'severity' => 'critical', 'default_receiver' => null, 'notifications_enabled' => true,
                'trigger_after_seconds' => 600, 'failed_poll_count' => 1, 'recovery_after_seconds' => 0,
                'repeat_seconds' => null, 'maximum_repeats' => null, 'notify_recovery' => true,
                'suppress_device_down' => true, 'suppress_admin_down' => true, 'suppress_ignored_port' => true,
                'suppress_disabled_port' => true, 'suppress_deleted_port' => true, 'suppress_maintenance' => true,
                'suppress_parent_down' => true, 'suppress_uplink_down' => false,
                'flap_threshold' => null, 'flap_window_seconds' => null, 'flap_settle_seconds' => null,
                'schedule' => 'Imported schedule',
                'actions' => [[
                    'destination' => 'Gateway', 'phase' => 'trigger', 'delay_seconds' => 0,
                    'repeat_seconds' => null, 'maximum_sends' => null, 'receivers_json' => [],
                    'message_template' => null, 'enabled' => true, 'sort_order' => 0,
                ]],
                'assignments' => [[
                    'assignment_type' => 'device', 'assignment_reference' => (string) $device->device_id,
                    'match_expression' => null, 'match_mode' => 'any', 'priority' => 0, 'enabled' => true,
                    'metadata_json' => ['receivers' => []], 'device_group_ids' => [],
                ]],
            ], $policyOverrides)],
        ]);
    }
}
