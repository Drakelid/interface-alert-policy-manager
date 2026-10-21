#!/usr/bin/env bash

# Install or update Interface Alert Policy Manager on a production LibreNMS host.
#
# Usage:
#   sudo bash update-production.sh
#   sudo bash update-production.sh 1.7.3       # install an exact release
#   sudo bash update-production.sh '^1.7' --yes
#
# Requires systemd and Linux /proc: the script drains the LibreNMS scheduler and
# workers in maintenance mode and reloads PHP-FPM through systemctl. On a host without systemd (a container image, for
# example) follow the manual steps in the README's "Updating" section instead.
#
# The default constraint follows stable 1.x releases from 1.7 onward. Database
# backup policy is installation-specific, so take and verify that backup before
# running this script. This script backs up only the affected Composer metadata;
# it deliberately does NOT copy .env, because that file holds APP_KEY and the
# database credentials, and an unmanaged plaintext copy per update accumulates
# indefinitely. Preserving .env is the operator's own backup step, which the
# pre-flight summary below asks for explicitly.

set -Eeuo pipefail

LIBRENMS_DIR="${LIBRENMS_DIR:-/opt/librenms}"
LIBRENMS_USER="${LIBRENMS_USER:-librenms}"
LIBRENMS_GROUP="${LIBRENMS_GROUP:-librenms}"
PACKAGE="drakelid/interface-alert-policy-manager"
PLUGIN_NAME="interface-alert-policy-manager"
VERSION_CONSTRAINT="^1.7"
ASSUME_YES=0
BACKUP_DIR=""
# BEGIN IAPM RUNTIME HELPERS
# Kept inline so copying this single script remains sufficient for an update.
# The isolated shell regression checks source only this marked block.
# The caller supplies log(), as_librenms(), fail(), and LIBRENMS_DIR.
IAPM_RESTORE_UNITS=()
IAPM_RUNTIME_PAUSED=0
IAPM_MAINTENANCE_CHANGED=0
IAPM_REPLACEMENT_STARTED=0
IAPM_REPLACEMENT_COMPLETE=0

iapm_runtime_command() {
    local index command
    local -a arguments=("$@")
    for ((index = 0; index + 1 < ${#arguments[@]}; index++)); do
        case "${arguments[index]##*/}" in
            artisan|lnms)
                command="${arguments[index + 1]}"
                case "$command" in
                    queue:work|queue:listen|schedule:run|schedule:work|iapm:*) return 0 ;;
                esac
                ;;
        esac
    done
    return 1
}

iapm_runtime_processes() {
    local pid process_directory argument entrypoint
    local -a arguments=()
    while IFS= read -r pid; do
        [[ -r "/proc/$pid/cmdline" ]] || continue
        process_directory="$(readlink -f "/proc/$pid/cwd" 2>/dev/null)" || continue
        mapfile -d '' -t arguments <"/proc/$pid/cmdline" 2>/dev/null || continue
        if iapm_runtime_command "${arguments[@]}"; then
            for argument in "${arguments[@]}"; do
                case "${argument##*/}" in
                    artisan|lnms)
                        if [[ "$argument" == /* ]]; then
                            entrypoint="$(readlink -f -- "$argument" 2>/dev/null)" || continue
                        else
                            entrypoint="$(readlink -f -- "$process_directory/$argument" 2>/dev/null)" || continue
                        fi
                        if [[ "$entrypoint" == "$LIBRENMS_DIR/artisan" || "$entrypoint" == "$LIBRENMS_DIR/lnms" ]]; then
                            printf '%s\n' "$pid"
                            break
                        fi
                        ;;
                esac
            done
        fi
    done < <(pgrep -f '(artisan|lnms)' || true)
}

iapm_wait_runtime() {
    local pending deadline
    deadline=$((SECONDS + ${IAPM_UPDATE_DRAIN_SECONDS:-180}))
    while true; do
        pending="$(iapm_runtime_processes)"
        [[ -n "$pending" ]] || return 0
        if (( SECONDS >= deadline )); then
            printf 'LibreNMS runtime processes still running: %s\n' "$pending" >&2
            printf 'Stop custom worker/scheduler supervisors, then retry the update. No package or schema replacement has started.\n' >&2
            return 1
        fi
        sleep 1
    done
}

iapm_pause_runtime() {
    local state unit
    local -a candidates=('librenms-scheduler.timer' 'iapm-worker@*.service')
    if [[ -n "${IAPM_UPDATE_EXTRA_UNITS:-}" ]]; then
        local -a extra=()
        read -r -a extra <<<"$IAPM_UPDATE_EXTRA_UNITS"
        for unit in "${extra[@]}"; do
            [[ "$unit" =~ ^[a-zA-Z0-9@_.:-]+\.(service|timer)$ ]] || fail "Invalid extra systemd unit: $unit"
        done
        candidates+=("${extra[@]}")
    fi
    state="$(as_librenms php -r 'require "vendor/autoload.php"; $app = require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); echo $app->isDownForMaintenance() ? "down" : "up";')"
    [[ "$state" == 'up' || "$state" == 'down' ]] || fail 'Could not determine Laravel maintenance state.'
    # Capture only units that were active, so neither normal exit nor a failed
    # update starts an intentionally stopped service or timer.
    local listed
    listed="$(systemctl list-units --type=service,timer --state=active,activating --no-legend --plain "${candidates[@]}")" || return 1
    mapfile -t IAPM_RESTORE_UNITS < <(awk 'NF {print $1}' <<<"$listed")
    IAPM_RUNTIME_PAUSED=1
    if [[ "$state" == 'up' ]]; then
        IAPM_MAINTENANCE_CHANGED=1
        as_librenms php artisan down --retry=60 || return 1
    fi
    if ((${#IAPM_RESTORE_UNITS[@]} > 0)); then
        systemctl stop "${IAPM_RESTORE_UNITS[@]}" || return 1
    fi
    # Laravel's default scheduler skips new events in maintenance mode. Existing
    # foreground runs must finish too; queue:restart is only a graceful signal.
    as_librenms php artisan queue:restart || return 1
    log 'Waiting for all LibreNMS queue, scheduler, and IAPM processes to finish'
    iapm_wait_runtime
}

iapm_resume_runtime() {
    local failed=0
    (( IAPM_RUNTIME_PAUSED == 1 )) || return 0
    if ((${#IAPM_RESTORE_UNITS[@]} > 0)); then
        systemctl start "${IAPM_RESTORE_UNITS[@]}" || failed=1
    fi
    if (( IAPM_MAINTENANCE_CHANGED == 1 )); then
        as_librenms php artisan up || failed=1
    fi
    if (( failed == 0 )); then
        IAPM_RUNTIME_PAUSED=0
        IAPM_MAINTENANCE_CHANGED=0
    fi
    return "$failed"
}

iapm_finish_runtime() {
    if (( IAPM_REPLACEMENT_STARTED == 1 && IAPM_REPLACEMENT_COMPLETE == 0 )); then
        printf '\nPackage/schema replacement failed. Maintenance mode and stopped units are being left in place.\n' >&2
        printf 'Complete the update and migrations, or restore verified package/database backups, before resuming runtime activity.\n' >&2
        printf 'Previously active units: %s\n' "${IAPM_RESTORE_UNITS[*]:-(none)}" >&2
        if (( IAPM_MAINTENANCE_CHANGED == 1 )); then
            printf 'After recovery, start those units and run php artisan up as the LibreNMS user.\n' >&2
        else
            printf 'After recovery, start those units; preserve the pre-existing maintenance state.\n' >&2
        fi
        return 1
    fi
    iapm_resume_runtime
}

# END IAPM RUNTIME HELPERS

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

on_exit() {
    local status=$?
    if ! iapm_finish_runtime; then
        if (( IAPM_REPLACEMENT_STARTED == 0 || IAPM_REPLACEMENT_COMPLETE == 1 )); then
            printf '\nFailed to restore the previous scheduler/worker or maintenance state. Restore it manually.\n' >&2
        fi
        status=1
    fi
    if (( status != 0 && status != 3 )); then
        printf '\nUpdate stopped with exit code %d.\n' "$status" >&2
        if [[ -n "$BACKUP_DIR" ]]; then
            printf 'Composer metadata backup: %s\n' "$BACKUP_DIR" >&2
        fi
    fi
    exit "$status"
}
trap on_exit EXIT

[[ "${EUID}" -eq 0 ]] || fail "Run this script with sudo or as root."
id "$LIBRENMS_USER" >/dev/null 2>&1 || fail "User '$LIBRENMS_USER' does not exist."
[[ -d "$LIBRENMS_DIR" ]] || fail "LibreNMS directory not found: $LIBRENMS_DIR"
cd "$LIBRENMS_DIR"
LIBRENMS_DIR="$(pwd -P)"

command -v php >/dev/null 2>&1 || fail "PHP is not available on PATH."
command -v sudo >/dev/null 2>&1 || fail "sudo is not available on PATH."
command -v systemctl >/dev/null 2>&1 || fail "systemctl is not available on PATH. This script manages the iapm-worker@ units and the PHP-FPM reload through systemd; on a non-systemd host follow the manual update steps in the README instead."
command -v pgrep >/dev/null 2>&1 || fail 'pgrep is required to verify runtime shutdown.'
[[ -d /proc ]] || fail 'Linux /proc is required to verify runtime shutdown.'
[[ "${IAPM_UPDATE_DRAIN_SECONDS:-180}" =~ ^[0-9]+$ ]] || fail 'IAPM_UPDATE_DRAIN_SECONDS must be a nonnegative integer.'

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
for file in composer.json composer.lock composer.plugins.json; do
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

log 'Pausing and draining LibreNMS runtime activity before replacing code'
iapm_pause_runtime || fail 'Runtime did not stop safely; package and schema replacement were cancelled.'

log "Installing $PACKAGE:$VERSION_CONSTRAINT"
IAPM_REPLACEMENT_STARTED=1
composer_wrapper require "$PACKAGE:$VERSION_CONSTRAINT" --with-dependencies --no-interaction

log "Running database migrations"
as_librenms php artisan migrate --force

log "Ensuring the plugin is enabled"
as_librenms ./lnms plugin:enable "$PLUGIN_NAME"

log "Clearing Laravel bootstrap caches while preserving runtime mutexes"
as_librenms php artisan optimize:clear --except=cache

log "Recycling queue workers onto the new code"
as_librenms php artisan queue:restart || true
IAPM_REPLACEMENT_COMPLETE=1
iapm_resume_runtime || fail 'Could not restore the previous runtime state.'

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
