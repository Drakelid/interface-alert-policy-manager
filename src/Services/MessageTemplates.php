<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Services;

/**
 * Resolves the message template for a notification phase. Precedence:
 *   1. the policy action's own template (handled by the caller)
 *   2. an administrator-customised default for the phase (stored in settings)
 *   3. the built-in default
 *
 * This gives operators one place to reword every "interface down"/"recovered"
 * message without editing each action, while still allowing per-action overrides.
 */
class MessageTemplates
{
    /** @var list<string> */
    public const PHASES = ['trigger', 'reminder', 'escalation', 'acknowledged', 'recovery', 'flapping'];

    public function __construct(private readonly SettingStore $settings) {}

    public function resolve(string $phase): string
    {
        $custom = $this->settings->get('template_'.$phase);

        return is_string($custom) && trim($custom) !== '' ? $custom : self::default($phase);
    }

    /** The device-digest template (one message for many interfaces on a device). */
    public function resolveDigest(): string
    {
        $custom = $this->settings->get('template_digest');

        return is_string($custom) && trim($custom) !== '' ? $custom : self::defaultDigest();
    }

    public function customDigest(): string
    {
        $custom = $this->settings->get('template_digest');

        return is_string($custom) ? $custom : '';
    }

    public static function defaultDigest(): string
    {
        return "{{ severity }}: {{ hostname }} — {{ interface_count }} interfaces down\n{{ interfaces }}\nDown since: {{ first_seen_at }}\nDevice: {{ device_url }}";
    }

    /** The administrator-customised template for a phase, or '' if none is set. */
    public function custom(string $phase): string
    {
        $custom = $this->settings->get('template_'.$phase);

        return is_string($custom) ? $custom : '';
    }

    public static function label(string $phase): string
    {
        return [
            'trigger' => 'Interface down (trigger)',
            'reminder' => 'Reminder (still down)',
            'escalation' => 'Escalation',
            'acknowledged' => 'Acknowledged',
            'recovery' => 'Recovered',
            'flapping' => 'Flapping',
        ][$phase] ?? ucfirst($phase);
    }

    public static function default(string $phase): string
    {
        return match ($phase) {
            'recovery' => "RECOVERED: Interface restored\nDevice: {{ hostname }}\nPort: {{ ifName }}\nDescription: {{ ifAlias }}\nOutage: {{ outage_duration }}\nIncident: {{ incident_id }}",
            'acknowledged' => "ACKNOWLEDGED: Interface down\nDevice: {{ hostname }}\nPort: {{ ifName }}\nBy: {{ acknowledgement_user }}\nIncident: {{ incident_id }}",
            'escalation' => "ESCALATION: Interface still down\nDevice: {{ hostname }}\nPort: {{ ifName }}\nDescription: {{ ifAlias }}\nDown since: {{ first_seen_at }}\nIncident: {{ incident_id }}",
            'reminder' => "REMINDER: Interface still down\nDevice: {{ hostname }}\nPort: {{ ifName }}\nDown since: {{ first_seen_at }}\nIncident: {{ incident_id }}",
            'flapping' => "FLAPPING: Interface unstable\nDevice: {{ hostname }}\nPort: {{ ifName }}\nDescription: {{ ifAlias }}\nFurther alerts dampened until stable.\nIncident: {{ incident_id }}",
            default => "CRITICAL: Interface down\nDevice: {{ hostname }}\nPort: {{ ifName }}\nDescription: {{ ifAlias }}\nLocation: {{ location }}\nDown since: {{ first_seen_at }}\nPolicy: {{ policy_name }}\nIncident: {{ incident_id }}",
        };
    }
}
