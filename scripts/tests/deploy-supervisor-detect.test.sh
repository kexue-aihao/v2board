#!/bin/bash
# update.sh / deploy-common.sh 识别 supervisor 的回归测试。
#
# 背景（2026-10-02 线上事故）：脚本原来只从配置文件里 sed 出 [program:<名字>]，而
# /etc/supervisor/conf.d/webman.conf 的段头被编辑弄丢之后，那一步静默返回空 ——
# 脚本认定「没有 supervisor 托管」，绕过 supervisord 手工 webman.php stop，
# supervisord 按 autorestart=true 几秒后把进程拉回来占住 6600 端口，
# 一分多钟后的 deploy_start_webman 撞上 Address already in use，部署死在最后一步。
#
# 全部用 stub：本机不需要 supervisor、不需要 Linux，也不碰任何真实进程。
#     bash scripts/tests/deploy-supervisor-detect.test.sh
set -Eeuo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/../.."
# update.sh / init.sh 会先设好这几个再 source deploy-common.sh，这里照做 ——
# 否则 set -u 下引用 $ROOT_DIR 会直接炸掉被测试的函数。
ROOT_DIR="$PWD"
PHP_BIN=/opt/ace/server/php/81/bin/php
PHP_INI=/opt/ace/server/php/81/etc/php.ini
PANEL_NAME=AcePanel
export ROOT_DIR PHP_BIN PHP_INI PANEL_NAME
# shellcheck source=../deploy-common.sh
source scripts/deploy-common.sh

fails=0
check() { # 名称 期望 实得
    if [ "$2" = "$3" ]; then
        echo "  ✓ $1"
    else
        echo "  ✗ $1 —— 期望 [$2] 实得 [$3]"
        fails=$((fails + 1))
    fi
}
check_absent() { # 名称 不该出现的串 文本
    case "$3" in
        *"$2"*) echo "  ✗ $1"; fails=$((fails + 1)) ;;
        *) echo "  ✓ $1" ;;
    esac
}
check_present() { # 名称 必须出现的串 文本
    case "$3" in
        *"$2"*) echo "  ✓ $1" ;;
        *) echo "  ✗ $1"; fails=$((fails + 1)) ;;
    esac
}

# 线上 supervisorctl status 的真实输出：除了 webman 还有一条 v2board（Horizon）。
supervisorctl() {
    [ "${1:-}" = status ] || return 1
    printf '%s\n' \
        'v2board                          RUNNING   pid 1242632, uptime 0:04:34' \
        'webman                           RUNNING   pid 1236757, uptime 0:04:46'
}

echo "1) 按 pid 反查程序名（supervisord 的实时视图）"
check "1236757 → webman" "webman" "$(deploy_supervisor_program_for_pid supervisorctl 1236757)"
check "1242632 → v2board（不会认错成 webman）" "v2board" "$(deploy_supervisor_program_for_pid supervisorctl 1242632)"
if deploy_supervisor_program_for_pid supervisorctl 999 >/dev/null 2>&1; then
    echo "  ✗ 未知 pid 不该认出任何程序"; fails=$((fails + 1))
else
    echo "  ✓ 未知 pid 不误认"
fi
if deploy_supervisor_program_for_pid supervisorctl '' >/dev/null 2>&1; then
    echo "  ✗ 空 pid 不该认出任何程序"; fails=$((fails + 1))
else
    echo "  ✓ 空 pid 不误认"
fi

echo
echo "2) 进程已停时的退路：按名字"
check "名字里带 webman 的那条" "webman" "$(deploy_supervisor_program_by_name supervisorctl)"

echo
echo "3) 分组名与 numprocs 后缀都要归一成组名"
supervisorctl_grouped() { printf '%s\n' 'foo:foo_00                       RUNNING   pid 4242, uptime 0:01:00'; }
supervisorctl_numprocs() { printf '%s\n' 'webman_00                        RUNNING   pid 4343, uptime 0:01:00'; }
check "foo:foo_00 → foo" "foo" "$(deploy_supervisor_program_for_pid supervisorctl_grouped 4242)"
check "webman_00 → webman" "webman" "$(deploy_supervisor_program_for_pid supervisorctl_numprocs 4343)"
check "webman_00 名字反查 → webman" "webman" "$(deploy_supervisor_program_by_name supervisorctl_numprocs)"

echo
echo "4) 完整链路：supervisord 认得出来就不该再看配置文件"
check "deploy_supervisor_program" "webman" "$(deploy_supervisor_program)"

echo
echo "5) 停 / 启两步（stub 掉 php 与端口探测）"
pgrep() { echo 1236757; }
deploy_port_listening() { return 0; }
deploy_port_pids() { echo 1236757; }
deploy_webman_php() { echo "!!! 不该走手工分支：$*" >&2; return 1; }
CALLS=""
supervisorctl() {   # 覆盖上面的 stub，记录调用
    CALLS="${CALLS}supervisorctl $*"$'\n'
    case "${1:-}" in
        status)  printf '%s\n' 'webman                           RUNNING   pid 1236757, uptime 0:04:46' ;;
        stop)    echo "webman: stopped" ;;
        restart) echo "webman: stopped"; echo "webman: started" ;;
        *)       echo "supervisorctl: 不认识子命令 $*" >&2; return 1 ;;
    esac
}
deploy_stop_webman
check "WEBMAN_MANAGER" "supervisor" "${WEBMAN_MANAGER:-}"
check_present "停的时候走的是 supervisorctl stop" "supervisorctl stop webman:*" "$CALLS"

CALLS=""
deploy_start_webman
check "WEBMAN_RESTARTED" "1" "${WEBMAN_RESTARTED:-0}"
check_present "启的时候走的是 supervisorctl restart" "supervisorctl restart webman:*" "$CALLS"
check_absent "启的时候没有自己起一套 webman.php start -d" "webman.php start" "$CALLS"

echo
echo "6) 事故重放：停的那一步没认出 supervisor，supervisord 已按 autorestart 把进程拉回来"
CALLS=""
WEBMAN_MANAGER=webman
unset SUPERVISORCTL_BIN SUPERVISOR_PROGRAM SUPERVISOR_TARGET
deploy_start_webman
check "启动前兜底认出 supervisor" "supervisor" "${WEBMAN_MANAGER:-}"
check "SUPERVISOR_TARGET" "webman:*" "${SUPERVISOR_TARGET:-}"
check_present "改用 supervisorctl restart 收尾" "supervisorctl restart webman:*" "$CALLS"

echo
echo "7) 退路分支：supervisorctl 完全连不上时退回手工停，盯梢必须报出「又被拉起来」"
supervisorctl() { return 1; }
deploy_webman_php() { return 0; }
deploy_webman_master_running() { return 1; }
sleep() { :; }                       # 别真等 5 秒
n=0
deploy_port_listening() { n=$((n + 1)); [ "$n" -ge 2 ]; }   # 第 1 次空，之后被重新占住
deploy_watch_webman_respawn 6600 >/dev/null 2>&1 && watch_rc=0 || watch_rc=$?
check "端口被重新占住时返回 1" "1" "$watch_rc"
n=0   # 上面那次直接调用已经用掉了计数，跑 deploy_stop_webman 之前重来
# 刻意不做命令替换：那会把函数放进子 shell，里面的全局变量就传不回来。
tmp="$(mktemp)"
deploy_stop_webman >"$tmp" 2>&1 || true
out="$(cat "$tmp")"
rm -f "$tmp"
check_present "打出了占用端口的 pid" "pid 1236757" "$out"
check_absent "没有 /proc 报错漏进部署输出" "No such file or directory" "$out"
check "走的是手工分支" "webman" "${WEBMAN_MANAGER:-}"
check "盯梢没有中断部署（set -e 下仍走到最后一行）" "1" "${WEBMAN_STOPPED:-0}"

echo
echo "8) 配置文件丢了 [program:...] 段头时要警告（但不阻断部署）"
conf_file="$(mktemp)"
deploy_supervisor_config() { echo "$conf_file"; }
printf '%s\n' 'command=/bin/bash '"$PWD"'/scripts/webman.sh start' >"$conf_file"
out="$(deploy_check_supervisor_php_config 2>&1)" && conf_rc=0 || conf_rc=$?
check "命令认得出是包装脚本（返回 0，不阻断）" "0" "$conf_rc"
check_present "警告了段头缺失" "has no [program:...] section header" "$out"
check_present "给出了补段头的命令" "sed -i '1i [program:webman]'" "$out"
printf '%s\n' '[program:webman]' 'command=/bin/bash '"$PWD"'/scripts/webman.sh start' >"$conf_file"
out="$(deploy_check_supervisor_php_config 2>&1)" || true
check_absent "段头在时不报警" "has no [program:...] section header" "$out"
rm -f "$conf_file"

echo
if [ "$fails" = 0 ]; then
    echo "全部通过"
else
    echo "有 $fails 项失败"
    exit 1
fi
