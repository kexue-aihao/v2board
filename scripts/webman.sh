#!/bin/bash

set -Eeuo pipefail

ROOT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"
source "$ROOT_DIR/scripts/deploy-common.sh"

deploy_setup
deploy_check_runtime
deploy_check_webman_runtime
exec "${WEBMAN_PHP_CMD[@]}" "$ROOT_DIR/webman.php" "$@"
