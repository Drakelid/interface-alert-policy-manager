# Fresh uninstall and reinstall

This runbook replaces the installed IAPM package with a clean copy from
Packagist. The default procedure **preserves all IAPM database tables, settings,
incidents, delivery history, and encrypted credentials**. Do not change
LibreNMS's `APP_KEY`: existing encrypted IAPM values depend on it.

Run every `php artisan`, `lnms`, and Composer command from `/opt/librenms` as
the `librenms` user. Commands that require root access explicitly use `sudo`
without `-u librenms`.

## 1. Back up and record the current state

```bash
cd /opt/librenms
IAPM_BACKUP_DIR="/var/backups/iapm/reinstall-$(date +%Y%m%d-%H%M%S)"
sudo install -d -m 0700 "$IAPM_BACKUP_DIR"
sudo -u librenms php artisan iapm:health
sudo -u librenms ./lnms plugin:list
sudo -u librenms env FORCE=1 ./scripts/composer_wrapper.php show drakelid/interface-alert-policy-manager
sudo cp composer.plugins.json .env "$IAPM_BACKUP_DIR/"
```

Also take a database backup that includes every `iapm_*` table. Keep the `.env`
backup with it because `APP_KEY` is required to decrypt stored credentials and
the ingestion inbox.

In the IAPM UI, enable **Dry-run**. Temporarily disable the LibreNMS alert
operation/transport that posts to IAPM so alerts do not arrive during the
reinstall.

If systemd or Supervisor owns the queue workers, stop those workers. For the
documented systemd template:

```bash
sudo systemctl stop 'iapm-worker@*'
```

For scheduler-managed workers, record the current `IAPM_QUEUE_WORKERS` value,
set it to `0` in `/opt/librenms/.env`, clear configuration, and wait for existing
workers to exit:

```bash
grep '^IAPM_QUEUE_WORKERS=' .env
# Edit .env and set: IAPM_QUEUE_WORKERS=0
sudo -u librenms php artisan config:clear
pgrep -af 'queue:work.*--queue=iapm'
```

Wait until the final command prints no IAPM worker. With the default settings,
this can take several minutes.

## 2. Remove the old package copy

Disable the plugin before removing its service provider:

```bash
sudo -u librenms ./lnms plugin:disable interface-alert-policy-manager
sudo -u librenms env FORCE=1 ./scripts/composer_wrapper.php remove drakelid/interface-alert-policy-manager
sudo -u librenms php artisan optimize:clear
```

Edit `/opt/librenms/composer.plugins.json` and remove only this member from its
`require` object:

```json
"drakelid/interface-alert-policy-manager": "^1.4"
```

Keep the file valid JSON and preserve every other plugin entry. Do not replace
the whole file with a sample. Confirm the old package is gone:

```bash
test ! -d vendor/drakelid/interface-alert-policy-manager && echo 'Old package removed'
sudo -u librenms env FORCE=1 ./scripts/composer_wrapper.php show drakelid/interface-alert-policy-manager
```

The `show` command should say the package is not installed. Composer removal
does not remove IAPM tables or data.

If an older, manually installed copy exists, remove its Composer `path`
repository or custom autoload entry before continuing. Also remove any obsolete
self-heal cron only if one was previously created:

```bash
sudo rm -f /etc/cron.d/iapm /opt/iapm/ensure-iapm.sh
```

## 3. Install a clean package copy

Merge the package requirement back into the existing
`composer.plugins.json` `require` object:

```json
{
    "require": {
        "drakelid/interface-alert-policy-manager": "^1.4"
    }
}
```

The snippet shows the required shape; retain any other entries already in the
file. Then install and verify it:

```bash
cd /opt/librenms
sudo -u librenms php daily.php -f composer_get_plugins
sudo -u librenms env FORCE=1 ./scripts/composer_wrapper.php require drakelid/interface-alert-policy-manager:^1.4
sudo -u librenms env FORCE=1 ./scripts/composer_wrapper.php show drakelid/interface-alert-policy-manager
sudo -u librenms php artisan migrate --force
sudo -u librenms ./lnms plugin:enable interface-alert-policy-manager
sudo -u librenms php artisan optimize:clear
sudo systemctl reload "php$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')-fpm"
```

If the PHP-FPM unit has a different name, find it with
`systemctl list-units 'php*-fpm*'`. Skip the reload when the web server does not
use PHP-FPM.

Restore `IAPM_QUEUE_WORKERS` to its previous value and clear configuration. If
systemd or Supervisor owns the workers, leave it at `0` and restart the external
workers instead. Do not leave it at `0` unless an external service really owns
the workers: otherwise queued notifications and heartbeats will remain in the
database with nothing to consume them.

```bash
sudo -u librenms php artisan config:clear
sudo systemctl restart 'iapm-worker@*'   # only for the documented systemd setup
```

## 4. Verify before returning to service

```bash
sudo -u librenms php artisan iapm:install-check
sudo -u librenms php artisan iapm:cache-rebuild
sudo -u librenms php artisan iapm:health
pgrep -af 'queue:work.*--queue=iapm'
```

Then:

1. Open **Plugins -> Interface Alert Policy Manager** and confirm the existing
   destinations, policies, assignments, and settings are present.
2. Send a simulated alert while Dry-run is still enabled and confirm a
   `dry_run` entry appears in Delivery Log.
3. Re-enable the LibreNMS alert operation/transport and confirm Setup Helper
   sees an incoming alert.
4. Turn Dry-run off only after the checks are green and perform one controlled
   live destination test.

## Optional: erase all IAPM data

This is not needed for a clean package reinstall. It permanently deletes IAPM
configuration, incidents, audit/delivery history, inbox/outbox rows, and stored
credentials. Make a verified database backup first and stop all IAPM workers.

While the package is still installed and the plugin is disabled, reset only its
migrations:

```bash
cd /opt/librenms
sudo -u librenms php artisan migrate:reset \
  --path=vendor/drakelid/interface-alert-policy-manager/database/migrations \
  --force
```

Review the command output and confirm the `iapm_*` tables are gone before
removing the Composer package. On reinstall, `php artisan migrate --force`
creates empty tables and the UI setup checklist must be completed from scratch.
