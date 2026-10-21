<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Support;

class RetryAfter
{
    public static function seconds(?string $value): ?int
    {
        $value = trim($value ?? '');
        if ($value === '') {
            return null;
        }
        // Leave timezone headroom below the database DATETIME year-9999 limit.
        // Reject an unrepresentable header instead of silently shortening it.
        $maximumTimestamp = 253402214400; // 9999-12-30 00:00:00 UTC
        $now = time();
        if (ctype_digit($value)) {
            $digits = ltrim($value, '0');
            $maximum = (string) ($maximumTimestamp - $now);
            if (strlen($digits) > strlen($maximum) || (strlen($digits) === strlen($maximum) && strcmp($digits, $maximum) > 0)) {
                return null;
            }

            return $digits === '' ? 0 : (int) $digits;
        }
        $timestamp = strtotime($value);

        return $timestamp === false || $timestamp > $maximumTimestamp ? null : max(0, $timestamp - $now);
    }
}
