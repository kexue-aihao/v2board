#!/bin/bash

set -Eeuo pipefail

ROOT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
cd "$ROOT_DIR"
source "$ROOT_DIR/scripts/deploy-common.sh"

WEBMAN_STOPPED=0
WEBMAN_RESTARTED=0
# 只在从没走到启动那一步时才兜底重启，否则一次失败的启动会被 trap 再跑一遍，
# 同样的报错刷两遍还是失败。
WEBMAN_START_ATTEMPTED=0
trap 'if [ "$WEBMAN_STOPPED" = 1 ] && [ "$WEBMAN_RESTARTED" = 0 ] && [ "$WEBMAN_START_ATTEMPTED" = 0 ]; then deploy_start_webman || true; fi' EXIT

# 失败时要讲清楚三件事：死在哪一步、留下了什么半成品状态、怎么恢复。
#
# 没有 ERR trap 的话，set -e 只是静默掐断脚本 —— 运维要么自己往上翻几十行找报错，
# 要么（更糟）以为部署成功了。有站点真实发生过：update.sh 在自检处中断了几个月，
# 代码靠手工 git checkout 更新、数据库从来没迁移过，直到登录路径读 v2_user_two_factor
# 报 1146 才暴露（AuthController → TwoFactorService 每次登录都查这张表，
# APP_DEBUG=false 时前端只有一句裸 500，日志里也未必有）。
#
# 处理函数只摘掉自己的 trap：万一输出过程中哪条命令也失败，直接让 shell 退出，
# 不会递归刷屏。**刻意不写 `set +e`** —— trap 里的 set 是会留下来的，那会让
# `set -e` 在后面全程失效：失败一次之后脚本继续往下跑，最后还以退出码 0 结束，
# 比现在这种静默掐断更糟（这个坑是实测出来的，不是想出来的）。
deploy_report_failure() {
    trap - ERR
    local line="$1" command="$2"
    {
        echo
        echo "=============================================================="
        echo "部署失败：脚本在第 ${line} 行中断，后面的步骤都没有执行。"
        if [ -n "${FUNCNAME[1]:-}" ]; then
            echo "失败位置：update.sh:${line}（${FUNCNAME[1]}() 内）"
            echo "失败的命令：${command}"
        else
            echo "失败的命令：${command}"
        fi
        echo
        echo "本次部署是半成品 —— 拉代码 / 装依赖 / 数据库迁移 / 缓存清理 /"
        echo "计划任务 / 文件属主 里至少有一项没做。"
        echo
        echo "恢复步骤："
        echo "  1. 先读上面那条命令自己的报错。自检类错误在动任何东西之前就退出了，"
        echo "     没有留下半成品，按它说的改环境即可。"
        echo "  2. 确认代码与数据库是否同步（幂等，可反复跑）："
        echo "       ${PHP_CMD[*]:-php} artisan v2board:update"
        echo "     全部 already applied 才算同步；出现 applied 就是没跑完。"
        echo "  3. 修掉根因后重跑：bash ./update.sh"
        echo "=============================================================="
    } >&2
}
trap 'deploy_report_failure "$LINENO" "$BASH_COMMAND"' ERR

[ -d .git ] || {
    echo "ERROR: Please deploy using Git." >&2
    exit 1
}
command -v git >/dev/null 2>&1 || {
    echo "ERROR: Git is not installed." >&2
    exit 1
}
[ -f .env ] || {
    echo "ERROR: .env is missing. Complete installation before upgrading." >&2
    exit 1
}

deploy_setup
deploy_check_runtime
deploy_check_webman_runtime

if [ "${DEPLOY_CHECK_ONLY:-0}" = "1" ]; then
    echo "Deployment preflight passed. No files, database, services, or cron entries were changed."
    exit 0
fi

DEPLOY_BRANCH="${DEPLOY_BRANCH:-$(git symbolic-ref --quiet --short HEAD || true)}"
[ -n "$DEPLOY_BRANCH" ] || {
    echo "ERROR: Detached HEAD. Set DEPLOY_BRANCH explicitly." >&2
    exit 1
}

echo "Deploying branch: $DEPLOY_BRANCH"
deploy_stop_webman
git config --global --add safe.directory "$ROOT_DIR"
UPDATE_SCRIPT_BEFORE="$(git rev-parse --verify HEAD:update.sh 2>/dev/null || true)"
git fetch origin "$DEPLOY_BRANCH"
git show-ref --verify --quiet "refs/remotes/origin/$DEPLOY_BRANCH" || {
    echo "ERROR: Remote branch not found: origin/$DEPLOY_BRANCH" >&2
    exit 1
}
git reset --hard "origin/$DEPLOY_BRANCH"
UPDATE_SCRIPT_AFTER="$(git rev-parse --verify HEAD:update.sh 2>/dev/null || true)"

# `git reset` replaces this file on disk, but the shell that started before the
# reset continues to execute its old in-memory script.  Restart once from the
# checked-out script so a newly introduced migration or backfill step cannot be
# skipped on the first deployment that contains it.
if [ "$UPDATE_SCRIPT_BEFORE" != "$UPDATE_SCRIPT_AFTER" ] && [ "${V2BOARD_UPDATE_REEXECUTED:-0}" != "1" ]; then
    echo "update.sh changed during deployment; continuing with the checked-out script."
    V2BOARD_UPDATE_REEXECUTED=1 exec bash "$ROOT_DIR/update.sh" "$@"
fi

DEPLOY_REVISION="$(git rev-parse HEAD)"
REMOTE_REVISION="$(git rev-parse "origin/$DEPLOY_BRANCH")"
[ "$DEPLOY_REVISION" = "$REMOTE_REVISION" ] || {
    echo "ERROR: Working tree revision does not match origin/$DEPLOY_BRANCH." >&2
    exit 1
}
echo "Deployed revision: $DEPLOY_REVISION"

# The Signature theme no longer hosts reward operations. Keep the deployment
# check focused on the actual requirement: a valid entry bundle with no
# leftover reward-page injection. Reward operations are handled by Telegram
# and the API, so requiring the former async reward chunk would block a valid
# deployment after the theme rollback.
SIGNATURE_ASSET_DIR="$ROOT_DIR/public/theme/signature/assets/static/js"
SIGNATURE_INDEX="$(find "$SIGNATURE_ASSET_DIR" -maxdepth 1 -type f -name 'index.*.js' -print | sort | head -n 1)"
[ -n "$SIGNATURE_INDEX" ] || {
    echo "ERROR: Signature theme entry bundle is missing." >&2
    exit 1
}
if grep -Fq 'signature-reward-page' "$SIGNATURE_INDEX" || grep -Fq 'signature-reward-center' "$SIGNATURE_INDEX"; then
    echo "ERROR: Signature theme still contains reward-page injection code." >&2
    exit 1
fi
echo "Signature theme entry verified: $(basename "$SIGNATURE_INDEX")"

# The reseller page used to share its URL with public/reseller/. Remove only
# the now-empty legacy asset directory so Nginx does not redirect /reseller
# to a directory instead of the Laravel route.
if [ -d "$ROOT_DIR/public/reseller" ] && [ -z "$(find "$ROOT_DIR/public/reseller" -mindepth 1 -maxdepth 1 -print -quit)" ]; then
    rmdir "$ROOT_DIR/public/reseller"
fi

deploy_setup
deploy_check_runtime
deploy_check_webman_runtime
deploy_download_composer
deploy_install_composer
deploy_patch_adapterman
deploy_php scripts/patch-admin-reward.php
deploy_php scripts/patch-admin-clean-gateway.php
deploy_check_mmdb

if [ "${LEGACY_DB_UPDATE:-0}" = "1" ]; then
    deploy_php artisan v2board:update --legacy
fi
# Always run the idempotent schema migrations. Legacy mode only prepares
# historical installations; it does not include newer reward schema changes.
deploy_php artisan v2board:update
deploy_php artisan audit:backfill-summaries --chunk=1000
deploy_php artisan optimize:clear
deploy_php scripts/refresh-telegram-webhook.php
deploy_php artisan ip:clear-location-cache
deploy_php artisan ip:backfill-subscribe-locations --chunk=500
# 上一条会清空 IP 归属缓存，也会把清洗网关那批冗余归属地一并重置；这里立刻补回来，
# 否则升级后到下一次 access:locations 定时任务之间，列表按运营商/ASN 筛选会筛不出东西。
deploy_php artisan access:locations --chunk=500
# 重算账号风险台账。加 --refresh-only 是为了不在部署时给管理员发提醒 ——
# 真正的提醒交给每 15 分钟的调度，它本来就是「未处理就重复发」的。
deploy_php artisan risk:notify --refresh-only
deploy_php artisan horizon:terminate || true
deploy_start_webman
# 升级也要跑：早于本次改动安装的站点从来没被写过这条 cron，而检查是幂等的 —— 运维手写的
# 条目（或系统级 /etc/cron.d 条目）会被识别并原样保留，不会重复追加。
deploy_install_cron
deploy_chown

echo "Upgrade completed."
