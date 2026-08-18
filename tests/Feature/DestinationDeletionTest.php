<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\Feature;

use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\DeliveryLog;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Destination;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\NotificationOutbox;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\IntegrationTestCase;

/**
 * Delivery logs and outbox rows both hold destination_id restrictOnDelete, but
 * destroy() only checked policy actions -- so deleting a destination that had
 * ever been used raised a raw QueryException and the operator landed on
 * "Whoops, looks like something went wrong. Check your librenms.log."
 */
class DestinationDeletionTest extends IntegrationTestCase
{
    private const BASE = '/plugin/interface-alert-policy-manager';

    /**
     * The reported path: create a destination, press Test, then delete it. The test
     * writes a delivery log with a null incident_id, which retention cleanup can
     * never reach -- so the row is dropped with the destination rather than pinning
     * it in place forever.
     */
    public function test_a_destination_that_was_only_tested_can_be_deleted(): void
    {
        $destination = $this->smsDestination();
        $log = DeliveryLog::create(['destination_id' => $destination->id, 'phase' => 'test', 'status' => 'sent', 'created_at' => now()]);

        $this->actingAs($this->admin())->delete(self::BASE."/destinations/{$destination->id}")
            ->assertRedirect(route('iapm.destinations.index'))
            ->assertSessionHasNoErrors();

        self::assertFalse(Destination::whereKey($destination->id)->exists());
        self::assertFalse(DeliveryLog::whereKey($log->id)->exists());
    }

    /** Real history is kept: it leaves with its incident when retention lapses. */
    public function test_a_destination_with_incident_delivery_history_is_refused_with_an_explanation(): void
    {
        $destination = $this->smsDestination();
        $incident = $this->incident($this->policy(), $this->downPort($this->device()));
        DeliveryLog::create(['incident_id' => $incident->id, 'destination_id' => $destination->id, 'phase' => 'trigger', 'status' => 'sent', 'created_at' => now()]);

        $this->actingAs($this->admin())->delete(self::BASE."/destinations/{$destination->id}")
            ->assertRedirect()
            ->assertSessionHasErrors();

        self::assertTrue(Destination::whereKey($destination->id)->exists());
    }

    public function test_a_destination_with_outbox_work_is_refused_with_an_explanation(): void
    {
        $destination = $this->smsDestination();
        $incident = $this->incident($this->policy(), $this->downPort($this->device()));
        NotificationOutbox::create(['idempotency_key' => hash('sha256', 'pending-delete'), 'episode_uuid' => $incident->episode_uuid, 'incident_id' => $incident->id, 'destination_id' => $destination->id, 'phase' => 'trigger', 'receiver_hash' => hash('sha256', 'noc'), 'receiver_encrypted' => 'noc', 'message_encrypted' => 'down', 'incident_ids_encrypted' => [$incident->id], 'status' => 'pending']);

        $this->actingAs($this->admin())->delete(self::BASE."/destinations/{$destination->id}")
            ->assertRedirect()
            ->assertSessionHasErrors();

        self::assertTrue(Destination::whereKey($destination->id)->exists());
    }

    public function test_a_destination_used_by_a_policy_action_is_refused_with_an_explanation(): void
    {
        $destination = $this->smsDestination();
        $this->triggerAction($this->policy(), $destination);

        $this->actingAs($this->admin())->delete(self::BASE."/destinations/{$destination->id}")
            ->assertRedirect()
            ->assertSessionHasErrors();

        self::assertTrue(Destination::whereKey($destination->id)->exists());
    }

    /**
     * The bulk path ran every delete inside one transaction, so a single referenced
     * destination rolled the whole batch back behind a 500. It skips instead.
     */
    public function test_bulk_delete_skips_referenced_destinations_and_deletes_the_rest(): void
    {
        $deletable = $this->smsDestination();
        DeliveryLog::create(['destination_id' => $deletable->id, 'phase' => 'test', 'status' => 'sent', 'created_at' => now()]);
        $referenced = $this->smsDestination();
        $incident = $this->incident($this->policy(), $this->downPort($this->device()));
        DeliveryLog::create(['incident_id' => $incident->id, 'destination_id' => $referenced->id, 'phase' => 'trigger', 'status' => 'sent', 'created_at' => now()]);

        $response = $this->actingAs($this->admin())->delete(self::BASE.'/destinations-bulk', ['ids' => [$deletable->id, $referenced->id]])
            ->assertRedirect(route('iapm.destinations.index'));

        self::assertStringContainsString($referenced->name, (string) $response->getSession()->get('error'));
        self::assertFalse(Destination::whereKey($deletable->id)->exists());
        self::assertTrue(Destination::whereKey($referenced->id)->exists());
    }
}
