<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Transports;

use Illuminate\Http\Client\Factory;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\Redactor;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\UrlGuard;

class SmsGatewayTransport implements NotificationTransport
{
    public function __construct(private readonly Factory $http, private readonly UrlGuard $urls, private readonly Redactor $redactor) {}

    public function send(array $configuration, string $receiver, string $message): TransportResult
    {
        $sensitive = [$receiver, $message, (string) ($configuration['username'] ?? ''), (string) ($configuration['password'] ?? '')];
        foreach ((array) ($configuration['headers'] ?? []) as $value) {
            if (is_scalar($value)) {
                $sensitive[] = (string) $value;
            }
        }
        try {
            $url = (string) ($configuration['url'] ?? '');
            $networkOptions = $this->urls->pinnedOptions($url, (bool) ($configuration['allow_private_networks'] ?? config('iapm.http.allow_private_networks', false)));
            $headers = $this->safeHeaders($configuration['headers'] ?? []);
            if (! empty($configuration['_iapm_idempotency_key'])) {
                $headers['Idempotency-Key'] = (string) $configuration['_iapm_idempotency_key'];
            }
            $client = $this->http->acceptJson()->withHeaders($headers)->connectTimeout((int) ($configuration['connect_timeout'] ?? config('iapm.http.connect_timeout')))->timeout((int) ($configuration['timeout'] ?? config('iapm.http.timeout')))->withOptions(array_merge($networkOptions, ['verify' => (bool) ($configuration['verify_tls'] ?? true)]));
            if (! empty($configuration['username'])) {
                $client = $client->withBasicAuth((string) $configuration['username'], (string) ($configuration['password'] ?? ''));
            }
            $payload = ['receiver' => $receiver, 'message' => $message];
            $response = ($configuration['mode'] ?? 'json') === 'form' ? $client->asForm()->post($url, $payload) : $client->asJson()->post($url, $payload);
            $rawBody = $response->body();
            $body = $this->redactor->text($rawBody, $sensitive);
            // A 2xx is success unless the body clearly says otherwise. `"error": null`
            // and `"error": ""` are both "no error"; anything else in that field is one.
            // Status must be read from the original structured response. Logs
            // may be truncated or redacted, and JSON whitespace is insignificant.
            $decoded = json_decode($rawBody, true);
            $bodyError = is_array($decoded) && (
                ($decoded['success'] ?? null) === false
                || ($decoded['ok'] ?? null) === false
                || (isset($decoded['error']) && $decoded['error'] !== '')
            );

            return new TransportResult($response->successful() && ! $bodyError, $response->status(), $body, $bodyError ? 'Gateway response indicates failure.' : null, $this->retryAfter($response->header('Retry-After')));
        } catch (\Throwable $e) {
            return new TransportResult(false, null, null, $this->redactor->text($e->getMessage(), $sensitive));
        }
    }

    private function retryAfter(?string $value): ?int
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        if (ctype_digit(trim($value))) {
            return min(86400, (int) trim($value));
        }
        $timestamp = strtotime($value);

        return $timestamp === false ? null : min(86400, max(0, $timestamp - time()));
    }

    /**
     * Drop headers the transport controls itself. HTTP header names are
     * case-insensitive, so this must compare case-insensitively — unsetting only
     * the two spellings let `AUTHORIZATION` through and override the Basic auth
     * credentials configured on the destination.
     */
    private function safeHeaders(array $headers): array
    {
        return array_filter(
            $headers,
            fn ($v, $k) => is_string($k) && is_scalar($v) && ! in_array(strtolower($k), ['authorization', 'host', 'content-length'], true),
            ARRAY_FILTER_USE_BOTH
        );
    }
}
