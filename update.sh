#!/bin/bash

set -Eeuo pipefail

ROOT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
cd "$ROOT_DIR"
source "$ROOT_DIR/scripts/deploy-common.sh"

WEBMAN_STOPPED=0
WEBMAN_RESTARTED=0
trap 'if [ "$WEBMAN_STOPPED" = 1 ] && [ "$WEBMAN_RESTARTED" = 0 ]; then deploy_start_webman || true; fi' EXIT

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

deploy_php artisan v2board:update
deploy_php artisan optimize:clear
deploy_php artisan ip:clear-location-cache
deploy_php artisan horizon:terminate || true
deploy_start_webman
deploy_chown

echo "Upgrade completed."
