<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Services;

class Redactor
{
    private const SECRET_KEYS = ['authorization', 'password', 'token', 'secret', 'api_key', 'api-key', 'bearer_token', 'username'];

    public function array(array $data): array
    {
        foreach ($data as $key => $value) {
            $data[$key] = in_array(strtolower((string) $key), self::SECRET_KEYS, true) ? '[REDACTED]' : (is_array($value) ? $this->array($value) : $value);
        }

        return $data;
    }

    /** @param list<string> $sensitive */
    public function text(string $value, array $sensitive = []): string
    {
        // Scrub complete values before truncating: a truncated message no longer
        // matches its original plaintext or JSON-escaped representation.
        $decoded = json_decode($value, true);
        if (is_array($decoded) && json_last_error() === JSON_ERROR_NONE) {
            $value = json_encode($this->array($decoded), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) ?: '[REDACTED]';
        }
        $replacements = [];
        foreach ($sensitive as $secret) {
            if ($secret === '') {
                continue;
            }
            $replacements[$secret] = '[REDACTED]';
            foreach ([0, JSON_UNESCAPED_UNICODE, JSON_UNESCAPED_SLASHES, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES] as $flags) {
                $encoded = json_encode($secret, $flags | JSON_INVALID_UTF8_SUBSTITUTE);
                if (is_string($encoded)) {
                    $replacements[substr($encoded, 1, -1)] = '[REDACTED]';
                }
            }
        }
        $value = strtr($value, $replacements);
        // Exception text can contain a JSON fragment rather than a whole JSON
        // document. Consume quoted values as one token, including escaped quotes.
        $value = preg_replace('/("(?:authorization|password|username|token|secret|api[_-]?key|bearer_token)"\s*:\s*)"(?:\\\\.|[^"\\\\])*"/i', '$1"[REDACTED]"', $value) ?? '[REDACTED]';
        $value = preg_replace('#(https?://)[^/@\s]+@#i', '$1[REDACTED]@', $value) ?? '[REDACTED]';
        $value = preg_replace('/\bBearer\s+[A-Za-z0-9._~+\/-]+=*/i', 'Bearer [REDACTED]', $value) ?? '[REDACTED]';
        $value = preg_replace('/(authorization|password|username|token|secret|api[_-]?key)\s*[:=]\s*[^\s,;]+/i', '$1=[REDACTED]', $value) ?? '[REDACTED]';

        return mb_substr($value, 0, 4096);
    }
}
