<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\Feature;

use LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\IntegrationTestCase;

class RouteSmokeTest extends IntegrationTestCase
{
    private const BASE = '/plugin/interface-alert-policy-manager';

    /**
     * Control-flow directives that must never survive into a response body.
     * Kept in sync with BladeDirectiveSyntaxTest, which guards the source.
     */
    private const LEAKED_DIRECTIVES = [
        'if', 'else', 'elseif', 'endif', 'unless', 'endunless',
        'foreach', 'endforeach', 'forelse', 'empty', 'endforelse',
        'for', 'endfor', 'while', 'endwhile',
        'switch', 'case', 'endswitch', 'isset', 'endisset',
        'php', 'endphp', 'section', 'endsection', 'yield', 'extends',
        'include', 'csrf', 'method', 'error', 'enderror', 'inject',
    ];

    public function test_every_user_facing_page_renders_for_an_administrator(): void
    {
        $admin = $this->admin();

        foreach ($this->paths() as $path) {
            $this->actingAs($admin)->get($path)->assertOk();
        }
    }

    /**
     * P0-1: a malformed Blade conditional emitted the literal text `@else` into
     * the Policy Test page and rendered both branches at once. Assert on the
     * rendered body of every route, not just its status code.
     */
    public function test_no_route_emits_an_unescaped_blade_directive(): void
    {
        $admin = $this->admin();
        $pattern = '/@('.implode('|', self::LEAKED_DIRECTIVES).')\b/i';

        foreach ($this->paths() as $path) {
            $body = $this->actingAs($admin)->get($path)->assertOk()->getContent();
            self::assertDoesNotMatchRegularExpression($pattern, $this->withoutLiteralBladeBlocks($body), "Route $path leaked an uncompiled Blade directive into its response body.");
        }
    }

    /**
     * The Setup Helper deliberately shows the LibreNMS alert template — Blade
     * source the operator copies elsewhere. Those elements opt out with
     * `data-literal-blade`; everything else must be fully compiled.
     */
    private function withoutLiteralBladeBlocks(string $html): string
    {
        $stripped = preg_replace('#<(textarea|pre|code)\b[^>]*\bdata-literal-blade\b[^>]*>.*?</\1>#si', '', $html);
        self::assertIsString($stripped, 'Failed to strip opt-out blocks.');

        return $stripped;
    }

    /**
     * The exact regression: a resolvable receiver must render the receiver
     * branch alone — no literal `@else`, and no contradictory "no receiver"
     * badge sitting next to a successfully resolved address.
     */
    public function test_policy_test_renders_exactly_one_receiver_branch(): void
    {
        $admin = $this->admin();
        $policy = $this->defaultPolicy();
        $destination = $this->smsDestination(['default_receiver' => 'noc@example.test']);
        $this->triggerAction($policy, $destination);
        $port = $this->downPort($this->device());

        $body = $this->actingAs($admin)
            ->get('/plugin/interface-alert-policy-manager/policy-test?port_id='.$port->port_id)
            ->assertOk()
            ->getContent();

        self::assertStringContainsString('noc@example.test', $body, 'The resolved receiver should be shown.');
        self::assertStringNotContainsString('@else', $body, 'The literal Blade directive leaked into the page.');
        self::assertStringNotContainsString('no receiver', $body, 'The negative branch rendered alongside a resolved receiver.');
    }

    /**
     * The negative branch in isolation: an action whose receiver cannot be
     * resolved shows the badge and nothing else.
     */
    public function test_policy_test_renders_the_no_receiver_branch_alone(): void
    {
        $admin = $this->admin();
        $policy = $this->defaultPolicy();
        $destination = $this->smsDestination(['default_receiver' => '']);
        $this->triggerAction($policy, $destination);
        $port = $this->downPort($this->device());

        $body = $this->actingAs($admin)
            ->get('/plugin/interface-alert-policy-manager/policy-test?port_id='.$port->port_id)
            ->assertOk()
            ->getContent();

        self::assertStringContainsString('no receiver', $body);
        self::assertStringNotContainsString('@else', $body);
    }

    public function test_the_retired_schedules_pages_are_not_routable_or_linked(): void
    {
        $admin = $this->admin();

        foreach (['/schedules', '/schedules/create', '/schedules/123/edit'] as $path) {
            $this->actingAs($admin)->get(self::BASE.$path)->assertNotFound();
        }

        $body = (string) $this->actingAs($admin)->get(self::BASE)->assertOk()->getContent();
        self::assertStringNotContainsString('iapm.schedules', $body);
        self::assertStringNotContainsString('>Schedules<', $body);
    }

    public function test_assignment_management_lives_on_the_policy_page(): void
    {
        $admin = $this->admin();
        $policy = $this->defaultPolicy();
        $assignment = $policy->assignments()->firstOrFail();

        $this->actingAs($admin)->get(self::BASE.'/assignments')->assertRedirect(route('iapm.policies.index'));
        $this->actingAs($admin)->get(self::BASE.'/assignments/create')->assertRedirect(route('iapm.policies.index'));
        $this->actingAs($admin)->get(self::BASE."/assignments/{$assignment->id}/edit")
            ->assertRedirect(route('iapm.policies.edit', ['policy' => $policy, 'assignment' => $assignment->id]).'#assignments');

        $this->actingAs($admin)
            ->get(self::BASE."/policies/{$policy->id}/edit?assignment={$assignment->id}")
            ->assertOk()
            ->assertSee('Interface assignments')
            ->assertSee('Edit assignment #'.$assignment->id);
    }

    /** Every GET route in the plugin, with fixtures materialised on demand. */
    private function paths(): array
    {
        $policy = $this->defaultPolicy();
        $assignment = $policy->assignments()->firstOrFail();
        $destination = $this->smsDestination();
        $action = $this->triggerAction($policy, $destination);
        $port = $this->downPort($this->device());
        $incident = $this->incident($policy, $port);
        $base = '/plugin/interface-alert-policy-manager';

        return [
            $base,
            "$base/policies",
            "$base/policies/create",
            "$base/policies/{$policy->id}/edit",
            "$base/policies/{$policy->id}/actions/create",
            "$base/actions/{$action->id}/edit",
            "$base/policies/{$policy->id}/edit?assignment={$assignment->id}",
            "$base/interface-matrix",
            "$base/policy-test",
            "$base/policy-test?port_id={$port->port_id}",
            "$base/stats",
            "$base/tools/simulate",
            "$base/import",
            "$base/comparison-report",
            "$base/setup-helper",
            "$base/template-preview",
            "$base/message-templates",
            "$base/sms-content-filters",
            "$base/destinations",
            "$base/destinations/create",
            "$base/destinations/{$destination->id}/edit",
            "$base/incidents",
            "$base/incidents/{$incident->id}",
            "$base/settings",
            "$base/delivery-log",
            "$base/audit-log",
        ];
    }
}
