<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\Feature;

use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\ReadinessService;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\IntegrationTestCase;

/**
 * P0-4: the banner read "6 / 6 steps done" above what looked like a
 * seven-item checklist. The seventh row is an informational check that is
 * deliberately not scored, but it sat inside the same list.
 */
class SetupChecklistTest extends IntegrationTestCase
{
    public function test_the_score_denominator_equals_the_number_of_scored_rows(): void
    {
        $document = $this->overviewDocument();
        $xpath = new \DOMXPath($document);

        $score = $xpath->query('//*[@data-iapm-setup-score]')->item(0);
        self::assertNotNull($score, 'The setup banner did not render a score.');

        [$done, $total] = array_map('intval', explode('/', $score->getAttribute('data-iapm-setup-score')));
        $scoredRows = $xpath->query('//*[@data-iapm-scored-step]')->length;

        self::assertSame($total, $scoredRows, "The banner claims $total steps but $scoredRows scored rows are rendered.");
        self::assertLessThanOrEqual($total, $done);
    }

    public function test_informational_checks_render_outside_the_scored_list(): void
    {
        $xpath = new \DOMXPath($this->overviewDocument());

        self::assertGreaterThan(0, $xpath->query('//*[@data-iapm-info-step]')->length, 'Expected at least one informational check.');
        // The defect was an informational row sitting inside the scored list.
        self::assertSame(0, $xpath->query('//*[@data-iapm-scored-steps]//*[@data-iapm-info-step]')->length);
        self::assertStringContainsString('not counted in the', $this->overviewHtml());
    }

    /**
     * The numbers come from the same collection the checklist iterates, so a
     * new check cannot desynchronise them. Pin that by comparing against the
     * service rather than a hard-coded 6.
     */
    public function test_the_denominator_tracks_the_readiness_service(): void
    {
        $expected = collect(app(ReadinessService::class)->checks())->where('group', 'setup')->count();

        self::assertStringContainsString("$expected required steps done", $this->overviewHtml());
    }

    public function test_the_alert_source_check_is_the_informational_one(): void
    {
        $informational = collect(app(ReadinessService::class)->checks())->where('group', 'info')->pluck('key')->all();

        self::assertSame(['alert_source'], $informational);
    }

    private function overviewHtml(): string
    {
        return (string) $this->actingAs($this->admin())
            ->get('/plugin/interface-alert-policy-manager')
            ->assertOk()
            ->getContent();
    }

    private function overviewDocument(): \DOMDocument
    {
        $document = new \DOMDocument;
        libxml_use_internal_errors(true);
        $document->loadHTML($this->overviewHtml());
        libxml_clear_errors();

        return $document;
    }
}
