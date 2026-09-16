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
    /**
     * The one format the plugin shows times in; `partials.time` and the CSV
     * exports render through this constant so a row on screen and the same row
     * in an export are byte-identical.
     *
     * `P` (+02:00), not `T`: `T` only produces a zone name ("CEST") when the
     * application timezone is a named zone, and falls back to "GMT+0200" when it
     * is a bare offset — so the suffix changed shape between installations. A
     * numeric offset is stable everywhere, and unlike an abbreviation it is
     * unambiguous when correlating against a poller run or a LibreNMS alert.
     */
    public const FORMAT = 'Y-m-d H:i:s P';

    /**
     * "2026-09-15 17:04:22 +02:00 (21 hours ago)".
     */
    public static function exactWithRelative(\DateTimeInterface $at): string
    {
        $relative = $at instanceof CarbonInterface ? $at : CarbonImmutable::instance($at);

        return self::exact($at).' ('.$relative->diffForHumans().')';
    }

    /**
     * "2026-09-15 17:04:22 +02:00".
     */
    public static function exact(\DateTimeInterface $at): string
    {
        return $at->format(self::FORMAT);
    }
}
