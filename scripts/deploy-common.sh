#!/bin/bash

deploy_setup() {
    ROOT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
    cd "$ROOT_DIR"

    if [ -z "${PHP_BIN:-}" ] && [ -x /www/server/php/85/bin/php ]; then
        PHP_BIN=/www/server/php/85/bin/php
    fi
    PHP_BIN="${PHP_BIN:-php}"

    if [ -z "${PHP_INI:-}" ] && [[ "$PHP_BIN" == /www/server/php/*/bin/php ]]; then
        AAPANEL_PHP_DIR="${PHP_BIN%/bin/php}"
        if [ -f "$AAPANEL_PHP_DIR/etc/php.ini" ]; then
            PHP_INI="$AAPANEL_PHP_DIR/etc/php.ini"
        elif [ -f "$AAPANEL_PHP_DIR/etc/php-cli.ini" ]; then
            PHP_INI="$AAPANEL_PHP_DIR/etc/php-cli.ini"
        fi
    fi
    PHP_INI="${PHP_INI:-cli-php.ini}"
    PHP_CMD=("$PHP_BIN" -c "$PHP_INI")

    # AdapterMan 要求 php.ini 通过 disable_functions 屏蔽 header/session 等原生函数，
    # 否则拒绝启动。aaPanel 的 php.ini 由 PHP-FPM 共用，加上这些禁用会破坏同版本下的
    # 其它站点，所以 Webman 固定使用仓库自带的 cli-php.ini，而 artisan 与 composer
    # 继续使用上面探测到的 PHP_INI（它才带齐全部扩展）。
    WEBMAN_PHP_INI="${WEBMAN_PHP_INI:-cli-php.ini}"
    WEBMAN_PHP_CMD=("$PHP_BIN" -c "$WEBMAN_PHP_INI")

    if [ "$(id -u 2>/dev/null || echo 1)" = "0" ]; then
        export COMPOSER_ALLOW_SUPERUSER=1
    fi

    if [ ! -f "$PHP_INI" ]; then
        echo "ERROR: PHP CLI configuration not found: $ROOT_DIR/$PHP_INI" >&2
        return 1
    fi
    if [ ! -f "$WEBMAN_PHP_INI" ]; then
        echo "ERROR: Webman PHP configuration not found: $ROOT_DIR/$WEBMAN_PHP_INI" >&2
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

# Webman/AdapterMan 专用：使用 WEBMAN_PHP_INI 而不是 artisan 那套 PHP_INI。
deploy_webman_php() {
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
    if [ "$version_id" -lt 70300 ]; then
        echo "ERROR: PHP 7.3 or newer is required." >&2
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

    for extension in pdo_mysql fileinfo redis; do
        if ! echo "$modules" | grep -Fxq "$extension"; then
            echo "ERROR: Required PHP extension is missing: $extension" >&2
            echo "Check $PHP_INI and the PHP extension directory. No automatic repair was attempted." >&2
            return 1
        fi
    done
    if [ "$version_id" -ge 80000 ] && ! echo "$modules" | grep -Fxq pcntl; then
        echo "ERROR: Required PHP extension is missing for PHP 8+: pcntl" >&2
        echo "Check $PHP_INI and the PHP extension directory. No automatic repair was attempted." >&2
        return 1
    fi
}

deploy_check_webman_runtime() {
    local modules extension missing=0

    if ! grep -qE '^[[:space:]]*disable_functions[[:space:]]*=.*\bheader\b' "$WEBMAN_PHP_INI"; then
        echo "ERROR: $WEBMAN_PHP_INI does not disable header/session functions." >&2
        echo "AdapterMan refuses to start without them. Do NOT add disable_functions to the" >&2
        echo "aaPanel php.ini shared with PHP-FPM; keep the Webman-only ini instead." >&2
        return 1
    fi

    modules="$(deploy_webman_php -m 2>&1)" || {
        echo "$modules" >&2
        echo "ERROR: PHP CLI cannot start with $WEBMAN_PHP_INI" >&2
        return 1
    }
    if echo "$modules" | grep -Eiq 'PHP Startup|Unable to load dynamic library'; then
        echo "$modules" >&2
        echo "ERROR: $WEBMAN_PHP_INI has an extension loading error." >&2
        return 1
    fi

    for extension in pdo_mysql redis pcntl; do
        if ! echo "$modules" | grep -Fxq "$extension"; then
            echo "ERROR: $WEBMAN_PHP_INI is missing PHP extension: $extension" >&2
            missing=1
        fi
    done
    [ "$missing" = 0 ] || return 1

    # fileinfo 只在文件类型探测时才用到，Webman 处理 API 请求并不依赖它，
    # 因此缺失只提示、不阻断部署。
    if ! echo "$modules" | grep -Fxq fileinfo; then
        echo "WARNING: $WEBMAN_PHP_INI is missing PHP extension: fileinfo" >&2
    fi

    echo "Webman runtime: $WEBMAN_PHP_INI OK"
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
    if ! deploy_geoip_enabled; then
        echo "IP geolocation is disabled; MMDB file check skipped."
        return 0
    fi
    for file in \
        resources/ipdb/china_ipv4.mmdb \
        resources/ipdb/china_ipv4_idc.mmdb \
        resources/ipdb/china_ipv6.mmdb \
        resources/ipdb/china_ipv6_idc.mmdb \
        resources/ipdb/global_ipv4_idc.mmdb \
        resources/ipdb/global_ipv4_residential.mmdb \
        resources/ipdb/global_ipv6_idc.mmdb \
        resources/ipdb/global_ipv6_residential.mmdb; do
        if [ ! -s "$file" ]; then
            echo "ERROR: MMDB file is missing or empty: $file" >&2
            return 1
        fi
    done
    echo "MMDB files: all required files are present."
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

deploy_stop_webman() {
    SUPERVISOR_PROGRAM="${SUPERVISOR_PROGRAM:-webman}"
    if command -v supervisorctl >/dev/null 2>&1 && supervisorctl status "$SUPERVISOR_PROGRAM" >/dev/null 2>&1; then
        supervisorctl stop "$SUPERVISOR_PROGRAM"
        WEBMAN_MANAGER=supervisor
    else
        deploy_webman_php webman.php stop >/dev/null 2>&1 || true
        # TERM 之后主进程有时不会立刻退出，残留进程会让启动校验误判为成功。
        local attempt
        for attempt in 1 2 3 4 5; do
            deploy_webman_master_running || break
            sleep 1
        done
        if deploy_webman_master_running; then
            echo "Webman did not exit on TERM; forcing shutdown."
            pkill -f 'WorkerMan: master process.*webman\.php' >/dev/null 2>&1 || true
            sleep 1
        fi
        WEBMAN_MANAGER=webman
    fi
    WEBMAN_STOPPED=1
}

deploy_start_webman() {
    if [ "${WEBMAN_MANAGER:-webman}" = supervisor ]; then
        supervisorctl start "$SUPERVISOR_PROGRAM"
        supervisorctl status "$SUPERVISOR_PROGRAM" | grep -q 'RUNNING' || {
            echo "ERROR: Webman did not start under supervisor: $SUPERVISOR_PROGRAM" >&2
            return 1
        }
    else
        # 启动失败时 AdapterMan 会把原因写到输出上，不要吞掉。
        deploy_webman_php webman.php start -d || {
            echo "ERROR: Webman failed to start; see the AdapterMan output above." >&2
            return 1
        }
        local port attempt
        port="$(deploy_webman_port)"
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
    WEBMAN_RESTARTED=1
}

deploy_chown() {
    if [ -f /etc/init.d/bt ]; then
        chown -R www .
    fi
}
