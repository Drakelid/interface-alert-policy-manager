<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\Feature;

use App\Models\User;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Enums\IncidentState;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\DeliveryLog;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\SuppressionService;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\IntegrationTestCase;

/**
 * P0-3: "Active critical", "Active warning" and "Awaiting escalation" all
 * linked to the same /incidents?state=active, and three further tiles pointed
 * at populations they had never counted.
 *
 * Rather than asserting nine hard-coded URLs, this discovers the tiles from the
 * rendered Overview and checks the invariant that actually matters: the number
 * printed on a tile equals the number of rows behind the link it opens.
 */
class KpiTileParityTest extends IntegrationTestCase
{
    private const BASE = '/plugin/interface-alert-policy-manager';

    public function test_every_kpi_tile_count_equals_the_rows_behind_its_link(): void
    {
        $admin = $this->admin();
        $this->seedDistinctPopulations($admin);

        $tiles = $this->tilesOn($admin);
        self::assertCount(9, $tiles, 'Expected the nine Overview KPI tiles.');

        foreach ($tiles as $label => $tile) {
            $total = $this->totalBehind($admin, $tile['href']);
            self::assertSame(
                $tile['value'],
                $total,
                sprintf('Tile "%s" shows %d but %s lists %d row(s).', $label, $tile['value'], $tile['href'], $total)
            );
        }
    }

    /**
     * Parity is trivially satisfiable if every tile reads zero, and it is also
     * satisfiable by accident if several tiles happen to share a value. Assert
     * the fixture actually exercises distinct, non-zero populations.
     */
    public function test_the_fixture_gives_the_tiles_distinct_non_zero_values(): void
    {
        $admin = $this->admin();
        $this->seedDistinctPopulations($admin);

        $values = array_map(fn ($t) => $t['value'], $this->tilesOn($admin));

        self::assertNotContains(0, $values, 'A zero tile cannot distinguish a correct link from a wrong one: '.json_encode($values));
        self::assertGreaterThanOrEqual(6, count(array_unique($values)), 'Tiles should not all share a value: '.json_encode($values));
    }

    /**
     * Each tile links to a filter; if a filter were silently ignored, the
     * destination would list everything and parity would fail. Assert directly
     * that the severity split is real.
     */
    public function test_the_severity_filter_narrows_the_incident_list(): void
    {
        $admin = $this->admin();
        $this->seedDistinctPopulations($admin);

        $critical = $this->totalBehind($admin, self::BASE.'/incidents?state=active&severity=critical');
        $warning = $this->totalBehind($admin, self::BASE.'/incidents?state=active&severity=warning');
        $both = $this->totalBehind($admin, self::BASE.'/incidents?state=active');

        self::assertSame($both, $critical + $warning);
        self::assertNotSame($critical, $warning);
    }

    /** The suppression-reason list offered in the UI must cover what the engine emits. */
    public function test_every_reason_the_engine_can_emit_is_offered_as_a_filter(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2).'/src/Services/SuppressionService.php');
        preg_match('/public function reason\(.*?\n    \}/s', (string) $source, $m);
        preg_match_all("/=> '([a-z_]+)'/", $m[0] ?? '', $found);

        foreach ($found[1] ?? [] as $reason) {
            self::assertContains($reason, SuppressionService::REASONS, "reason() can return '$reason' but the incident filter does not offer it.");
        }
    }

    /**
     * Deliberately unequal populations so a mis-pointed link cannot pass.
     * Counts: 3 active critical, 2 active warning, 4 pending, 5 acknowledged,
     * 7 suppressed (of which 6 no_policy), 8 recovered in 24h (plus one older
     * that must be excluded), 3 escalation-pending, 9 failed deliveries in 24h
     * (plus one older and one sent that must both be excluded).
     */
    private function seedDistinctPopulations(User $admin): void
    {
        $device = $this->device();
        $escalationPolicy = $this->policy(['name' => 'With escalation']);
        $escalationPolicy->actions()->create(['destination_id' => $this->smsDestination()->id, 'phase' => 'escalation', 'delay_seconds' => 600, 'enabled' => true, 'sort_order' => 0]);
        $plainPolicy = $this->policy(['name' => 'No escalation']);

        // 3 active critical, all on the escalating policy -> also the 3 awaiting escalation.
        $this->makeIncidents(3, $escalationPolicy, $device, ['state' => IncidentState::Active, 'severity' => 'critical']);
        $this->makeIncidents(2, $plainPolicy, $device, ['state' => IncidentState::Active, 'severity' => 'warning']);
        $this->makeIncidents(4, $plainPolicy, $device, ['state' => IncidentState::Pending, 'severity' => 'critical']);
        $this->makeIncidents(5, $plainPolicy, $device, ['state' => IncidentState::Acknowledged, 'severity' => 'critical']);
        $this->makeIncidents(6, $plainPolicy, $device, ['state' => IncidentState::Suppressed, 'severity' => 'warning', 'suppression_reason' => 'no_policy']);
        $this->makeIncidents(1, $plainPolicy, $device, ['state' => IncidentState::Suppressed, 'severity' => 'warning', 'suppression_reason' => 'device_down']);
        $this->makeIncidents(8, $plainPolicy, $device, ['state' => IncidentState::Recovered, 'severity' => 'info', 'recovered_at' => now()->subHours(3)]);
        $this->makeIncidents(1, $plainPolicy, $device, ['state' => IncidentState::Recovered, 'severity' => 'info', 'recovered_at' => now()->subDays(4)]);

        $destination = $this->smsDestination();
        for ($i = 0; $i < 5; $i++) {
            DeliveryLog::create(['destination_id' => $destination->id, 'phase' => 'trigger', 'status' => 'failed', 'created_at' => now()->subHours(2)]);
        }
        for ($i = 0; $i < 4; $i++) {
            DeliveryLog::create(['destination_id' => $destination->id, 'phase' => 'trigger', 'status' => 'failed_configuration', 'created_at' => now()->subHours(2)]);
        }
        DeliveryLog::create(['destination_id' => $destination->id, 'phase' => 'trigger', 'status' => 'failed', 'created_at' => now()->subDays(3)]);
        DeliveryLog::create(['destination_id' => $destination->id, 'phase' => 'trigger', 'status' => 'sent', 'created_at' => now()->subHour()]);
    }

    private function makeIncidents(int $count, $policy, $device, array $attributes): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->incident($policy, $this->downPort($device), $attributes);
        }
    }

    /**
     * @return array<string, array{value:int, href:string}>
     */
    private function tilesOn(User $admin): array
    {
        $html = $this->actingAs($admin)->get(self::BASE)->assertOk()->getContent();
        $document = new \DOMDocument;
        libxml_use_internal_errors(true);
        $document->loadHTML((string) $html);
        libxml_clear_errors();

        $tiles = [];
        foreach ((new \DOMXPath($document))->query('//a[@data-iapm-tile]') as $node) {
            $tiles[$node->getAttribute('data-iapm-tile')] = [
                'value' => (int) $node->getAttribute('data-iapm-value'),
                'href' => $node->getAttribute('href'),
            ];
        }

        return $tiles;
    }

    /** Reads the machine-readable total from the destination list view. */
    private function totalBehind(User $admin, string $href): int
    {
        $html = $this->actingAs($admin)->get($href)->assertOk()->getContent();
        self::assertMatchesRegularExpression('/data-iapm-total="\d+"/', (string) $html, "No result total rendered at $href.");
        preg_match('/data-iapm-total="(\d+)"/', (string) $html, $m);

        return (int) $m[1];
    }
}
