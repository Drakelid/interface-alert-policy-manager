<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\Feature;

use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Outage;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\AssignmentMatchCounter;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\IntegrationTestCase;

/** P4-1 to P4-8: polish items that are observable in the rendered output. */
class PolishTest extends IntegrationTestCase
{
    private const BASE = '/plugin/interface-alert-policy-manager';

    /** P4-1: nine tiles wrapped 5-then-4 on a 12-column grid. */
    public function test_the_tile_grids_wrap_evenly_instead_of_using_fixed_columns(): void
    {
        $admin = $this->admin();

        foreach ([self::BASE, self::BASE.'/comparison-report', self::BASE.'/stats'] as $path) {
            $body = (string) $this->actingAs($admin)->get($path)->assertOk()->getContent();
            self::assertStringContainsString('iapm-tile-grid', $body, "$path still uses fixed tile columns.");
        }

        $overview = (string) $this->actingAs($admin)->get(self::BASE)->assertOk()->getContent();
        self::assertStringNotContainsString('col-sm-2">{!!', $overview);
        // All nine tiles live in the one grid.
        preg_match('#<div class="iapm-tile-grid">(.*?)</div>\s*<div class="panel panel-default">#s', $overview, $grid);
        self::assertNotEmpty($grid);
        self::assertSame(9, substr_count($grid[1], 'data-iapm-tile='));
    }

    /** P4-2: the checklist rows were plain text. */
    public function test_setup_checklist_rows_link_to_where_they_are_resolved(): void
    {
        $body = (string) $this->actingAs($this->admin())->get(self::BASE)->assertOk()->getContent();

        $document = new \DOMDocument;
        libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">'.$body);
        libxml_clear_errors();
        $xpath = new \DOMXPath($document);

        $rows = $xpath->query('//*[@data-iapm-scored-step]');
        self::assertGreaterThan(0, $rows->length, 'The scored checklist was not found.');

        foreach ($rows as $row) {
            self::assertGreaterThan(
                0,
                $xpath->query('.//a[@href]', $row)->length,
                sprintf('Checklist row "%s" is plain text with nowhere to go.', trim(mb_substr($row->textContent, 0, 50)))
            );
        }
    }

    /** Even a completed row stays a link, so the checklist doubles as navigation. */
    public function test_completed_checklist_rows_still_link(): void
    {
        // Generating a token completes the first scored step.
        $this->settings->put('ingestion_token', 'a-token');

        $body = (string) $this->actingAs($this->admin())->get(self::BASE)->assertOk()->getContent();

        self::assertMatchesRegularExpression(
            '#fa-check text-success[^>]*></i>\s*<a href="[^"]*settings[^"]*"><strong>Ingestion token generated</strong></a>#',
            $body
        );
    }

    /** P4-3: the checkbox gave no interval and no last-refresh indication. */
    public function test_auto_refresh_states_its_interval_and_is_configurable(): void
    {
        $admin = $this->admin();

        foreach ([self::BASE, self::BASE.'/incidents'] as $path) {
            $body = (string) $this->actingAs($admin)->get($path)->assertOk()->getContent();
            self::assertStringContainsString('data-iapm-refresh-interval', $body, "$path has no interval control.");
            self::assertStringContainsString('iapm-updated', $body, "$path has no last-refresh readout.");
            self::assertStringContainsString('every 30s', $body);
        }
    }

    /** P4-4: character/segment counter, click-to-insert chips, roomier boxes. */
    public function test_message_templates_have_counters_and_insertable_chips(): void
    {
        $body = (string) $this->actingAs($this->admin())->get(self::BASE.'/message-templates')->assertOk()->getContent();

        self::assertStringContainsString('data-iapm-sms-counter', $body);
        self::assertStringContainsString('data-iapm-chip="hostname"', $body);
        // The digest box offers its own, device-level placeholder set.
        self::assertStringContainsString('data-iapm-chip="interface_count"', $body);
        // The trigger box was clipped at 7 rows.
        self::assertStringContainsString('id="iapm-tpl-trigger" rows="10"', $body);
        self::assertStringContainsString('iapm-wrap-code', $body);
    }

    /** P4-5: "Matches 0 interface(s)" cannot distinguish a right regex from a wrong one. */
    public function test_the_match_preview_returns_sample_interfaces(): void
    {
        $device = $this->device(['hostname' => 'sample-sw']);
        $port = $this->downPort($device, ['ifName' => 'xe-7/7/7']);
        $policy = $this->policy();

        $response = $this->actingAs($this->admin())->post(self::BASE.'/assignments/preview', [
            'policy_id' => $policy->id, 'assignment_type' => 'device',
            'assignment_reference' => (string) $device->device_id,
            'match_mode' => 'any', 'priority' => 0, 'enabled' => '1',
        ])->assertOk();

        $response->assertJsonPath('count', 1);
        $response->assertJsonPath('samples.0.port_id', (int) $port->port_id);
        $response->assertJsonPath('samples.0.hostname', 'sample-sw');
        $response->assertJsonPath('samples.0.ifName', 'xe-7/7/7');
    }

    public function test_the_sample_list_is_bounded(): void
    {
        $device = $this->device();
        for ($i = 0; $i < AssignmentMatchCounter::SAMPLE_LIMIT + 5; $i++) {
            $this->downPort($device);
        }

        $response = $this->actingAs($this->admin())->post(self::BASE.'/assignments/preview', [
            'policy_id' => $this->policy()->id, 'assignment_type' => 'device',
            'assignment_reference' => (string) $device->device_id,
            'match_mode' => 'any', 'priority' => 0, 'enabled' => '1',
        ])->assertOk();

        $response->assertJsonPath('count', AssignmentMatchCounter::SAMPLE_LIMIT + 5);
        self::assertCount(AssignmentMatchCounter::SAMPLE_LIMIT, $response->json('samples'));
    }

    public function test_the_assignment_form_can_show_the_samples(): void
    {
        $policy = $this->policy();
        $body = (string) $this->actingAs($this->admin())->get(self::BASE."/policies/{$policy->id}/edit?assignment=new")->assertOk()->getContent();

        self::assertStringContainsString('id="iapm-preview-samples"', $body);
        self::assertStringContainsString('Sample of matched interfaces', $body);
    }

    /** P4-6: most pages had no empty state. */
    public function test_every_list_view_has_an_empty_state(): void
    {
        $admin = $this->admin();
        $expected = [
            '/policies' => 'No policies yet',
            '/destinations' => 'No destinations yet',
            '/interface-matrix' => 'No interfaces match',
            '/delivery-log' => 'No deliveries yet',
            '/audit-log' => 'No audit entries yet',
            '/incidents' => 'No incidents match',
        ];

        foreach ($expected as $path => $title) {
            $body = (string) $this->actingAs($admin)->get(self::BASE.$path)->assertOk()->getContent();
            self::assertStringContainsString($title, $body, "$path has no empty state.");
        }
    }

    /** P4-7: the chart drew barely-visible dashes with no axes. */
    public function test_the_outages_chart_says_so_when_there_is_no_data(): void
    {
        $body = (string) $this->actingAs($this->admin())->get(self::BASE.'/stats')->assertOk()->getContent();

        self::assertStringContainsString('No outages recorded in this period', $body);
        self::assertStringNotContainsString('<svg class="iapm-chart"', $body);
    }

    public function test_the_outages_chart_has_axes_and_labels_when_there_is_data(): void
    {
        Outage::create([
            'device_id' => $this->device()->device_id, 'port_id' => $this->downPort($this->device())->port_id,
            'policy_id' => $this->policy()->id, 'severity' => 'critical',
            'started_at' => now()->subDays(2), 'recovered_at' => now()->subDays(2)->addHour(),
            'duration_seconds' => 3600, 'notification_count' => 1,
        ]);

        $body = (string) $this->actingAs($this->admin())->get(self::BASE.'/stats')->assertOk()->getContent();

        self::assertStringContainsString('<svg class="iapm-chart"', $body);
        // Axis gridlines, value labels and a readable summary.
        self::assertStringContainsString('<line', $body);
        self::assertStringContainsString('text-anchor="end"', $body, 'No value-axis labels.');
        self::assertStringContainsString('peak 1 on one day', $body);
    }

    /** P4-8: several pages had no explanatory copy at all. */
    public function test_sparse_pages_now_explain_themselves(): void
    {
        $admin = $this->admin();
        $expected = [
            '/policy-test' => 'without sending anything',
            '/interface-matrix' => 'which policy would apply to it',
            '/delivery-log' => 'Every notification attempt',
            '/audit-log' => 'who changed what',
            '/stats' => 'MTTA',
            '/template-preview' => 'Nothing is delivered',
        ];

        foreach ($expected as $path => $copy) {
            $body = (string) $this->actingAs($admin)->get(self::BASE.$path)->assertOk()->getContent();
            self::assertStringContainsString($copy, $body, "$path has no context help.");
        }
    }
}
