<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Services;

class ReceiverResolver
{
    public function resolve(array ...$levels): array
    {
        foreach ($levels as $receivers) {
            $normalized = array_values(array_unique(array_filter(array_map(fn ($v) => $this->normalize((string) $v), $receivers))));
            if ($normalized !== []) return $normalized;
        }
        return [];
    }
    private function normalize(string $value): ?string { $value = trim($value); return $value !== '' && mb_strlen($value) <= 128 && preg_match('/^[\pL\pN+_.@() \-]+$/u', $value) ? $value : null; }
}
