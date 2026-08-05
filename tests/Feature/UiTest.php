<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\Feature;

use LibreNMS\Plugins\InterfaceAlertPolicyManager\Enums\IncidentState;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\IntegrationTestCase;

class UiTest extends IntegrationTestCase
{
    public function test_incidents_are_ordered_urgent_first(): void
    {
        $policy = $this->policy();
        // Names chosen so alphabetical order is the OPPOSITE of urgency order,
        // proving the sort is by urgency, not by name.
        $this->incident($policy, $this->downPort($this->device(), ['ifName' => 'aaa-recovered']), ['state' => IncidentState::Recovered, 'severity' => 'info', 'recovered_at' => now()]);
        $this->incident($policy, $this->downPort($this->device(), ['ifName' => 'zzz-active']), ['state' => IncidentState::Active, 'severity' => 'critical']);

        $this->actingAs($this->admin())
            ->get('/plugin/interface-alert-policy-manager/incidents')
            ->assertOk()
            ->assertSeeInOrder(['zzz-active', 'aaa-recovered']);
    }

    public function test_the_incidents_list_offers_inline_actions(): void
    {
        $this->incident($this->policy(), $this->downPort($this->device()));

        $this->actingAs($this->admin())
            ->get('/plugin/interface-alert-policy-manager/incidents')
            ->assertOk()
            ->assertSee('incidents/'.\LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Incident::first()->id.'/acknowledge')
            ->assertSee('Auto-refresh');
    }

    public function test_the_overview_tiles_link_to_filtered_views(): void
    {
        $this->actingAs($this->admin())
            ->get('/plugin/interface-alert-policy-manager')
            ->assertOk()
            ->assertSee('state=active')
            ->assertSee('no_policy=1');
    }

    public function test_the_stats_page_renders_a_sparkline(): void
    {
        $this->actingAs($this->admin())
            ->get('/plugin/interface-alert-policy-manager/stats')
            ->assertOk()
            ->assertSee('Outages per day')
            ->assertSee('<svg', false);
    }

    public function test_state_labels_carry_an_icon_not_colour_alone(): void
    {
        $this->incident($this->policy(), $this->downPort($this->device()));

        $this->actingAs($this->admin())
            ->get('/plugin/interface-alert-policy-manager/incidents')
            ->assertOk()
            ->assertSee('fa-exclamation-circle'); // the "active" state icon
    }
}
