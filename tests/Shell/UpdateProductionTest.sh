#!/usr/bin/env bash
set -Eeuo pipefail

REPO_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)"
TEST_DIR="$(mktemp -d)"
trap 'rm -rf -- "$TEST_DIR"' EXIT
load_runtime_helpers() {
    source <(sed -n '/^# BEGIN IAPM RUNTIME HELPERS$/,/^# END IAPM RUNTIME HELPERS$/p' "$REPO_DIR/tools/update-production.sh")
}
load_runtime_helpers
LIBRENMS_DIR="$TEST_DIR"

log() { printf '%s\n' "$*" >>"$TEST_DIR/events"; }
fail() { printf '%s\n' "$*" >&2; return 1; }
as_librenms() {
    if [[ "$1 $2" == 'php -r' ]]; then
        printf '%s' "$PRIOR_STATE"
    else
        log "$*"
        [[ "$*" != "$FAIL_COMMAND" ]]
    fi
}
systemctl() {
    if [[ "$1" == 'list-units' ]]; then
        printf '%s' "$ACTIVE_UNITS"
    else
        log "systemctl $*"
        [[ "systemctl $*" != "$FAIL_COMMAND" ]]
    fi
}
iapm_runtime_processes() { cat "$TEST_DIR/pending"; }
sleep() { log 'waited'; : >"$TEST_DIR/pending"; }

reset_case() {
    : >"$TEST_DIR/events"
    : >"$TEST_DIR/pending"
    IAPM_RESTORE_UNITS=()
    IAPM_RUNTIME_PAUSED=0
    IAPM_MAINTENANCE_CHANGED=0
    IAPM_REPLACEMENT_STARTED=0
    IAPM_REPLACEMENT_COMPLETE=0
    IAPM_UPDATE_DRAIN_SECONDS=180
    PRIOR_STATE=up
    FAIL_COMMAND=''
    ACTIVE_UNITS=$'iapm-worker@1.service loaded active running worker\nlibrenms-scheduler.timer loaded active waiting scheduler\n'
}
expect() {
    if ! eval "$1"; then
        printf 'FAIL: %s\n' "$2" >&2
        cat "$TEST_DIR/events" >&2
        exit 1
    fi
}

reset_case
printf '123\n' >"$TEST_DIR/pending"
iapm_pause_runtime
log 'replace package/schema'
iapm_resume_runtime
events="$(cat "$TEST_DIR/events")"
expect '[[ "$events" == *"php artisan down --retry=60"*"systemctl stop iapm-worker@1.service librenms-scheduler.timer"*"php artisan queue:restart"*"waited"*"replace package/schema"*"systemctl start iapm-worker@1.service librenms-scheduler.timer"*"php artisan up"* ]]' 'all active runtime work must drain before replacement, then resume'

reset_case
PRIOR_STATE=down
ACTIVE_UNITS=''
iapm_pause_runtime
iapm_resume_runtime
events="$(cat "$TEST_DIR/events")"
expect '[[ "$events" != *"php artisan up"* && "$events" != *"systemctl start"* && "$events" != *"php artisan down"* ]]' 'preserve maintenance and inactive units'

reset_case
IAPM_UPDATE_DRAIN_SECONDS=0
printf '456\n' >"$TEST_DIR/pending"
if iapm_pause_runtime; then
    printf 'FAIL: a persistent/custom supervisor must block replacement\n' >&2
    exit 1
fi
iapm_resume_runtime
events="$(cat "$TEST_DIR/events")"
expect '[[ "$events" == *"systemctl start iapm-worker@1.service librenms-scheduler.timer"*"php artisan up"* ]]' 'restore runtime after drain timeout'

reset_case
FAIL_COMMAND='php artisan down --retry=60'
if iapm_pause_runtime; then
    printf 'FAIL: failed maintenance transition must abort replacement\n' >&2
    exit 1
fi
iapm_resume_runtime
events="$(cat "$TEST_DIR/events")"
expect '[[ "$events" != *"systemctl stop"* && "$events" != *"php artisan queue:restart"* && "$events" == *"php artisan up"* ]]' 'stop immediately and restore after failed maintenance transition'

reset_case
(
    trap 'iapm_finish_runtime || true' EXIT
    iapm_pause_runtime
    IAPM_REPLACEMENT_STARTED=1
    log 'replace failed'
    exit 42
) && result=0 || result=$?
expect '[[ "$result" == 42 ]]' 'preserve replacement failure status'
events="$(cat "$TEST_DIR/events")"
expect '[[ "$events" == *"replace failed"* && "$events" != *"systemctl start"* && "$events" != *"php artisan up"* ]]' 'failed replacement must remain quiesced until code/schema recovery'

# Exercise process classification independently of PID discovery and privileges.
load_runtime_helpers
iapm_runtime_command php /opt/librenms/artisan queue:work --queue=custom
iapm_runtime_command php artisan schedule:run
iapm_runtime_command /opt/librenms/lnms iapm:drain-ingestion
if iapm_runtime_command php artisan migrate; then exit 1; fi
if iapm_runtime_command pgrep -f '(artisan|lnms)'; then exit 1; fi

bash -n "$REPO_DIR/tools/update-production.sh"
printf 'Production updater isolation checks passed.\n'
