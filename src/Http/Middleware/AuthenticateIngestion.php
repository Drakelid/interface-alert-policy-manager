<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Http\Middleware;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\SettingStore;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateIngestion
{
    public function __construct(private readonly SettingStore $settings) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isJson() || ! str_starts_with(strtolower((string) $request->header('Content-Type')), 'application/json')) {
            return response()->json(['error' => ['code' => 'unsupported_media_type', 'message' => 'Content-Type must be application/json.']], 415);
        }
        if ((int) $request->server('CONTENT_LENGTH', 0) > (int) config('iapm.ingestion.max_bytes', 1048576)) {
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
            Log::channel('iapm')->warning('Ingestion authentication rejected.', ['ip' => $request->ip()]);

            return response()->json(['error' => ['code' => 'unauthorized', 'message' => 'Invalid ingestion token.']], 401);
        }

        return $next($request);
    }
}
