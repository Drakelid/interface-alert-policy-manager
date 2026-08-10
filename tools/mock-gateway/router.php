<?php

declare(strict_types=1);

/**
 * Controlled SMS/webhook failure target for IAPM staging tests.
 * It never persists request bodies, receivers, messages, or authorization data.
 * Run only on a test host: php -S 127.0.0.1:9087 tools/mock-gateway/router.php
 */
$stateFile = getenv('MOCK_GATEWAY_STATE') ?: sys_get_temp_dir().'/iapm-mock-gateway.json';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

if ($method === 'POST' && $path === '/reset') {
    file_put_contents($stateFile, json_encode(['total' => 0, 'keys' => [], 'requests' => []], JSON_THROW_ON_ERROR), LOCK_EX);
    respond(204, null);
}

if ($method === 'GET' && $path === '/state') {
    header('Content-Type: application/json');
    echo is_file($stateFile) ? file_get_contents($stateFile) : json_encode(['total' => 0, 'keys' => [], 'requests' => []]);
    exit;
}

$latencyMs = max(0, min(120000, (int) (getenv('MOCK_GATEWAY_LATENCY_MS') ?: 0)));
if ($latencyMs > 0) {
    usleep($latencyMs * 1000);
}

$handle = fopen($stateFile, 'c+');
if ($handle === false || ! flock($handle, LOCK_EX)) {
    respond(500, ['error' => 'mock state unavailable']);
}
$raw = stream_get_contents($handle);
$state = $raw === '' ? ['total' => 0, 'keys' => [], 'requests' => []] : (json_decode($raw, true) ?: ['total' => 0, 'keys' => [], 'requests' => []]);
$state['total'] = (int) ($state['total'] ?? 0) + 1;
$key = trim((string) ($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? ''));
$keyHash = $key === '' ? null : hash('sha256', $key);
$duplicate = $keyHash !== null && isset($state['keys'][$keyHash]);
if ($keyHash !== null) {
    $state['keys'][$keyHash] = (int) ($state['keys'][$keyHash] ?? 0) + 1;
}

$status = max(100, min(599, (int) (getenv('MOCK_GATEWAY_STATUS') ?: 200)));
$failFirst = max(0, (int) (getenv('MOCK_GATEWAY_FAIL_FIRST') ?: 0));
$rateLimitAfter = max(0, (int) (getenv('MOCK_GATEWAY_RATE_LIMIT_AFTER') ?: 0));
if ($state['total'] <= $failFirst) {
    $status = 500;
} elseif ($rateLimitAfter > 0 && $state['total'] > $rateLimitAfter) {
    $status = 429;
}
if ($status === 429) {
    header('Retry-After: '.max(1, (int) (getenv('MOCK_GATEWAY_RETRY_AFTER') ?: 60)));
}
$state['requests'][] = ['sequence' => $state['total'], 'time' => gmdate(DATE_ATOM), 'idempotency_key_hash' => $keyHash, 'duplicate' => $duplicate, 'status' => $status];
$state['requests'] = array_slice($state['requests'], -10000);
rewind($handle);
ftruncate($handle, 0);
fwrite($handle, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
fflush($handle);
flock($handle, LOCK_UN);
fclose($handle);

respond($status, ['ok' => $status >= 200 && $status < 300, 'duplicate' => $duplicate, 'sequence' => $state['total']]);

function respond(int $status, ?array $body): never
{
    http_response_code($status);
    if ($body !== null) {
        header('Content-Type: application/json');
        echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
    exit;
}
