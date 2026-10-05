#!/bin/bash

# AdapterMan replaces these native functions only inside the Workerman process.
# They must remain enabled in the panel-managed PHP-FPM configuration so
# phpMyAdmin and normal PHP routes keep working.
DEPLOY_ADAPTERMAN_DISABLED_FUNCTIONS=(
    header header_remove headers_sent headers_list http_response_code
    setcookie
    session_create_id session_id session_name session_save_path session_status
    session_start session_write_close session_regenerate_id session_unset
    session_get_cookie_params session_set_cookie_params
    set_time_limit
)

DEPLOY_WORKERMAN_REQUIRED_FUNCTIONS=(
    stream_socket_client
    exec shell_exec
    proc_open proc_get_status proc_close
    pcntl_signal_dispatch pcntl_signal pcntl_alarm pcntl_fork pcntl_wait
    posix_getuid posix_getpwuid posix_kill posix_setsid posix_getpid
    posix_getpwnam posix_getgrnam posix_getgid posix_setgid posix_initgroups
    posix_setuid posix_isatty
)

# AcePanel 的 app.root 能在面板配置里改（它自己的 public.sh 也是先读
# /opt/ace/panel/storage/config.yml 的 root，再拼 server/php 等路径），改过之后 PHP 就不在
# /opt/ace 下了。这里按面板自己那套办法把根目录读出来，并确认传进来的 PHP 确实落在它的
# server/php/<版本>/bin 下 —— 认不出来就照旧报错，不猜路径、也不动权限。
deploy_custom_panel_root() {
    local bin="$1" root config=/opt/ace/panel/storage/config.yml
    [ -f "$config" ] || return 1
    root="$(sed -n 's/^[[:space:]]*root[[:space:]]*:[[:space:]]*//p' "$config" | tail -n 1 | tr -d "\"'" | xargs || true)"
    [ -n "$root" ] || return 1
    case "$bin" in
        "$root"/server/php/*/bin/php)
            echo "$root"
            return 0
            ;;
    esac
    return 1
}

deploy_setup() {
    ROOT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
    cd "$ROOT_DIR"

    PHP_BIN="${PHP_BIN:-php}"

    # All project processes must use the same panel-managed PHP configuration.
    case "$PHP_BIN" in
        /*) ;;
        *)
            if command -v "$PHP_BIN" >/dev/null 2>&1; then
                PHP_BIN="$(command -v "$PHP_BIN")"
            fi
            ;;
    esac
    if [ -x "$PHP_BIN" ] && command -v readlink >/dev/null 2>&1; then
        RESOLVED_PHP_BIN="$(readlink -f "$PHP_BIN" 2>/dev/null || true)"
        [ -n "$RESOLVED_PHP_BIN" ] && PHP_BIN="$RESOLVED_PHP_BIN"
    fi

    case "$PHP_BIN" in
        /www/server/php/*/bin/php)
            PANEL_NAME="aaPanel"
            PANEL_PHP_DIR="${PHP_BIN%/bin/php}"
            ;;
        /opt/ace/server/php/*/bin/php)
            PANEL_NAME="AcePanel"
            PANEL_PHP_DIR="${PHP_BIN%/bin/php}"
            ;;
        *)
            # 换过 app.root 的 AcePanel：根目录不在 /opt/ace，但 PHP 仍在
            # <root>/server/php/<版本>/bin 下，按面板配置里写的 root 认下来。
            if [ -n "$(deploy_custom_panel_root "$PHP_BIN")" ]; then
                PANEL_NAME="AcePanel"
                PANEL_PHP_DIR="${PHP_BIN%/bin/php}"
            else
                echo "ERROR: aaPanel or AcePanel PHP binary is required: $PHP_BIN" >&2
                echo "Set PHP_BIN to /www/server/php/<version>/bin/php or /opt/ace/server/php/<version>/bin/php." >&2
                echo "For an AcePanel install with a custom app.root, its root: entry in" >&2
                echo "/opt/ace/panel/storage/config.yml must match this PHP path." >&2
                return 1
            fi
            ;;
    esac

    PANEL_PHP_VERSION="${PANEL_PHP_DIR##*/php/}"
    PANEL_PHP_INI="$PANEL_PHP_DIR/etc/php.ini"
    # 面板根目录从 PHP_BIN 反推，不写死：aaPanel 固定在 /www，AcePanel 默认 /opt/ace，
    # 但它的 app.root 可以在 /opt/ace/panel/storage/config.yml 里改成别处（面板自己的
    # 安装/依赖脚本也是先读这份配置再拼路径）。写死会让改过根目录的站点扫错 cron 目录。
    PANEL_ROOT="${PANEL_PHP_DIR%/server/php/*}"
    # Keep the old variable names available for deployments that source this file.
    AAPANEL_PHP_DIR="$PANEL_PHP_DIR"
    AAPANEL_PHP_VERSION="$PANEL_PHP_VERSION"
    AAPANEL_PHP_INI="$PANEL_PHP_INI"

    if [ -n "${PHP_INI:-}" ] && [ "$PHP_INI" != "$PANEL_PHP_INI" ]; then
        echo "ERROR: PHP_INI must be the $PANEL_NAME configuration: $PANEL_PHP_INI" >&2
        return 1
    fi
    PHP_INI="$PANEL_PHP_INI"
    PHP_CMD=("$PHP_BIN" -c "$PHP_INI")

    if [ "$(id -u 2>/dev/null || echo 1)" = "0" ]; then
        export COMPOSER_ALLOW_SUPERUSER=1
    fi

    if [ ! -f "$PHP_INI" ]; then
        echo "ERROR: $PANEL_NAME PHP configuration not found: $PHP_INI" >&2
        return 1
    fi
    if ! command -v "$PHP_BIN" >/dev/null 2>&1 && [ ! -x "$PHP_BIN" ]; then
        echo "ERROR: PHP binary not found: $PHP_BIN" >&2
        return 1
    fi
}

deploy_php() {
    "${PHP_CMD[@]}" "$@"
}

# Keep one panel php.ini for every process. AdapterMan's function overrides
# are supplied only to the Webman CLI process, preserving PHP-FPM/phpMyAdmin.
deploy_prepare_webman_command() {
    local base_disabled function
    local -a base_functions

    base_disabled="$(deploy_php -r 'echo ini_get("disable_functions");' 2>&1)" || {
        echo "$base_disabled" >&2
        echo "ERROR: PHP CLI cannot read Disabled functions from $PHP_INI" >&2
        return 1
    }

    WEBMAN_DISABLED_FUNCTIONS=""
    IFS=',' read -r -a base_functions <<< "$base_disabled"
    for function in "${base_functions[@]}" "${DEPLOY_ADAPTERMAN_DISABLED_FUNCTIONS[@]}"; do
        function="$(printf '%s' "$function" | tr -d '[:space:]')"
        [ -n "$function" ] || continue
        case ",${WEBMAN_DISABLED_FUNCTIONS}," in
            *",${function},"*) ;;
            *) WEBMAN_DISABLED_FUNCTIONS="${WEBMAN_DISABLED_FUNCTIONS:+${WEBMAN_DISABLED_FUNCTIONS},}${function}" ;;
        esac
    done

    WEBMAN_PHP_CMD=("$PHP_BIN" -c "$PHP_INI" -d "disable_functions=$WEBMAN_DISABLED_FUNCTIONS")
}

deploy_webman_php() {
    deploy_prepare_webman_command || return
    "${WEBMAN_PHP_CMD[@]}" "$@"
}

deploy_check_runtime() {
    local version_output modules version_id extension

    version_output="$(deploy_php -v 2>&1)" || {
        echo "$version_output" >&2
        echo "ERROR: PHP CLI cannot start with $PHP_INI" >&2
        return 1
    }
    if echo "$version_output" | grep -Eiq 'PHP Startup|Unable to load dynamic library'; then
        echo "$version_output" >&2
        echo "ERROR: PHP CLI has a startup or extension loading error." >&2
        return 1
    fi

    version_id="$(deploy_php -r 'echo PHP_VERSION_ID;' 2>&1)" || return 1
    if [ "$version_id" -lt 80000 ]; then
        echo "ERROR: PHP 8.0 or newer is required by AdapterMan." >&2
        return 1
    fi

    echo "PHP: $(deploy_php -r 'echo PHP_VERSION;' 2>/dev/null)"
    deploy_php --ini
    modules="$(deploy_php -m 2>&1)" || {
        echo "$modules" >&2
        return 1
    }
    if echo "$modules" | grep -Eiq 'PHP Startup|Unable to load dynamic library'; then
        echo "$modules" >&2
        echo "ERROR: PHP CLI has an extension loading error." >&2
        return 1
    fi

    for extension in pdo_mysql fileinfo redis pcntl posix; do
        if ! echo "$modules" | grep -Fxq "$extension"; then
            echo "ERROR: Required PHP extension is missing: $extension" >&2
            echo "Check $PHP_INI and the PHP extension directory. No automatic repair was attempted." >&2
            return 1
        fi
    done
}

# CREATE TRIGGER is rejected with MySQL error 1419 when binary logging is on,
# the application account lacks SUPER, and
# log_bin_trust_function_creators is off.  Check this before stopping Webman so
# a deployment with no database-admin path leaves the running site untouched.
deploy_db_trigger_status() {
    local output

    output="$(deploy_php scripts/check-db-trigger-capability.php 2>&1)" || {
        echo "$output" >&2
        return 1
    }
    case "$output" in
        ready)
            printf '%s\n' "$output"
            ;;
        *)
            if [[ "$output" =~ ^needs-trust\|[a-f0-9]{64}$ ]]; then
                printf '%s\n' "$output"
            else
                echo "ERROR: unexpected database trigger capability output: $output" >&2
                return 1
            fi
            ;;
    esac
}

deploy_check_db_trigger_capability() {
    local status

    status="$(deploy_db_trigger_status)" || return 1
    if [ "$status" = ready ]; then
        echo "Database audit triggers: no binlog compatibility change required."
        return 0
    fi

    if [ -z "${V2BOARD_DB_ADMIN_CMD:-}" ]; then
        echo "ERROR: Audit triggers are missing; MySQL binlog is enabled and log_bin_trust_function_creators=OFF (error 1419)." >&2
        echo "Provide a database-admin command through V2BOARD_DB_ADMIN_CMD, for example:" >&2
        echo "  V2BOARD_DB_ADMIN_CMD='mysql --defaults-extra-file=/root/v2board-db-admin.cnf --batch --skip-column-names' bash ./update.sh" >&2
        echo "The command must accept SQL on stdin and be able to SET GLOBAL log_bin_trust_function_creators." >&2
        echo "Or ask a database administrator to set that variable temporarily before rerunning the deployment." >&2
        return 1
    fi
    deploy_db_admin_check "${status#*|}" || return 1
    echo "Database admin command supplied; the deployment will temporarily enable trust for the migration." >&2
}

deploy_db_admin_check() {
    local expected="$1" output identity line grants=0
    local grant_pattern='^GRANT (.*) ON \*\.\* TO '
    local privilege_pattern='(^|, *)(SUPER|SYSTEM_VARIABLES_ADMIN|ALL PRIVILEGES)(,|$)'
    output="$(deploy_db_admin_sql "SELECT CONCAT('v2board-db:', SHA2(CONCAT_WS('/', @@hostname, @@port, @@server_id, @@version), 256), ':', @@GLOBAL.log_bin_trust_function_creators + 0); SHOW GRANTS;" 2>/dev/null)" || {
        echo "ERROR: Database admin client cannot connect/query. Check V2BOARD_DB_ADMIN_CMD and its protected credentials file." >&2
        return 1
    }
    # The client must return raw rows (mysql --batch --skip-column-names).
    # Do not print SHOW GRANTS: MariaDB may include password hashes in it.
    while IFS= read -r line; do
        line="${line%$'\r'}"
        case "$line" in
            "v2board-db:$expected:"[01]) identity="$line" ;;
        esac
        if [[ "$line" =~ $grant_pattern ]] && [[ "${BASH_REMATCH[1]}" =~ $privilege_pattern ]]; then
            grants=1
        fi
    done <<< "$output"
    if [ -z "${identity:-}" ]; then
        echo "ERROR: Admin client must connect to the SAME MySQL server as the application and return raw rows." >&2
        echo "Include --batch --skip-column-names in V2BOARD_DB_ADMIN_CMD; check its host, port and socket." >&2
        return 1
    fi
    if [ "$grants" != 1 ]; then
        echo "ERROR: Admin client needs a direct global SUPER or SYSTEM_VARIABLES_ADMIN grant to change log_bin_trust_function_creators." >&2
        return 1
    fi
    DB_TRIGGER_ADMIN_TRUST="${identity##*:}"
}

# Run a deliberately supplied database-admin client without putting a password
# in update.sh or in the process arguments.  V2BOARD_DB_ADMIN_CMD should use a
# protected defaults file, socket credentials, sudo, or an equivalent secret
# mechanism.  SQL is sent through stdin and is limited to fixed statements in
# the functions below.
deploy_db_admin_sql() {
    local sql="$1" command="${V2BOARD_DB_ADMIN_CMD:-}"

    if [ -z "$command" ]; then
        echo "ERROR: V2BOARD_DB_ADMIN_CMD is required for this database operation." >&2
        return 1
    fi
    printf '%s\n' "$sql" | bash -c "$command"
}

deploy_db_trigger_prepare() {
    local status

    DB_TRIGGER_TRUST_CHANGED="${DB_TRIGGER_TRUST_CHANGED:-0}"
    status="$(deploy_db_trigger_status)" || return 1
    if [ "$status" = ready ]; then
        return 0
    fi

    if [ -z "${V2BOARD_DB_ADMIN_CMD:-}" ]; then
        echo "ERROR: cannot create audit triggers while MySQL binary logging is enabled." >&2
        echo "Set V2BOARD_DB_ADMIN_CMD to a database-admin client and rerun the deployment." >&2
        return 1
    fi

    deploy_db_admin_check "${status#*|}" || return 1
    # Recheck just before changing it; never restore a setting already enabled
    # by an operator. Do not run deployments sharing a database in parallel.
    [ "$DB_TRIGGER_ADMIN_TRUST" = 0 ] || return 0
    echo "Temporarily enabling log_bin_trust_function_creators for schema migration..."
    # Record restoration before the write: a lost connection may hide a
    # successful SET. EXIT must still try to put the original value back.
    DB_TRIGGER_TRUST_CHANGED=1
    deploy_db_admin_sql 'SET GLOBAL log_bin_trust_function_creators = 1;' >/dev/null || {
        echo "ERROR: database-admin command could not enable log_bin_trust_function_creators." >&2
        return 1
    }
    status="$(deploy_db_trigger_status)" || return 1
    if [ "$status" != ready ]; then
        echo "ERROR: log_bin_trust_function_creators did not become enabled." >&2
        return 1
    fi
}

deploy_db_trigger_restore() {
    local restored
    [ "${DB_TRIGGER_TRUST_CHANGED:-0}" = "1" ] || return 0

    echo "Restoring log_bin_trust_function_creators=OFF..."
    if ! restored="$(deploy_db_admin_sql 'SET GLOBAL log_bin_trust_function_creators = 0; SELECT @@GLOBAL.log_bin_trust_function_creators;')" || [ "${restored//$'\r'/}" != 0 ]; then
        echo "ERROR: failed to restore log_bin_trust_function_creators=OFF." >&2
        echo "A database administrator must restore it manually after checking the migration." >&2
        return 1
    fi
    DB_TRIGGER_TRUST_CHANGED=0
    return 0
}

deploy_schema_update() {
    local migration_status=0 restore_status=0

    if ! deploy_db_trigger_prepare; then
        deploy_db_trigger_restore || true
        return 1
    fi
    if deploy_php artisan v2board:update; then
        migration_status=0
    else
        migration_status=$?
    fi

    if deploy_db_trigger_restore; then
        restore_status=0
    else
        restore_status=$?
    fi
    if [ "$migration_status" -ne 0 ]; then
        return "$migration_status"
    fi
    return "$restore_status"
}

deploy_function_is_enabled() {
    deploy_php -r "exit(function_exists('$1') ? 0 : 1);"
}

deploy_webman_function_is_enabled() {
    deploy_webman_php -r "exit(function_exists('$1') ? 0 : 1);"
}

deploy_print_disabled_function_conflicts() {
    local conflicts=" $1 "

    case "$conflicts" in
        *" header "*|*" header_remove "*|*" headers_sent "*|*" headers_list "*|*" http_response_code "*)
            echo "    HTTP response functions: AdapterMan replaces native response handling in Webman." >&2
            ;;
    esac
    case "$conflicts" in
        *" setcookie "*)
            echo "    setcookie: AdapterMan provides cookie handling for Webman requests." >&2
            ;;
    esac
    case "$conflicts" in
        *" session_create_id "*|*" session_id "*|*" session_name "*|*" session_save_path "*|*" session_status "*|*" session_start "*|*" session_write_close "*|*" session_regenerate_id "*|*" session_unset "*|*" session_get_cookie_params "*|*" session_set_cookie_params "*)
            echo "    session_*: AdapterMan provides session handling for Webman requests." >&2
            ;;
    esac
    case "$conflicts" in
        *" set_time_limit "*)
            echo "    set_time_limit: AdapterMan requires this disabled for its long-running worker model." >&2
            ;;
    esac
}

deploy_print_php_fpm_function_conflicts() {
    local conflicts=" $1 "

    case "$conflicts" in
        *" header "*|*" header_remove "*|*" headers_sent "*|*" headers_list "*|*" http_response_code "*)
            echo "    HTTP response functions: PHP-FPM/phpMyAdmin need headers and redirects." >&2
            ;;
    esac
    case "$conflicts" in
        *" setcookie "*)
            echo "    setcookie: phpMyAdmin login and normal browser sessions need cookies." >&2
            ;;
    esac
    case "$conflicts" in
        *" session_create_id "*|*" session_id "*|*" session_name "*|*" session_save_path "*|*" session_status "*|*" session_start "*|*" session_write_close "*|*" session_regenerate_id "*|*" session_unset "*|*" session_get_cookie_params "*|*" session_set_cookie_params "*)
            echo "    session_*: phpMyAdmin login and PHP-FPM sessions require these functions." >&2
            ;;
    esac
    case "$conflicts" in
        *" set_time_limit "*)
            echo "    set_time_limit: PHP-FPM administrative and long-running requests may require it." >&2
            ;;
    esac
}

deploy_print_enabled_function_conflicts() {
    local conflicts=" $1 "

    case "$conflicts" in
        *" putenv "*)
            echo "    putenv: V2Board's Webman bootstrap sets its console mode." >&2
            ;;
    esac
    case "$conflicts" in
        *" stream_socket_client "*)
            echo "    stream_socket_client: Workerman network connection support." >&2
            ;;
    esac
    case "$conflicts" in
        *" exec "*|*" shell_exec "*|*" proc_open "*|*" proc_get_status "*|*" proc_close "*)
            echo "    exec, shell_exec, proc_*: Workerman creates and supervises worker processes." >&2
            ;;
    esac
    case "$conflicts" in
        *" pcntl_signal_dispatch "*|*" pcntl_signal "*|*" pcntl_alarm "*|*" pcntl_fork "*|*" pcntl_wait "*)
            echo "    pcntl_*: Workerman forks workers and processes reload/stop signals." >&2
            ;;
    esac
    case "$conflicts" in
        *" posix_getuid "*|*" posix_getpwuid "*|*" posix_kill "*|*" posix_setsid "*|*" posix_getpid "*|*" posix_getpwnam "*|*" posix_getgrnam "*|*" posix_getgid "*|*" posix_setgid "*|*" posix_initgroups "*|*" posix_setuid "*|*" posix_isatty "*)
            echo "    posix_*: Workerman manages PIDs, sessions, users, and process groups." >&2
            ;;
    esac
}

deploy_check_webman_runtime() {
    local function
    local php_fpm_conflicts=() adapterman_conflicts=() workerman_conflicts=()

    deploy_prepare_webman_command || return 1
    deploy_check_supervisor_php_config || return 1

    for function in "${DEPLOY_ADAPTERMAN_DISABLED_FUNCTIONS[@]}"; do
        if ! deploy_function_is_enabled "$function"; then
            php_fpm_conflicts+=("$function")
        fi
        if deploy_webman_function_is_enabled "$function"; then
            adapterman_conflicts+=("$function")
        fi
    done
    for function in putenv "${DEPLOY_WORKERMAN_REQUIRED_FUNCTIONS[@]}"; do
        if ! deploy_function_is_enabled "$function"; then
            workerman_conflicts+=("$function")
        fi
    done

    if [ "${#php_fpm_conflicts[@]}" -gt 0 ] || [ "${#adapterman_conflicts[@]}" -gt 0 ] || [ "${#workerman_conflicts[@]}" -gt 0 ]; then
        echo "ERROR: $PANEL_NAME Disabled functions conflicts with this Webman deployment:" >&2
        if [ "${#php_fpm_conflicts[@]}" -gt 0 ]; then
            echo "  Remove from $PANEL_NAME Disabled functions (required by PHP-FPM/phpMyAdmin):" >&2
            printf '    %s\n' "${php_fpm_conflicts[*]}" >&2
            deploy_print_php_fpm_function_conflicts "${php_fpm_conflicts[*]}"
            echo "    AdapterMan disables these only for the Webman process; do not disable them globally." >&2
        fi
        if [ "${#adapterman_conflicts[@]}" -gt 0 ]; then
            echo "  Webman process failed to disable AdapterMan compatibility functions:" >&2
            printf '    %s\n' "${adapterman_conflicts[*]}" >&2
            deploy_print_disabled_function_conflicts "${adapterman_conflicts[*]}"
        fi
        if [ "${#workerman_conflicts[@]}" -gt 0 ]; then
            echo "  Remove from Disabled functions:" >&2
            printf '    %s\n' "${workerman_conflicts[*]}" >&2
            deploy_print_enabled_function_conflicts "${workerman_conflicts[*]}"
        fi
        echo "No $PANEL_NAME setting was changed automatically." >&2
        echo "Keeping any listed PHP-FPM or Workerman function disabled means this shared-PHP" >&2
        echo "AdapterMan/Webman deployment cannot run safely." >&2
        return 1
    fi

    echo "Webman runtime: $PHP_INI with process-only AdapterMan function overrides OK"
}

deploy_download_composer() {
    if [ -f composer.phar ]; then
        return 0
    fi
    if command -v curl >/dev/null 2>&1; then
        curl -fsSL https://github.com/composer/composer/releases/latest/download/composer.phar -o composer.phar
    elif command -v wget >/dev/null 2>&1; then
        wget -q https://github.com/composer/composer/releases/latest/download/composer.phar -O composer.phar
    else
        echo "ERROR: curl or wget is required to download Composer." >&2
        return 1
    fi
    [ -s composer.phar ] || {
        echo "ERROR: Composer download failed." >&2
        return 1
    }
}

deploy_install_composer() {
    deploy_php composer.phar install --no-dev --optimize-autoloader --no-interaction
}

deploy_patch_adapterman() {
    local version_id
    version_id="$(deploy_php -r 'echo PHP_VERSION_ID;' 2>/dev/null)"
    if [ "$version_id" -ge 80000 ] && [ -f scripts/patch-adapterman.php ]; then
        deploy_php scripts/patch-adapterman.php
    fi
}

deploy_geoip_enabled() {
    local enabled="${IP_GEOIP_ENABLED:-}"
    if [ -z "$enabled" ] && [ -f .env ]; then
        enabled="$(sed -n 's/^[[:space:]]*IP_GEOIP_ENABLED[[:space:]]*=[[:space:]]*\([^#]*\).*$/\1/p' .env | tail -n 1 | tr -d '\r"' | xargs || true)"
    fi
    [ -n "$enabled" ] || enabled=1
    [ "$enabled" != "0" ]
}

deploy_check_mmdb() {
    local file
    local -a required_files
    if ! deploy_geoip_enabled; then
        echo "IP geolocation is disabled; MMDB file check skipped."
        return 0
    fi
    required_files=(
        resources/ipdb/china_ipv4_high_prec_v2.mmdb
        resources/ipdb/china_ipv4_high_prec.mmdb
        resources/ipdb/china_ipv4.mmdb
        resources/ipdb/china_ipv4_idc_enriched.mmdb
        resources/ipdb/china_ipv4_idc.mmdb
        resources/ipdb/china_ipv4_mobile.mmdb
        resources/ipdb/china_ipv4_other.mmdb
        resources/ipdb/china_ipv4_telecom.mmdb
        resources/ipdb/china_ipv4_unicom.mmdb
        resources/ipdb/china_ipv4_with_isp.mmdb
        resources/ipdb/china_ipv6_enriched.mmdb
        resources/ipdb/china_ipv6.mmdb
        resources/ipdb/china_ipv6_idc_enriched.mmdb
        resources/ipdb/china_ipv6_idc.mmdb
        resources/ipdb/china_ipv6_mobile.mmdb
        resources/ipdb/china_ipv6_other.mmdb
        resources/ipdb/china_ipv6_telecom.mmdb
        resources/ipdb/china_ipv6_unicom.mmdb
        resources/ipdb/china_ipv6_with_isp.mmdb
        resources/ipdb/global_ipv4_idc.mmdb
        resources/ipdb/global_ipv4_residential.mmdb
        resources/ipdb/global_ipv6_idc.mmdb
        resources/ipdb/global_ipv6_residential.mmdb
    )
    for file in "${required_files[@]}"; do
        if [ ! -s "$file" ]; then
            echo "ERROR: MMDB file is missing or empty: $file" >&2
            return 1
        fi
    done
    deploy_php -r '
        require "vendor/autoload.php";
        $ok = true;
        foreach (array_slice($argv, 1) as $path) {
            try {
                $reader = new \MaxMind\Db\Reader($path);
                $version = (int)$reader->metadata()->ipVersion;
                $expected = strpos(basename($path), "ipv6") !== false ? 6 : 4;
                if ($version !== $expected) {
                    fwrite(STDERR, "ERROR: MMDB IP version mismatch: {$path}\n");
                    $ok = false;
                }
            } catch (Throwable $e) {
                fwrite(STDERR, "ERROR: MMDB cannot be read: {$path}\n");
                $ok = false;
            }
        }
        exit($ok ? 0 : 1);
    ' "${required_files[@]}"
    echo "MMDB files: all 23 required files are present and readable."
}

deploy_webman_port() {
    local port
    port="$(sed -n 's/.*new Worker([^:]*:\/\/[^:]*:\([0-9][0-9]*\).*/\1/p' webman.php | head -n 1)"
    echo "${port:-6600}"
}

deploy_port_listening() {
    local port="$1"
    if command -v ss >/dev/null 2>&1; then
        ss -lnt 2>/dev/null | grep -qE "[:.]${port}[[:space:]]"
    elif command -v netstat >/dev/null 2>&1; then
        netstat -lnt 2>/dev/null | grep -qE "[:.]${port}[[:space:]]"
    else
        # 没有可用的探测工具时不阻断部署。
        return 0
    fi
}

# 只匹配 Workerman 主进程，避免把 worker 或任何命令行里含 webman.php 的进程算进来。
deploy_webman_master_running() {
    pgrep -f 'WorkerMan: master process.*webman\.php' >/dev/null 2>&1
}

# 主进程 pid。supervisor 的程序名就靠它反查（见 deploy_supervisor_program_for_pid）——
# 配置文件可能被面板或人工编辑弄丢 [program:x] 段头，supervisord 的实时视图不会。
deploy_webman_master_pid() {
    command -v pgrep >/dev/null 2>&1 || return 1
    pgrep -f 'WorkerMan: master process.*webman\.php' 2>/dev/null | head -n 1
}

# 必须按端口找残留：Workerman 会把 worker 的进程标题改成
# "WorkerMan: worker process AdapterMan http://127.0.0.1:6600"，里面没有 webman.php，
# 所以任何 pgrep -f webman.php 都看不见它们，而它们才是继续占着监听套接字的那批进程。
deploy_port_pids() {
    local port="$1" pids=""
    if command -v ss >/dev/null 2>&1; then
        pids="$(ss -lntp 2>/dev/null | grep -E "[:.]${port}[[:space:]]" | grep -o 'pid=[0-9]*' | cut -d= -f2)"
    fi
    if [ -z "$pids" ] && command -v lsof >/dev/null 2>&1; then
        pids="$(lsof -t -iTCP:"$port" -sTCP:LISTEN 2>/dev/null)"
    fi
    if [ -z "$pids" ] && command -v fuser >/dev/null 2>&1; then
        pids="$(fuser -n tcp "$port" 2>/dev/null | tr -s ' ' '\n')"
    fi
    { printf '%s\n' "$pids" | grep -E '^[0-9]+$' | sort -u; } || true
}

# 把占用端口的进程连同父进程、命令行一起列出来。排查「谁把它拉起来的」时，
# 父进程是 supervisord 还是 1（孤儿进程）是两条完全不同的线索，光看 pid 看不出来。
deploy_report_port_holders() {
    local port="$1" pid ppid cmd
    for pid in $(deploy_port_pids "$port"); do
        ppid=""
        cmd=""
        # 先判可读再读：`cmd < "/proc/…" 2>/dev/null` 这种写法里，重定向失败是 shell 自己报的
        # 错，那时 2>/dev/null 还没生效，会把一行 "No such file or directory" 混进部署输出。
        if [ -r "/proc/$pid/status" ]; then
            ppid="$(sed -n 's/^PPid:[[:space:]]*//p' "/proc/$pid/status")"
        fi
        if [ -r "/proc/$pid/cmdline" ]; then
            cmd="$(tr '\0' ' ' < "/proc/$pid/cmdline")"
        fi
        printf '  pid %s  ppid %s  %s\n' "$pid" "${ppid:-?}" "$cmd" >&2
    done
}

# 进程标题被 Workerman 改写过，所以三种可能的写法都认；认不出来的一律不动，
# 免得误杀恰好占用同一端口的其它服务。
deploy_pid_is_webman() {
    local cmdline
    [ -r "/proc/$1/cmdline" ] || return 1
    cmdline="$(tr '\0' ' ' < "/proc/$1/cmdline" 2>/dev/null)"
    case "$cmdline" in
        *WorkerMan*|*AdapterMan*|*php*) return 0 ;;
    esac
    return 1
}

deploy_free_webman_port() {
    local port="$1" pid signal attempt
    deploy_port_listening "$port" || return 0
    for signal in TERM KILL; do
        for pid in $(deploy_port_pids "$port"); do
            if deploy_pid_is_webman "$pid"; then
                kill "-$signal" "$pid" 2>/dev/null || true
            else
                echo "ERROR: port ${port} is held by pid ${pid}, which is not a Webman process." >&2
                if [ -r "/proc/$pid/cmdline" ]; then
                    echo "$(tr '\0' ' ' < "/proc/$pid/cmdline")" >&2
                fi
                return 1
            fi
        done
        for attempt in 1 2 3 4 5; do
            deploy_port_listening "$port" || return 0
            sleep 1
        done
    done
    return 1
}

# aaPanel 的 supervisor 插件把 supervisorctl 装在面板自带的 pyenv 里，不在 PATH 上，
# 所以 command -v supervisorctl 会失败，不能用它来判断有没有 supervisor。
# AcePanel 反过来：它的 supervisor 应用直接 dnf/apt 装发行版那个 supervisor，面板自己也是
# 调用 PATH 上的 supervisorctl，二进制落在 /usr/bin —— 已经被下面的 PATH 分支覆盖。
deploy_supervisorctl_bin() {
    local candidate
    if [ -n "${SUPERVISORCTL:-}" ]; then
        echo "$SUPERVISORCTL"
        return 0
    fi
    if command -v supervisorctl >/dev/null 2>&1; then
        echo supervisorctl
        return 0
    fi
    for candidate in \
        /www/server/panel/pyenv/bin/supervisorctl \
        /usr/local/bin/supervisorctl \
        /usr/bin/supervisorctl; do
        [ -x "$candidate" ] && { echo "$candidate"; return 0; }
    done
    return 1
}

# 面板把一个守护进程写成一个独立配置文件（程序名即 [program:<名字>]），位置随面板不同：
#   aaPanel   /www/server/panel/plugin/supervisor/profile/<名字>.ini
#   AcePanel  /etc/supervisor/conf.d/<名字>.conf（Debian/Ubuntu）
#             /etc/supervisord.d/<名字>.conf（RHEL：其安装脚本把主配置里的
#             files = supervisord.d/*.ini 改写成 *.conf，只按 *.ini 扫会整个漏掉，
#             于是误判成「没有 supervisor 托管」，转去手工起进程与 supervisord 抢端口）
#   通用部署  Debian 系与 RHEL 系都用上面同名目录，两种后缀都认。
deploy_supervisor_config() {
    local conf

    for conf in /www/server/panel/plugin/supervisor/profile/*.ini \
                /www/server/panel/plugin/supervisor/profile/*.conf \
                /etc/supervisor/conf.d/*.conf \
                /etc/supervisor/conf.d/*.ini \
                /etc/supervisord.d/*.conf \
                /etc/supervisord.d/*.ini; do
        [ -f "$conf" ] || continue
        grep -Eq 'webman\.(php|sh)' "$conf" || continue
        grep -Fq "$ROOT_DIR" "$conf" || continue
        echo "$conf"
        return 0
    done
    return 1
}

deploy_check_supervisor_php_config() {
    local conf command

    conf="$(deploy_supervisor_config)" || return 0

    # 段头丢了不影响本脚本（它按 pid 反查程序名），但影响 supervisord 自己：reread 会因为
    # 「文件里找不到节」报错，update 更可能把程序整个移除，站点从此失去自动重启。而它现在
    # 照跑不误，是因为配置在 supervisord 启动时就已读进内存 —— 所以这里只警告、不阻断部署。
    if ! grep -qE '^[[:space:]]*\[(program|group):' "$conf"; then
        local program_hint
        program_hint="$(deploy_supervisor_program 2>/dev/null || true)"
        echo "WARNING: $conf has no [program:...] section header." >&2
        echo "  supervisord still runs this program because it read the file at startup," >&2
        echo "  but the next reread or supervisord restart cannot parse it and may drop the program." >&2
        echo "  Fix: sed -i '1i [program:${program_hint:-webman}]' $conf && supervisorctl reread && supervisorctl update" >&2
    fi
    command="$(sed -n 's/^[[:space:]]*command[[:space:]]*=[[:space:]]*//p' "$conf" | head -n 1)"
    case "$command" in
        *"$ROOT_DIR/scripts/webman.sh"*) return 0 ;;
        *"$PHP_BIN"*"-c"*"$PHP_INI"*"-d"*"disable_functions="*webman.php*) return 0 ;;
    esac

    echo "ERROR: Supervisor Webman command does not use the $PANEL_NAME PHP configuration:" >&2
    echo "  $conf" >&2
    echo "Expected command=${ROOT_DIR}/scripts/webman.sh start" >&2
    echo "The wrapper reads ${PHP_INI} and applies AdapterMan overrides only to Webman." >&2
    echo "Do not use bare php, -n, or a project php.ini. No process was stopped." >&2
    return 1
}

# 程序名不能写死，也不能只信配置文件。
#
# 2026-10-02 的事故就栽在这里：/etc/supervisor/conf.d/webman.conf 里的 [program:webman]
# 段头被编辑弄丢了，文件只剩 command= / directory= / autorestart= 这些键。于是「从配置
# 文件反查程序名」返回空，脚本判定「没有 supervisor 托管」，转去手工 webman.php stop ——
# 而 supervisord 内存里那条程序还在、配置是 autorestart=true，几秒后就把进程重新拉起来
# 占住端口。一分多钟后脚本自己 start 时撞上 Address already in use，部署死在最后一步。
# 而 supervisord 的实时视图（supervisorctl status）里明明写着程序名和 pid，只是没人问它。
#
# 所以顺序是：环境变量 → 按 pid 反查（supervisord 实时视图）→ 程序名里带 webman 的那条
# → 最后才是读配置文件。前两条不依赖配置文件长什么样。
deploy_supervisor_program() {
    local conf name sc
    if [ -n "${SUPERVISOR_PROGRAM:-}" ]; then
        echo "$SUPERVISOR_PROGRAM"
        return 0
    fi
    if sc="$(deploy_supervisorctl_bin)"; then
        if name="$(deploy_supervisor_program_for_pid "$sc" "$(deploy_webman_master_pid || true)")"; then
            echo "$name"
            return 0
        fi
        if name="$(deploy_supervisor_program_by_name "$sc")"; then
            echo "$name"
            return 0
        fi
    fi
    if conf="$(deploy_supervisor_config)"; then
        name="$(sed -n 's/^\[program:\([^]]*\)\].*/\1/p' "$conf" | head -n 1)"
        [ -n "$name" ] && { echo "$name"; return 0; }
    fi
    return 1
}

# 用 pid 反查 supervisor 程序名。supervisorctl status 是 supervisord 的实时视图，每行形如
# 「webman   RUNNING   pid 1236757, uptime 0:04:46」，那个 pid 就是这条配置真正拉起来的进程。
# 包装脚本用 exec 起 php，pid 不会变，所以它同时就是 Workerman 主进程的 pid。
# 这条路不依赖配置文件的布局、程序名怎么写、numprocs 是几。
deploy_supervisor_program_for_pid() {
    local sc="$1" want="$2" line name pid
    [ -n "$sc" ] && [ -n "$want" ] || return 1
    while IFS= read -r line; do
        [ -n "$line" ] || continue
        name="${line%%[[:space:]]*}"
        pid="$(printf '%s\n' "$line" | sed -n 's/.*[[:space:]]pid \([0-9][0-9]*\).*/\1/p')"
        [ -n "$pid" ] && [ "$pid" = "$want" ] || continue
        # 「foo:foo_00」（分组）与「webman_00」（numprocs）都归一成组名，
        # 因为停 / 启要用 <程序名>:* 这种组形式。
        name="${name%%:*}"
        case "$name" in
            *_[0-9][0-9]) name="${name%_[0-9][0-9]}" ;;
        esac
        echo "$name"
        return 0
    done <<< "$("$sc" status 2>/dev/null || true)"
    return 1
}

# 退路：程序名里带 webman 的那条。只在 pid 认不出来时用（进程已经停了、标题被改写过…），
# 仍然比读配置文件可靠 —— 这个名字至少是 supervisord 自己认的。
deploy_supervisor_program_by_name() {
    local sc="$1" line name
    [ -n "$sc" ] || return 1
    while IFS= read -r line; do
        [ -n "$line" ] || continue
        name="${line%%[[:space:]]*}"
        case "$name" in
            *[Ww]eb[Mm]an*) ;;
            *) continue ;;
        esac
        name="${name%%:*}"
        case "$name" in
            *_[0-9][0-9]) name="${name%_[0-9][0-9]}" ;;
        esac
        echo "$name"
        return 0
    done <<< "$("$sc" status 2>/dev/null || true)"
    return 1
}

# numprocs + process_name=%(program_name)s_%(process_num)02d 会让进程叫 <program>_00，
# 裸程序名查不到（no such process），必须用组形式 <program>:*。
deploy_supervisor_target() {
    case "$1" in
        *:*) echo "$1" ;;
        *)   echo "${1}:*" ;;
    esac
}

# supervisorctl status 对 FATAL / STOPPED 的程序返回非 0 退出码，用退出码判断会把
# 「程序存在但没在跑」误判成「没有这个程序」，于是掉回手工分支去和 supervisord 抢端口。
deploy_supervisor_knows_program() {
    "$1" status "$2" 2>/dev/null | grep -qE 'RUNNING|STOPPED|STARTING|BACKOFF|FATAL|EXITED|STOPPING|UNKNOWN'
}

deploy_supervisor_running() {
    "$1" status "$2" 2>/dev/null | grep -q RUNNING
}

# 启动前再认一次 supervisor。
#
# 停的那一步没认出来时（配置丢了 [program:x] 段头、程序名对不上……），脚本会绕过
# supervisord 手工停进程，而 supervisord 按 autorestart=true 几秒后又把它拉回来 ——
# 等走到 start，端口已经被占住。这时按「占着端口的那个 pid」反查，一定能从
# supervisorctl status 里认出程序名，改用 supervisorctl restart 收尾，而不是对着一个
# 不属于自己的进程报 Address already in use。
deploy_supervisor_adopt_running() {
    local sc program port pid
    sc="$(deploy_supervisorctl_bin)" || return 1
    port="$(deploy_webman_port)"
    pid="$(deploy_webman_master_pid || true)"
    if [ -z "$pid" ]; then
        pid="$(deploy_port_pids "$port" | head -n 1)"
    fi
    # 刻意只用 pid 反查、不用名字兜底：这一步决定「重启谁」，认错了就是把别的守护重启
    # 一遍而站点仍然没人起。pid 对不上就老实走手工分支并报错。
    program="$(deploy_supervisor_program_for_pid "$sc" "$pid")" || return 1
    SUPERVISORCTL_BIN="$sc"
    SUPERVISOR_PROGRAM="$program"
    SUPERVISOR_TARGET="$(deploy_supervisor_target "$program")"
    WEBMAN_MANAGER=supervisor
    return 0
}

# 手工停完盯 5 秒。先无条件等一会儿再看：进程刚停、supervisord 还没反应过来，
# 立刻查一定是「端口是空的」，什么都发现不了。真被重新拉起来了就把现场打出来 ——
# 否则要等一分多钟后的 start 才以一句 already in use 收场，那时 pid 已经换了一批，
# 看不出是谁干的。返回非 0 只是给调用处读，调用处一律 || true：此刻中止部署只会把
# 站点留在「已经停了但没人起」的状态，比继续往下走更糟。
deploy_watch_webman_respawn() {
    local port="$1" attempt
    for attempt in 1 2 3 4 5; do
        sleep 1
        if deploy_port_listening "$port"; then
            echo "WARNING: port ${port} is occupied again ${attempt}s after Webman was stopped." >&2
            echo "Something is auto-restarting it — supervisord with autorestart=true does exactly that." >&2
            echo "Holders (pid / parent pid / command):" >&2
            deploy_report_port_holders "$port"
            return 1
        fi
    done
    return 0
}

deploy_stop_webman() {
    local sc program

    WEBMAN_MANAGER=webman
    if sc="$(deploy_supervisorctl_bin)" && program="$(deploy_supervisor_program)"; then
        SUPERVISORCTL_BIN="$sc"
        SUPERVISOR_PROGRAM="$program"
        SUPERVISOR_TARGET="$(deploy_supervisor_target "$program")"
        if deploy_supervisor_knows_program "$sc" "$SUPERVISOR_TARGET"; then
            # 这里必须走 supervisorctl：配置是 autorestart=true，手工 webman.php stop
            # 之后 supervisord 会在几秒内把它重新拉起来占住端口，随后我们自己的 start
            # 必然撞上 Address already in use，而且会起出一套 supervisord 不认的实例。
            echo "Webman is managed by supervisor: $SUPERVISOR_TARGET"
            "$sc" stop "$SUPERVISOR_TARGET"
            WEBMAN_MANAGER=supervisor
        fi
    fi

    if [ "$WEBMAN_MANAGER" != supervisor ]; then
        deploy_webman_php webman.php stop >/dev/null 2>&1 || true
        # TERM 之后主进程有时不会立刻退出，残留进程会让启动校验误判为成功。
        local attempt port
        for attempt in 1 2 3 4 5; do
            deploy_webman_master_running || break
            sleep 1
        done
        if deploy_webman_master_running; then
            echo "Webman did not exit on TERM; forcing shutdown."
            pkill -f 'WorkerMan: master process.*webman\.php' >/dev/null 2>&1 || true
            sleep 1
        fi
        # webman.php stop 靠 pid 文件定位主进程，pid 文件过期时它只会打印
        # "Master pid:N is not alive" 就返回，worker 仍然握着监听套接字，
        # 于是下一步 start 必然撞上 Address already in use。以端口为准再收一次尾。
        port="$(deploy_webman_port)"
        if deploy_port_listening "$port"; then
            echo "Port ${port} is still in use after stop; clearing leftover workers."
            deploy_free_webman_port "$port" || {
                echo "ERROR: could not free 127.0.0.1:${port}; aborting before any code changes." >&2
                return 1
            }
        fi
        # 停干净之后再盯几秒，见 deploy_watch_webman_respawn。
        deploy_watch_webman_respawn "$port" || true
    fi
    WEBMAN_STOPPED=1
}

deploy_start_webman() {
    local port attempt
    WEBMAN_START_ATTEMPTED=1
    port="$(deploy_webman_port)"

    # 停的那一步没认出 supervisor 时（配置丢了 [program:x] 段头、程序名对不上……），
    # 这里按「占着端口的那个 pid」再认一次，见 deploy_supervisor_adopt_running。
    if [ "${WEBMAN_MANAGER:-webman}" != supervisor ] && deploy_supervisor_adopt_running; then
        echo "Webman is managed by supervisor: $SUPERVISOR_TARGET"
        echo "  (依据是正在跑的进程，不是配置文件里那个可能已被改坏的名字)"
    fi
    if [ "${WEBMAN_MANAGER:-webman}" = supervisor ]; then
        # restart 而不是 start：supervisord 可能已经按 autorestart 把它拉起来了，
        # 这时 start 会以 ERROR (already started) 退出、把 set -e 引到 ERR trap 上；
        # 而且那批 worker 是部署中途 fork 的，可能还握着 git reset 之前的代码，
        # 重启一次才能保证它们加载的是这次部署的代码。
        "$SUPERVISORCTL_BIN" restart "$SUPERVISOR_TARGET"
        # start 返回不代表端口已经 bind 好，配置里 startsecs=3 还要再等一会儿。
        for attempt in 1 2 3 4 5 6 7 8 9 10; do
            if deploy_supervisor_running "$SUPERVISORCTL_BIN" "$SUPERVISOR_TARGET" \
               && deploy_port_listening "$port"; then
                echo "Webman is listening on 127.0.0.1:${port} (supervisor: $SUPERVISOR_TARGET)"
                WEBMAN_RESTARTED=1
                return 0
            fi
            sleep 1
        done
        echo "ERROR: supervisor program $SUPERVISOR_TARGET is not serving 127.0.0.1:${port}." >&2
        "$SUPERVISORCTL_BIN" status "$SUPERVISOR_TARGET" >&2 || true
        return 1
    else
        # 端口没空出来就直接说清楚是谁占着，而不是让 Workerman 抛一段
        # stream_socket_server / Address already in use 的堆栈出来。
        if deploy_port_listening "$port"; then
            echo "ERROR: 127.0.0.1:${port} is already in use by pid(s): $(deploy_port_pids "$port" | tr '\n' ' ')" >&2
            deploy_report_port_holders "$port"
            echo "Webman cannot start until they are gone." >&2
            return 1
        fi
        # 启动失败时 AdapterMan 会把原因写到输出上，不要吞掉。
        deploy_webman_php webman.php start -d || {
            echo "ERROR: Webman failed to start; see the AdapterMan output above." >&2
            return 1
        }
        for attempt in 1 2 3 4 5 6 7 8 9 10; do
            if deploy_webman_master_running && deploy_port_listening "$port"; then
                echo "Webman is listening on 127.0.0.1:${port}"
                WEBMAN_RESTARTED=1
                return 0
            fi
            sleep 1
        done
        echo "ERROR: Webman is not listening on 127.0.0.1:${port} after start." >&2
        return 1
    fi
}

deploy_chown() {
    case "${PANEL_NAME:-}" in
        aaPanel|AcePanel)
            chown -R www .
            ;;
    esac
}

# ---- Laravel 计划任务 --------------------------------------------------------
# app/Console/Kernel.php 里的 schedule 全靠外部每分钟调用一次 artisan schedule:run，
# 本仓库没有任何常驻载体承担这件事（config/ 下没有 process.php，webman.php 里也没有
# Timer）。缺这条 cron 时站点表面完全正常，但流量重置、统计、订单/工单检查、订阅风险
# 与审计聚合/清理全部静默停摆，所以安装和升级都要保证它存在。
# 选 cron 而不是 webman 自定义进程：cron 不依赖 webman 进程存活（升级期间 webman 会被
# 停掉几十秒），与上游 v2board 的运维习惯一致，也不会在 supervisor 托管下被重复拉起。
V2BOARD_CRON_MARKER='# v2board-schedule'

# 可选 CRON_USER：把条目写到指定用户的 crontab（aaPanel 下通常是 www，可让
# schedule:run 产生的日志属主与 Webman 一致）。默认写当前用户。
deploy_crontab() {
    if [ -n "${CRON_USER:-}" ]; then
        crontab -u "$CRON_USER" "$@"
    else
        crontab "$@"
    fi
}

deploy_cron_php_bin() {
    local bin="${PHP_BIN:-php}"
    case "$bin" in
        /*) ;;
        # cron 的 PATH 很短，必须落成绝对路径。
        *) bin="$(command -v "$bin" 2>/dev/null || echo "$bin")" ;;
    esac
    echo "$bin"
}

deploy_cron_php_ini() {
    echo "$PHP_INI"
}

# 可被 deploy_install_cron 改成 /dev/null（日志文件建不出来时的兜底，见 deploy_cron_prepare_log）。
deploy_cron_log() {
    echo "${V2BOARD_CRON_LOG:-$ROOT_DIR/storage/logs/schedule-cron.log}"
}

# cron、Webman、Horizon、artisan 与 Composer 都使用同一套面板 PHP 配置。
#
# 输出去向是分开的，不是 >> /dev/null 2>&1：
#   stdout -> /dev/null：Laravel 8 的 schedule:run 在没有到期任务时每分钟都会往 stdout 打一行
#             "No scheduled commands are ready to run."，落盘就是每年几十 MB 的纯噪音；扔掉它
#             同时也避免 cron 每分钟发一封邮件。
#   stderr -> storage/logs/schedule-cron.log：这条 cron 最常见的真实故障（PHP 绝对路径写错、
#             php.ini 读不到、cron 用户对项目目录没权限）全都发生在 Laravel 启动之前，
#             storage/logs/laravel.log 里一个字都不会有。全扔 /dev/null 的话，条目看着装好了却
#             永远不干活，唯一症状只剩后台「系统状态」一颗红灯，没有任何可查的现场。
# 整段用 { ...; } 包起来再重定向，这样 cd 失败（目录被删、权限不足）的报错也会进日志，
# 而不是只有 php 的报错进日志。
deploy_cron_line() {
    local log redirect
    log="$(deploy_cron_log)"
    if [ "$log" = /dev/null ]; then
        redirect='>> /dev/null 2>&1'
    else
        redirect="$(printf ">> /dev/null 2>> '%s'" "$log")"
    fi
    printf "* * * * * { cd '%s' && '%s' -c '%s' artisan schedule:run; } %s" \
        "$ROOT_DIR" "$(deploy_cron_php_bin)" "$(deploy_cron_php_ini)" "$redirect"
}

# 日志文件必须在写 crontab 之前就存在且对 cron 用户可写：`2>> 文件` 打不开时 shell 会在执行
# schedule:run 之前就放弃整条命令，那等于调度彻底不跑 —— 比原来的 /dev/null 更糟。
# 因此这里先建好文件，指定了 CRON_USER 就把属主交给它（未指定时属主就是当前用户，天然可写）。
deploy_cron_prepare_log() {
    local log
    log="$(deploy_cron_log)"
    [ -d "$(dirname "$log")" ] || return 1
    if [ ! -f "$log" ]; then
        : >> "$log" 2>/dev/null || return 1
    fi
    if [ -n "${CRON_USER:-}" ] && [ "$(id -u 2>/dev/null || echo 1)" = "0" ]; then
        chown "$CRON_USER" "$log" 2>/dev/null || true
    fi
    [ -w "$log" ] || return 1
}

deploy_cron_manual_hint() {
    echo "WARNING: the scheduler cron entry was NOT installed: $1" >&2
    echo "Laravel's scheduler will not run at all: traffic resets, statistics, order/ticket" >&2
    echo "checks and the audit aggregation stay silently stopped." >&2
    echo "Add this line by hand (crontab -e) and re-run the check afterwards:" >&2
    echo "  $2" >&2
}

deploy_cron_daemon_hint() {
    command -v pgrep >/dev/null 2>&1 || return 0
    if pgrep -x crond >/dev/null 2>&1 || pgrep -x cron >/dev/null 2>&1; then
        return 0
    fi
    echo "WARNING: no cron daemon (cron/crond) process found; the entry will never fire." >&2
    echo "Enable it, e.g.: systemctl enable --now crond   (Debian/Ubuntu: cron)" >&2
}

# 只有 ROOT_DIR 后面紧跟 /、引号、空白、命令分隔符或行尾时才算「指向本目录」。裸的子串
# 匹配会让 /www/wwwroot/v2board 把隔壁 /www/wwwroot/v2board-test 的条目认成自己的，结果
# 本站的 cron 永远写不进去，调度依旧不跑 —— 那是最难查的一种漏配。
deploy_cron_text_targets_root() {
    local rest="$1"
    while [ -n "$rest" ]; do
        case "$rest" in
            *"$ROOT_DIR"*) ;;
            *) return 1 ;;
        esac
        rest="${rest#*"$ROOT_DIR"}"
        case "$rest" in
            ''|/*|\'*|\"*|[[:space:]]*|'&'*|';'*|'|'*) return 0 ;;
        esac
    done
    return 1
}

# 从 stdin 逐行找「未被注释掉、且指向本目录」的 schedule:run。注释行不算已配置：被 # 掉的
# 条目本来就不会跑。
deploy_cron_has_schedule_run() {
    local line
    while IFS= read -r line; do
        case "$line" in
            ''|\#*) continue ;;
        esac
        case "$line" in
            *schedule:run*) ;;
            *) continue ;;
        esac
        if deploy_cron_text_targets_root "$line"; then
            return 0
        fi
    done
    return 1
}

# 幂等：几种"已配置"都算命中并原样跳过 —— 我们自己的标记行、运维手写的任何指向本目录的
# schedule:run、/etc/crontab 与 /etc/cron.d 里的系统级条目、其它用户的 crontab（/var/spool/cron）、
# 以及 aaPanel/AcePanel 面板任务脚本。命中时一个字都不改。
#
# 注意这里一律用 here-string 而不是 `printf ... | deploy_cron_has_schedule_run`：读取端一旦匹配
# 就 return，管道左边的 printf/grep 会吃到 SIGPIPE 退出 141，而 init.sh / update.sh 都是
# `set -o pipefail`，整条管道于是返回 141 —— 明明已配置却被判成"没配"，再追加一条重复条目。
# here-string 走临时文件，没有写端，不存在这个洞。
deploy_cron_already_configured() {
    local current="$1" conf
    if grep -Fxq "$V2BOARD_CRON_MARKER $ROOT_DIR" <<< "$current"; then
        echo "Scheduler cron: marker entry already present; crontab left untouched."
        return 0
    fi
    if deploy_cron_has_schedule_run <<< "$current"; then
        echo "Scheduler cron: an existing schedule:run entry for this directory was found; crontab left untouched."
        return 0
    fi
    for conf in /etc/crontab /etc/cron.d/*; do
        [ -f "$conf" ] || continue
        if deploy_cron_has_schedule_run < "$conf"; then
            echo "Scheduler cron: an existing schedule:run entry for this directory was found in $conf; crontab left untouched."
            return 0
        fi
    done
    # 其它用户的 crontab。运维常把调度装在 www 名下（`crontab -u www -e`，好让 storage/logs 里
    # 新建的文件属主与 Webman 一致），而 update.sh 一般是以 root 跑的：只看 `crontab -l`（= root
    # 自己那份）就完全看不见 www 的条目，于是给一个本来配好的站点再追加一条 —— 每分钟两次
    # schedule:run，而 send:remindMail / v2board:statistics / reset:traffic 都没有
    # withoutOverlapping，会在同一分钟跑两遍（重复发信、重复统计）。
    # 只有 root 读得到这些目录；非 root 时循环里的 -r 判断会把它们统统跳过，行为与加固前一致。
    for conf in /var/spool/cron/* /var/spool/cron/crontabs/*; do
        [ -f "$conf" ] || continue
        [ -r "$conf" ] || continue
        if deploy_cron_has_schedule_run < "$conf"; then
            echo "Scheduler cron: an existing schedule:run entry for this directory was found in $conf (another user's crontab); crontab left untouched."
            return 0
        fi
    done
    # 面板计划任务把命令正文写进面板 cron 目录，crontab 里只留一行 wrapper 路径，既没有
    # schedule:run 也没有本目录。不看这些脚本就会把面板里已经配好的调度判成缺失，于是再
    # 追加一条 —— 每分钟两次 schedule:run，而 v2board:statistics / reset:traffic /
    # send:remindMail 这些没有 withoutOverlapping 的命令就会在同一分钟里跑两遍（重复发信、
    # 重复统计）。两个面板的落点不同：
    #   aaPanel   /www/server/cron/<id>（命令正文直接写在该文件里）
    #   AcePanel  <app.root>/server/cron/<随机>.sh（另有 _wrapper.sh，日志在 logs/ 子目录）
    # 首项跟随 PHP_BIN 反推出的 PANEL_ROOT，面板改过 app.root 时也扫得到；后两项是两家的
    # 默认根，留着兜底（同机装过两套面板时才可能命中不同的那个）。
    for conf in "${PANEL_ROOT:-/www}"/server/cron/* /www/server/cron/* /opt/ace/server/cron/*; do
        [ -f "$conf" ] || continue
        case "$conf" in
            *.log) continue ;;
        esac
        if grep -Fq 'schedule:run' "$conf" 2>/dev/null \
           && deploy_cron_text_targets_root "$(cat "$conf" 2>/dev/null)"; then
            echo "Scheduler cron: a panel task for this directory was found in $conf; crontab left untouched."
            return 0
        fi
    done
    return 1
}

# systemd timer 托管的站点：schedule:run 写在某个 .service 的 ExecStart 里，crontab 与
# /etc/cron.d 里一个字都没有，光靠上面那些扫描认不出来。这里只告警不跳过 —— 认成"已配置"却
# 其实 timer 没启用，会让调度彻底不跑，比多一条 cron 危险得多。真是 timer 在跑的话，用
# SKIP_CRON=1 跑部署脚本即可整段跳过。
deploy_cron_systemd_hint() {
    local unit found=0
    for unit in /etc/systemd/system/*.service /etc/systemd/system/*.timer; do
        [ -f "$unit" ] || continue
        grep -Fq 'schedule:run' "$unit" 2>/dev/null || continue
        deploy_cron_text_targets_root "$(cat "$unit" 2>/dev/null)" || continue
        echo "WARNING: a systemd unit for this directory already runs schedule:run: $unit" >&2
        found=1
    done
    [ "$found" = 1 ] || return 0
    echo "A cron entry is being added anyway (an existing unit file does not prove its timer is" >&2
    echo "enabled, and skipping on a disabled timer would stop the scheduler entirely)." >&2
    echo "If that unit really is this site's scheduler, delete the cron entry just added and" >&2
    echo "re-run deployments with SKIP_CRON=1 to leave the crontab alone." >&2
}

deploy_install_cron() {
    # V2BOARD_CRON_LOG 声明成 local，兜底只影响本次调用，不会泄漏到后续调用。
    local line current tmp owner V2BOARD_CRON_LOG=""

    # 运维已经用别的载体（systemd timer、外部调度器、容器 sidecar）跑 schedule:run 时的显式退路，
    # 脚本一个字都不改 crontab。
    if [ "${SKIP_CRON:-0}" = "1" ]; then
        echo "Scheduler cron: SKIP_CRON=1, crontab left untouched."
        echo "Make sure something else calls artisan schedule:run every minute for $ROOT_DIR." >&2
        return 0
    fi

    owner="${CRON_USER:-$(id -un 2>/dev/null || echo "current user")}"

    if ! command -v crontab >/dev/null 2>&1; then
        deploy_cron_prepare_log || V2BOARD_CRON_LOG=/dev/null
        deploy_cron_manual_hint "the crontab command is not available" "$(deploy_cron_line)"
        return 0
    fi

    # 没有 crontab 时 crontab -l 会以非 0 退出，这不是错误。
    current="$(deploy_crontab -l 2>/dev/null || true)"

    # 已配置的站点在这里原样返回：不建日志文件、不动 crontab、不打印任何多余东西。
    if deploy_cron_already_configured "$current"; then
        deploy_cron_daemon_hint
        return 0
    fi

    # 日志文件建不出来（storage/logs 不可写等）时退回历史写法 >> /dev/null 2>&1：宁可没有现场，
    # 也不能让 `2>> 文件` 打不开而使整条 cron 在执行 schedule:run 之前就放弃。
    if ! deploy_cron_prepare_log; then
        echo "WARNING: cannot prepare $(deploy_cron_log) for the cron entry; stderr will go to" >&2
        echo "/dev/null instead, so a failing entry will leave no trace. Check permissions on" >&2
        echo "$ROOT_DIR/storage/logs." >&2
        V2BOARD_CRON_LOG=/dev/null
    fi
    line="$(deploy_cron_line)"

    # 有 schedule:run 但没有一条提到本目录：多半是同机另一个站点（正常，两个站点各需一条），
    # 但也可能是本站点用了我们认不出的写法（典型是 docroot 为软链，crontab 里的路径与
    # ROOT_DIR 解析结果不同）。后者会变成每分钟两次 schedule:run，而 v2board:statistics /
    # reset:traffic / send:remindMail 没有 withoutOverlapping，会在同一分钟跑两遍（重复统计、
    # 重复发信）。多站点是合法诉求所以照旧追加，但必须把这件事说出来让运维自己核一眼。
    # 用一条 grep -E 而不是 `grep -v ... | grep -Fq`：管道右边匹配上就退出，左边会吃 SIGPIPE 退出
    # 141，而 pipefail 会把 141 当成整条管道的结果，于是该告警在大 crontab 上莫名不打印。
    if grep -Eq '^[[:space:]]*[^#[:space:]].*schedule:run' <<< "$current"; then
        echo "WARNING: the crontab already has schedule:run entries, none of which targets $ROOT_DIR." >&2
        echo "Appending one for this directory anyway (another site on the same host needs its own)." >&2
        echo "If one of the existing entries is in fact this site — a symlinked document root, say —" >&2
        echo "remove the duplicate by hand: two schedule:run per minute means send:remindMail," >&2
        echo "reset:traffic and v2board:statistics can each run twice in the same minute." >&2
    fi
    deploy_cron_systemd_hint

    tmp="$(mktemp 2>/dev/null || echo "${TMPDIR:-/tmp}/v2board-cron.$$")"
    {
        # 原有条目逐字保留，只在末尾追加，绝不覆盖运维已有的 crontab。
        if [ -n "$current" ]; then
            printf '%s\n' "$current"
        fi
        printf '%s %s\n' "$V2BOARD_CRON_MARKER" "$ROOT_DIR"
        printf '%s\n' "$line"
    } > "$tmp"

    # 写入失败时不吞 stderr：crontab 自己的报错（权限、语法）才是运维要看的东西。
    if deploy_crontab "$tmp"; then
        rm -f "$tmp"
        echo "Scheduler cron installed for $owner:"
        echo "  $line"
        deploy_cron_daemon_hint
    else
        rm -f "$tmp"
        deploy_cron_manual_hint "writing the crontab of $owner failed (permission?)" "$line"
    fi
    return 0
}
