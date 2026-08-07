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

- `RECOVERED` — retained recovered incidents (the history the hot paths must skip). 2M ≈ a busy year.
- `OPEN` — active/pending/suppressed working set.
- `DEVICES` — spreads rows across this many synthetic devices (for realistic per-device grouping).
- `BATCH` — insert batch size (lower it if you hit `max_allowed_packet`).

## 2. Measure

```bash
sudo -u librenms php artisan tinker --execute="require '/opt/iapm/interface-alert-policy-manager/tools/loadtest/loadtest.php';"
```

Prints each hot-path query's best-of-3 time and the index it used. **Pass criteria:**

- every timing is low-single- to low-double-digit **ms**, and
- no hot-path scan reports `NO-INDEX`; the chunk scans use a `state` index (not the PK),
  and "Overview recent 25" uses `iapm_incident_last_seen_idx`.

Add `RUN_COMMANDS=1` to also time the real `iapm:reconcile` / `iapm:process-actions`
end-to-end (these mutate the synthetic open incidents — re-seed to repeat):

```bash
sudo -u librenms env RUN_COMMANDS=1 php artisan tinker --execute="require '.../tools/loadtest/loadtest.php';"
```

Requires migrations `2026_08_06_000002` / `000003` to be applied (the scale indexes).
Compare timings before/after those migrations to see the difference.

## 3. Clean up

```bash
sudo -u librenms php artisan tinker --execute="require '/opt/iapm/interface-alert-policy-manager/tools/loadtest/cleanup.php';"
```
