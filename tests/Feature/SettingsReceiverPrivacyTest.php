<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\Feature;

use App\Models\User;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\IntegrationTestCase;
use Spatie\Permission\Models\Permission;

class SettingsReceiverPrivacyTest extends IntegrationTestCase
{
    public function test_viewers_and_policy_managers_cannot_read_the_global_sms_receiver(): void
    {
        $receiver = '+4798765432';
        $this->settings->put('sms_default_receiver', $receiver);
        foreach ([['view iapm'], ['view iapm', 'manage iapm policies']] as $abilities) {
            $user = $this->userWithAbilities($abilities);
            $this->actingAs($user)->get('/plugin/interface-alert-policy-manager/settings')
                ->assertOk()->assertDontSee($receiver)
                ->assertViewHas('values', fn (array $values) => $values['sms_default_receiver'] === null);
        }
    }

    public function test_a_settings_manager_can_read_the_global_receiver(): void
    {
        $receiver = '+4798765432';
        $this->settings->put('sms_default_receiver', $receiver);

        $this->actingAs($this->userWithAbilities(['view iapm', 'manage iapm settings']))
            ->get('/plugin/interface-alert-policy-manager/settings')->assertOk()->assertSee($receiver);
    }

    private function userWithAbilities(array $abilities): User
    {
        foreach ($abilities as $ability) {
            Permission::findOrCreate($ability, 'web');
        }
        $user = User::factory()->create(['enabled' => true]);
        $user->givePermissionTo($abilities);

        return $user;
    }
}
