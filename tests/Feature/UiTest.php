<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\Feature;

use LibreNMS\Plugins\InterfaceAlertPolicyManager\Enums\IncidentState;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Incident;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\PluginVersion;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\IntegrationTestCase;

class UiTest extends IntegrationTestCase
{
    public function test_the_installed_plugin_version_is_visible_in_the_shared_navigation(): void
    {
        $this->actingAs($this->admin())
            ->get('/plugin/interface-alert-policy-manager')
            ->assertOk()
            ->assertSeeText('IAPM '.app(PluginVersion::class)->display());
    }

    public function test_the_plugin_publishes_a_dedicated_librenms_top_navigation_link(): void
    {
        $body = $this->actingAs($this->admin())
            ->get('/plugin/interface-alert-policy-manager')
            ->assertOk()
            ->getContent();

        self::assertStringContainsString('id="iapm-plugin-menu-fallback"', (string) $body);
        self::assertStringContainsString("item.id = 'iapm-top-navigation'", (string) $body);
        self::assertStringContainsString("textContent = 'IAPM'", (string) $body);
    }

    public function test_every_shared_navigation_link_has_an_icon(): void
    {
        $html = $this->actingAs($this->admin())
            ->get('/plugin/interface-alert-policy-manager')
            ->assertOk()
            ->getContent();

        $document = new \DOMDocument;
        @$document->loadHTML((string) $html);
        $xpath = new \DOMXPath($document);
        $links = $xpath->query('//div[contains(concat(" ", normalize-space(@class), " "), " iapm-nav ")]//ul[contains(concat(" ", normalize-space(@class), " "), " nav-pills ")]//a');

        self::assertNotFalse($links);
        self::assertGreaterThan(10, $links->count(), 'Expected the complete shared navigation menu.');
        foreach ($links as $link) {
            $icons = $xpath->query('.//i[contains(concat(" ", normalize-space(@class), " "), " fa ")]', $link);
            self::assertNotFalse($icons);
            self::assertGreaterThan(0, $icons->count(), 'Navigation link "'.trim($link->textContent).'" has no icon.');
        }
    }

    public function test_incidents_are_ordered_urgent_first(): void
    {
        $policy = $this->policy();
        // Names chosen so alphabetical order is the OPPOSITE of urgency order,
        // proving the sort is by urgency, not by name.
        $this->incident($policy, $this->downPort($this->device(), ['ifName' => 'aaa-recovered']), ['state' => IncidentState::Recovered, 'severity' => 'info', 'recovered_at' => now()]);
        $this->incident($policy, $this->downPort($this->device(), ['ifName' => 'zzz-active']), ['state' => IncidentState::Active, 'severity' => 'critical']);

        $this->actingAs($this->admin())
            ->get('/plugin/interface-alert-policy-manager/incidents?state=all')
            ->assertOk()
            ->assertSeeInOrder(['zzz-active', 'aaa-recovered']);
    }

    public function test_the_incidents_list_offers_inline_actions(): void
    {
        $this->incident($this->policy(), $this->downPort($this->device()));

        $this->actingAs($this->admin())
            ->get('/plugin/interface-alert-policy-manager/incidents')
            ->assertOk()
            ->assertSee('incidents/'.Incident::first()->id.'/acknowledge')
            ->assertSee('Auto-refresh');
    }

    /**
     * P0-3's headline symptom was three different tiles sharing one URL. Counts
     * versus rows are covered by KpiTileParityTest; this just pins the weaker
     * property that no two tiles lead to the same place.
     */
    public function test_no_two_overview_tiles_share_a_link(): void
    {
        $html = $this->actingAs($this->admin())
            ->get('/plugin/interface-alert-policy-manager')
            ->assertOk()
            ->getContent();

        preg_match_all('/<a href="([^"]+)"[^>]*data-iapm-tile="([^"]+)"/', (string) $html, $matches, PREG_SET_ORDER);
        $byHref = [];
        foreach ($matches as [, $href, $label]) {
            $byHref[html_entity_decode($href)][] = $label;
        }

        self::assertCount(9, $matches, 'Expected the nine Overview KPI tiles.');
        foreach ($byHref as $href => $labels) {
            self::assertCount(1, $labels, sprintf('Tiles %s all link to %s.', implode(', ', $labels), $href));
        }
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
