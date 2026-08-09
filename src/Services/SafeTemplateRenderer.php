<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Services;

use InvalidArgumentException;

class SafeTemplateRenderer
{
    public function render(string $template, array $values, ?int $limit = null): string
    {
        $rendered = preg_replace_callback('/\{\{\s*([a-zA-Z][a-zA-Z0-9_]*)\s*\}\}/', function (array $m) use ($values): string {
            if (! array_key_exists($m[1], $values)) {
                throw new InvalidArgumentException("Unknown template placeholder: {$m[1]}");
            }
            $value = $values[$m[1]];
            if (! is_scalar($value) && $value !== null) {
                throw new InvalidArgumentException("Invalid template value: {$m[1]}");
            }

            return (string) $value;
        }, $template) ?? throw new InvalidArgumentException('Invalid template.');

        if ($limit !== null && mb_strlen($rendered) > $limit) {
            $suffix = isset($values['incident_id']) ? "\nIncident: {$values['incident_id']}" : '';
            $rendered = rtrim(mb_substr($rendered, 0, max(0, $limit - mb_strlen($suffix) - 1))).'…'.$suffix;
        }

        return $rendered;
    }
}
