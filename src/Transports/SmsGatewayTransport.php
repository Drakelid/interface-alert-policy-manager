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
            $body = $this->redactor->text($response->body());
            // A 2xx is success unless the body clearly says otherwise. `"error": null`
            // and `"error": ""` are both "no error"; anything else in that field is one.
            $bodyError = preg_match('/"(?:success|ok)"\s*:\s*false|"error"\s*:\s*(?!null|"")/i', $body) === 1;

            return new TransportResult($response->successful() && ! $bodyError, $response->status(), $body, $bodyError ? 'Gateway response indicates failure.' : null);
        } catch (\Throwable $e) {
            return new TransportResult(false, null, null, $this->redactor->text($e->getMessage()));
        }
    }

    private function safeHeaders(array $headers): array
    {
        unset($headers['Authorization'], $headers['authorization'], $headers['Host'], $headers['host'], $headers['Content-Length'], $headers['content-length']);

        return array_filter($headers, fn ($v, $k) => is_string($k) && is_scalar($v), ARRAY_FILTER_USE_BOTH);
    }
}
