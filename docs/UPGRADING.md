# Upgrading IAPM safely

## Before the upgrade

Stop IAPM scheduler and queue workers, retain the prior direct-notification rollback path, and back up every `iapm_*` table together with the active Laravel `APP_KEY` and any configured previous keys. Confirm the backup can be restored. Record `php artisan iapm:health` and the current package revision.

`tools/update-production.sh` temporarily puts the whole LibreNMS application into Laravel maintenance mode. It stops active `librenms-scheduler.timer` and `iapm-worker@*.service` units, signals queue workers to finish gracefully, and waits for application scheduler, queue, and IAPM processes to exit before replacing code or migrating. The default drain deadline is 180 seconds (`IAPM_UPDATE_DRAIN_SECONDS`). Custom supervisors must be stopped first or named as space-separated systemd units in `IAPM_UPDATE_EXTRA_UNITS`; a supervisor that keeps restarting a process makes the update abort before replacement. All application workers sharing the LibreNMS code must drain, including workers on other queue names. Do not launch manual scheduler or IAPM commands during the maintenance window.

The updater preserves existing maintenance mode and restarts only units that were active beforehand. An abort before replacement restores the previous runtime state. A failure after Composer replacement starts leaves maintenance mode and stopped units in place: complete the update/migrations or restore verified package and database backups before manually resuming the recorded units. Run `php artisan up` only if the updater originally entered maintenance mode. Bootstrap caches are cleared with `optimize:clear --except=cache`, preserving overlap locks and restart signals.

## Apply and verify

Install dependencies, run `php artisan migrate --force`, then run:

```sh
php artisan iapm:install-check
php artisan iapm:cache-rebuild
php artisan iapm:health
```

### Delivery and configuration audit fixes

The October 2026 fixes add two migrations: legacy delivery-history correlation and an indexed `recovery_action_due_at` marker. Apply both before restarting IAPM commands or workers. Recovery delays and repeats remain discoverable after 48 hours without scanning all historical incidents.

Completed deliveries from before the outbox retain their send and repeat budgets. When old redacted logs or digest flags cannot identify the original recipient, their completed occurrence conservatively counts toward each current receiver's budget. A new outage episode starts a new budget. Inspect the incident's delivery history before forcing any resend.

Switching delivery from queue to sync now drains existing queued and retryable rows through the same atomic claim path. Valid `Retry-After` values on HTTP 429 and 503 postpone delivery for the requested duration, even beyond the ordinary exponential backoff cap. Device digest activation uses bounded, persistent cohorts so large pending sets do not spill into individual trigger messages.

Run migrations before restarting workers. The additive outbox migration adds queue publication timestamps, claim tokens, and action/force metadata. Existing rows remain readable; existing action-backed rows stop if their action has been deleted. No prior migration is changed.

Workers now recheck plugin enablement, dry-run, mute, policy, action, destination, phase and outage episode before each HTTP attempt. Enabling dry-run pauses previously queued live work; it resumes after dry-run is disabled. Newly discovered dry-run actions continue to record simulated delivery. Inapplicable work becomes `cancelled`; paused work remains `pending` with a retry time.

The outbox drainer recovers expired processing claims and abandoned queue publications (five minutes by default). It also prevents failed device digests from retrying after individual fallback takes over. Repeats and send limits apply independently to each receiver, and digest members must have an enabled trigger action whose delay has elapsed.

Inbox workers now replay each device's observations in acceptance order, including retry backoff, while other devices continue independently. Later observations for a device with pending, processing or failed inbox work also return HTTP 202, even when the payload is below the asynchronous size threshold. Inline processing resumes after that device's backlog clears. A second additive migration indexes that ordering check. Exhausted inbox rows still become terminal so later observations can progress.

Rebuild the policy cache after upgrading. Routing revisions now keep filters stale after policy/default/assignment changes and reject a rebuild that overlaps a routing change. Imports preserve repeated actions and assignment matching modes while enforcing authoritative destination/parent IDs.

Real simulations now serialize port updates with ingestion and recovery. Failed simulations are terminal history and cannot restore a port again. Recovery and overlay maintenance stop when a newer simulation, a different outage episode, or a physical alert takes ownership of that interface. This prevents an old simulation from clearing current monitoring state.

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

### Scheduler-managed workers now recycle every few minutes

Only affects installs where the scheduler owns the workers (`IAPM_QUEUE_WORKERS`
greater than 0); externally supervised workers are unchanged.

Workers used to run with `--max-time=3600` behind a 70-minute overlap lock. A
worker that died without releasing that lock — OOM kill, container stop, any
`SIGKILL` — was not replaced until the lock expired, so a single kill could stop
all IAPM delivery for over an hour. With the heartbeat above, `iapm:health` now
reports that outage correctly, but it still had to be waited out.

Workers now exit after `IAPM_QUEUE_WORKER_MAX_SECONDS` (default 240) with the
lock sized just above that, so a killed worker is replaced within about 7
minutes. Lifetimes are staggered per worker so they never all exit on the same
tick. Nothing to do on upgrade; expect worker PIDs to change every few minutes,
and treat that as normal. Raising the value lengthens the unreplaced-worker
window by roughly the same amount.

Do not run `php artisan schedule:clear-cache` while IAPM workers are running —
see "Duplicate queue workers" in [OPERATIONS.md](OPERATIONS.md#duplicate-queue-workers).

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
