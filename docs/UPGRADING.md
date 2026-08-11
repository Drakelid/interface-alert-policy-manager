# Upgrading IAPM safely

## Before the upgrade

Stop IAPM scheduler and queue workers, retain the prior direct-notification rollback path, and back up every `iapm_*` table together with the active Laravel `APP_KEY` and any configured previous keys. Confirm the backup can be restored. Record `php artisan iapm:health` and the current package revision.

## Apply and verify

Install dependencies, run `php artisan migrate --force`, then run:

```sh
php artisan iapm:install-check
php artisan iapm:cache-rebuild
php artisan iapm:health
```

### Queue-worker health now uses a heartbeat (no schema change)

`iapm:health` used to infer queue-worker liveness from the timestamp
`SendNotificationJob` wrote, so an install with healthy workers, an empty queue
and no failed jobs reported `[FAIL] Queue worker delivering` after ten quiet
minutes. Liveness is now proven by a heartbeat job the scheduler enqueues every
minute and a worker must execute.

Nothing to migrate — the state lives in `iapm_settings`. Two things to be aware
of on upgrade:

- **Restart the workers.** They must load the code containing
  `QueueHeartbeatJob`, or every heartbeat fails to deserialise and the check
  stays red: `sudo systemctl restart 'iapm-worker@*'` (or your supervisor's
  equivalent). This is the one required action.
- **Expect up to a minute of red.** The first heartbeat is enqueued on the next
  scheduler tick. Before that the check reports "the first heartbeat has not been
  enqueued yet" and stays green; once enqueued and consumed it turns green for
  good.

`last_queue_worker_at` is no longer written. It is replaced by
`last_queue_heartbeat_at` (worker liveness) and `last_queue_delivery_at` (last
real notification). The stale row from the old key is harmless and is cleaned up
with the rest of the settings; nothing reads it.

New optional setting: `IAPM_QUEUE_HEARTBEAT_STALE_SECONDS` (default 300).

### Schema change in this release: `failed_poll_count` → `down_observations`

`iapm_policies.failed_poll_count` is renamed to `down_observations`. The field
never counted polls — reconciliation increments it once a minute while an
interface stays down — and the UI already called it "Down observations".

The migration is a straight `renameColumn`, guarded on both sides and reversible
with `down()`. `iapm_policies` holds tens of rows, so the lock is negligible. It
is **not** additive: rolling the plugin code back without also rolling this
migration back will break policy reads, so roll both back together or neither.

Export now emits `down_observations`. Import accepts either key, so
configuration documents exported before this release still import unchanged; if
a document carries both, the new key wins.

The remaining production-safety migrations are additive. A preflight migration adds episode columns and fills UUIDs in restartable 5,000-row set-based batches before the released v1.2.1 migration runs; already-upgraded installations treat it as a no-op. Later migrations add storm-path indexes and the encrypted durable ingestion inbox. On multi-million-row tables, verify free disk for a second index copy and test MariaDB's online-DDL behavior on a staging clone. Start one inbox worker and one queue worker, exercise a controlled dry-run trigger/recovery, then one controlled live delivery before restoring normal concurrency.

## Rollback

Prefer application rollback with the new tables left intact. Set dry-run, stop workers, restore the preceding package revision, and re-enable the old direct transport if needed. Do not run `migrate:rollback` while any outbox row is pending, queued, processing, or failed: rollback drops outbox data and episode correlation columns. A schema rollback is appropriate only after a verified backup, explicit data-loss approval, and confirmation that no live notification remains.

## Release notes

- Durable encrypted outbox with deterministic logical-notification idempotency.
- Encrypted durable ingestion inbox with 202-after-commit acceptance and explicit 503 backpressure.
- Per-episode lifecycle resets, outage uniqueness, and acknowledgement restoration.
- One receiver resolver shared by live actions, digests, readiness, and policy tests.
- Lossless digest fallback and confirmation only after successful/dry-run completion.
- Fail-closed queue health, logical-vs-attempt reporting, strict atomic imports, and indexed resolver/matrix paths.
- CI across PHP 8.2–8.4 plus LibreNMS 26.7.0 integration coverage.
