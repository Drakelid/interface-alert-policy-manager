# Interface Alert Policy Manager (IAPM)

IAPM is a LibreNMS package plugin that turns one broad interface-down alert into independently managed per-`port_id` incidents. It resolves policy assignments, records decisions and delivery attempts, reconciles current port state, and owns SMS/webhook delivery.

## Compatibility discovered

Development targets LibreNMS `master` commit `9be80715833d7b350e423301e8b005ac730d8abd` (2026-07-09), PHP `^8.2`, and Laravel `^12.10`. The current LibreNMS package system uses Laravel Composer discovery plus `librenms/plugin-interfaces`; `PluginManagerInterface::publishHook()` supplies menu/settings integration. IAPM references `App\Models\Device`, `Port`, `DeviceGroup`, `PortGroup`, `Location`, and related models directly without copying core models. LibreNMS alert states are `CLEAR/RECOVERED=0`, `ACTIVE=1`, `ACKNOWLEDGED=2`, `WORSE=3`, `BETTER=4`, and `CHANGED=5`.

## Installation

For local development, from the LibreNMS directory:

```sh
composer config repositories.iapm '{"type":"path","url":"../interface-alert-policy-manager","options":{"symlink":true}}'
./lnms plugin:add librenms/interface-alert-policy-manager @dev
php artisan migrate
./lnms plugin:enable interface-alert-policy-manager
php artisan iapm:install-check
```

Keep the package outside LibreNMS core so updates do not overwrite it. Production releases should be installed from a versioned Composer repository. Back up the database before upgrades; migrations are additive and reversible. Disable the plugin before uninstalling, retain a database backup, then remove the Composer package. Uninstall migrations deliberately delete IAPM data.

## Configuration and security

Create a cryptographically random ingestion token (at least 32 random bytes), store it under `ingestion_token` in encrypted IAPM settings, and use a short overlap in `previous_ingestion_token` during rotation. Destination configuration uses Laravel encrypted casts. Environment placeholders are supported, but encrypted database configuration is preferred for administration. Never put credentials in a URL.

IAPM starts in dry-run mode. Private/reserved destination addresses are blocked unless `allow_private_networks` is explicitly enabled for the trusted internal SMS gateway. HTTP(S) only is permitted. Authorization uses LibreNMS authentication with administrator fallback and distinct IAPM abilities. Browser writes use CSRF; the machine endpoint is outside the browser middleware and uses its dedicated bearer token and throttling.

SMS destination fields: URL, username, password, default receiver, JSON/form mode, connection/request timeouts, retries, retry delay, TLS verification, optional safe headers, and `allow_private_networks`. JSON mode is the default and sends `{"receiver":"...","message":"..."}`. Basic credentials are never written to delivery logs.

Receiver precedence is policy-action override, interface assignment metadata, device-group metadata, policy default, destination default, then global default. No delivery occurs without a valid receiver. Templates support only explicit `{{ placeholder }}` substitutions; unknown placeholders fail validation and no PHP/Blade is executed.

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
- **Queued dispatch (optional, for scale).** Settings → *Delivery dispatch* → **Queued** hands each notification to a Laravel queue so workers deliver with concurrency during very large storms, instead of the every-minute job sending each SMS synchronously. It requires a running worker (`php artisan queue:work`); set `IAPM_QUEUE_CONNECTION` to a real async driver (redis/database) for true parallelism (a `sync` connection runs jobs inline — safe, but no concurrency gain). An in-flight "queued" delivery marker dedupes re-enqueues, and `iapm:health` flags queued mode when no worker is draining the queue. Leave it on **Synchronous** (the default) unless you regularly page on wide simultaneous events.
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

## Troubleshooting

- `401`: confirm the bearer token and rotation window; generate a new token if its value is unknown.
- `422`: inspect the structured field list without logging the request token or gateway credentials.
- Missing policy: run Policy Test, rebuild the cache, then review assignment precedence and enabled state.
- Missing recovery: run `iapm:reconcile --dry-run --incident=ID`, check polling freshness and recovery hold-down, then reconcile normally.
- Failed delivery: filter the Delivery Log, verify DNS/TLS/routing, and use a controlled destination test.
- Stuck pending: verify trigger delay, direct observation count, current port state, and scheduler execution.
- Duplicate notification: compare incident/action/receiver/attempt rows and confirm only one live LibreNMS-to-IAPM transport and one scheduler runner exist.

Detailed outage, credential-rotation, rollback, and gateway runbooks are in `docs/OPERATIONS.md`; development and extension guidance is in `docs/DEVELOPMENT.md`.
