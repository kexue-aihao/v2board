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
deploy_check_webman_runtime

if [ "${DEPLOY_CHECK_ONLY:-0}" = "1" ]; then
    echo "Deployment preflight passed. No files, database, services, or cron entries were changed."
    exit 0
fi

deploy_download_composer
deploy_install_composer
deploy_patch_adapterman
deploy_php scripts/patch-admin-reward.php
deploy_check_mmdb

deploy_php artisan v2board:install
deploy_php artisan optimize:clear
# 计划任务是部署必需项：没有它 Kernel.php 里的定时任务一个都不会跑。幂等，已配置则跳过。
deploy_install_cron
deploy_chown

echo "Installation completed."
echo
# 刻意不在这里启动 Webman：全新安装时进程托管方式还没定，若这里 `webman.php start -d` 起一个
# 裸守护进程，运维随后配好 supervisor 再 start 就会撞上 Address already in use，并且留下一套
# supervisord 不认、属主也不对的实例（update.sh 里 deploy_stop_webman 的注释是同一个坑）。
# 所以只把剩下的手工步骤列清楚。
echo "Remaining manual steps:"
echo "  1. Point the web server at ${ROOT_DIR}/public and configure the upstream PHP-FPM/Webman rewrite rules."
echo "  2. Start Webman, either under supervisor (recommended) or directly:"
echo "       PHP_BIN=${PHP_BIN} /bin/bash ${ROOT_DIR}/scripts/webman.sh start -d"
echo "     Supervisor's command= must use ${ROOT_DIR}/scripts/webman.sh start."
echo "  3. Start a queue worker: ${PHP_BIN} -c ${PHP_INI} artisan horizon   (also usually under supervisor)."
echo "  4. Confirm the scheduler: wait two minutes, then check 系统状态 in the admin panel,"
echo "     or run ${PHP_BIN} -c ${PHP_INI} artisan schedule:run by hand and watch for output."
