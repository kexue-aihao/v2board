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
deploy_php artisan horizon:terminate || true
deploy_start_webman
# 升级也要跑：早于本次改动安装的站点从来没被写过这条 cron，而检查是幂等的 —— 运维手写的
# 条目（或系统级 /etc/cron.d 条目）会被识别并原样保留，不会重复追加。
deploy_install_cron
deploy_chown

echo "Upgrade completed."
