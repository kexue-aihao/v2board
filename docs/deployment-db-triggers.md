# 数据库触发器部署兼容

`v2_admin_audit_no_update`、`v2_admin_audit_no_delete` 禁止修改、删除审计记录。
MySQL/MariaDB 开启 binlog、`log_bin_trust_function_creators=OFF`，且应用账号没有
`SUPER` 权限时，创建这些触发器会报 1419。Linux 的 root 身份不等于数据库管理员。

`update.sh` 在停 Webman 前只读检查当前数据库。两个触发器已存在、binlog 未开启、
trust 已开启，或应用账号直接具有 `SUPER` 时，不需要管理员命令。否则可以提供
`V2BOARD_DB_ADMIN_CMD`：脚本先核对数据库实例及全局变量管理权限，迁移前临时开启
trust，迁移成功、失败或收到 INT/TERM/HUP 后都尝试恢复原值。恢复失败会明确报错并
使部署失败，EXIT 清理会再次尝试。它不修改 MySQL 配置文件，也不跳过审计触发器。

## 配置一次管理员连接

先在项目目录之外创建仅部署用户可读的文件；以 root 部署时：

```bash
(umask 077; touch /root/v2board-db-admin.cnf)
chmod 600 /root/v2board-db-admin.cnf
vi /root/v2board-db-admin.cnf
```

填写**与应用相同数据库实例**的地址、端口和管理员凭据，例如：

```ini
[client]
host=127.0.0.1
port=3306
user=root
password="填写数据库管理员密码"
```

远程数据库填写实际地址；使用 socket、TLS 时填写对应客户端选项。已有受保护的
客户端配置文件可以直接复用，不要覆盖。密码不要写进 update.sh、Git 或 shell 命令行。

在项目目录运行（`mysql` 可替换成 `mariadb` 或面板客户端的完整路径）：

```bash
export V2BOARD_DB_ADMIN_CMD='mysql --defaults-extra-file=/root/v2board-db-admin.cnf --batch --skip-column-names'
mysql --defaults-extra-file=/root/v2board-db-admin.cnf --batch --skip-column-names -e 'SELECT 1;'
DEPLOY_CHECK_ONLY=1 bash ./update.sh
bash ./update.sh
```

`--defaults-extra-file` 必须放在客户端其他选项前。命令必须从标准输入接收 SQL，
以 batch 格式输出无列名结果，并在 SQL 失败时返回非零状态；不要使用 `--force`。
预检查要求管理账号直接具有全局 `SUPER` 或 `SYSTEM_VARIABLES_ADMIN` 权限；仅对
某个数据库授予 `ALL PRIVILEGES` 不够。应用账号仍需要迁移所需的建表、加列、
`TRIGGER` 等权限；管理员连接只用于临时管理全局变量，不会替换应用连接或触发器定义者。

两个触发器创建完成后，后续部署会识别它们，可直接使用 `bash ./update.sh`。
脚本按当前检出的代码做前置检查；首次取得这个修复时需先拉取并检出更新后的文件。

## 管理员连接失败排查

`export V2BOARD_DB_ADMIN_CMD=...` 只指定命令，不会创建配置文件或填入数据库密码。
先执行上面的 `SELECT 1`，成功返回 `1` 后再部署。脚本会区分以下常见原因：

- 客户端不存在或不可执行：运行 `command -v mysql mariadb`，使用实际客户端路径。
- defaults 文件缺失、不可读或选项错误：按上面的步骤创建并填写文件，检查 `[client]`。
- `1045/1698`：数据库认证失败，检查数据库管理员密码和允许连接的 host。
- `2002/2003/2005`：检查数据库地址、端口、socket 和服务状态。
- `2026`：检查客户端 TLS 设置、CA 及证书。

脚本不会把原始客户端输出或 `SHOW GRANTS` 写入部署日志，避免泄露命令里的密码或
MariaDB 的认证哈希。直接执行 `SELECT 1` 能看到客户端的具体错误，不要分享密码文件内容。

若从旧版脚本升级时已经出现 `webman: stopped`，而退出前没有重新启动服务，先执行：

```bash
supervisorctl start 'webman:*'
supervisorctl status 'webman:*'
```

这对应本文事故中的 Supervisor 组名，其他部署使用自己的组名。新版已兼容旧脚本
只传递重执行标记的路径：前置检查失败也会尝试恢复已停止的服务，并报告部署已部分执行。
先修好管理员连接，再重新部署；恢复 Webman 不代表数据库迁移已经完成。

## 无管理员连接时

托管数据库可通过管理控制台设置参数，或请数据库管理员先记录原值再执行：

```sql
SELECT @@GLOBAL.log_bin_trust_function_creators;
SET GLOBAL log_bin_trust_function_creators = ON;
```

然后重新运行 `bash ./update.sh`。已经执行了一部分的 schema 迁移可以幂等续跑。
部署完成后由管理员恢复原值，原先为 OFF 时执行：

```sql
SET GLOBAL log_bin_trust_function_creators = OFF;
```

trust 是整个数据库实例的变量，请串行执行共享该实例的部署。断电、SIGKILL 或数据库
失联无法保证清理代码执行，遇到这类中断需检查并恢复该变量。脚本不会授予应用账号
`SUPER` 权限，也不会读取面板密码库、关闭 binlog 或删除已有触发器。

## 验证

```bash
bash scripts/tests/deploy-db-trigger.test.sh
```

该回归覆盖只读预检查、错误实例/凭据/权限、原值保持、迁移及恢复失败、异常退出、
TERM、Webman 停启顺序。另已在隔离 MariaDB 11.4.5（binlog=ON、trust=OFF）实测：
普通账号复现 1419，临时设置后创建两个审计触发器，恢复 OFF，后续预检查无需管理员，
UPDATE/DELETE 仍被拒绝。生产环境的具体 MySQL 版本、数据库代理及授权仍以部署检查为准。
