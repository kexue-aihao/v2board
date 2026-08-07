#!/bin/bash

deploy_setup() {
    ROOT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
    cd "$ROOT_DIR"

    PHP_BIN="${PHP_BIN:-php}"

    # PATH 中的 php 可能只是命令名，先解析为实际路径以识别其 aaPanel 配置。
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
        if [ -n "$RESOLVED_PHP_BIN" ]; then
            PHP_BIN="$RESOLVED_PHP_BIN"
        fi
    fi

    case "$PHP_BIN" in
        /www/server/php/*/bin/php)
            AAPANEL_PHP_DIR="${PHP_BIN%/bin/php}"
            AAPANEL_PHP_INI="$AAPANEL_PHP_DIR/etc/php.ini"
            ;;
        *)
            echo "ERROR: aaPanel PHP binary is required: $PHP_BIN" >&2
            echo "Set PHP_BIN to /www/server/php/<version>/bin/php before running this script." >&2
            return 1
            ;;
    esac

    if [ -n "${PHP_INI:-}" ] && [ "$PHP_INI" != "$AAPANEL_PHP_INI" ]; then
        echo "ERROR: PHP_INI must be the aaPanel configuration: $AAPANEL_PHP_INI" >&2
        return 1
    fi
    PHP_INI="$AAPANEL_PHP_INI"
    PHP_CMD=("$PHP_BIN" -c "$PHP_INI")

    if [ "$(id -u 2>/dev/null || echo 1)" = "0" ]; then
        export COMPOSER_ALLOW_SUPERUSER=1
    fi

    if [ ! -f "$PHP_INI" ]; then
        echo "ERROR: aaPanel PHP configuration not found: $PHP_INI" >&2
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
    local disabled_functions required_function missing=0
    local required_functions=(
        header header_remove headers_sent headers_list http_response_code
        setcookie
        session_create_id session_id session_name session_save_path session_status
        session_start session_write_close session_regenerate_id session_unset
        session_get_cookie_params session_set_cookie_params
        set_time_limit
    )

    disabled_functions="$(deploy_php -r 'echo ini_get("disable_functions");' 2>&1)" || {
        echo "$disabled_functions" >&2
        echo "ERROR: PHP CLI cannot read disabled functions from $PHP_INI" >&2
        return 1
    }

    for required_function in "${required_functions[@]}"; do
        if ! printf '%s\n' "$disabled_functions" | tr ',' '\n' | sed 's/^[[:space:]]*//;s/[[:space:]]*$//' | grep -Fxq "$required_function"; then
            echo "ERROR: aaPanel Disabled functions is missing: $required_function" >&2
            missing=1
        fi
    done
    if [ "$missing" -ne 0 ]; then
        echo "AdapterMan requires every listed function to be disabled in aaPanel PHP settings." >&2
        return 1
    fi

    echo "Webman runtime: $PHP_INI OK"
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
        deploy_php webman.php stop >/dev/null 2>&1 || true
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
        deploy_php webman.php start -d || {
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

# cron、Webman、Horizon、artisan 与 Composer 都使用同一套 aaPanel PHP 配置。
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
# 以及 aaPanel 面板任务脚本。命中时一个字都不改。
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
    # aaPanel 的面板计划任务把命令正文写进 /www/server/cron/<id>，crontab 里只留一行
    # `/bin/bash /www/server/cron/<id>`，既没有 schedule:run 也没有本目录。不看这些脚本就会
    # 把面板里已经配好的调度判成缺失，于是再追加一条 —— 每分钟两次 schedule:run，而
    # v2board:statistics / reset:traffic / send:remindMail 这些没有 withoutOverlapping 的
    # 命令就会在同一分钟里跑两遍（重复发信、重复统计）。
    for conf in /www/server/cron/*; do
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
