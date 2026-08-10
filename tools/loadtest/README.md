# IAPM load-test kit

Standalone scripts (run via `artisan tinker`, **not** registered commands — they can't be
invoked accidentally in production) to verify IAPM stays fast at 500k+ interface scale.

They populate `iapm_incidents` with a year of synthetic history plus an open working set,
then time the queries that run every minute (reconcile/process-actions) and on every
Overview load. Everything is tagged `incident_key = loadtest:*` in a synthetic id space
(`>= 900,000,000`) so it can't touch real data and is removed exactly by `cleanup.php`.

> Run on a **test database**. Seeding 2M rows writes real data and load.

## 1. Seed

```bash
sudo -u librenms env RECOVERED=2000000 OPEN=5000 DEVICES=20000 \
  php artisan tinker --execute="require '/opt/iapm/interface-alert-policy-manager/tools/loadtest/seed.php';"
```

For a bind-mounted development checkout, `run.php` boots LibreNMS without Tinker:

```bash
LIBRENMS_ROOT=/opt/librenms RECOVERED=2000000 OPEN=5000 DEVICES=20000 \
  php tools/loadtest/run.php seed
LIBRENMS_ROOT=/opt/librenms RUN_COMMANDS=1 php tools/loadtest/run.php measure
LIBRENMS_ROOT=/opt/librenms php tools/loadtest/run.php cleanup
```

For an isolated schema clone already current through the released v1.2.1
migrations, apply the pending audit migrations with
`php tools/loadtest/run.php migrate`. Normal installations use
`php artisan migrate --force`.

- `RECOVERED` — retained recovered incidents (the history the hot paths must skip). 2M ≈ a busy year.
- `OPEN` — active/pending/suppressed working set.
- `DEVICES` — spreads rows across this many synthetic devices (for realistic per-device grouping).
- `BATCH` — insert batch size (default 2,000; capped at 4,000 to remain below MariaDB's prepared-statement placeholder limit).
- `POLICIES`, `ASSIGNMENTS`, `ACTIONS` — indexed resolver topology (defaults: 1,000 / 5,000 / 1,000).
- `OUTBOX` — optional durable outbox backlog; use `100000` for the storm gate. Rows contain only encrypted synthetic fixtures and an unreachable loopback destination.
- `SEQUENCE_START` — offsets synthetic incident keys when intentionally appending a second batch.

## 2. Measure

```bash
sudo -u librenms php artisan tinker --execute="require '/opt/iapm/interface-alert-policy-manager/tools/loadtest/loadtest.php';"
```

Prints the seeded scale, policy/assignment/action counts, each hot-path query's best,
p50, p95, and p99 latency (20 samples by default) and chosen index, a resolver/matrix batch timing, total SQL query count, and peak
memory. **Pass criteria:**

- every timing is low-single- to low-double-digit **ms**, and
- no hot-path scan reports `NO-INDEX`; the chunk scans use a `state` index (not the PK),
  and "Overview recent 25" uses `iapm_incident_last_seen_idx`; and
- resolver query growth remains bounded as `RESOLVER_PORTS` increases (default 500).

The harness fails non-zero when a hot query exceeds 50 ms, an indexed plan reports
`NO-INDEX`, the 500-port resolver/matrix batch exceeds 2,000 ms or 25 queries, or peak
memory exceeds 256 MiB. Override these hardware-sensitive gates with
`HOT_QUERY_LIMIT_MS`, `HOT_QUERY_P95_LIMIT_MS`, `RESOLVER_LIMIT_MS`, `RESOLVER_QUERY_LIMIT`, `QUERY_RUNS`, and
`PEAK_MEMORY_LIMIT_MIB`, and retain both the defaults and overrides with benchmark
results.

Add `RUN_COMMANDS=1` to also time the real `iapm:reconcile` / `iapm:process-actions`
end-to-end (these mutate the synthetic open incidents — re-seed to repeat):

```bash
sudo -u librenms env RUN_COMMANDS=1 php artisan tinker --execute="require '.../tools/loadtest/loadtest.php';"
```

Requires migrations `2026_08_06_000002` / `000003` to be applied (the scale indexes).
Compare timings before/after those migrations to see the difference.

The harness intentionally does not fabricate LibreNMS core device/port rows. Run it on
a staging clone with representative core inventory to obtain resolver and Interface
Matrix numbers; otherwise that measurement is explicitly reported as skipped. The
integration suite covers authenticated multi-fault ingestion and action correctness,
while this harness supplies fleet-scale SQL, command, query-count, and memory evidence.

## 3. Clean up

```bash
sudo -u librenms php artisan tinker --execute="require '/opt/iapm/interface-alert-policy-manager/tools/loadtest/cleanup.php';"
```
