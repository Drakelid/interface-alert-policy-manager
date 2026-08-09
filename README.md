# Interface Alert Policy Manager (IAPM)

IAPM is a LibreNMS package plugin that turns one broad interface-down alert into independently managed per-`port_id` incidents. It resolves policy assignments, records decisions and delivery attempts, reconciles current port state, and owns SMS/webhook delivery.

## Quick start

For the impatient — install and enable, then finish configuration in the UI. See [Installation](#installation) for the full walkthrough and the production queue-worker setup.

```bash
cd /opt/librenms
sudo -u librenms env FORCE=1 ./scripts/composer_wrapper.php require drakelid/interface-alert-policy-manager
sudo -u librenms php artisan migrate --force
sudo -u librenms ./lnms plugin:enable interface-alert-policy-manager
sudo -u librenms php artisan optimize:clear && sudo systemctl reload php8.4-fpm
sudo -u librenms php artisan iapm:install-check
```

Then open **Plugins → Interface Alert Policy Manager** and follow the Overview setup checklist: generate the ingestion token, add a destination, a policy with a notification action, and an assignment — then wire up the LibreNMS rule/template/transport from **Tools → Setup Helper**. Queued delivery and its workers start automatically. It begins in dry-run; turn that off when you're ready to send.

## Compatibility discovered

The documented and CI-tested baseline is LibreNMS `26.7.0`, PHP 8.2–8.4, and Laravel `^12.10`. The package uses Laravel Composer discovery plus `librenms/plugin-interfaces`; `PluginManagerInterface::publishHook()` supplies menu/settings integration. IAPM references `App\Models\Device`, `Port`, `DeviceGroup`, `PortGroup`, `Location`, and related models directly without copying core models. LibreNMS alert states are `CLEAR/RECOVERED=0`, `ACTIVE=1`, `ACKNOWLEDGED=2`, `WORSE=3`, `BETTER=4`, and `CHANGED=5`.

## Installation

Follow these steps in order. At the end the plugin is fully operational and delivering notifications. All commands run on the LibreNMS host; `php artisan` must run as the `librenms` user.

### Requirements

- LibreNMS on Laravel 12+ with its scheduler running (the standard `* * * * * librenms /opt/librenms/lnms schedule:run` cron). IAPM's reconcile, action processing, and queue workers all rely on it.
- PHP 8.2+ and Composer.
- An SMS gateway (or webhook) reachable from the LibreNMS host.

### 1. Install the package (Packagist)

Installs via LibreNMS's composer wrapper so it is written to `composer.plugins.json` and **survives `daily.sh` updates**:

```bash
cd /opt/librenms
sudo -u librenms env FORCE=1 ./scripts/composer_wrapper.php require drakelid/interface-alert-policy-manager
```

<sub>If your LibreNMS version's wrapper has no `require` subcommand: add `{"require":{"drakelid/interface-alert-policy-manager":"^1.0"}}` to `/opt/librenms/composer.plugins.json`, then `sudo -u librenms env FORCE=1 ./scripts/composer_wrapper.php update drakelid/interface-alert-policy-manager`.</sub>

### 2. Migrate and enable

```bash
sudo -u librenms php artisan migrate --force          # IAPM tables + queue tables + scale indexes
sudo -u librenms ./lnms plugin:enable interface-alert-policy-manager    # if not already active
sudo -u librenms php artisan optimize:clear && sudo systemctl reload php8.4-fpm
sudo -u librenms php artisan iapm:install-check        # should report all green
```

Refresh LibreNMS — **Plugins → Interface Alert Policy Manager** is now in the menu, starting in **dry-run** (nothing is sent until you go live in step 7).

### 3. Configure the essentials (UI)

Open the plugin and work down the **Overview setup checklist** (or the numbered **Configure** menu). Each step has a Fix button:

1. **Settings → generate the ingestion token** (top of the page).
2. **Destinations → create** your SMS gateway (URL, credentials, default receiver). Use *Send test* to confirm it reaches the gateway.
3. **Policies → create** a policy (trigger delay, repeats, recovery), then **add at least one notification Action** pointing at the destination — a policy with no action never notifies.
4. **Assignments → create** how interfaces map to the policy. A **Default** assignment covers every interface; or scope to specific devices/groups/port-groups/regex.
5. **Large fleets:** either set a default assignment/policy so nothing is unmatched, **or** Settings → turn **off** *Record alerts for interfaces with no policy* so un-scoped interfaces are ignored instead of stored.

### 4. Point LibreNMS at IAPM (Setup Helper)

Open **Tools → Setup Helper**. Copy the three blocks into LibreNMS alerting:

1. **Alert rule** — build it in the rule editor so LibreNMS validates it.
2. **Alert template** — paste as the rule's template.
3. **API transport** — an *API* transport (POST, "send as form" OFF) to the ingestion URL with the `Authorization: Bearer <token>` header; route the rule to it.

The Setup Helper's **"Confirm it's working"** panel turns green once LibreNMS posts its first alert.

### 5. Delivery workers (queued dispatch is the default)

Queued delivery is on by default and **self-provisions**: the queue tables were created in step 2, and the scheduler keeps `IAPM_QUEUE_WORKERS` (default 3) background workers draining the queue — nothing else to do for a working setup. To confirm:

```bash
pgrep -af 'queue:work --queue=iapm'     # workers appear within ~1 minute
```

For a **production-hardened** setup (supervised, boot-persistent, auto-restarting workers), see [Rock-solid queue workers](#rock-solid-queue-workers-production) below and set `IAPM_QUEUE_WORKERS=0` so systemd owns the workers. To use synchronous delivery instead (no workers), Settings → *Delivery dispatch* → Synchronous.

### 6. Test before going live

With dry-run still on, fire a synthetic alert and confirm the pipeline without sending anything:

- **Tools → Simulate Alert** for one interface, then check the **Delivery Log** (rows show `dry_run`).
- **Tools → Policy Test** shows the effective policy and *who would be paged* for a given `port_id`.

### 7. Go live

Settings → turn **off Dry-run** (you'll be asked to confirm). Real notifications now flow. Watch the first genuine interface-down incident through the **Overview** and **Delivery Log**.

### Verify it's healthy

The **Overview health panel** should be all green, and:

```bash
sudo -u librenms php artisan iapm:health   # exits 0 when scheduler, gateway, backlog, and queue are healthy
```

Point external monitoring at `iapm:health` as a dead-man's switch.

---

### Rock-solid queue workers (production)

For large fleets, run the workers under **systemd** (auto-restart, boot-persistent, memory-capped) instead of the scheduler. First disable the scheduler-managed workers:

```bash
echo 'IAPM_QUEUE_WORKERS=0' | sudo tee -a /opt/librenms/.env
# optional: keep queue load off MySQL at scale
# echo 'IAPM_QUEUE_CONNECTION=redis' | sudo tee -a /opt/librenms/.env
cd /opt/librenms && sudo -u librenms php artisan config:clear
sudo -u librenms bash -lc 'command -v php'    # note the php path for the unit below
```

Install the worker template and start N instances (concurrency = instance count; match your gateway):

```bash
sudo tee /etc/systemd/system/iapm-worker@.service >/dev/null <<'UNIT'
[Unit]
Description=IAPM notification queue worker %i
After=network-online.target mariadb.service mysql.service redis-server.service
Wants=network-online.target
StartLimitIntervalSec=0

[Service]
Type=simple
User=librenms
Group=librenms
Restart=always
RestartSec=5
ExecStart=/usr/bin/php /opt/librenms/artisan queue:work --queue=iapm --name=iapm-%i --sleep=1 --tries=3 --backoff=10 --max-time=3600 --timeout=90
KillSignal=SIGTERM
TimeoutStopSec=120
MemoryMax=512M

[Install]
WantedBy=multi-user.target
UNIT

sudo systemctl daemon-reload
sudo systemctl enable --now iapm-worker@{1..6}
```

<sub>Adjust the `ExecStart` php path to match `command -v php`. For Redis, insert `redis` right after `queue:work`. Failed sends land in `failed_jobs` — inspect with `php artisan queue:failed`, retry with `queue:retry all`.</sub>

### Updating

`daily.sh` reinstalls the package from `composer.plugins.json`, so updates are automatic within your version constraint. Publish a new release by tagging (`git tag -a v1.2.0 && git push origin v1.2.0`). After any update that changes code, restart the workers so they load it:

```bash
sudo -u librenms php artisan migrate --force
sudo -u librenms php artisan optimize:clear && sudo systemctl reload php8.4-fpm
sudo systemctl restart 'iapm-worker@*'        # or `php artisan queue:restart` for scheduler-managed workers
```

Back up the database before upgrades; migrations are additive. To uninstall: disable the plugin, back up, then remove the Composer package — uninstall migrations deliberately delete IAPM data.

### Development install (path repo)

For local development against a checkout instead of Packagist:

```bash
cd /opt/librenms
composer config repositories.iapm '{"type":"path","url":"../interface-alert-policy-manager","options":{"symlink":true}}'
sudo -u librenms env FORCE=1 composer require drakelid/interface-alert-policy-manager:@dev
php artisan migrate && ./lnms plugin:enable interface-alert-policy-manager && php artisan iapm:install-check
```

### Troubleshooting

**`env: 'composer': No such file or directory`, or composer commands seem to do nothing.**
On many LibreNMS hosts `composer` is not on the PATH (it's a phar), so bare `composer …` silently fails. Use the LibreNMS wrapper (`./scripts/composer_wrapper.php …`) for install/update, and for any *manual* composer step use the phar directly, e.g. `sudo -u librenms php /opt/librenms/composer.phar dump-autoload`. Find it with `sudo find /opt/librenms -maxdepth 2 -name 'composer*'`.

**`Ambiguous class resolution … InterfaceAlertPolicyManager … the first will be used` during `composer` dump / update.**
The plugin is installed **twice** — usually a leftover `vendor/librenms/interface-alert-policy-manager` (an old path-repo/dev install) next to the Packagist `vendor/drakelid/…`. "The first will be used" means the **old** copy wins, so you run stale code. Remove the old one:
```bash
cd /opt/librenms
grep -c "librenms/interface-alert-policy-manager" composer.json composer.plugins.json   # find where it's required
# delete that require from composer.json AND composer.plugins.json, then:
sudo -u librenms php /opt/librenms/composer.phar remove librenms/interface-alert-policy-manager   # if in composer.json
sudo rm -rf vendor/librenms/interface-alert-policy-manager
sudo -u librenms php /opt/librenms/composer.phar dump-autoload 2>&1 | grep -c InterfaceAlertPolicyManager   # want 0
```
If you previously ran a self-heal cron to survive updates (`/etc/cron.d/iapm`), delete it — Packagist + `composer.plugins.json` replaces it, and it will keep re-adding the old package: `sudo rm -f /etc/cron.d/iapm /opt/iapm/ensure-iapm.sh`.

**Notifications aren't delivered even though everything is green.**
Check the delivery mode and workers. `iapm:install-check` prints `delivery=queue` or `delivery=sync`:
- `delivery=sync` — sent inline by the scheduler; no workers needed. Fine, but no parallelism.
- `delivery=queue` — requires running workers. Confirm with `pgrep -af 'queue:work --queue=iapm'`. If nothing is draining, jobs pile up in the `jobs` table. Either start workers (scheduler-managed needs `IAPM_QUEUE_WORKERS>0`; or the systemd units above) or switch to sync: Settings → *Delivery dispatch*.

To read or change the mode from the CLI:
```bash
sudo -u librenms php artisan tinker --execute="\$s=app(LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\SettingStore::class); echo \$s->get('dispatch_mode','queue').PHP_EOL;"
sudo -u librenms php artisan tinker --execute="app(LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\SettingStore::class)->put('dispatch_mode','queue');"
```

**`Table 'librenms.jobs' doesn't exist`.** Queued delivery needs the queue tables — run `php artisan migrate --force` (the plugin ships the migration). Until then, IAPM automatically falls back to synchronous delivery, so no alert is lost.

**`install-check` shows `[FAIL] default_policy`.** Decide coverage: add a **Default** assignment (or set a default policy) so unmatched interfaces are covered, **or** Settings → turn off *Record alerts for interfaces with no policy* to intentionally ignore them (recommended when you scope IAPM to specific interfaces).

**Plugin missing from the menu / "must be run as the user librenms".** Run `artisan`/`lnms` as `sudo -u librenms`. If the plugin vanished after an update, confirm it's in `composer.plugins.json` (that's what `daily.sh` reinstalls from) and that `vendor/drakelid/interface-alert-policy-manager` exists; re-run step 1 if not.

## Configuration and security

Create a cryptographically random ingestion token (at least 32 random bytes), store it under `ingestion_token` in encrypted IAPM settings, and use a short overlap in `previous_ingestion_token` during rotation. Destination configuration uses Laravel encrypted casts. Environment placeholders are supported, but encrypted database configuration is preferred for administration. Never put credentials in a URL.

IAPM starts in dry-run mode. Private/reserved destination addresses are blocked unless `allow_private_networks` is explicitly enabled for the trusted internal SMS gateway. HTTP(S) only is permitted. Authorization uses LibreNMS authentication with administrator fallback and distinct IAPM abilities. Browser writes use CSRF; the machine endpoint is outside the browser middleware and uses its dedicated bearer token and throttling.

SMS destination fields: URL, username, password, default receiver, JSON/form mode, connection/request timeouts, retries, retry delay, TLS verification, optional safe headers, and `allow_private_networks`. JSON mode is the default and sends `{"receiver":"...","message":"..."}`. Basic credentials are never written to delivery logs.

Receiver precedence is policy-action override, metadata on the single winning assignment, policy default, destination default/list, then global default. Losing assignments never contribute receivers. Device digests union the per-incident result of that same resolver, so policy and assignment overrides survive aggregation. No delivery occurs without a valid receiver. Templates support only explicit `{{ placeholder }}` substitutions; unknown placeholders fail validation and no PHP/Blade is executed.

The available placeholders are `incident_id`, `severity`, `state`, `hostname`, `sysName`, `display_name`, `device_id`, `port_id`, `ifName`, `ifDescr`, `ifAlias`, `ifAdminStatus`, `ifOperStatus`, `interface_type`, `location`, `policy_name`, `assignment_source`, `first_seen_at`, `triggered_at`, `recovered_at`, `outage_duration`, `device_url`, `port_url`, `acknowledgement_user`, and `suppression_reason`. `device_url` and `port_url` are built from the `url_base` setting, which defaults to the application URL. Template preview and real delivery use the same placeholder map, so a template that previews cleanly renders identically when it is sent.

Destination tests are explicit administrator actions: they send even while dry-run mode is enabled, and every attempt is written to the delivery log with phase `test` and no incident.

## Alert rule and transport

Create one LibreNMS rule whose fault query selects ports where the device is up, `ifAdminStatus = up`, `ifOperStatus != up`, and `ignore`, `disabled`, and `deleted` are false. Verify field names in the rule builder for the installed version.

POST JSON to:

```text
/plugin/interface-alert-policy-manager/api/v1/alerts
Authorization: Bearer <generated-token>
Content-Type: application/json
Accept: application/json
```

Use LibreNMS's JSON encoding in the alert template rather than manually quoting fault strings. The payload must contain `device_id`, state, and a `faults` array with stable `port_id` values. See `samples/active-alert.json` and `samples/recovery-alert.json`. State 0 is recovery; 1/3/4/5 are active observations; 2 is acknowledged.

Identifiers may arrive as JSON numbers or as numeric strings; both are normalized. A payload whose state is not a supported LibreNMS alert state is refused with a structured `422` rather than an error page. A recovery payload must carry `alert_id`, `alert_uid`, or `rule_id` so the affected incidents can be correlated.

The verified rule expression for this checkout is:

```text
macros.device_up = 1 AND ports.ifAdminStatus = 'up' AND ports.ifOperStatus != 'up' AND ports.ignore = 0 AND ports.disabled = 0 AND ports.deleted = 0
```

Use `samples/librenms-alert-template.blade.php`; it constructs an array from the documented `$alert` fields and encodes it with Blade `@json`, preserving quotes, newlines, Unicode, multiple faults, and empty recoveries. `samples/librenms-api-transport.md` contains the transport fields. The same content is available from the plugin Setup page.

## Policies and lifecycle

Assignment precedence is port, port group, device, device group, location, ifAlias regex, ifName regex, interface type, default. Ties use assignment priority, policy priority, then newest assignment. Device-group assignments support `any`, `all`, and `exclude`. Incidents begin pending, become active when delay/poll requirements pass, may be suppressed or acknowledged, and finally recover. The stable key is `interface-down:{device_id}:{port_id}`.

Commands:

```sh
php artisan iapm:install-check
php artisan iapm:reconcile [--dry-run] [--incident=ID] [--device=ID]
php artisan iapm:process-actions [--incident=ID]
php artisan iapm:test-policy --port=PORT_ID
php artisan iapm:test-destination --destination=ID --receiver=VALUE [--force]
php artisan iapm:cleanup [--force]
php artisan iapm:cache-clear
php artisan iapm:cache-rebuild [--device=DEVICE_ID]
php artisan iapm:health   # non-zero exit when IAPM is unhealthy (for external monitoring)
```

## Noise control, monitoring, and tooling

- **Root-cause suppression.** Designate an *uplink port group* in Settings, then enable "suppress when uplink down" on a policy. When an uplink interface on a device is down, downstream customer interfaces on that device are suppressed (reason `uplink_down`) instead of storming.
- **Flap dampening (per policy).** Set a flap threshold, window, and settle period. When an interface cycles down/up faster than the threshold, IAPM sends one `FLAPPING` notice and suppresses the routine churn until it settles.
- **Device digest (storm control).** Set an *aggregate threshold* (and window) in Settings. When at least that many interfaces on the **same device** trigger within the window, IAPM sends one grouped "device down" message (device-level receivers) instead of an SMS per interface, so a linecard or downstream-switch failure produces one notification rather than a hundred. Wording is customisable on the Message Templates page. Set the threshold to `0` to always notify per interface. When an upstream LibreNMS acknowledgement arrives it acknowledges the incident rather than re-triggering it.
- **Escalation chains.** Add multiple `escalation` actions with increasing delays and different destinations/receivers; acknowledging the incident stops further escalation.
- **Queued dispatch (default, self-provisioning).** A durable encrypted outbox is committed before a queue job containing only the outbox ID is published. Its unique episode/action/phase/destination/receiver key prevents scheduler overlap and worker retry from creating another logical notification. Workers atomically claim rows; a crash leaves a reclaimable row, and `iapm:health` reports stale claims and overdue pending work. If publication fails, IAPM returns the row to pending and delivers synchronously. Tune `IAPM_QUEUE_WORKERS` (default 3) to gateway capacity, or use Redis via `IAPM_QUEUE_CONNECTION=redis`. Switch to **Synchronous** in Settings for a worker-free setup; both modes use the same outbox.
- **Self-monitoring.** The Overview shows an IAPM health panel, and `iapm:health` exits non-zero when the scheduler has stalled, the gateway is failing, or notifications are stuck — point your own monitoring at it as a dead-man's switch.
- **Statistics & SLA** (Monitor → Statistics): MTTA/MTTR, longest outage, notifications, flapping outages, noisiest interfaces, per-policy breakdown, and delivery success rate, computed from an append-only `iapm_outages` record.
- **Simulate alert** (Tools): fire a synthetic alert for one interface through the real pipeline to validate policy/assignment/suppression behaviour without curl (respects dry-run).
- **Import / Export** (Tools): back up or promote schedules, policies, actions, and assignments as JSON between installs. Destinations are excluded (they hold secrets); actions are matched to destinations by name on import.
- **Comparison report** gains a per-policy breakdown and CSV export.

The provider schedules reconciliation and action processing every minute and cleanup daily. Ensure `php artisan schedule:run` is executed every minute by the normal LibreNMS scheduler. While the plugin is disabled, its routes return `404` and the scheduled commands exit without acting, so disabling the plugin is a safe way to stop IAPM without removing it.

Before production cutover, complete every step in `docs/MANUAL_TEST.md` on a staging clone and retain the results with the change record.

## Dry-run cutover

Install IAPM, configure the SMS destination, create a default policy and assignments, keep `dry_run=true`, send alerts to IAPM while retaining direct LibreNMS SMS, compare incidents and delivery logs, correct missing policies/receivers, perform a controlled destination test, set `dry_run=false`, then disable the old direct SMS operation. Confirm one trigger and recovery before broad rollout.

## Logs, backup, and limitations

Incidents, timelines, deliveries, and audit history live in `iapm_*` tables; include them in database backups. IAPM structured operational logs are written to `storage/logs/iapm.log`. Credentials, tokens, authorization headers, and full responses are redacted or omitted.

### Backup and restore

Back up all `iapm_*` tables together with the LibreNMS application encryption key. Encrypted destination and setting values cannot be recovered with the database alone. Restore into the same LibreNMS/application-key environment, run migrations, clear/rebuild IAPM caches, and run `iapm:install-check` before enabling live delivery.

The notification outbox also contains encrypted receiver/message payloads until retained rows are cleaned. Rotate `APP_KEY` only with Laravel's supported previous-key mechanism and keep the old key available until all destination settings and outstanding outbox rows have been read and re-encrypted or expired. Losing the old key makes queued payloads undecryptable; stop workers, restore the key, and retry rather than deleting live rows.

### Running at large scale (100k+ interfaces)

IAPM is built to scale to very large fleets, but a few things must be configured deliberately:

- **Set a default assignment/policy _or_ turn off "Record alerts for interfaces with no policy"** (Settings). Otherwise every alerting interface without a matching policy is stored as a suppressed `no_policy` incident — at hundreds of thousands of interfaces that is a lot of rows. Scope IAPM to the interfaces you care about, then disable `record_unpoliced` so the rest are ignored.
- **Tune the ingestion rate limit.** `iapm.ingestion.rate_limit` (env `IAPM_INGEST_RATE`, default `2000,1`) caps all of LibreNMS's alert POSTs together; a fleet-wide event can exceed it and rejected alerts are lost. Raise it for large fleets and firewall the endpoint to the LibreNMS host.
- **Enable the device digest** (`aggregate_threshold`) so a device dropping many interfaces sends one message instead of hundreds, and consider **queued dispatch** for very wide simultaneous events.
- **Schedule `iapm:cache-rebuild`** (e.g. hourly) if you rely on the Interface Matrix policy filters — the per-request/reconcile cache writes were removed to keep the hot paths write-light, so the matrix cache is refreshed on view and by the rebuild command.
- Recovered incidents are retained (`retention_days`, default 365) and cleaned up in batches nightly; process-actions only re-scans recoveries from the last 48h, so old history doesn't slow the every-minute run.

### Known limitations

- Materialized policy filters are complete only after `iapm:cache-rebuild` has covered the relevant ports.
- Delivery is synchronous by default (bounded per run by `iapm.processing.max_seconds`); very large storms are best served by the device digest plus the optional **queued dispatch** mode below.
- Parent suppression uses current LibreNMS device relationships and cannot infer dependencies not modeled in LibreNMS.
- Private-network destinations must be explicitly allowed and should be limited to trusted internal gateway hosts.
- The current administration forms accept numeric LibreNMS group/entity identifiers instead of providing every possible type-ahead selector.

### Future extensions

The transport contract supports future email, Teams Workflow, Slack, Alertmanager, and ticket-system transports. New transports must implement encrypted configuration, redaction, SSRF controls where applicable, controlled test delivery, and per-attempt delivery logging.

## Permissions

IAPM defines abilities for viewing the plugin, managing policies, assignments, destinations and settings, acknowledging or muting incidents, testing destinations, and viewing audit logs. LibreNMS administrators receive these abilities through the administrator fallback. Non-administrators must receive the corresponding Spatie/LibreNMS permissions; hiding a menu item never replaces route authorization.

## Upgrade and uninstall

Back up the database and application key, update the Composer package, run `php artisan migrate`, rebuild the policy cache, and run `iapm:install-check`. Never edit or reorder a migration already deployed. To disable safely, enable dry-run, restore the prior LibreNMS transport if needed, then disable the plugin. Composer removal does not intentionally delete tables. Only run migration rollback when permanent data deletion is approved and backed up.

This safety upgrade is additive: it backfills stable episode IDs, adds a unique outage-episode constraint, and creates the encrypted durable outbox. During rollout, stop the scheduler/workers, back up all `iapm_*` tables and `APP_KEY`, migrate, run the install check, then restart one worker and confirm health before restoring normal concurrency. See `docs/UPGRADING.md` for rollback and release-note details.

## Troubleshooting

- `401`: confirm the bearer token and rotation window; generate a new token if its value is unknown.
- `422`: inspect the structured field list without logging the request token or gateway credentials.
- Missing policy: run Policy Test, rebuild the cache, then review assignment precedence and enabled state.
- Missing recovery: run `iapm:reconcile --dry-run --incident=ID`, check polling freshness and recovery hold-down, then reconcile normally.
- Failed delivery: filter the Delivery Log, verify DNS/TLS/routing, and use a controlled destination test.
- Stuck pending: verify trigger delay, direct observation count, current port state, and scheduler execution.
- Duplicate notification: compare incident/action/receiver/attempt rows and confirm only one live LibreNMS-to-IAPM transport and one scheduler runner exist.

Detailed outage, credential-rotation, rollback, and gateway runbooks are in `docs/OPERATIONS.md`; development and extension guidance is in `docs/DEVELOPMENT.md`.
