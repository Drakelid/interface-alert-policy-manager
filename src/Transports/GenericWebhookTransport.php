<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Transports;

use Illuminate\Http\Client\Factory;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\Redactor;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\UrlGuard;

class GenericWebhookTransport implements NotificationTransport
{
    public function __construct(private readonly Factory $http, private readonly UrlGuard $urls, private readonly Redactor $redactor) {}

    public function send(array $configuration, string $receiver, string $message): TransportResult
    {
        try {
            $url = (string) ($configuration['url'] ?? '');
            $networkOptions = $this->urls->pinnedOptions($url, (bool) ($configuration['allow_private_networks'] ?? false));
            $client = $this->http->acceptJson()->asJson()->connectTimeout((int) ($configuration['connect_timeout'] ?? 5))->timeout((int) ($configuration['timeout'] ?? 15))->withOptions(array_merge($networkOptions, ['verify' => (bool) ($configuration['verify_tls'] ?? true)]));
            $headers = array_filter((array) ($configuration['headers'] ?? []), fn ($v, $k) => is_string($k) && is_scalar($v) && ! in_array(strtolower($k), ['authorization', 'host', 'content-length'], true), ARRAY_FILTER_USE_BOTH);
            if (! empty($configuration['_iapm_idempotency_key'])) {
                $headers['Idempotency-Key'] = (string) $configuration['_iapm_idempotency_key'];
            } $client = $client->withHeaders($headers);
            if (! empty($configuration['username'])) {
                $client = $client->withBasicAuth((string) $configuration['username'], (string) ($configuration['password'] ?? ''));
            } elseif (! empty($configuration['bearer_token'])) {
                $client = $client->withToken((string) $configuration['bearer_token']);
            }
            $response = $client->post($url, ['receiver' => $receiver, 'message' => $message]);

            return new TransportResult($response->successful(), $response->status(), $this->redactor->text($response->body()), $response->successful() ? null : 'Webhook returned a non-success response.', $this->retryAfter($response->header('Retry-After')));
        } catch (\Throwable $e) {
            return new TransportResult(false, null, null, $this->redactor->text($e->getMessage()));
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
}
