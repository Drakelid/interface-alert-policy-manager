# IAPM Operations

## Browser access and restricted operators

IAPM permissions apply together with LibreNMS inventory permissions. `view iapm` grants entry to the plugin; core device, static device-group and individual port grants determine the interfaces, incidents, statistics and exports an operator can access. Itemized access also requires the corresponding core read permission (`device.view` or `port.view`), or LibreNMS's built-in `user` role. Dynamic device-group grants follow LibreNMS's `permission.device_group.allow_dynamic` setting. Individual port access includes the parent device's label, while sibling ports remain hidden. Accessing an unavailable incident or interface returns HTTP 404, and a bulk request containing an inaccessible item is rejected before any change.

Shared policy, assignment, destination and settings changes, configuration import/export, cache administration and the audit log require unrestricted core `device.viewAll` and `port.viewAll` permissions, in addition to the relevant IAPM ability. LibreNMS administrators retain access. Restricted operators can still read policy/destination lists and settings, use permitted incident actions and simulations, and manage individual visible ports through Interface Matrix bulk actions when granted the corresponding IAPM ability. Imports containing assignments also require `manage iapm assignments`; `manage iapm policies` alone is insufficient. An HTTP 403 on a shared configuration operation can therefore indicate missing core inventory permissions even when the IAPM management permission is present.

These restrictions apply to browser operations. Machine ingestion and scheduled processing continue to evaluate the complete configured inventory.

## CSV exports

Interface Matrix, Comparison Report, Delivery Log and Audit Log exports prefix text that could start a spreadsheet formula with a single quote. This includes formula markers after whitespace and control characters. The quote is intentional: spreadsheets should treat such values as text. Consumers importing CSV programmatically may see the added quote; retain it when opening the data in a spreadsheet.

## Gateway unavailable or failed deliveries

Keep incidents active, enable dry-run if failures are prolonged, inspect redacted delivery status, verify DNS/routing/TLS from the LibreNMS host, and use `iapm:test-destination` with a controlled receiver. Do not paste credentials into logs or tickets.

## Queue crash or stuck outbox

Run `php artisan iapm:health` and inspect pending, in-flight, stale, failed, and overdue outbox counts. Restart the normal queue worker, then run `php artisan iapm:drain-outbox`; stale `processing` rows become claimable after the configured worker timeout plus its safety margin, so do not duplicate or delete them. Failed rows retain the same idempotency key and become due after `Retry-After` or exponential backoff. Queue publication failure never falls back to synchronous HTTP. If payload decryption fails after an application-key rotation, stop workers and restore the previous key before retrying.

IAPM sends the logical key as `Idempotency-Key` on every retry. Configure gateways to honor that header: no local transaction can atomically cover an external HTTP delivery and the later database commit, so a worker or database crash in that narrow interval can otherwise repeat a transport call. The outbox still prevents concurrent local duplicates and preserves the same key for safe gateway-side deduplication.

## Ingestion storm or inbox backlog

Large active payloads and all whole-device recoveries return HTTP 202 only after their encrypted inbox row commits. Check `iapm:health`, then run `php artisan iapm:drain-ingestion --limit=1` under the LibreNMS user for controlled replay. Pending/failed/processing rows must not be deleted during an incident. HTTP 503 with `Retry-After: 60` means `IAPM_INGEST_MAX_PENDING` was reached: restore database capacity or inbox workers, confirm the backlog is shrinking, then let LibreNMS retry. Duplicate payload retries converge on one inbox idempotency key. `IAPM_INGEST_BATCH_PER_WORKER` raises the number claimed by each scheduled worker pass (maximum 100); increase it only after measuring database headroom because one row may contain 10,000 faults.

Successful ingestion logs are sampled and heartbeat writes are throttled. Authentication and validation rejection logs are rate-limited per source IP; use web-server/firewall counters for volumetric abuse.

## Duplicate queue workers

Applies to scheduler-managed workers (`IAPM_QUEUE_WORKERS` greater than 0).

`php artisan schedule:clear-cache` releases every scheduled task's overlap lock,
including those held by IAPM workers that are still running. The scheduler then
sees a free slot on the next tick and starts a second worker under the same
`--name`. That state does not heal on its own: the lock is keyed by the command
string, not by the process, so whichever duplicate exits first releases the slot
for both and a replacement starts again. The steady state is two workers per
name indefinitely.

This is not harmful — the queue backend locks each job row, so no notification is
delivered twice — but it runs more concurrent deliveries than configured, which
can exceed what the SMS gateway accepts.

Check with:

```bash
pgrep -af '[q]ueue:work.*--queue=iapm' | wc -l # should equal IAPM_QUEUE_WORKERS
```

To recover, stop every worker and let the scheduler rebuild the set:

```bash
pkill -f 'queue:work --queue=iapm'
php artisan schedule:clear-cache
```

Workers reappear over the next few minutes as each staggered slot comes due.

The reason to reach for `schedule:clear-cache` at all is a worker that died
without releasing its lock, which `iapm:health` now reports. Since v1.4.1 that
clears itself within minutes, so prefer waiting over clearing the cache — and if
you do clear it, stop the workers in the same step as above.

`php artisan optimize:clear` also flushes the application cache and can remove the same overlap locks. Use `php artisan optimize:clear --except=cache` when refreshing bootstrap files. The production updater uses that form and drains runtime processes before Composer or migration work; see [UPGRADING.md](UPGRADING.md#before-the-upgrade) for custom-supervisor and failure-recovery instructions.

## Failed or superseded real simulations

A failed simulation is retained for diagnosis and cannot restore the interface again. If another alert source or outage episode takes over the port, simulation recovery and maintenance leave that current state intact and stop the simulation. Inspect the current LibreNMS port and incident before starting a new test. Continue to use isolated test interfaces: physical polling and simulation overlays share the LibreNMS port record.

## LibreNMS alert operations (26.x)

LibreNMS 26.x resolves transports through **alert operations**, not through `alert_transport_map`. A rule whose `alert_operation_id` is `NULL` is muted before any transport runs, so IAPM receives nothing and its ingestion heartbeat never advances. The signature in `php /opt/librenms/alerts.php` output is:

```
RunAlerts():
Muted Alert-UID #1
```

with no `Issuing Alert-UID` line. `LibreNMS\Alert\AlertUtil::mergeProblemPhaseTimingFromOperations()` sets `mute` when the rule has no operation, and `getAlertTransports()` returns an empty list for the same reason — even when `alert_transport_map` contains a row.

To fix: open the rule in the LibreNMS rule editor, attach an alert operation, and map the IAPM API transport to that operation's **problem** segment. All alert states currently map to the problem phase, so one segment covers both trigger and recovery notifications. Rules created through the editor get this wiring automatically; rules inserted directly into `alert_rules` (imports, provisioning scripts, restored backups) do not.

Verify with:

```bash
php artisan tinker --execute="var_dump(LibreNMS\Alert\AlertUtil::getAlertTransports(<alert_id>));"
```

An empty array means the operation or its transport mapping is missing.

## Missed recovery or stuck pending

Run `iapm:reconcile --dry-run`, inspect the port in LibreNMS, then run reconciliation normally. For one incident use `--incident=ID`; for an outage scope use `--device=ID`. Confirm polling is current before forcing state.

## Duplicate alerts

Compare `source_alert_id`, `source_alert_uid`, stable incident key, and timeline. Do not delete an open incident. Check whether multiple LibreNMS transports target IAPM and whether scheduler overlap protection is functioning.

## Large outage storm

Enable dry-run if gateway capacity is at risk, confirm device-down/parent suppression, monitor database locks and scheduler duration, temporarily reduce destination throughput, and reconcile in device-scoped batches after device reachability returns.

Use bulk acknowledgement or mute in batches of no more than 1,000 incidents. Do not repeatedly force-resend actions during a storm. Export the Interface Matrix for an offline scope record and preserve delivery/audit logs until the post-incident review is complete.

## Invalid token and rotation

Generate a new random token, move the current token to `previous_ingestion_token`, install the new token in LibreNMS, verify ingestion, then remove the previous token. A 401 never identifies which token failed.

## Credential rotation

Enter a replacement password without exposing the old value, test a controlled receiver, inspect the redacted response, then invalidate the old gateway credential.

## Temporary rollback to direct SMS

Set IAPM dry-run true before re-enabling the prior LibreNMS SMS operation. Verify direct trigger/recovery, keep IAPM ingestion enabled for comparison, and avoid two live senders. Reverse the cutover only after the IAPM fault is understood.
