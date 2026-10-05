#!/bin/bash
# No real database, Git mutation, or service operations. The client stub uses
# files so state survives command substitutions used in production.
set -Eeuo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/../.."
export DB_TEST_REPO="$PWD"
db_test_dir="$(mktemp -d)"
export db_test_dir
trap 'rm -rf -- "$db_test_dir"' EXIT
source scripts/deploy-common.sh

cat > "$db_test_dir/admin-client.sh" <<'STUB'
set -eu
sql="$(cat)"
case "$sql" in
    *'SHOW GRANTS;'*)
        echo query >> "$db_test_dir/calls"
        if [ -f "$db_test_dir/connection-fail" ]; then
            cat "$db_test_dir/connection-fail" >&2
            exit 1
        fi
        printf 'v2board-db:%s:%s\n' "$(cat "$db_test_dir/identity")" "$(cat "$db_test_dir/trust")"
        cat "$db_test_dir/grants"
        ;;
    *' = 1;'*)
        echo enable >> "$db_test_dir/calls"
        echo 1 > "$db_test_dir/trust"
        [ ! -f "$db_test_dir/enable-fail" ] || exit 1
        ;;
    *' = 0;'*)
        echo restore >> "$db_test_dir/calls"
        [ ! -f "$db_test_dir/restore-fail" ] || exit 1
        echo 0 > "$db_test_dir/trust"
        echo 0
        ;;
    *) exit 2 ;;
esac
STUB

cat > "$db_test_dir/php-stub.sh" <<'STUB'
deploy_php() {
    case "$*" in
        'scripts/check-db-trigger-capability.php')
            if [ -f "$db_test_dir/ready" ] || [ "$(cat "$db_test_dir/trust")" = 1 ]; then
                echo ready
            else
                printf 'needs-trust|%064d\n' 1
            fi
            ;;
        'artisan v2board:update')
            echo migrate >> "$db_test_dir/calls"
            case "$(cat "$db_test_dir/migration")" in
                exit) exit 29 ;;
                term) kill -TERM "$$" ;;
                *) return "$(cat "$db_test_dir/migration")" ;;
            esac
            ;;
        *) return 0 ;;
    esac
}
STUB
source "$db_test_dir/php-stub.sh"

reset_case() {
    rm -f "$db_test_dir/ready" "$db_test_dir/connection-fail" "$db_test_dir/enable-fail" "$db_test_dir/restore-fail"
    : > "$db_test_dir/calls"
    printf '%064d\n' 1 > "$db_test_dir/identity"
    echo 'GRANT ALL PRIVILEGES ON *.* TO test' > "$db_test_dir/grants"
    echo 0 > "$db_test_dir/trust"
    echo 0 > "$db_test_dir/migration"
    DB_TRIGGER_TRUST_CHANGED=0
    export V2BOARD_DB_ADMIN_CMD='bash "$db_test_dir/admin-client.sh"'
}
fails=0
check() {
    if [ "$2" = "$3" ]; then echo "PASS $1"
    else echo "FAIL $1: expected [$2], got [$3]"; fails=$((fails + 1)); fi
}
run_status() { rc=0; "$@" > "$db_test_dir/output" 2>&1 || rc=$?; }
check_output() {
    if grep -Fq "$2" "$db_test_dir/output"; then check "$1" 0 0
    else check "$1" "$2" "$(cat "$db_test_dir/output")"; fi
}

reset_case
unset V2BOARD_DB_ADMIN_CMD
run_status deploy_check_db_trigger_capability
check 'missing admin path fails preflight' 1 "$rc"
check 'preflight does not change database' 0 "$(cat "$db_test_dir/trust")"
touch "$db_test_dir/ready"
run_status deploy_schema_update
check 'existing triggers need no admin' 0 "$rc"
check 'no unnecessary global writes' migrate "$(cat "$db_test_dir/calls")"

reset_case
run_status deploy_check_db_trigger_capability
check 'valid admin passes read-only preflight' 0 "$rc"
check 'preflight only queries' query "$(cat "$db_test_dir/calls")"
echo 'GRANT SELECT, SYSTEM_VARIABLES_ADMIN ON *.* TO test' > "$db_test_dir/grants"
run_status deploy_check_db_trigger_capability
check 'dynamic variable privilege accepted' 0 "$rc"
echo 'GRANT ALL PRIVILEGES ON app.* TO test' > "$db_test_dir/grants"
run_status deploy_check_db_trigger_capability
check 'database-level grant rejected' 1 "$rc"
echo 'GRANT SUPER ON *.* TO test' > "$db_test_dir/grants"
printf '%064d\n' 2 > "$db_test_dir/identity"
run_status deploy_check_db_trigger_capability
check 'wrong instance rejected before writes' 1 "$rc"
reset_case
touch "$db_test_dir/connection-fail"
run_status deploy_check_db_trigger_capability
check 'invalid admin connection fails' 1 "$rc"

for error in defaults auth network tls unknown; do
    reset_case
    case "$error" in
        defaults) message='Could not open required defaults file: /root/missing.cnf'; expected='defaults file is missing' ;;
        auth) message='ERROR 1045 (28000): Access denied'; expected='authentication failed' ;;
        network) message='ERROR 2002 (HY000): Connection failed'; expected='connection failed' ;;
        tls) message='ERROR 2026 (HY000): SSL connection error'; expected='TLS connection failed' ;;
        unknown) message='unexpected client failure'; expected='query failed (client exit 1)' ;;
    esac
    printf '%s\n' "$message SECRET_MUST_NOT_BE_LOGGED" > "$db_test_dir/connection-fail"
    run_status deploy_check_db_trigger_capability
    check "$error error fails preflight" 1 "$rc"
    check_output "$error diagnostic" "$expected"
    if grep -q SECRET_MUST_NOT_BE_LOGGED "$db_test_dir/output"; then
        check "$error diagnostic hides raw credentials" absent present
    fi
done
reset_case
V2BOARD_DB_ADMIN_CMD='v2board_nonexistent_mysql_client' run_status deploy_check_db_trigger_capability
check 'missing client fails preflight' 1 "$rc"
check_output 'missing client diagnostic' 'not found or is not executable'

reset_case
run_status deploy_schema_update
check 'migration succeeds' 0 "$rc"
check 'original OFF restored' 0 "$(cat "$db_test_dir/trust")"
check 'enable brackets migration' $'query\nenable\nmigrate\nrestore' "$(cat "$db_test_dir/calls")"
reset_case
echo 17 > "$db_test_dir/migration"
run_status deploy_schema_update
check 'migration error propagated' 17 "$rc"
check 'restore on migration failure' 0 "$(cat "$db_test_dir/trust")"
reset_case
echo 1 > "$db_test_dir/trust"
run_status deploy_schema_update
check 'original ON preserved' 1 "$(cat "$db_test_dir/trust")"
check 'original ON needs no writes' migrate "$(cat "$db_test_dir/calls")"
reset_case
touch "$db_test_dir/restore-fail"
run_status deploy_schema_update
check 'restore failure fails deployment' 1 "$rc"
check 'restore failure retains cleanup state' 1 "$DB_TRIGGER_TRUST_CHANGED"
reset_case
touch "$db_test_dir/enable-fail"
run_status deploy_schema_update
check 'ambiguous enable failure aborts migration' 1 "$rc"
check 'ambiguous enable failure still restores' 0 "$(cat "$db_test_dir/trust")"
check 'no migration after prepare failure' $'query\nenable\nrestore' "$(cat "$db_test_dir/calls")"

# Exercise update.sh's EXIT and signal traps with only external actions stubbed.
fixture="$db_test_dir/site"
mkdir -p "$fixture/scripts" "$fixture/.git" "$fixture/public/theme/signature/assets/static/js"
cp update.sh "$fixture/update.sh"
touch "$fixture/.env" "$fixture/public/theme/signature/assets/static/js/index.test.js"
cat > "$fixture/scripts/deploy-common.sh" <<'STUB'
source "$DB_TEST_REPO/scripts/deploy-common.sh"
source "$db_test_dir/php-stub.sh"
deploy_setup() { PHP_CMD=(php); }
deploy_check_runtime() { :; }
deploy_check_webman_runtime() { :; }
deploy_stop_webman() { WEBMAN_STOPPED=1; echo stop >> "$db_test_dir/calls"; }
deploy_start_webman() {
    if [ -f "$db_test_dir/legacy" ]; then
        [ "${WEBMAN_MANAGER:-}" = supervisor ] || return 1
        "$SUPERVISORCTL_BIN" restart "$SUPERVISOR_TARGET" || return 1
    fi
    WEBMAN_RESTARTED=1
    echo start >> "$db_test_dir/calls"
}
deploy_supervisorctl_bin() { echo fixture_supervisorctl; }
deploy_webman_master_pid() { return 1; }
deploy_supervisor_config() { return 1; }
fixture_supervisorctl() {
    case "$*" in
        status|'status webman:*') echo 'webman STOPPED Not started'; return 3 ;;
        'restart webman:*') echo supervisor-restart >> "$db_test_dir/calls" ;;
        *) return 1 ;;
    esac
}
deploy_download_composer() { :; }
deploy_install_composer() { :; }
deploy_patch_adapterman() { :; }
deploy_check_mmdb() { :; }
deploy_install_cron() { :; }
deploy_chown() { :; }
git() {
    case "$*" in
        'symbolic-ref --quiet --short HEAD') echo debug ;;
        'rev-parse --verify HEAD:update.sh')
            if [ -f "$db_test_dir/reset-done" ]; then echo new; else echo revision; fi ;;
        'reset --hard origin/debug')
            if [ -f "$db_test_dir/reexec" ]; then
                touch "$db_test_dir/reset-done" "$db_test_dir/connection-fail"
            fi ;;
        'rev-parse HEAD'|'rev-parse origin/debug') echo revision ;;
    esac
}
STUB
reset_case
unset V2BOARD_DB_ADMIN_CMD
run_status bash "$fixture/update.sh"
check 'update preflight aborts' 1 "$rc"
check 'preflight never stops Webman' '' "$(cat "$db_test_dir/calls")"
reset_case
DEPLOY_CHECK_ONLY=1 run_status bash "$fixture/update.sh"
check 'check-only succeeds with admin' 0 "$rc"
check 'check-only never writes' query "$(cat "$db_test_dir/calls")"
reset_case
run_status bash "$fixture/update.sh"
check 'update succeeds' 0 "$rc"
check 'update restores before restart' $'query\nstop\nquery\nenable\nmigrate\nrestore\nstart' "$(cat "$db_test_dir/calls")"
for failure in 17 exit term; do
    reset_case
    echo "$failure" > "$db_test_dir/migration"
    run_status bash "$fixture/update.sh"
    case "$failure" in 17) expected=17 ;; exit) expected=29 ;; term) expected=143 ;; esac
    check "update propagates $failure" "$expected" "$rc"
    check "update restores on $failure" 0 "$(cat "$db_test_dir/trust")"
    check "update restarts on $failure" start "$(tail -n 1 "$db_test_dir/calls")"
done

reset_case
touch "$db_test_dir/reexec"
run_status bash "$fixture/update.sh"
check 'reexec preflight fails on changed environment' 1 "$rc"
check 'reexec preserves stopped Webman cleanup' start "$(tail -n 1 "$db_test_dir/calls")"

# c50b92d1 exported only the re-exec marker: all shell variables were lost.
# Replay that boundary with a stopped Supervisor program returning status 3.
cat > "$fixture/legacy-update.sh" <<'STUB'
#!/bin/bash
set -Eeuo pipefail
ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$ROOT_DIR/scripts/deploy-common.sh"
WEBMAN_STOPPED=0
DEPLOY_MUTATED=0
deploy_stop_webman
DEPLOY_MUTATED=1
V2BOARD_UPDATE_REEXECUTED=1 exec bash "$ROOT_DIR/update.sh"
STUB
reset_case
touch "$db_test_dir/legacy" "$db_test_dir/connection-fail"
run_status bash "$fixture/legacy-update.sh"
check 'legacy reexec propagates preflight failure' 1 "$rc"
check 'legacy reexec restarts stopped Supervisor service' $'stop\nquery\nsupervisor-restart\nstart' "$(cat "$db_test_dir/calls")"
check_output 'legacy reexec reports partially completed deployment' '本次部署是半成品'
if grep -Fq '本次没有拉代码' "$db_test_dir/output"; then
    check 'legacy reexec must not claim nothing changed' absent present
fi

test "$fails" = 0
echo 'All database trigger deployment checks passed.'
