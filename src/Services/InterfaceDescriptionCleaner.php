<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Services;

/** Removes common interface-description decoration without touching identifiers. */
class InterfaceDescriptionCleaner
{
    public function clean(string $description): string
    {
        $cleaned = str_replace('#', ' ', $description);
        $cleaned = preg_replace([
            '/\bbundle[\s_-]+to\b/iu',
            '/\bbundle[\s_-]*(?:ethernet|ether|eth)\s*\d*(?:[.\/:_-]\d+)*\b(?:\s+to\b)?/iu',
        ], ' ', $cleaned) ?? $cleaned;
        $cleaned = preg_replace('/\s+/u', ' ', $cleaned) ?? $cleaned;

        return preg_replace('/^[\s\-–—:;|]+|[\s\-–—:;|]+$/u', '', $cleaned) ?? $cleaned;
    }
}
