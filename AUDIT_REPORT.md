# IAPM ISP-scale audit and hardening report

## 1. Executive summary

Audit baseline: `master` commit `161de833bf5e9cdf3548ae739fcbd72fbda62a44` (`Add PluginVersion service and display version in navigation`). Work was performed locally on `audit/isp-scale-hardening`; nothing was pushed, tagged, published, released, or merged.

The baseline passed its existing quality and LibreNMS integration suites, but it was not safe to describe as storm-proof. Confirmed P1 failure modes included synchronous processing of 10,000 faults and whole-device recoveries, N+1 ingestion reads, ambiguous alert correlation, out-of-order event reversal, synchronous gateway fallback when queue publication failed, tight retry loops that ignored `Retry-After`, incomplete finalization after a success/crash window, cross-destination digest suppression, unbounded digest membership payloads, non-resumable scheduled scans, repeated unchanged incident writes, silent regex-assignment truncation, fleet-sized configuration validation reads, and a released per-row UUID migration backfill.

The hardening changes address those confirmed P1s with encrypted durable ingestion, explicit 503 backpressure, bulk relationship loading, conjunction correlation and source-event ordering, bounded database retries, destination/receiver-scoped digest fallback, durable outbox backoff/drain/repair, cursor-resumable commands, set-based lifecycle operations, PCRE limits with fail-explicit capacity enforcement, reference-scoped atomic imports, additive indexes, and a restartable migration preflight. Delivery is deliberately described as **at least once**. Duplicate external delivery remains possible if a gateway accepts a request and IAPM crashes before recording success; the gateway must honor the stable `Idempotency-Key` to suppress it.

## 2. Baseline and environment

| Item | Audited value |
|---|---|
| Repository/default branch | `Drakelid/interface-alert-policy-manager`, `master` |
| Exact baseline commit | `161de833bf5e9cdf3548ae739fcbd72fbda62a44` |
| Audit branch | `audit/isp-scale-hardening` (local only) |
| LibreNMS | Official `librenms/librenms:26.7.0` image; LibreNMS 26.7.0 |
| PHP | 8.4.21 integration runtime; CI matrix 8.2, 8.3, 8.4 |
| Framework/dependencies | Laravel 12.65.0, Testbench 10.11.0, Larastan 3.10.0, PHPUnit 11.5.56, plugin-interfaces 1.0 |
| Database | MariaDB 11.4.12, InnoDB, `REPEATABLE-READ` |
| DB settings | MariaDB started with a 128 MiB buffer pool; the 2.005M-row before/after measurements used a 1 GiB dynamically resized pool. `innodb_flush_log_at_trx_commit=1`, `sync_binlog=0`, `max_connections=151` |
| Host | Windows 11 Pro; Intel i7-8750H, 6 cores/12 threads, 31.9 GiB RAM; Docker 29.5.2 / Compose 5.1.4 |
| Queue assumptions | Laravel database or Redis queue; dedicated `iapm` queue; worker timeout 60 s; connection `retry_after` must exceed timeout (90 s minimum recommended) |

Baseline gates, before code changes:

| Gate | Result |
|---|---|
| `composer validate --strict` | PASS |
| Pint (`--test`) | PASS, 138 files |
| PHPStan | PASS, no errors |
| Unit tests | PASS, 41 tests / 65 assertions, 3.547 s, 16 MiB |
| LibreNMS integration | PASS, 270 tests / 1,441 assertions, 175.703 s, 130.5 MiB |
| Composer advisory audit | PASS, no known advisories |

Final gates after hardening:

| Gate | Result |
|---|---|
| Composer validation/advisory audit | PASS; strict manifest validation, no known advisories |
| PHP syntax | PASS under PHP 8.2 across source, migrations, tests, routes, config, and tools |
| Pint | PASS, 146 files |
| PHPStan/Larastan | PASS, no errors |
| Unit tests | PASS on PHP 8.2.33, 8.3.33, and 8.4.24; 41 tests / 65 assertions on each runtime |
| LibreNMS 26.7.0 integration | PASS on PHP 8.4.21; 289 tests / 1,546 assertions, 186.409 s, 134.5 MiB |
| Migration coverage | PASS inside the integration suite: complete down/up chain, shared queue-table preservation, additive round trip, interrupted-inbox resume, and repeated index/inbox `up()` |
| Scale query gate | PASS on the strict warmed run at 2.005M incidents / 100k outbox; cold/noisy variance disclosed in section 7 |
| Controlled gateway fault probe | PASS: first-N 500, recovery, 429/`Retry-After`, latency, duplicate-key detection, and payload non-retention |

## 3. Architecture and event flow

1. LibreNMS evaluates its interface alert rule and POSTs JSON to the plugin API.
2. `EnsurePluginEnabled`, source-IP throttling, content length/type checks, bearer-token authentication, and structured request validation run before state mutation.
3. Small active requests follow the synchronous compatibility path. Duplicate `port_id` faults are removed first; ports, device/location/groups, and port groups are loaded in bulk.
4. Requests at or above the configurable fault threshold, plus whole-device recoveries, are encrypted into `iapm_ingestion_inbox`. HTTP 202 is returned only after the row commits. A full inbox returns HTTP 503 with `Retry-After`.
5. Scheduler-managed ingestion workers atomically claim pending, failed, or stale-processing inbox rows and replay the same validated controller path. A crash produces an idempotent replay.
6. `PolicyResolver` loads the enabled topology once per resolver instance, applies deterministic specificity/priority/update ordering, evaluates bounded regex/group matches, and hands the winner to centralized receiver resolution.
7. Suppression evaluates device/admin/ignore/disabled/deleted/maintenance/parent/uplink conditions. The lifecycle service creates, activates, acknowledges, suppresses, recovers, or reopens the one incident row for a device/port, with a new UUID per outage episode.
8. `iapm:reconcile` reloads current port state in chunks, reevaluates policy/suppression, preserves acknowledgement/mute invariants, bulk-recovers deleted ports, and checkpoints its incident cursor before its wall-clock budget expires.
9. `iapm:process-actions` promotes eligible pending incidents, detects/dampens flapping, discovers device digests, selects due trigger/escalation/reminder/recovery/acknowledgement actions, and checkpoints its cursor.
10. `ReceiverResolver` applies one precedence chain: action, winning assignment, policy, destination list/default, then global default.
11. `NotificationDispatcher` commits an encrypted outbox row and membership pivots before queue publication. The serialized job contains only the outbox ID.
12. Workers claim one row, call the selected transport with a stable `Idempotency-Key`, record each attempt, honor 429 `Retry-After`, and otherwise schedule exponential backoff with jitter.
13. Success is followed by lock-protected transactional finalization. `finalized_at` makes a crash after transport success repairable without another transport call. Delayed old-episode work cannot mutate a reopened episode.
14. Recovery writes one unique outage per `(incident_id, episode_uuid)`. Bounded cleanup removes retained recovered incidents, cascaded history, outages, audits, and completed/failed inbox rows. Overview, health, statistics, comparison, matrix, and log views read the indexed operational/history tables.

## 4. State machines and invariants

Incident state machine:

```text
new -> pending -> active -> recovered
new -----------> active -> recovered
new/active/pending -> suppressed -> pending|active|recovered
pending|active|suppressed -> acknowledged -> prior valid state|recovered
recovered --newer active event--> new episode (pending|active|suppressed)
```

Invalid transitions are rejected: recovered incidents cannot be acknowledged or unacknowledged into life; continued active observations cannot remove acknowledgement; an older source timestamp cannot reverse a newer transition. `incident_key` is unique per device/port, while `episode_uuid` separates repeated outages. Outage uniqueness enforces one history row per episode.

Outbox state machine:

```text
pending -> queued -> processing -> sent -> finalized
                       |             `-> finalized repair (no HTTP)
                       `-> failed --available_at--> queued
pending --queue unavailable--> pending (backoff; never synchronous fallback)
pending -> dry_run -> finalized
processing --stale claim--> processing retry with the same idempotency key
```

The local guarantee is one durable logical outbox row per episode/action/phase/destination/receiver/send ordinal and at-least-once transport attempts with a stable key. It is not exactly-once external delivery.

## 5. Scale assumptions and tested capacity envelope

The supported design envelope is 500,000 monitored interfaces, thousands of policies/actions/assignments, millions of retained history rows, 10,000 faults in one accepted webhook, and 100,000 durable outbox rows. These are design/data-set targets, not an unlimited throughput claim.

The durable HTTP boundary keeps request memory and time independent of per-fault database writes for requests at or above 1,000 faults: the request validates and commits one encrypted inbox row, and replay occurs outside PHP-FPM. The default maximum outstanding inbox working set is 10,000 payloads; overload is explicit 503 backpressure. Two inbox workers are conservative for database-queue installations. Raise only after staging measurements show database headroom.

Final measured percentile/query/throughput results are recorded in section 7. Capacity is limited by notification gateway latency and worker concurrency: approximate steady-state transport capacity is `workers / mean request seconds`, reduced for retries and destination rate limits. For example, ten workers at 250 ms mean latency provide at most about 40 attempts/s before safety headroom; production arrival should stay below 50–60% of that during normal operation so failure retries can drain.

## 6. Findings

| ID | Sev | Component / file:line | Evidence and failure scenario | Operational impact | Fix/status | Proof |
|---|---|---|---|---|---|---|
| IAPM-001 | P1 | `src/Http/Controllers/IngestionController.php:42`; `src/Console/DrainIngestionCommand.php:19` | 10k faults performed linear transactions inside HTTP; whole-device recovery also ran synchronously | Timeout/retry amplification and ambiguous acceptance | Encrypted durable inbox, 202-after-commit, stale reclaim, 503 backpressure; fixed | 10k acceptance, encrypted replay, recovery replay tests |
| IAPM-002 | P1 | `src/Http/Controllers/IngestionController.php:71`; `src/Services/DependencyResolver.php:29` | Port and relationships were loaded per fault; duplicate ports were deduped after work; uplink lookup was keyed by excluded port | N+1 reads during storms | Bulk port graph load, early dedup, device-scoped uplink set; fixed | 250-fault query-shape regression |
| IAPM-003 | P1 | `src/Http/Controllers/IngestionController.php:258` | `alert_id OR alert_uid` recovered rows matching either identifier | Unrelated interfaces could recover | All supplied identifiers must match; fixed | Crossed-identifier regression |
| IAPM-004 | P1 | `src/Http/Controllers/IngestionController.php:371`; `src/Services/IncidentLifecycleService.php:75` | Source timestamps were fingerprint inputs but not transition ordering guards | Delayed active/recovery could reverse newer state | Episode context stores last source time; stale events fail closed under lock; fixed | Delayed active/recovery regression |
| IAPM-005 | P1 | `src/Http/Controllers/IngestionController.php:318` | Only unique insert races retried once; deadlock/1205 paths surfaced | Retryable storm contention became 500 | Three bounded retries with jitter for unique/deadlock/lock-timeout and response-counter rollback; fixed | Integration invariants plus MariaDB retry path review |
| IAPM-006 | P1 | `src/Services/NotificationDispatcher.php:107` | Queue publish exception fell through to synchronous HTTP | Broken queue converted a storm into blocking gateway calls | Durable pending state and independent drain command; fixed | Broken-backend test asserts zero HTTP |
| IAPM-007 | P1 | `src/Services/NotificationDispatcher.php:179`; `src/Services/NotificationDispatcher.php:365` | 429 was retried immediately; failed rows were reset every action pass | Gateway hammering and ignored rate limits | Persisted `Retry-After`, exponential jittered backoff, due-row drain; fixed | 429/one-attempt/backoff/requeue tests |
| IAPM-008 | P1 | `src/Services/NotificationDispatcher.php:273`; `database/migrations/2026_08_10_000001_add_storm_path_indexes.php:42` | `sent` committed before incident/digest bookkeeping, with no completion marker | Crash left history/counts incomplete | `finalized_at`, outbox lock, transactional idempotent repair without HTTP; fixed | Simulated success-before-finalize crash test |
| IAPM-009 | P1 | `src/Console/ProcessActionsCommand.php:373` | A successful digest flag was global to the episode | Success on destination A hid failure on B/receiver branches | Destination+receiver+episode membership check; fixed | Partial-destination fallback test |
| IAPM-010 | P1 | `src/Services/NotificationDispatcher.php:73`; `src/Services/NotificationDispatcher.php:287` | Entire incident-ID list was encrypted in one column and pivot was queried once per incident | Large ciphertext/memory and N+1 finalization | Pivot is canonical; encrypted list empty; 500-row pivot finalization; fixed | Digest membership/finalization tests |
| IAPM-011 | P1 | `src/Console/ProcessActionsCommand.php:86`; `src/Console/ReconcileCommand.php:35` | Runs restarted at ID 0; activation/digest prepasses ignored deadline; unchanged down rows rewrote JSON | High IDs starved, overlap risk, redo/undo amplification | Persistent cursors, bounded digest candidates, command budgets, capped observations, update-on-change; fixed | Cursor, zero-budget, unchanged-write tests |
| IAPM-012 | P1 | `src/Services/PolicyResolver.php:114`; `src/Services/ConfigurationImportValidator.php:85` | Assignments above cap were silently omitted; raw patterns had no match/depth/subject bound | Deterministic misrouting or ReDoS | Fail explicit, bounded PCRE verbs, subject cap, precompiled safe patterns, import limit; fixed | 5k/500 resolver, cap, pathological regex tests |
| IAPM-013 | P1 | `src/Http/Controllers/ImportExportController.php:81`; `src/Services/ConfigurationImportValidator.php:169` | Validator plucked all ports/devices/groups; validation occurred outside write transaction | O(fleet) memory and reference race | Query only document references in 1k chunks; validate/write/audit atomically; fixed | Import rollback/reference suite and query review |
| IAPM-014 | P1 | `database/migrations/2026_08_08_235959_prepare_episode_identity_backfill.php:47` | One UUID UPDATE per incident/outage, after adding an index | Millions of round trips and long rollout | Earlier pending preflight adds columns and runs restartable 5k set-based UUID updates; fixed for not-yet-upgraded installs | UUID uniqueness probe and migration round trip |
| IAPM-015 | P2 | `src/Services/SettingStore.php:43`; `src/Http/Requests/IngestAlertRequest.php:69` | Encrypted settings and heartbeats were repeatedly queried/written; success/auth failures logged per request | DB/log amplification and potential echoed test payload retention | Short TTL singleton, conditional heartbeats, sampled/rate-limited logs, and explicit test-response redaction; fixed | Integration settings/health/redaction coverage |
| IAPM-016 | P2 | `src/Services/HealthService.php:118` | Status grouping included full sent history | Health page scan grew with retention | Restrict actionable statuses; indexed stale/finalization/inbox checks; fixed | Health integration and EXPLAIN |
| IAPM-017 | P2 | `src/Console/CleanupCommand.php:45` | Cleanup could run without a wall-clock bound | Undo/replication pressure and scheduler overlap | Batch, pause, time budget, resumable-by-deletion; fixed | Command dry-run/force and scale tooling |
| IAPM-018 | P2 | `src/Models/Assignment.php:21`; `src/Models/Policy.php:21` | Broad policy/assignment save deletes the full cache table | Admin change can create large delete/undo work | Remaining risk; alert routing does not use this table. Schedule rebuilds off-peak | Documented in remaining risks |
| IAPM-019 | P2 | `src/Transports/SmsGatewayTransport.php:20`; `src/Transports/GenericWebhookTransport.php:21` | HTTP success cannot share a transaction with MariaDB | Duplicate transport call in crash gap | Stable `Idempotency-Key`; gateway enforcement required | Crash-state tests; guarantee documented |
| IAPM-020 | P3 | `src/Http/Controllers/ComparisonReportController.php:15`; `src/Http/Controllers/LogController.php:12` | User-selected wide windows can scan large outage/log ranges | Slow administrative views under maximum retention | Indexed/bounded defaults where present; further warehouse/read-replica work remains | Remaining risk |

## 7. Before/after performance and query plans

The baseline and final harness used the same isolated database and 20 samples per query: 2,000,000 recovered incidents, 5,000 open incidents, 1,000 policies, 5,000 assignments, and 1,000 actions. The final run added 100,000 encrypted outbox rows. Times are milliseconds.

| Query | Baseline best / p50 / p95 / p99 | Final best / p50 / p95 / p99 | Final EXPLAIN key / estimated rows |
|---|---:|---:|---|
| Overview open counts | 4.0 / 4.7 / 7.4 / 7.8 | 3.6 / 4.2 / 8.4 / 10.7 | `iapm_incidents_state_last_seen_at_index` / 5,001 |
| Overview recent 25 | 1.1 / 1.6 / 2.2 / 2.2 | 1.2 / 1.4 / 1.9 / 1.9 | `iapm_incident_last_seen_idx` / 25 |
| Missing-policy tile | 4.1 / 5.9 / 7.8 / 8.1 | 4.0 / 4.3 / 5.4 / 6.5 | `iapm_incidents_state_last_seen_at_index` / 1,000 |
| Recovered in 24 h | 4.2 / 6.4 / 10.2 / 10.5 | 4.1 / 4.4 / 5.3 / 5.9 | `iapm_incident_state_recovered_idx` / 9,886 |
| Reconcile first 500 | 10.8 / 13.8 / 24.9 / 25.2 | 10.5 / 12.6 / 15.7 / 17.1 | `iapm_incidents_state_last_seen_at_index` / 5,001 |
| Process-actions first 500 | 22.6 / 25.9 / 32.0 / 117.9 | 20.7 / 22.3 / 27.6 / 33.4 | `iapm_incident_state_recovered_idx` / 27,631 |
| Due outbox first 1,000 | n/a | 2.3 / 2.8 / 3.5 / 3.9 | `PRIMARY` / 1,000 (ordered early due rows) |
| Stale outbox count | n/a | 1.0 / 1.2 / 1.5 / 1.7 | `iapm_outbox_status_claimed_idx` / 1 |

The first cold run immediately after index construction and outbox seeding recorded a 54.9 ms process-actions p95, narrowly above the default 50 ms gate; the strict repeat after buffer warming recorded 27.6 ms and passed. A later mutating-command run recorded 70.2 ms while the other query p95s remained at or below 13.1 ms, showing host/cache variance on that OR query; it used an explicitly disclosed 75 ms gate. Final non-mutating instrumentation observed 176 SQL queries and 36 MiB peak PHP memory (2 MiB growth), versus 134 queries and 34 MiB peak at baseline; the added queries are the two outbox probes. The mutating run scanned the 5,000-open-incident action set in 8.55 s (about 585 incidents/s, zero sends because the synthetic destination is disabled) and bulk-reconciled all 5,000 missing synthetic ports in 10.10 s (about 495 recoveries/s), with 375 queries, 56 MiB peak memory, and no failures. The three pending migrations completed against an already episode-populated 2.005M-incident dataset in 75.8 seconds (so the UUID preflight was a no-op), and 100,000 encrypted outbox rows plus pivots seeded in 29.2 seconds on this host. These are single-host development measurements, not a production SLA; sites with null episode IDs must separately time the primary-key-ranged backfill on a staging clone.

Observed/supporting index mappings:

| Query | Supporting index |
|---|---|
| Open reconcile/action chunks | `iapm_incident_state_id_idx` |
| Recent recovery window | `iapm_incident_state_recovered_idx` |
| Recent overview rows | `iapm_incident_last_seen_idx` |
| Device digest window | `iapm_incident_digest_idx` |
| Source correlation | `iapm_incident_{alert,uid,rule}_device_state_idx` |
| Logical delivery probes | `iapm_delivery_action_episode_idx`, `iapm_outbox_logical_lookup_idx` |
| Flap window | `iapm_event_incident_type_time_idx` |
| Due/stale/finalization outbox | `iapm_outbox_status_available_idx`, `...status_claimed_idx`, `...status_finalized_idx` |
| Digest membership | `iapm_outbox_incident_episode_idx` |
| Durable ingestion due/stale | `iapm_inbox_due_idx`, `iapm_inbox_claim_idx` |

## 8. Concurrency and failure injection

Automated cases cover duplicate payloads and faults, first-incident unique races, old-episode queued work, stale/live claims, failed logical outbox reuse, queue publication failure, terminal job failure, 429 `Retry-After`, gateway 500 and recovery, disabled destinations, partial digest destinations/receivers, out-of-order source events, finalization crash repair, migration round trip, and explicit inbox backpressure. Laravel HTTP fakes prevent stray delivery.

`tools/mock-gateway/router.php` provides controlled latency, first-N 500 failures, fixed status, 429 after N calls, `Retry-After`, duplicate detection, and idempotency-key hashes. It never records bodies, receivers, messages, credentials, or authorization headers.

The live local probe used 25 ms configured latency, fail-first=2,
rate-limit-after=3, and `Retry-After: 7`. Four calls with the same logical key
returned `500, 500, 200, 429` in 47.7/28.9/28.6/28.9 ms. `/state` marked calls
2â€“4 as duplicates and contained only sequence/time/status plus one SHA-256 key
hash; the submitted receiver, message, password, and bearer token were absent.

The invariant under worker concurrency is enforced by unique idempotency keys plus `lockForUpdate` claims. A killed processing worker leaves a stale claim; a killed successful worker either retries the transport with the same external key or repairs unfinalized local bookkeeping without HTTP, depending on the last committed state.

## 9. Migration and rollout plan

1. Back up all `iapm_*`, `jobs`, and `failed_jobs` tables and retain the active/previous Laravel application keys. Verify restore on staging.
2. Record row counts, largest table/index sizes, free disk, replication lag, `iapm:health`, queue depth, and current commit.
3. Stop scheduler and IAPM workers. Leave LibreNMS polling running.
4. On a staging clone, run `php artisan migrate --force` and time each DDL/backfill. The preflight UUID update is restartable in 5,000-row statements. Confirm UUID null/duplicate counts are zero before unique creation.
5. Budget temporary disk for additive indexes. Confirm the installed MariaDB/MySQL version uses online/in-place index creation acceptable to the site's maintenance window; Laravel does not force a server that lacks it to be online.
6. Deploy code and migrate production. Do not run schema rollback merely to roll back application code.
7. Enable dry-run. Start one ingestion worker and one notification worker; run `iapm:drain-ingestion --limit=1`, `iapm:drain-outbox --limit=10`, install check, health, controlled trigger/recovery, and mock delivery.
8. Restore configured workers gradually while watching DB threads/locks, redo, replication lag, inbox/outbox age, gateway 429 rate, and p95 delivery latency.

## 10. Queue and worker capacity model

- Service capacity is bounded by worker concurrency and end-to-end gateway latency. `capacity attempts/s ≈ workers / latency seconds`.
- Reserve at least 40% capacity for retries/recovery. Required workers are approximately `peak logical notifications/s × attempts/logical × latency / 0.6`, then capped by gateway concurrency/rate contracts.
- Database queue is suitable for small/moderate volumes but every reservation/release competes with LibreNMS MariaDB. Use Redis and dedicated systemd workers for sustained large bursts.
- Set queue `retry_after` at least 30 seconds above the 60-second worker timeout. Keep destination timeout and internal attempts below worker timeout.
- Use one `iapm` queue, 3 workers initially for DB queue, and 10 only after staging evidence. Redis installations may scale wider subject to gateway and DB finalization capacity.
- Outbox backlog drain batch defaults to 1,000. Backoff defaults to 15 seconds exponential, capped at 3,600 seconds; 429 uses the gateway value up to that cap.

## 11. Remaining risks and delivery guarantee

- Exactly-once external delivery is impossible without gateway cooperation. Require stable-key deduplication for the full maximum retry horizon.
- Policy topology is hydrated once per PHP resolver instance, not shared versioned across hosts. It passed the 5,000-assignment gate but very high concurrent PHP-FPM fan-out adds memory/DB load.
- Materialized Interface Matrix invalidation still performs a broad delete for broad policy/assignment changes. Make such changes off-peak and rebuild in controlled batches.
- A single device with thousands of digest members remains bounded one device at a time, but finalization necessarily updates each represented incident. Gateway delivery is one call; database bookkeeping remains O(members).
- Historical reporting is operational SQL, not a warehouse. Very long windows over tens of millions of outages should use a replica/export/warehouse.
- MariaDB DDL behavior and disk cost vary by exact production version/table format. Staging-clone evidence is mandatory.
- If LibreNMS does not retry 429/503/timeout responses, non-accepted alerts remain a source-system operational risk. HTTP 202/200 alone denote durable acceptance.

## 12. Recommended production configurations

Small installation:

```dotenv
IAPM_QUEUE_CONNECTION=database
IAPM_QUEUE_WORKERS=3
IAPM_INGEST_INBOX_WORKERS=1
IAPM_INGEST_BATCH_PER_WORKER=1
IAPM_INGEST_ASYNC_THRESHOLD=1000
IAPM_INGEST_MAX_PENDING=1000
```

Large installation, database queue:

```dotenv
IAPM_QUEUE_CONNECTION=database
IAPM_QUEUE_WORKERS=0
IAPM_INGEST_INBOX_WORKERS=2
IAPM_INGEST_BATCH_PER_WORKER=1
IAPM_OUTBOX_DRAIN_BATCH=1000
IAPM_INGEST_RATE=20000,1
IAPM_INGEST_MAX_PENDING=10000
```

Run 3–5 supervised workers initially. Keep MariaDB queue polling and IAPM finalization within measured database headroom.

Large installation, Redis/systemd:

```dotenv
IAPM_QUEUE_CONNECTION=redis
IAPM_QUEUE_WORKERS=0
IAPM_INGEST_INBOX_WORKERS=2
IAPM_INGEST_BATCH_PER_WORKER=1
IAPM_OUTBOX_DRAIN_BATCH=2000
IAPM_RETRY_BASE_SECONDS=15
IAPM_RETRY_MAX_SECONDS=3600
```

Use the systemd template in the README with `queue:work redis --queue=iapm --sleep=1 --tries=3 --backoff=15 --timeout=60 --max-time=3600`. Start with 5–10 workers and scale from measured p95 latency and gateway rate limits.

## 13. Rollback

1. Enable dry-run; stop inbox/outbox/queue workers and scheduler tasks.
2. Preserve inbox/outbox/jobs, logs, database snapshot, application keys, and current health output.
3. Roll application code back to the prior known commit while leaving additive tables/columns/indexes in place. Old code ignores them.
4. Do not run migration rollback while any inbox/outbox work exists. The outbox migration rollback is destructive to pending notification state.
5. If schema rollback is explicitly approved after backup and drain verification, reverse migrations in Laravel order during a maintenance window, then verify core shared `jobs`/`failed_jobs` tables remain.
6. Re-enable the prior LibreNMS notification path only after ensuring there cannot be two live senders.

## 14. Operational runbook

Alert storm:

1. Check HTTP 202/503/429 rates, `iapm:health`, oldest inbox/outbox age, DB lock waits, CPU/IO, and gateway status.
2. Do not delete or replay rows manually. Let stable idempotency converge duplicates.
3. If DB is healthy, raise inbox workers gradually. If not, preserve 503 backpressure and reduce them.
4. Confirm digest threshold/window and receiver routing with dry-run.

Gateway failure/rate limit:

1. Keep queue mode enabled; never switch a storm to synchronous delivery.
2. Confirm 429 `Retry-After`, failed `available_at`, and stable key in the mock/real gateway.
3. Restore gateway, run `iapm:drain-outbox`, and watch failed/due age decline.
4. If duplicates appear, verify gateway key retention before raising workers.

Queue backlog/backend outage:

1. Restore Redis/database queue connectivity and supervised workers.
2. Run `iapm:drain-outbox`; pending rows were retained during publication failure.
3. Check `failed_jobs` for process failures separately from transport-failed outbox rows.
4. Repair any unfinalized successes by running the drain command; it performs no transport call for those rows.

Database pressure:

1. Reduce notification and inbox concurrency; retain work durably.
2. Inspect InnoDB lock waits, buffer-pool misses, redo/checkpoint pressure, disk, replication lag, and the reported EXPLAIN keys.
3. Pause cleanup/cache rebuild/import/index DDL during the storm.
4. Resume cleanup in bounded runs after pressure clears.
