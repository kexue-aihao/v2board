#!/bin/bash

set -Eeuo pipefail

ROOT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
cd "$ROOT_DIR"
source "$ROOT_DIR/scripts/deploy-common.sh"

deploy_setup
command -v git >/dev/null 2>&1 || {
    echo "ERROR: Git is not installed." >&2
    exit 1
}
deploy_check_runtime
deploy_download_composer
deploy_install_composer
deploy_patch_adapterman
deploy_check_mmdb

deploy_php artisan v2board:install
deploy_php artisan optimize:clear
deploy_chown

echo "Installation completed."
