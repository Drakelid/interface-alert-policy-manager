<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Every way an alert can be refused at the door, written down once.
 *
 * A refused POST is a lost alert. Authentication and payload validation always
 * said so in the log, but the media-type, size, correlation, unknown-device and
 * backlog paths answered with a JSON body and nothing else — so an operator
 * reading iapm.log could not tell a LibreNMS that had stopped sending from an
 * endpoint that was refusing everything it sent. That ambiguity is exactly what
 * makes a silent alert path expensive to diagnose.
 *
 * Logging is throttled per reason per source: two of these paths run before
 * authentication, so an unauthenticated caller must never be able to fill the
 * disk by repeating a bad request.
 */
class IngestionRejectionLog
{
    public const THROTTLE_SECONDS = 60;

    /** @param  array<string, scalar|null>  $context */
    public function record(string $code, ?string $ip, array $context = []): void
    {
        $key = 'iapm:ingest-reject:'.$code.':'.hash('sha256', (string) $ip);
        if (RateLimiter::tooManyAttempts($key, 1)) {
            return;
        }
        RateLimiter::hit($key, self::THROTTLE_SECONDS);

        // One message and a code, so a grep for "Ingestion request rejected"
        // finds every refusal regardless of which layer produced it.
        Log::channel('iapm')->warning('Ingestion request rejected.', array_merge(['code' => $code, 'ip' => $ip], $context));
    }
}
