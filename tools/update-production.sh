#!/usr/bin/env bash

# Install or update Interface Alert Policy Manager on a production LibreNMS host.
#
# Usage:
#   sudo bash update-production.sh
#   sudo bash update-production.sh 1.7.3       # install an exact release
#   sudo bash update-production.sh '^1.7' --yes
#
# The default constraint follows stable 1.x releases from 1.7 onward. Database
# backup policy is installation-specific, so take and verify that backup before
# running this script. This script backs up only the affected Composer metadata.

set -Eeuo pipefail

LIBRENMS_DIR="${LIBRENMS_DIR:-/opt/librenms}"
LIBRENMS_USER="${LIBRENMS_USER:-librenms}"
LIBRENMS_GROUP="${LIBRENMS_GROUP:-librenms}"
PACKAGE="drakelid/interface-alert-policy-manager"
PLUGIN_NAME="interface-alert-policy-manager"
VERSION_CONSTRAINT="^1.7"
ASSUME_YES=0
BACKUP_DIR=""
SYSTEMD_WORKERS=()
WORKERS_STOPPED=0

for argument in "$@"; do
    case "$argument" in
        --yes|-y)
            ASSUME_YES=1
            ;;
        --help|-h)
            sed -n '1,14p' "$0"
            exit 0
            ;;
        -* )
            printf 'Unknown option: %s\n' "$argument" >&2
            exit 2
            ;;
        *)
            VERSION_CONSTRAINT="$argument"
            ;;
    esac
done

log() {
    printf '\n==> %s\n' "$*"
}

fail() {
    printf '\nERROR: %s\n' "$*" >&2
    exit 1
}

as_librenms() {
    sudo -u "$LIBRENMS_USER" -- "$@"
}

composer_wrapper() {
    as_librenms env FORCE=1 ./scripts/composer_wrapper.php "$@"
}

restart_workers() {
    if (( WORKERS_STOPPED == 1 )) && ((${#SYSTEMD_WORKERS[@]} > 0)); then
        log "Starting the IAPM systemd workers"
        systemctl start "${SYSTEMD_WORKERS[@]}" || true
        WORKERS_STOPPED=0
    fi
}

on_exit() {
    local status=$?
    restart_workers
    if (( status != 0 && status != 3 )); then
        printf '\nUpdate stopped with exit code %d.\n' "$status" >&2
        if [[ -n "$BACKUP_DIR" ]]; then
            printf 'Composer metadata backup: %s\n' "$BACKUP_DIR" >&2
        fi
    fi
}
trap on_exit EXIT

[[ "${EUID}" -eq 0 ]] || fail "Run this script with sudo or as root."
id "$LIBRENMS_USER" >/dev/null 2>&1 || fail "User '$LIBRENMS_USER' does not exist."
[[ -d "$LIBRENMS_DIR" ]] || fail "LibreNMS directory not found: $LIBRENMS_DIR"
cd "$LIBRENMS_DIR"

command -v php >/dev/null 2>&1 || fail "PHP is not available on PATH."
command -v sudo >/dev/null 2>&1 || fail "sudo is not available on PATH."
command -v systemctl >/dev/null 2>&1 || fail "systemctl is not available on PATH."

for required in artisan lnms scripts/composer_wrapper.php composer.json; do
    [[ -e "$required" ]] || fail "Required LibreNMS file is missing: $LIBRENMS_DIR/$required"
done
[[ -x scripts/composer_wrapper.php ]] || fail "scripts/composer_wrapper.php is not executable."

PHP_VERSION="$(as_librenms php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
PHP_MAJOR="${PHP_VERSION%%.*}"
PHP_MINOR="${PHP_VERSION#*.}"
if (( PHP_MAJOR < 8 || (PHP_MAJOR == 8 && PHP_MINOR < 2) || (PHP_MAJOR == 8 && PHP_MINOR > 4) )); then
    fail "IAPM supports PHP 8.2 through 8.4; LibreNMS is using PHP $PHP_VERSION."
fi

if [[ -f composer.plugins.json ]]; then
    # The single quotes intentionally protect PHP variables from the shell.
    # shellcheck disable=SC2016
    as_librenms php -r '
        $path = $argv[1];
        try {
            json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            fwrite(STDERR, "Invalid composer.plugins.json: {$e->getMessage()}\n");
            exit(1);
        }
    ' composer.plugins.json || fail "Repair composer.plugins.json before updating."
fi

# The single quotes intentionally protect PHP variables from the shell.
# shellcheck disable=SC2016
CURRENT_VERSION="$(composer_wrapper show "$PACKAGE" --format=json 2>/dev/null | as_librenms php -r '
    $data = json_decode(stream_get_contents(STDIN), true);
    echo $data["versions"][0] ?? "not installed";
' || printf 'not installed')"

printf 'LibreNMS directory : %s\n' "$LIBRENMS_DIR"
printf 'Current IAPM      : %s\n' "$CURRENT_VERSION"
printf 'Requested version : %s\n' "$VERSION_CONSTRAINT"
printf 'PHP               : %s\n' "$PHP_VERSION"
printf '\nTake and verify a database backup containing all iapm_* tables and preserve /opt/librenms/.env (APP_KEY) before continuing.\n'

if (( ASSUME_YES == 0 )); then
    read -r -p 'Type UPDATE to continue: ' confirmation
    [[ "$confirmation" == "UPDATE" ]] || fail "Update cancelled."
fi

BACKUP_DIR="/var/backups/iapm/update-$(date +%Y%m%d-%H%M%S)"
install -d -m 0700 "$BACKUP_DIR"
for file in composer.json composer.lock composer.plugins.json .env; do
    if [[ -f "$file" ]]; then
        cp --preserve=mode,timestamps "$file" "$BACKUP_DIR/$file"
    fi
done
composer_wrapper show "$PACKAGE" --format=json >"$BACKUP_DIR/package-before.json" 2>/dev/null || true
printf '%s\n' "$VERSION_CONSTRAINT" >"$BACKUP_DIR/requested-version.txt"

log "Recording the plugin in composer.plugins.json"
# The single quotes intentionally protect PHP variables from the shell.
# shellcheck disable=SC2016
as_librenms php -r '
    $path = $argv[1];
    $package = $argv[2];
    $constraint = $argv[3];
    $document = file_exists($path)
        ? json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR)
        : [];
    if (!is_array($document)) {
        throw new RuntimeException("composer.plugins.json must contain a JSON object.");
    }
    $document["require"] ??= [];
    if (!is_array($document["require"])) {
        throw new RuntimeException("composer.plugins.json require must be an object.");
    }
    $document["require"][$package] = $constraint;
    ksort($document["require"]);
    file_put_contents(
        $path,
        json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL,
        LOCK_EX
    );
' composer.plugins.json "$PACKAGE" "$VERSION_CONSTRAINT"

log "Checking current IAPM health (informational pre-update snapshot)"
as_librenms php artisan iapm:health || true

mapfile -t SYSTEMD_WORKERS < <(
    systemctl list-units --type=service --state=running --no-legend 'iapm-worker@*.service' 2>/dev/null \
        | awk '{print $1}'
)
if ((${#SYSTEMD_WORKERS[@]} > 0)); then
    log "Stopping ${#SYSTEMD_WORKERS[@]} systemd-managed IAPM worker(s)"
    systemctl stop "${SYSTEMD_WORKERS[@]}"
    WORKERS_STOPPED=1
else
    log "No running iapm-worker@ systemd units found; requesting scheduler-managed workers to recycle"
    as_librenms php artisan queue:restart || true
fi

log "Installing $PACKAGE:$VERSION_CONSTRAINT"
composer_wrapper require "$PACKAGE:$VERSION_CONSTRAINT" --with-dependencies --no-interaction

log "Running database migrations"
as_librenms php artisan migrate --force

log "Ensuring the plugin is enabled"
as_librenms ./lnms plugin:enable "$PLUGIN_NAME"

log "Clearing Laravel and LibreNMS application caches"
as_librenms php artisan optimize:clear

log "Recycling queue workers onto the new code"
as_librenms php artisan queue:restart || true
restart_workers

mapfile -t PHP_FPM_UNITS < <(
    systemctl list-units --type=service --state=running --no-legend 'php*-fpm.service' 2>/dev/null \
        | awk '{print $1}'
)
if ((${#PHP_FPM_UNITS[@]} > 0)); then
    log "Reloading PHP-FPM: ${PHP_FPM_UNITS[*]}"
    systemctl reload "${PHP_FPM_UNITS[@]}"
else
    log "No running PHP-FPM unit found; skipping PHP-FPM reload"
fi

log "Confirming LibreNMS will retain the plugin during daily updates"
PLUGIN_LIST="$(as_librenms php daily.php -f composer_get_plugins)"
printf '%s\n' "$PLUGIN_LIST"
grep -Fq "$PACKAGE" <<<"$PLUGIN_LIST" || fail "$PACKAGE is missing from LibreNMS's persistent plugin list."

log "Installed package"
composer_wrapper show "$PACKAGE"

log "Refreshing the policy cache in worker-safe batches"
as_librenms php artisan iapm:cache-rebuild --queue

log "Running the installation check"
INSTALL_STATUS=0
as_librenms php artisan iapm:install-check || INSTALL_STATUS=$?

log "Running health checks"
HEALTH_STATUS=1
for attempt in 1 2 3; do
    if as_librenms php artisan iapm:health; then
        HEALTH_STATUS=0
        break
    fi
    if (( attempt < 3 )); then
        printf 'Health is not green yet; waiting 20 seconds for scheduler/worker heartbeats...\n'
        sleep 20
    fi
done

printf '\nIAPM update completed.\n'
printf 'Composer metadata backup: %s\n' "$BACKUP_DIR"
if (( INSTALL_STATUS != 0 || HEALTH_STATUS != 0 )); then
    printf 'The package update succeeded, but one or more operational checks remain red. Review the output above before considering the maintenance complete.\n' >&2
    exit 3
fi

printf 'Installation and health checks are green.\n'
