<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\Feature;

use LibreNMS\Plugins\InterfaceAlertPolicyManager\Enums\IncidentState;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Http\Controllers\IncidentController;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\AuditLog;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\DeliveryLog;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\IntegrationTestCase;

/**
 * P1-6: Incidents, Delivery Log, Audit Log and the Interface Matrix had no
 * visible pagination, no per-page control, no total and no column sorting.
 */
class ListPaginationAndSortingTest extends IntegrationTestCase
{
    private const BASE = '/plugin/interface-alert-policy-manager';

    /** @return list<string> */
    private function listPaths(): array
    {
        return [
            self::BASE.'/incidents?state=all',
            self::BASE.'/delivery-log',
            self::BASE.'/audit-log',
            self::BASE.'/interface-matrix',
        ];
    }

    public function test_every_list_view_reports_a_total_and_offers_a_per_page_control(): void
    {
        $this->seedRows();
        $admin = $this->admin();

        foreach ($this->listPaths() as $path) {
            $body = (string) $this->actingAs($admin)->get($path)->assertOk()->getContent();
            self::assertMatchesRegularExpression('/data-iapm-total="\d+"/', $body, "$path reports no total.");
            self::assertStringContainsString('Rows per page', $body, "$path has no per-page control.");
            self::assertStringContainsString('Showing', $body, "$path does not say which rows it is showing.");
        }
    }

    public function test_the_per_page_control_changes_the_page_size(): void
    {
        $this->seedIncidents(8);

        $body = (string) $this->actingAs($this->admin())
            ->get(self::BASE.'/incidents?state=all&per_page=25')
            ->assertOk()
            ->getContent();

        self::assertStringContainsString('data-iapm-total="8"', $body);
        self::assertStringContainsString('Showing <strong>1</strong>&ndash;<strong>8</strong>', $body);
    }

    /** An unknown page size must not become the page size. */
    public function test_an_unsupported_per_page_falls_back_to_the_default(): void
    {
        $this->seedIncidents(3);

        foreach (['999999', '0', '-1', 'abc'] as $bogus) {
            $this->actingAs($this->admin())
                ->get(self::BASE.'/incidents?state=all&per_page='.$bogus)
                ->assertOk()
                ->assertSee('data-iapm-total="3"', false);
        }
    }

    public function test_pagination_splits_a_long_list_and_keeps_the_total(): void
    {
        $this->seedIncidents(30);

        $page1 = (string) $this->actingAs($this->admin())->get(self::BASE.'/incidents?state=all&per_page=25')->assertOk()->getContent();
        self::assertStringContainsString('data-iapm-total="30"', $page1);
        self::assertStringContainsString('Showing <strong>1</strong>&ndash;<strong>25</strong>', $page1);

        $page2 = (string) $this->actingAs($this->admin())->get(self::BASE.'/incidents?state=all&per_page=25&page=2')->assertOk()->getContent();
        self::assertStringContainsString('Showing <strong>26</strong>&ndash;<strong>30</strong>', $page2);
    }

    public function test_sorting_reorders_the_incidents_list_in_both_directions(): void
    {
        $this->seedIncidents(3);

        $ascending = $this->incidentIdsOn(self::BASE.'/incidents?state=all&sort=id&direction=asc');
        $descending = $this->incidentIdsOn(self::BASE.'/incidents?state=all&sort=id&direction=desc');

        self::assertSame($ascending, array_reverse($descending));
        self::assertSame($ascending, collect($ascending)->sort()->values()->all(), 'Ascending sort did not order by id.');
    }

    /** Sorting must not silently discard the filter the operator arrived with. */
    public function test_sorting_preserves_the_active_filter(): void
    {
        $policy = $this->policy();
        $device = $this->device();
        $this->incident($policy, $this->downPort($device), ['state' => IncidentState::Active, 'severity' => 'critical']);
        $this->incident($policy, $this->downPort($device), ['state' => IncidentState::Active, 'severity' => 'warning']);

        $body = (string) $this->actingAs($this->admin())
            ->get(self::BASE.'/incidents?state=active&severity=critical&sort=id&direction=desc')
            ->assertOk()
            ->getContent();

        self::assertStringContainsString('data-iapm-total="1"', $body);
        // Every sort link must carry the filter forward too.
        self::assertStringContainsString('severity=critical', $body);
    }

    /** A crafted sort column must never reach the query builder. */
    public function test_an_unknown_sort_column_is_ignored(): void
    {
        $this->seedIncidents(2);

        foreach (['id); drop table iapm_incidents;--', 'password', 'iapm_incidents.id'] as $bogus) {
            $this->actingAs($this->admin())
                ->get(self::BASE.'/incidents?state=all&sort='.urlencode($bogus))
                ->assertOk()
                ->assertSee('data-iapm-total="2"', false);
        }
    }

    public function test_the_matrix_sorts_by_interface_name(): void
    {
        $device = $this->device();
        $this->downPort($device, ['ifName' => 'zzz-last']);
        $this->downPort($device, ['ifName' => 'aaa-first']);

        $body = (string) $this->actingAs($this->admin())
            ->get(self::BASE.'/interface-matrix?sort=ifName&direction=asc')
            ->assertOk()
            ->getContent();

        self::assertLessThan(strpos($body, 'zzz-last'), strpos($body, 'aaa-first'));
    }

    /** Hostname sorting needs a join; make sure it works rather than 500s. */
    public function test_the_matrix_sorts_by_device_hostname(): void
    {
        $this->downPort($this->device(['hostname' => 'zzz-switch']));
        $this->downPort($this->device(['hostname' => 'aaa-switch']));

        $body = (string) $this->actingAs($this->admin())
            ->get(self::BASE.'/interface-matrix?sort=hostname&direction=asc')
            ->assertOk()
            ->getContent();

        self::assertLessThan(strpos($body, 'zzz-switch'), strpos($body, 'aaa-switch'));
    }

    public function test_the_logs_sort_by_their_columns(): void
    {
        $destination = $this->smsDestination();
        DeliveryLog::create(['destination_id' => $destination->id, 'phase' => 'trigger', 'status' => 'sent', 'created_at' => now()->subHour()]);
        DeliveryLog::create(['destination_id' => $destination->id, 'phase' => 'recovery', 'status' => 'failed', 'created_at' => now()]);
        AuditLog::create(['user_id' => null, 'action' => 'zeta', 'object_type' => 'policy', 'source_ip' => '127.0.0.1', 'created_at' => now()]);
        AuditLog::create(['user_id' => null, 'action' => 'alpha', 'object_type' => 'policy', 'source_ip' => '127.0.0.1', 'created_at' => now()->subHour()]);

        // Only the table body: the filter bar lists every phase and action in its
        // own fixed order, which would otherwise decide the assertion.
        $deliveries = $this->tableBody($this->actingAs($this->admin())->get(self::BASE.'/delivery-log?sort=phase&direction=asc')->assertOk()->getContent());
        self::assertLessThan(strpos($deliveries, 'trigger'), strpos($deliveries, 'recovery'));

        $audits = $this->tableBody($this->actingAs($this->admin())->get(self::BASE.'/audit-log?sort=action&direction=asc')->assertOk()->getContent());
        self::assertLessThan(strpos($audits, 'zeta'), strpos($audits, 'alpha'));
    }

    private function tableBody(?string $html): string
    {
        preg_match('#<tbody>(.*?)</tbody>#s', (string) $html, $m);
        self::assertNotEmpty($m, 'No table body found in the response.');

        return $m[1];
    }

    /** Every whitelisted column must actually be usable, not just declared. */
    public function test_all_advertised_sort_columns_work(): void
    {
        $this->seedRows();
        $admin = $this->admin();

        foreach ($this->listPaths() as $path) {
            $body = (string) $this->actingAs($admin)->get($path)->assertOk()->getContent();
            // Separators are HTML-escaped in href attributes, so match &amp; too.
            preg_match_all('/(?:[?&]|&amp;)sort=([a-zA-Z_]+)&amp;direction=(asc|desc)/', $body, $matches, PREG_SET_ORDER);
            self::assertNotEmpty($matches, "$path advertises no sortable columns.");

            foreach ($matches as [, $column, $direction]) {
                $separator = str_contains($path, '?') ? '&' : '?';
                $this->actingAs($admin)
                    ->get($path.$separator."sort=$column&direction=$direction")
                    ->assertOk();
            }
        }
    }

    public function test_the_per_page_options_are_the_ones_offered(): void
    {
        // Read through a consuming class: a trait constant is not accessible
        // via the trait name itself.
        self::assertSame([25, 50, 100, 250], IncidentController::PER_PAGE_OPTIONS);
    }

    private function seedRows(): void
    {
        $this->seedIncidents(3);
        $destination = $this->smsDestination();
        DeliveryLog::create(['destination_id' => $destination->id, 'phase' => 'trigger', 'status' => 'sent', 'created_at' => now()]);
        AuditLog::create(['user_id' => null, 'action' => 'updated', 'object_type' => 'policy', 'object_id' => 1, 'source_ip' => '127.0.0.1', 'created_at' => now()]);
    }

    private function seedIncidents(int $count): void
    {
        $policy = $this->policy();
        $device = $this->device();
        for ($i = 0; $i < $count; $i++) {
            $this->incident($policy, $this->downPort($device));
        }
    }

    /** @return list<int> */
    private function incidentIdsOn(string $path): array
    {
        $body = (string) $this->actingAs($this->admin())->get($path)->assertOk()->getContent();
        preg_match_all('#/incidents/(\d+)"#', $body, $matches);

        return array_values(array_unique(array_map('intval', $matches[1])));
    }
}
