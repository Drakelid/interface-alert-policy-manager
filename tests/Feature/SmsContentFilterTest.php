<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Http;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\AuditLog;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Destination;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\IntegrationTestCase;
use Spatie\Permission\Models\Permission;

class SmsContentFilterTest extends IntegrationTestCase
{
    private const PATH = '/plugin/interface-alert-policy-manager/sms-content-filters';

    public function test_administrator_can_open_and_save_filters(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->get(self::PATH)
            ->assertOk()
            ->assertSee('SMS content filters')
            ->assertSee('Bundle-Ether*');

        $this->actingAs($admin)->put(self::PATH, [
            'phrases' => "Noise\nBundle*",
            'symbols' => "#\n@@",
        ])->assertRedirect();

        self::assertSame(['Noise', 'Bundle*'], $this->settings->get('sms_filter_phrases'));
        self::assertSame(['#', '@@'], $this->settings->get('sms_filter_symbols'));
        self::assertTrue(AuditLog::where('object_type', 'sms_content_filters')->where('action', 'updated')->exists());
    }

    public function test_user_without_manage_settings_cannot_view_save_or_preview_filters(): void
    {
        Permission::findOrCreate('view iapm', 'web');
        $user = User::factory()->create(['enabled' => true]);
        $user->givePermissionTo('view iapm');

        $this->actingAs($user)->get(self::PATH)->assertForbidden();
        $this->actingAs($user)->put(self::PATH, ['phrases' => '', 'symbols' => ''])->assertForbidden();
        $this->actingAs($user)->post(self::PATH.'/preview', ['phrases' => '', 'symbols' => '', 'message' => 'test'])->assertForbidden();
    }

    public function test_preview_uses_unsaved_form_values(): void
    {
        $this->actingAs($this->admin())->postJson(self::PATH.'/preview', [
            'phrases' => 'Secret*',
            'symbols' => '#',
            'message' => '### Secret123 to Customer A',
        ])->assertOk()->assertJson(['filtered' => 'Customer A']);
    }

    public function test_sms_filters_apply_to_sms_but_not_generic_webhooks(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        $this->settings->putMany([
            'sms_filter_phrases' => ['Noise'],
            'sms_filter_symbols' => ['#'],
        ]);
        $policy = $this->policy();
        $message = '### Noise useful details';
        $sms = $this->smsDestination();
        $webhook = Destination::create([
            'name' => 'Unfiltered webhook',
            'type' => 'generic_webhook',
            'enabled' => true,
            'configuration_encrypted' => [
                'url' => 'http://127.0.0.1/hook',
                'default_receiver' => 'noc',
                'allow_private_networks' => true,
            ],
        ]);
        $this->triggerAction($policy, $sms, ['message_template' => $message]);
        $this->triggerAction($policy, $webhook, ['message_template' => $message]);
        $this->incident($policy, $this->downPort($this->device()));

        $this->artisan('iapm:process-actions');

        Http::assertSent(fn ($request) => $request['message'] === 'useful details');
        Http::assertSent(fn ($request) => $request['message'] === $message);
    }
}
