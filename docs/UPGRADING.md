# Upgrading IAPM safely

## Before the upgrade

Stop IAPM scheduler and queue workers, retain the prior direct-notification rollback path, and back up every `iapm_*` table together with the active Laravel `APP_KEY` and any configured previous keys. Confirm the backup can be restored. Record `php artisan iapm:health` and the current package revision.

## Apply and verify

Install dependencies, run `php artisan migrate`, then run:

```sh
php artisan iapm:install-check
php artisan iapm:cache-rebuild
php artisan iapm:health
```

The production-safety migration is additive. It backfills an episode UUID on existing incidents and outage rows, makes each `(incident_id, episode_uuid)` outage unique, creates the encrypted durable notification outbox and its incident links, and adds delivery-log correlation fields. Start one worker, exercise one controlled dry-run trigger/recovery, then one controlled live delivery before restoring normal worker concurrency.

## Rollback

Prefer application rollback with the new tables left intact. Set dry-run, stop workers, restore the preceding package revision, and re-enable the old direct transport if needed. Do not run `migrate:rollback` while any outbox row is pending, queued, processing, or failed: rollback drops outbox data and episode correlation columns. A schema rollback is appropriate only after a verified backup, explicit data-loss approval, and confirmation that no live notification remains.

## Release notes

- Durable encrypted outbox with deterministic logical-notification idempotency.
- Per-episode lifecycle resets, outage uniqueness, and acknowledgement restoration.
- One receiver resolver shared by live actions, digests, readiness, and policy tests.
- Lossless digest fallback and confirmation only after successful/dry-run completion.
- Fail-closed queue health, logical-vs-attempt reporting, strict atomic imports, and indexed resolver/matrix paths.
- CI across PHP 8.2–8.4 plus LibreNMS 26.7.0 integration coverage.
