<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Time wording for status sentences and log prose.
 *
 * The UI's tables render times through the `partials.time` Blade partial, which
 * shows the exact timestamp. Prose built in PHP ("Last ingestion …") has no
 * partial to lean on, so it goes through here to reach the same convention: the
 * exact timestamp first, with the relative phrasing in parentheses. A bare "21
 * hours ago" cannot be correlated against a poller run, a LibreNMS alert_log
 * row or a delivery attempt, which is the whole reason anyone reads these.
 */
class TimeText
{
    /** The format the whole plugin shows times in — matches `partials.time` and the CSV exports. */
    public const FORMAT = 'Y-m-d H:i:s T';

    /**
     * "2026-09-16 14:03:11 UTC (21 hours ago)".
     */
    public static function exactWithRelative(\DateTimeInterface $at): string
    {
        $relative = $at instanceof CarbonInterface ? $at : CarbonImmutable::instance($at);

        return self::exact($at).' ('.$relative->diffForHumans().')';
    }

    /**
     * "2026-09-16 14:03:11 UTC".
     */
    public static function exact(\DateTimeInterface $at): string
    {
        return $at->format(self::FORMAT);
    }
}
