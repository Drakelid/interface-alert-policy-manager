<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\Feature;

use LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\IntegrationTestCase;

/**
 * P1-1 / P0-6: the matrix never displayed port_id -- it existed only as the
 * invisible value of each row's checkbox -- while Simulate Alert, Policy Test
 * and Template Preview all demand one, and Simulate told operators to "use the
 * Interface Matrix to find a port_id".
 */
class InterfaceMatrixRowActionsTest extends IntegrationTestCase
{
    private const BASE = '/plugin/interface-alert-policy-manager';

    public function test_the_matrix_shows_the_port_id_and_offers_to_copy_it(): void
    {
        $port = $this->downPort($this->device());

        $body = (string) $this->actingAs($this->admin())->get(self::BASE.'/interface-matrix')->assertOk()->getContent();

        self::assertStringContainsString('<code>'.$port->port_id.'</code>', $body);
        self::assertStringContainsString('data-iapm-copy-text="'.$port->port_id.'"', $body);
    }

    public function test_each_row_links_to_policy_test_and_simulate_for_that_port(): void
    {
        $port = $this->downPort($this->device());

        $body = (string) $this->actingAs($this->admin())->get(self::BASE.'/interface-matrix')->assertOk()->getContent();

        self::assertStringContainsString('policy-test?port_id='.$port->port_id, $body);
        self::assertStringContainsString('tools/simulate?port_id='.$port->port_id, $body);
    }

    public function test_the_interface_name_links_to_the_librenms_port_page(): void
    {
        $device = $this->device();
        $port = $this->downPort($device);

        $body = (string) $this->actingAs($this->admin())->get(self::BASE.'/interface-matrix')->assertOk()->getContent();

        $expected = '/device/device='.$device->device_id.'/tab=port/port='.$port->port_id.'/';
        self::assertStringContainsString($expected, $body);
        self::assertStringNotContainsString('/device/'.$device->device_id.'/port/'.$port->port_id, $body);
    }

    /** The row shortcut is only useful if it arrives at a form ready to submit. */
    public function test_the_simulate_form_is_prefilled_from_the_query_string(): void
    {
        $port = $this->downPort($this->device());

        $this->actingAs($this->admin())
            ->get(self::BASE.'/tools/simulate?port_id='.$port->port_id)
            ->assertOk()
            ->assertSee('value="'.$port->port_id.'"', false);
    }

    /** P1-4: the policy and assignment-source cells were plain text. */
    public function test_the_policy_and_assignment_cells_link_to_their_editors(): void
    {
        $policy = $this->defaultPolicy();
        $assignment = $policy->assignments()->firstOrFail();
        $this->downPort($this->device());

        $body = (string) $this->actingAs($this->admin())->get(self::BASE.'/interface-matrix')->assertOk()->getContent();

        self::assertStringContainsString(route('iapm.policies.edit', $policy), $body);
        self::assertStringContainsString(route('iapm.policies.edit', ['policy' => $policy, 'assignment' => $assignment->id]), $body);
    }
}
