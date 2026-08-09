<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Services;

class Redactor
{
    private const SECRET_KEYS = ['authorization', 'password', 'token', 'secret', 'api_key', 'username'];

    public function array(array $data): array
    {
        foreach ($data as $key => $value) {
            $data[$key] = in_array(strtolower((string) $key), self::SECRET_KEYS, true) ? '[REDACTED]' : (is_array($value) ? $this->array($value) : $value);
        }

        return $data;
    }

    public function text(string $value): string
    {
        $value = mb_substr($value, 0, 4096);
        $value = preg_replace('#(https?://)[^/@\s]+@#i', '$1[REDACTED]@', $value) ?? '[REDACTED]';
        $value = preg_replace('/\bBearer\s+[A-Za-z0-9._~+\/-]+=*/i', 'Bearer [REDACTED]', $value) ?? '[REDACTED]';

        return preg_replace('/(authorization|password|username|token|secret|api[_-]?key)\s*[:=]\s*[^\s,;]+/i', '$1=[REDACTED]', $value) ?? '[REDACTED]';
    }
}
