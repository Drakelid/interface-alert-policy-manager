<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Services;

class Redactor
{
    private const SECRET_KEYS = ['authorization', 'password', 'token', 'secret', 'api_key', 'username'];
    public function array(array $data): array { foreach ($data as $key => $value) { $data[$key] = in_array(strtolower((string) $key), self::SECRET_KEYS, true) ? '[REDACTED]' : (is_array($value) ? $this->array($value) : $value); } return $data; }
    public function text(string $value): string { return preg_replace('/(authorization|password|token|secret|api[_-]?key)\s*[:=]\s*[^\s,;]+/i', '$1=[REDACTED]', mb_substr($value, 0, 4096)) ?? '[REDACTED]'; }
}
