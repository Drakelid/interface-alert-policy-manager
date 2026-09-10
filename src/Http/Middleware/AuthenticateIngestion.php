<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Http\Middleware;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Request;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\IngestionRejectionLog;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\SettingStore;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateIngestion
{
    public function __construct(private readonly SettingStore $settings, private readonly IngestionRejectionLog $rejections) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isJson() || ! str_starts_with(strtolower((string) $request->header('Content-Type')), 'application/json')) {
            // A transport sending the wrong body type refuses every alert it has.
            // Record the Content-Type it did send: "send as form" left enabled is
            // the usual cause, and the header names it immediately.
            $this->rejections->record('unsupported_media_type', $request->ip(), ['content_type' => (string) $request->header('Content-Type')]);

            return response()->json(['error' => ['code' => 'unsupported_media_type', 'message' => 'Content-Type must be application/json.']], 415);
        }
        if ((int) $request->server('CONTENT_LENGTH', 0) > (int) config('iapm.ingestion.max_bytes', 1048576)) {
            $this->rejections->record('payload_too_large', $request->ip(), ['bytes' => (int) $request->server('CONTENT_LENGTH', 0), 'limit' => (int) config('iapm.ingestion.max_bytes', 1048576)]);

            return response()->json(['error' => ['code' => 'payload_too_large', 'message' => 'Payload exceeds the configured limit.']], 413);
        }
        $provided = $request->bearerToken();
        $previousExpiry = $this->settings->get('previous_ingestion_token_expires_at');
        $previousValid = $previousExpiry && now()->lessThan(CarbonImmutable::parse($previousExpiry));
        $tokens = array_filter([$this->settings->get('ingestion_token'), $previousValid ? $this->settings->get('previous_ingestion_token') : null]);
        $valid = false;
        if (is_string($provided)) {
            foreach ($tokens as $token) {
                if (is_string($token) && hash_equals($token, $provided)) {
                    $valid = true;
                    break;
                }
            }
        }
        if (! $valid) {
            // Never record which token was offered, or whether one was offered at
            // all: a 401 must not become an oracle for probing the endpoint.
            $this->rejections->record('unauthorized', $request->ip());

            return response()->json(['error' => ['code' => 'unauthorized', 'message' => 'Invalid ingestion token.']], 401);
        }

        return $next($request);
    }
}
