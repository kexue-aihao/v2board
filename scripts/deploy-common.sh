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

    if [ "$(id -u 2>/dev/null || echo 1)" = "0" ]; then
        export COMPOSER_ALLOW_SUPERUSER=1
    fi

    if [ ! -f "$PHP_INI" ]; then
        echo "ERROR: PHP CLI configuration not found: $ROOT_DIR/$PHP_INI" >&2
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

deploy_stop_webman() {
    SUPERVISOR_PROGRAM="${SUPERVISOR_PROGRAM:-webman}"
    if command -v supervisorctl >/dev/null 2>&1 && supervisorctl status "$SUPERVISOR_PROGRAM" >/dev/null 2>&1; then
        supervisorctl stop "$SUPERVISOR_PROGRAM"
        WEBMAN_MANAGER=supervisor
    else
        deploy_php webman.php stop >/dev/null 2>&1 || true
        WEBMAN_MANAGER=webman
    fi
    WEBMAN_STOPPED=1
}

deploy_start_webman() {
    if [ "${WEBMAN_MANAGER:-webman}" = supervisor ]; then
        supervisorctl start "$SUPERVISOR_PROGRAM"
        supervisorctl status "$SUPERVISOR_PROGRAM" | grep -q 'RUNNING'
    else
        deploy_php webman.php start -d
        pgrep -f '[w]ebman.php' >/dev/null 2>&1 || {
            echo "ERROR: Webman did not start successfully." >&2
            return 1
        }
    fi
    WEBMAN_RESTARTED=1
}

deploy_chown() {
    if [ -f /etc/init.d/bt ]; then
        chown -R www .
    fi
}
