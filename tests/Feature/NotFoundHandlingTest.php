<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\Feature;

use LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\IntegrationTestCase;

/**
 * P0-2: /statistics dead-ended on an unstyled 404 with no navigation.
 */
class NotFoundHandlingTest extends IntegrationTestCase
{
    private const BASE = '/plugin/interface-alert-policy-manager';

    public function test_statistics_redirects_to_the_stats_page(): void
    {
        $this->actingAs($this->admin())
            ->get(self::BASE.'/statistics')
            ->assertRedirect(route('iapm.stats'));
    }

    public function test_the_statistics_alias_preserves_the_query_string(): void
    {
        $this->actingAs($this->admin())
            ->get(self::BASE.'/statistics?days=7')
            ->assertRedirect(route('iapm.stats', ['days' => 7]));
    }

    public function test_an_unknown_plugin_path_renders_inside_the_plugin_layout(): void
    {
        $response = $this->actingAs($this->admin())->get(self::BASE.'/no-such-page');

        $response->assertNotFound();
        $body = $response->getContent();
        self::assertStringContainsString('Page not found', $body);
        // The whole point of the fix: navigation must be reachable from the 404.
        self::assertStringContainsString(route('iapm.overview'), $body);
        self::assertStringContainsString(route('iapm.incidents.index'), $body);
        self::assertStringContainsString('/no-such-page', $body);
    }

    public function test_a_missing_model_also_renders_the_branded_page(): void
    {
        $response = $this->actingAs($this->admin())->get(self::BASE.'/policies/987654/edit');

        $response->assertNotFound();
        self::assertStringContainsString('Page not found', $response->getContent());
        self::assertStringContainsString(route('iapm.overview'), $response->getContent());
    }

    /**
     * The ingestion endpoint shares the route prefix. LibreNMS posts to it as a
     * machine client, so a miss there must stay machine-readable rather than
     * being handed the branded HTML page. (The status itself is LibreNMS's to
     * decide; what matters here is that we do not intercept it.)
     */
    public function test_the_json_ingestion_prefix_is_never_given_the_html_page(): void
    {
        $response = $this->postJson(self::BASE.'/api/v1/nope', [], ['Authorization' => 'Bearer test-ingestion-token']);

        $body = (string) $response->getContent();
        self::assertStringNotContainsString('Page not found', $body);
        self::assertStringNotContainsString('<html', $body);
    }

    public function test_an_anonymous_request_is_not_shown_the_plugin_404(): void
    {
        $response = $this->get(self::BASE.'/no-such-page');

        self::assertStringNotContainsString('Page not found', (string) $response->getContent());
    }
}
