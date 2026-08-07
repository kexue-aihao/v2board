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

DEPLOY_BRANCH="${DEPLOY_BRANCH:-$(git symbolic-ref --quiet --short HEAD || true)}"
[ -n "$DEPLOY_BRANCH" ] || {
    echo "ERROR: Detached HEAD. Set DEPLOY_BRANCH explicitly." >&2
    exit 1
}

echo "Deploying branch: $DEPLOY_BRANCH"
deploy_stop_webman
git config --global --add safe.directory "$ROOT_DIR"
git fetch origin "$DEPLOY_BRANCH"
git show-ref --verify --quiet "refs/remotes/origin/$DEPLOY_BRANCH" || {
    echo "ERROR: Remote branch not found: origin/$DEPLOY_BRANCH" >&2
    exit 1
}
git reset --hard "origin/$DEPLOY_BRANCH"

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
deploy_check_mmdb

if [ "${LEGACY_DB_UPDATE:-0}" = "1" ]; then
    deploy_php artisan v2board:update --legacy
else
    deploy_php artisan v2board:update
fi
deploy_php artisan optimize:clear
deploy_php artisan ip:clear-location-cache
deploy_php artisan horizon:terminate || true
deploy_start_webman
# 升级也要跑：早于本次改动安装的站点从来没被写过这条 cron，而检查是幂等的 —— 运维手写的
# 条目（或系统级 /etc/cron.d 条目）会被识别并原样保留，不会重复追加。
deploy_install_cron
deploy_chown

echo "Upgrade completed."
