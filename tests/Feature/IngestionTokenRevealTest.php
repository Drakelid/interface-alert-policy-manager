<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\Feature;

use App\Models\User;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\AuditLog;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\IntegrationTestCase;
use Spatie\Permission\Models\Permission;

/**
 * P0-5: the Setup Helper instructed operators to send
 * `Authorization: Bearer <your IAPM ingestion token>`, but the token was never
 * displayed. The only way to obtain a working value was to rotate it, which is
 * destructive on a live install.
 */
class IngestionTokenRevealTest extends IntegrationTestCase
{
    private const BASE = '/plugin/interface-alert-policy-manager';

    public function test_an_administrator_can_read_the_current_token_without_rotating_it(): void
    {
        $this->settings->put('ingestion_token', 'live-token-value');

        $this->actingAs($this->admin())
            ->getJson(self::BASE.'/settings/ingestion-token')
            ->assertOk()
            ->assertExactJson(['token' => 'live-token-value']);

        // The whole point: reading it must not change it.
        self::assertSame('live-token-value', $this->settings->get('ingestion_token'));
        self::assertNull($this->settings->get('previous_ingestion_token'));
    }

    public function test_revealing_the_token_is_audited_without_recording_its_value(): void
    {
        $this->settings->put('ingestion_token', 'live-token-value');

        $this->actingAs($this->admin())->getJson(self::BASE.'/settings/ingestion-token')->assertOk();

        $entry = AuditLog::where('action', 'revealed_token')->firstOrFail();
        self::assertStringNotContainsString('live-token-value', json_encode($entry->toArray()));
    }

    public function test_a_viewer_without_settings_permission_cannot_read_the_token(): void
    {
        $this->settings->put('ingestion_token', 'live-token-value');

        $this->actingAs($this->viewer())
            ->getJson(self::BASE.'/settings/ingestion-token')
            ->assertForbidden();
    }

    /**
     * The token must not be embedded in pages a plain viewer can open; that is
     * why it is fetched from its own endpoint rather than rendered inline.
     */
    public function test_the_token_never_appears_in_page_source(): void
    {
        $this->settings->put('ingestion_token', 'live-token-value');

        foreach ([$this->admin(), $this->viewer()] as $user) {
            foreach ([self::BASE.'/settings', self::BASE.'/setup-helper'] as $path) {
                $body = (string) $this->actingAs($user)->get($path)->assertOk()->getContent();
                self::assertStringNotContainsString('live-token-value', $body, "$path leaked the ingestion token into its HTML.");
            }
        }
    }

    public function test_the_settings_page_offers_reveal_and_copy_controls(): void
    {
        $this->settings->put('ingestion_token', 'live-token-value');

        $body = (string) $this->actingAs($this->admin())->get(self::BASE.'/settings')->assertOk()->getContent();

        self::assertStringContainsString('data-iapm-reveal-token', $body);
        self::assertStringContainsString('data-copy="#iapm-token-value"', $body);
    }

    /**
     * Step 3's header block must be paste-ready: same shape as before, but with
     * a slot the reveal control fills in rather than prose telling the operator
     * to substitute a value they cannot see.
     */
    public function test_the_setup_helper_header_block_has_a_token_slot(): void
    {
        $this->settings->put('ingestion_token', 'live-token-value');

        $body = (string) $this->actingAs($this->admin())->get(self::BASE.'/setup-helper')->assertOk()->getContent();

        self::assertStringContainsString('id="iapm-transport-headers"', $body);
        self::assertStringContainsString('Authorization: Bearer __TOKEN__', $body);
        self::assertStringContainsString('data-copy="#iapm-transport-headers"', $body);
    }

    public function test_reveal_reports_not_found_before_a_token_exists(): void
    {
        $this->settings->put('ingestion_token', null);

        $this->actingAs($this->admin())
            ->getJson(self::BASE.'/settings/ingestion-token')
            ->assertNotFound();
    }

    /** Rotation keeps its 15-minute overlap; the reveal control does not replace it. */
    public function test_rotation_still_grants_the_previous_token_a_grace_window(): void
    {
        $this->settings->put('ingestion_token', 'old-token-value');

        $this->actingAs($this->admin())
            ->post(self::BASE.'/settings/rotate-token')
            ->assertRedirect();

        self::assertSame('old-token-value', $this->settings->get('previous_ingestion_token'));
        self::assertNotSame('old-token-value', $this->settings->get('ingestion_token'));
        self::assertNotNull($this->settings->get('previous_ingestion_token_expires_at'));
    }

    /** A user who can view the plugin but not manage its settings. */
    private function viewer(): User
    {
        foreach (['view iapm', 'view iapm audit logs'] as $ability) {
            Permission::findOrCreate($ability, 'web');
        }
        $user = User::factory()->create(['enabled' => true]);
        $user->givePermissionTo('view iapm');

        return $user;
    }
}
