<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\Feature;

use App\Models\User;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Schedule;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\IntegrationTestCase;

/**
 * P3-1 to P3-4, verified programmatically across every plugin page rather than
 * spot-checked.
 *
 * The audit found the policy edit page carrying 26 <label> elements and zero
 * `for` attributes, leaving 16 of 27 fields with no programmatic label; pages
 * starting at <h2> with no <h1>; and icon-only buttons with a title but no
 * accessible name.
 *
 * Only the plugin's own markup is examined — the LibreNMS layout wraps every
 * page and is not ours to fix, so each assertion is scoped to the plugin's
 * container.
 */
class AccessibilityTest extends IntegrationTestCase
{
    private const BASE = '/plugin/interface-alert-policy-manager';

    public function test_every_visible_form_control_has_an_accessible_name(): void
    {
        $admin = $this->admin();
        $problems = [];

        foreach ($this->paths() as $path) {
            $xpath = $this->pluginXPath($admin, $path);
            foreach ($xpath->query('//input | //select | //textarea') as $control) {
                if ($this->isHiddenControl($control) || $this->hasAccessibleName($control, $xpath)) {
                    continue;
                }
                $problems[] = sprintf('%s: <%s name="%s" id="%s">', $path, $control->nodeName, $control->getAttribute('name'), $control->getAttribute('id'));
            }
        }

        self::assertSame([], $problems, "Form controls with no accessible name:\n".implode("\n", $problems));
    }

    /** P3-1 also asked that labels be associated, not merely present. */
    public function test_labels_are_associated_with_a_control(): void
    {
        $admin = $this->admin();
        $problems = [];

        foreach ($this->paths() as $path) {
            $xpath = $this->pluginXPath($admin, $path);
            foreach ($xpath->query('//label') as $label) {
                $for = $label->getAttribute('for');
                if ($for !== '') {
                    if ($xpath->query('//*[@id="'.$for.'"]')->length === 0) {
                        $problems[] = "$path: <label for=\"$for\"> points at no element";
                    }

                    continue;
                }
                // A label with no `for` is fine when it wraps its control.
                if ($xpath->query('.//input | .//select | .//textarea', $label)->length === 0) {
                    $problems[] = sprintf('%s: <label>%s</label> is associated with nothing', $path, trim(mb_substr($label->textContent, 0, 40)));
                }
            }
        }

        self::assertSame([], $problems, "Unassociated labels:\n".implode("\n", $problems));
    }

    public function test_every_page_has_exactly_one_h1(): void
    {
        $admin = $this->admin();
        $problems = [];

        foreach ($this->paths() as $path) {
            $count = $this->pluginXPath($admin, $path)->query('//h1')->length;
            if ($count !== 1) {
                $problems[] = "$path has $count <h1> elements";
            }
        }

        self::assertSame([], $problems, implode("\n", $problems));
    }

    public function test_heading_levels_do_not_skip(): void
    {
        $admin = $this->admin();
        $problems = [];

        foreach ($this->paths() as $path) {
            $previous = 0;
            foreach ($this->pluginXPath($admin, $path)->query('//h1 | //h2 | //h3 | //h4 | //h5 | //h6') as $heading) {
                $level = (int) substr($heading->nodeName, 1);
                if ($previous !== 0 && $level > $previous + 1) {
                    $problems[] = sprintf('%s: h%d "%s" follows h%d', $path, $level, trim(mb_substr($heading->textContent, 0, 40)), $previous);
                }
                $previous = $level;
            }
        }

        self::assertSame([], $problems, "Skipped heading levels:\n".implode("\n", $problems));
    }

    /** P3-4: a title attribute is not an accessible name for a screen reader. */
    public function test_icon_only_controls_have_an_accessible_name(): void
    {
        $admin = $this->admin();
        $problems = [];

        foreach ($this->paths() as $path) {
            $xpath = $this->pluginXPath($admin, $path);
            foreach ($xpath->query('//button | //a') as $control) {
                if (trim($control->textContent) !== '') {
                    continue; // has visible text
                }
                if ($control->getAttribute('aria-label') !== '' || $control->getAttribute('aria-labelledby') !== '') {
                    continue;
                }
                $problems[] = sprintf('%s: icon-only <%s title="%s">', $path, $control->nodeName, $control->getAttribute('title'));
            }
        }

        self::assertSame([], $problems, "Icon-only controls with no accessible name:\n".implode("\n", $problems));
    }

    /**
     * P3-5: Bootstrap 3's .text-muted is #777, which is 4.48:1 on white — below
     * WCAG AA — and much worse on the dark theme. The plugin's own help text
     * uses .iapm-hint, which is defined for both themes.
     */
    public function test_plugin_help_text_does_not_rely_on_text_muted(): void
    {
        $admin = $this->admin();
        $problems = [];

        foreach ($this->paths() as $path) {
            $count = $this->pluginXPath($admin, $path)->query('//*[contains(concat(" ",normalize-space(@class)," ")," text-muted ")]')->length;
            if ($count > 0) {
                $problems[] = "$path uses .text-muted on $count element(s)";
            }
        }

        self::assertSame([], $problems, "Low-contrast help text:\n".implode("\n", $problems));
    }

    /** P3-6: inline handlers block a Content-Security-Policy without unsafe-inline. */
    public function test_no_inline_event_handlers_remain(): void
    {
        $admin = $this->admin();
        $problems = [];

        foreach ($this->paths() as $path) {
            $xpath = $this->pluginXPath($admin, $path);
            foreach (['onclick', 'onsubmit', 'onchange', 'oninput'] as $attribute) {
                foreach ($xpath->query('//*[@'.$attribute.']') as $node) {
                    $problems[] = sprintf('%s: <%s %s="%s">', $path, $node->nodeName, $attribute, mb_substr($node->getAttribute($attribute), 0, 50));
                }
            }
        }

        self::assertSame([], $problems, "Inline event handlers:\n".implode("\n", $problems));
    }

    /** Hidden inputs and CSRF tokens need no label. */
    private function isHiddenControl(\DOMElement $control): bool
    {
        if ($control->nodeName === 'input' && strtolower($control->getAttribute('type')) === 'hidden') {
            return true;
        }

        return str_contains($control->getAttribute('style'), 'display:none');
    }

    private function hasAccessibleName(\DOMElement $control, \DOMXPath $xpath): bool
    {
        foreach (['aria-label', 'aria-labelledby', 'title'] as $attribute) {
            if (trim($control->getAttribute($attribute)) !== '') {
                return true;
            }
        }
        $id = $control->getAttribute('id');
        if ($id !== '' && $xpath->query('//label[@for="'.$id.'"]')->length > 0) {
            return true;
        }

        // Wrapped in its own label.
        for ($node = $control->parentNode; $node instanceof \DOMElement; $node = $node->parentNode) {
            if ($node->nodeName === 'label') {
                return true;
            }
        }

        return false;
    }

    private function pluginXPath(User $admin, string $path): \DOMXPath
    {
        $html = (string) $this->actingAs($admin)->get($path)->assertOk()->getContent();
        $document = new \DOMDocument;
        libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();

        $xpath = new \DOMXPath($document);
        // Scope to the plugin's own container: the LibreNMS chrome around it is
        // not ours to fix and would otherwise dominate every result.
        $container = $xpath->query('//div[contains(concat(" ",normalize-space(@class)," ")," container-fluid ")]')->item(0);
        self::assertNotNull($container, "No plugin container found at $path.");
        $xpath->registerNamespace('php', 'http://php.net/xpath');
        $scoped = new \DOMDocument;
        $scoped->appendChild($scoped->importNode($container, true));

        return new \DOMXPath($scoped);
    }

    /** @return list<string> */
    private function paths(): array
    {
        $policy = $this->defaultPolicy();
        $assignment = $policy->assignments()->firstOrFail();
        $destination = $this->smsDestination();
        $action = $this->triggerAction($policy, $destination);
        $schedule = Schedule::create(['name' => 'Always', 'timezone' => 'UTC', 'enabled' => true, 'schedule_json' => ['mode' => 'always', 'periods' => []]]);
        $port = $this->downPort($this->device());
        $incident = $this->incident($policy, $port);
        $base = self::BASE;

        return [
            $base,
            "$base/policies", "$base/policies/create", "$base/policies/{$policy->id}/edit",
            "$base/policies/{$policy->id}/actions/create", "$base/actions/{$action->id}/edit",
            "$base/assignments", "$base/assignments/create", "$base/assignments/{$assignment->id}/edit",
            "$base/interface-matrix", "$base/policy-test", "$base/policy-test?port_id={$port->port_id}",
            "$base/stats", "$base/tools/simulate", "$base/tools/real-simulations", "$base/import", "$base/comparison-report",
            "$base/setup-helper", "$base/template-preview", "$base/message-templates",
            "$base/schedules", "$base/schedules/create", "$base/schedules/{$schedule->id}/edit",
            "$base/destinations", "$base/destinations/create", "$base/destinations/{$destination->id}/edit",
            "$base/incidents", "$base/incidents/{$incident->id}",
            "$base/settings", "$base/delivery-log", "$base/audit-log",
        ];
    }
}
