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

deploy_setup
deploy_check_runtime
deploy_download_composer
deploy_install_composer
deploy_patch_adapterman
deploy_check_mmdb
deploy_check_webman_runtime

if [ "${LEGACY_DB_UPDATE:-0}" = "1" ]; then
    deploy_php artisan v2board:update --legacy
else
    deploy_php artisan v2board:update
fi
deploy_php artisan optimize:clear
deploy_php artisan ip:clear-location-cache
deploy_php artisan horizon:terminate || true
deploy_start_webman
deploy_chown

echo "Upgrade completed."
