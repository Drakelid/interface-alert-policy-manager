<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\Feature;

use LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\IntegrationTestCase;

/**
 * P0-6: Simulate Alert told operators to "use the Interface Matrix to find a
 * port_id", but the matrix never displayed one — the documented lookup path did
 * not exist. Rather than only pinning that one sentence, these assert the two
 * general properties behind it: the UI never sends an operator somewhere that
 * cannot answer, and never asks a web-only administrator to run a shell command.
 */
class NoImpossibleInstructionsTest extends IntegrationTestCase
{
    private const BASE = '/plugin/interface-alert-policy-manager';

    /**
     * Anywhere a page mentions port_id, the matrix must actually be able to
     * supply one. Proven directly rather than by grepping for a phrase.
     */
    public function test_the_matrix_can_supply_the_port_id_the_tools_ask_for(): void
    {
        $port = $this->downPort($this->device());

        $matrix = (string) $this->actingAs($this->admin())->get(self::BASE.'/interface-matrix')->assertOk()->getContent();

        // The heading is a sort link since P1-6, so match the column rather than
        // an exact tag pairing.
        self::assertMatchesRegularExpression('#<th[^>]*>\s*<a[^>]*>\s*port_id#', $matrix, 'The matrix has no port_id column.');
        self::assertStringContainsString('<code>'.$port->port_id.'</code>', $matrix);
    }

    /** Every form control that takes an entity offers a name-based way in. */
    public function test_the_assignment_port_type_offers_an_interface_search(): void
    {
        $body = (string) $this->actingAs($this->admin())->get(self::BASE.'/assignments/create')->assertOk()->getContent();

        self::assertStringContainsString(route('iapm.lookup.ports'), $body);
        // The raw reference field is still submitted, so existing data keeps working.
        self::assertStringContainsString('name="assignment_reference"', $body);
    }

    /** An existing port assignment reopens showing the interface, not just a number. */
    public function test_editing_a_port_assignment_shows_the_interface_name(): void
    {
        $policy = $this->policy();
        $port = $this->downPort($this->device(['hostname' => 'edge-01']), ['ifName' => 'xe-9/9/9']);
        $assignment = $policy->assignments()->create(['assignment_type' => 'port', 'assignment_reference' => (string) $port->port_id, 'match_mode' => 'any', 'priority' => 0, 'enabled' => true]);

        $body = (string) $this->actingAs($this->admin())->get(self::BASE."/assignments/{$assignment->id}/edit")->assertOk()->getContent();

        self::assertStringContainsString('edge-01', $body);
        self::assertStringContainsString('xe-9/9/9', $body);
    }
}
