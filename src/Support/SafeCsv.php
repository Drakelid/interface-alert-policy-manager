<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Support;

class SafeCsv
{
    /** @param resource $stream @param list<mixed> $row */
    public static function write($stream, array $row): void
    {
        $row = array_map(static function (mixed $value): mixed {
            // Quoting is CSV syntax, not a formula boundary. Prefix dangerous
            // text (including control/space-prefixed formulas) as literal text.
            return is_string($value) && preg_match('/^[\x00-\x20]*[=+\-@\x{FF1D}\x{FF0B}\x{FF0D}\x{FF20}]/u', $value) === 1 ? "'".$value : $value;
        }, $row);
        fputcsv($stream, $row, ',', '"', '');
    }
}
