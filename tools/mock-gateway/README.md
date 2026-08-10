# Controlled mock gateway

This local-only server exercises latency, failure, rate-limit, retry, and idempotency behavior without sending notifications or retaining sensitive payloads.

```bash
MOCK_GATEWAY_STATE=/tmp/iapm-gateway.json \
MOCK_GATEWAY_FAIL_FIRST=3 \
MOCK_GATEWAY_RATE_LIMIT_AFTER=100 \
MOCK_GATEWAY_RETRY_AFTER=60 \
MOCK_GATEWAY_LATENCY_MS=250 \
php -S 127.0.0.1:9087 tools/mock-gateway/router.php
```

Point a test-only destination at `http://127.0.0.1:9087/send` with private-network access enabled. `GET /state` returns request sequence, response status, and SHA-256 hashes of idempotency keys. It never records request bodies, receivers, messages, credentials, or authorization headers. `POST /reset` clears state.

Environment controls:

- `MOCK_GATEWAY_STATUS`: fixed response status (default `200`).
- `MOCK_GATEWAY_FAIL_FIRST`: make the first N requests return `500`.
- `MOCK_GATEWAY_RATE_LIMIT_AFTER`: return `429` after N requests.
- `MOCK_GATEWAY_RETRY_AFTER`: `Retry-After` seconds for `429` (default `60`).
- `MOCK_GATEWAY_LATENCY_MS`: response latency, capped at 120 seconds.
- `MOCK_GATEWAY_STATE`: state-file path; always use a disposable test path.
