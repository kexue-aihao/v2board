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
                echo "$(tr '\0' ' ' < "/proc/$pid/cmdline" 2>/dev/null)" >&2
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

# aaPanel 把 supervisorctl 装在面板自带的 pyenv 里，不在 PATH 上，
# 所以 command -v supervisorctl 会失败，不能用它来判断有没有 supervisor。
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

# 程序名不能写死：aaPanel 的配置在 supervisord.conf 的 files= 指向的
# plugin/supervisor/profile/*.ini 里，一个程序一个文件，通用部署一般也是这个布局。
# 所以「哪个文件同时提到 webman.php 和本项目目录」就足够定位，不必解析 ini 分块。
deploy_supervisor_program() {
    local conf name
    if [ -n "${SUPERVISOR_PROGRAM:-}" ]; then
        echo "$SUPERVISOR_PROGRAM"
        return 0
    fi
    for conf in /www/server/panel/plugin/supervisor/profile/*.ini \
                /etc/supervisor/conf.d/*.conf \
                /etc/supervisord.d/*.ini; do
        [ -f "$conf" ] || continue
        grep -q 'webman\.php' "$conf" || continue
        grep -Fq "$ROOT_DIR" "$conf" || continue
        name="$(sed -n 's/^\[program:\([^]]*\)\].*/\1/p' "$conf" | head -n 1)"
        [ -n "$name" ] && { echo "$name"; return 0; }
    done
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
    fi
    WEBMAN_STOPPED=1
}

deploy_start_webman() {
    local port attempt
    WEBMAN_START_ATTEMPTED=1
    port="$(deploy_webman_port)"
    if [ "${WEBMAN_MANAGER:-webman}" = supervisor ]; then
        "$SUPERVISORCTL_BIN" start "$SUPERVISOR_TARGET"
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
    if [ -f /etc/init.d/bt ]; then
        chown -R www .
    fi
}
