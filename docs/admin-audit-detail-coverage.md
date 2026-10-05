# 详细安全审计交付与覆盖清单

更新：2026-10-05。承接 `admin-audit-detail-plan.md`，详细采集、业务接入、异步关联、中文页面和本地验收已实现。本文记录实现边界和验收证据；线上发布尚未执行。

## 阶段进度

| 阶段 | 交付 |
| --- | --- |
| 功能清单 | 下列业务分支矩阵，以及附录从 Laravel 实际注册路由得到的 233 个入口（含别名、账号安全和 Horizon）；数量不等同于独立业务功能数 |
| 统一采集 | `SecurityAuditBusiness`、`SecurityAuditMutation`、`config/admin_audit.php`；中文字段、单位、枚举、对象及名称快照、真实差异、秘密变更标记 |
| 业务接入 | 模型事件采集与显式批量写入适配；配置文件、会话撤销、关联删除等效果另行记录 |
| 批量与队列 | 每 100 项分段；变化、无变化、跳过、预览分别记录；任务携带请求、批次、任务编号、收件对象和尝试次数，执行前复查角色版本 |
| 页面与接口 | 按操作 / 原始记录查看；中文字段对照、业务状态、分页详情、任务时间线、同批次关联；模块、动作、对象、字段、时间、操作者、请求、批次和任务筛选 |
| 验收和构建 | PHP 功能测试、前端回归、浏览器检查、角色资源重新构建；数据库与全量回归结果见下文 |

## 统一证据规则

- 新增 `details.business.version=1`，外层签名结构不变。历史载荷及哈希不改写；缺失的旧详情明确显示未保留。
- 字段取实际变更前后值；忽略 `updated_at`、版本计数等系统噪声。金额从分换算、流量区分套餐 GB 与用户字节，日期使用应用时区。关联套餐、权限组等保留当时可查到的名称和编号。
- 模型新增记录取真实 ID；节点用协议加 ID 定位；复制保留来源，删除保留旧对象。Query Builder 写入锁定本次范围，新增逐行取得实际 ID，不能用最大 ID 或时间窗口归因。
- SQL 事务及保存点回滚同步撤销内存中的变更。邮件、文件、Redis 等外部效果不随数据库回滚；若它们的原审计行被回滚，重新分段保留已发生的效果，不宣称外部效果已撤销。
- 业务结果覆盖 HTTP 200 中的 `data=false`、`ok=false` 和邮件任务内部捕获的发送异常。任务提交、执行成功、失败、拒绝和重试次数分开显示；邮件成功只表示发送服务接受。
- 批量对象的变化、无变化和扫描 / 预览结果均分段保留。详情接口每页最多 100 条**审计记录**，一条变更记录最多含 100 个对象，可继续加载。概述仅显示少量代表字段；完整对象在分段证据中。
- 关联影响分段记录；完成记录只摘录前 20 项并标明总数。订阅访问日志、令牌历史和倍率运行态等清理保存实际关联对象与删除数量，不把原日志全文再复制一份。
- 密码、令牌、UUID、私钥、兑换码、恢复码不保留值；先比较再脱敏，仍能看到是否修改。订阅 URL 仅保留协议、域名和端口，路径及参数隐藏。正文仅保留长度和 SHA-256 摘要；工单、邮件、公告、知识库均不保留全文。未知字段只显示变更标记。
- 文本使用 React 文本节点渲染，不插入 HTML。自由文本只能按既定规则处理，不能保证识别任意未标记的秘密；不要在名称、备注或标题中填写凭据。

## 业务分支矩阵

“模型”表示 Eloquent 的创建 / 修改 / 删除事件；“批量”表示限定范围的 `SecurityAuditMutation`；“效果”表示业务明确报告的执行结果。

| 功能及分支 | 主对象 / 采集策略 | 结果与敏感字段 | 验收依据 |
| --- | --- | --- | --- |
| 用户查询、详情、筛选、CSV | 用户 ID、筛选条件、返回 / 导出数 | 不复制整库；令牌不记录 | AdminSecurity、用户接口回归 |
| 用户编辑 / 管理员身份 | 用户、主订阅；模型字段差异；身份和会话撤销效果 | 金额、流量、期限、设备数；密码仅标记；无变化独立状态 | AdminAuditDetail、AdminSecurity |
| 单个 / 批量生成用户 | 批量插入获得每个真实 ID | 不记录生成密码和订阅凭据 | 批量采集契约及用户回归 |
| 批量停用 | 锁定目标、逐个前后状态 | 只描述停用；重复停用记录无变化 | 批量采集契约 |
| 单个 / 批量删除用户 | 用户及订单、邀请码、工单、回复、订阅；批量删除 / 解除邀请关系；访问记录清理效果 | 删除前快照、实际关联数量 | AdminAuditDetail、AdminSubscriptionCleanup |
| 无有效订阅扫描 / 确认删除 | 全部扫描 ID 分段；删除前复核；已不存在或不再符合条件单独跳过 | 扫描不当作删除，保留匹配 / 删除 / 跳过数量 | AdminSubscriptionCleanup、AdminAuditDetail |
| 用户订阅重置 / 撤销 / 主订阅切换、Telegram 解绑 | 用户、订阅模型差异及令牌历史关联效果 | 令牌、UUID 只标记；解绑保留账号变化 | 用户及订阅业务回归 |
| 邮件提交 / 实际执行 | 固定收件对象、主题、正文长度、队列请求及任务编号 | 邮件服务接受或失败；全文不保留 | AdminAuditDetail、AdminSecurity |
| 订单查询 / 分配 / 佣金状态 / 取消 / 手动支付 / 补单 | 订单号、用户、套餐、金额、状态；模型、取消批量写入；开通队列 | 订单变更与异步开通分开；回滚与重试可追踪 | 订单业务回归、队列与保存点测试 |
| 八种协议节点新增 / 编辑 / 复制 / 删除 / 显示 | 协议加 ID、名称快照、模型；复制来源；ID 迁移用批量并记录关联统计迁移 | 私钥和认证字段只标记；显示与完整编辑有不同动作 | ServerBatchOperation、ServerHostReplacement、AdminAuditDetail |
| 节点排序 / 权限组 / 路由 | 实际节点、组、路由模型变更 | 保留前后排序和关联范围；秘密按字段策略 | 节点业务回归、路由覆盖断言 |
| 批量地址 / 名称 / 倍率 / 端口 / TLS / 传输 / 删除 | 预览用旧值与拟改值；执行用实际保存后的模型；无变化 / 不适用 / 跳过逐项记录 | 不根据预览宣称已修改；协议配置内秘密脱敏 | ServerBatchOperation、ServerHostReplacement、AdminAuditDetail、前端批量测试 |
| 倍率全局配置 / 时段 / 场景 / 绑定 | 配置键、规则 / 策略真实 ID；节点协议与 ID 复合定位；限定范围批量 | 版本冲突失败、绑定前后状态、运行态清理数量 | AdminRateController、DynamicRate、RatePolicy、AdminAuditDetail |
| 系统 / 娱乐 / 清洗留存 / 倒卖商支付驱动设置 | 实际保存文件前后差异及写入效果 | 被忽略的空密钥不记修改；脚本及秘密只标记 | AdminSecurity 配置与回滚测试、字段格式化契约 |
| 主题配置 / 支付方式新增编辑、开关、排序、删除 | 主题文件或支付模型、主题名 / 支付 ID | 自定义 HTML / 脚本和支付密钥不保留；运维权限边界不扩大 | AdminSecurity、支付业务回归 |
| 套餐新增编辑 / 上下架 / 续费 / 排序 / 删除 / 强制同步用户 | 套餐模型；强制同步锁定用户并记录全部实际差异 | GB 与用户字节正确换算；无变化不制造修改 | AdminPlanController、AdminAuditDetail |
| 优惠券 / 礼品卡新增编辑、批量生成、状态、删除 | 每个实际生成 ID、类型、额度、限制和状态 | 兑换凭据只标记 | 批量创建及字段脱敏契约、路由覆盖断言 |
| 公告 / 知识库新增编辑、显示、排序、删除 | 标题、分类、模型差异 | 正文长度和摘要；历史正文不补造 | AdminAuditDetail、内容处理回归 |
| 工单列表 / 阅读 / 回复 / 关闭 | 工单、回复 ID、目标用户、模型及通知任务 | 正文仅长度摘要；客服无关闭权限 | AdminSecurity、浏览器客服流程、正文 / 队列契约 |
| Telegram 工单回复 | 显式工单动作及目标，入口标记 Telegram | 与 Web 回复同一记录结构及权限 | Telegram 入口接入、角色与正文契约 |
| 外部源新增 / 编辑 / 删除 / 试运行 / 刷新 / 全部刷新 | 源、外部节点实际 ID；删除替换逐项采集；全部刷新共享批次 | URL 脱敏；HTTP 200 的 `ok=false` 是失败；试运行不是导入 | AdminExternalSourceController、AdminAuditDetail、前端外部源测试 |
| 溯源 / 令牌查找与揭示 | 查询范围、用户 / 订阅 / 历史记录 ID | 揭示行为留痕，返回的明文令牌不进审计 | 角色和脱敏契约、溯源业务回归 |
| 清洗阻断 / 解除 / 风险处理 | 规则、事件、用户风险记录；模型与批量 | IP / UA / 范围 / 原因 / 期限 / 状态差异 | 清洗网关和风控回归 |
| 清洗查询 / 导出 / 多账号同 IP | 筛选条件、返回 / 实际导出数及截断状态 | 不复制拉取日志或整个查询响应 | 前端清洗网关、导出契约 |
| 倒卖商审核账号 / 店铺、重置密码、模板 | 账号、店铺、模板、审核日志模型；配置文件效果 | 审核前后状态与原因；密码只标记 | 业务模型采集、字段 / 路由覆盖断言 |
| 统计、系统运行信息及 Horizon | 查询范围 / 路由对象、返回数；队列监控和重试使用专门中文动作 | 不记录任务序列化正文；重试执行仍复查管理员权限 | 233 路由覆盖断言、队列重试测试 |
| 登录 / 二步验证 / 改密 / 会话撤销 / 退出 / 拒绝 | 身份快照、阶段、结果；会话实际撤销数量 | 不记录二步验证码、会话凭据；授权拒绝独立留痕 | AdminSecurity |
| 审计查询 / 详情 / 导出 / 校验 / 归档 | 范围、关联编号、原始序号、数量、校验结果 | 仅超级管理员；原始 JSONL 不改写 | AdminSecurity、AdminAuditDetail、浏览器测试 |

模块接入依赖通用采集契约及现有业务回归；浏览器测试覆盖代表性详情和五角色入口，不表示逐个按钮均已在真实生产数据上人工操作。

## 验收结果

- SQLite：`AdminAuditDetailTest` 与 `AdminSecurityTest` 共 51 项通过。覆盖 1205 个实际变化对象、1101 个无变化对象、1101 项关联影响、HTTP 200 业务失败、邮件内部捕获异常、保存点回滚、异步重试、复合节点键、详情分页、批次、原始导出及旧哈希不变。
- Node：188 项通过，包括六套资源可重复构建和权限模块隔离、节点批量 / 外部源 / 风控页面回归。
- Chromium：密码与二步验证登录、五角色入口、中文详情、文本安全渲染、任务失败、分页和批次关联，以及权限初始化失败、资源加载失败、会话过期均通过。
- 全量 PHP：400 项，4 个失败和 2 个错误，与既有记录一致：`PlanContentSanitizer` 的非特性 JSON、`AdminTelegramUnbind` 的语言预期、`ExternalNodeRender` 的原生 header / 响应序列化、`ExternalSubscription` 的 HTTP 错误文本。不能表述为全量全部通过。
- MariaDB 11.4.5：隔离数据库中 20 项详细审计测试通过，包括 JSON 字段 / 对象 / 模块筛选、原请求分段关联、批次查询、原始导出、回滚、千级批量、防 UPDATE / DELETE 触发器和完整性校验。测试总耗时约 53 秒，包含每项新建和销毁测试库；不是接口延迟指标。MySQL 本体及生产容量仍需部署环境验证。

查询目前直接使用 SQLite JSON 函数或 MySQL/MariaDB JSON 函数检索签名载荷，通过原请求编号关联完整分段。没有新增查询投影或修改原始表；当前千级对象测试验证完整性，不能替代真实生产审计量的容量测试。大库应优先按时间和操作者收窄范围，再依据实测决定是否增加可重建投影。

## 部署与复验

本次无需新的业务表迁移，沿用已安装的 4A 审计表与签名密钥。发布包含新增服务、`config/admin_audit.php`、控制器 / 队列接入、源码以及重新生成的角色资源和清单。部署后清除旧配置和路由缓存，重启 Webman / PHP 常驻进程及队列 worker，确保新配置字典及队列采集器生效；按部署方习惯重新生成缓存。原审计密钥保持不变。

```sh
php artisan config:clear
php artisan route:clear
php vendor/bin/phpunit --filter 'AdminAuditDetailTest|AdminSecurityTest'
node scripts/apply-admin-external.cjs
node scripts/build-admin-security.cjs
node --test scripts/tests/*.test.cjs
node scripts/tests/admin-security.browser.cjs
```

构建依赖 `acorn`、`acorn-walk`，浏览器检查依赖 Playwright / Chromium。也可以用 `NODE_PATH`、`PLAYWRIGHT_MODULE` 指向现有安装。

MySQL/MariaDB 集成复验仅连接 `127.0.0.1` 的指定测试端口；每项测试新建随机 `audit_detail_test_*` 数据库，结束时仅删除本次创建的库，不清空既有数据库：

```sh
ADMIN_AUDIT_MYSQL_PORT=33307 php vendor/bin/phpunit tests/Feature/AdminAuditDetailTest.php
```

测试账号默认为本机 `root`，可通过 `ADMIN_AUDIT_MYSQL_USER` / `ADMIN_AUDIT_MYSQL_PASSWORD` 指定具有创建测试库权限的账号。PHP 需启用 `pdo_mysql`。

现有旧版队列载荷缺少新增元数据，历史记录已缺失的字段和正文不会自动恢复。首次上线后用实际用户编辑、节点批量和邮件任务复核详情，并按现有部署文档验证独立对象存储的归档策略；本地测试不代表已验证远端 WORM 配置。

## 实际注册入口附录

`{admin}` 表示配置的后台路径。入口是路由清单，具体新增 / 编辑、扫描 / 删除、预览 / 执行、提交 / 执行分支遵守上方矩阵。

| 入口 | 实际动作 | 中文功能 | 所属模块 |
| --- | --- | --- | --- |
| `DELETE monitor/api/monitoring/{tag}` | `Horizon:MonitoringController@destroy` | 队列监控：删除监控标签 | 队列监控 |
| `GET /{admin}/2fa/status` | `User\TwoFactorController@status` | 查看二步验证状态 | 二步验证 |
| `GET /{admin}/config/fetch` | `ConfigController@fetch` | 查看系统配置 | 系统设置 |
| `GET /{admin}/config/getEmailTemplate` | `ConfigController@getEmailTemplate` | 查看邮件模板 | 系统设置 |
| `GET /{admin}/config/getThemeTemplate` | `ConfigController@getThemeTemplate` | 查看主题模板 | 系统设置 |
| `GET /{admin}/coupon/fetch` | `CouponController@fetch` | 查看优惠券 | 优惠券 |
| `GET /{admin}/external/fetch` | `ExternalSourceController@fetch` | 查看外部订阅源 | 外部订阅源 |
| `GET /{admin}/giftcard/fetch` | `GiftcardController@fetch` | 查看礼品卡 | 礼品卡 |
| `GET /{admin}/knowledge/fetch` | `KnowledgeController@fetch` | 查看知识库 | 知识库 |
| `GET /{admin}/knowledge/getCategory` | `KnowledgeController@getCategory` | 查看知识库分类 | 知识库 |
| `GET /{admin}/notice/fetch` | `NoticeController@fetch` | 查看公告 | 公告管理 |
| `GET /{admin}/order/fetch` | `OrderController@fetch` | 查看订单 | 订单管理 |
| `GET /{admin}/payment/fetch` | `PaymentController@fetch` | 查看支付配置 | 支付设置 |
| `GET /{admin}/payment/getPaymentMethods` | `PaymentController@getPaymentMethods` | 查看支付方式 | 支付设置 |
| `GET /{admin}/plan/fetch` | `PlanController@fetch` | 查看订阅套餐 | 订阅套餐 |
| `GET /{admin}/rate/fetch` | `RateController@fetch` | 查看动态倍率配置 | 动态倍率 |
| `GET /{admin}/rate/policies` | `RateController@policyOptions` | 查看动态倍率场景选项 | 动态倍率 |
| `GET /{admin}/reseller/accounts` | `ResellerController@accounts` | 查看账号：倒卖商配置 | 倒卖商管理 |
| `GET /{admin}/reseller/fetch` | `ResellerController@fetch` | 查看倒卖商配置 | 倒卖商管理 |
| `GET /{admin}/reseller/orders` | `ResellerController@orders` | 查看订单：倒卖商配置 | 倒卖商管理 |
| `GET /{admin}/reseller/payment-drivers` | `ResellerController@paymentDrivers` | 查看支付方式：倒卖商配置 | 倒卖商管理 |
| `GET /{admin}/reseller/review-logs` | `ResellerController@reviewLogs` | 查看审核记录：倒卖商配置 | 倒卖商管理 |
| `GET /{admin}/reseller/stores` | `ResellerController@stores` | 查看店铺：倒卖商配置 | 倒卖商管理 |
| `GET /{admin}/reseller/summary` | `ResellerController@summary` | 查看概览：倒卖商配置 | 倒卖商管理 |
| `GET /{admin}/reseller/template/fetch` | `ResellerController@templates` | 查看模板：倒卖商配置 | 倒卖商管理 |
| `GET /{admin}/reseller/templates` | `ResellerController@templates` | 查看模板：倒卖商配置 | 倒卖商管理 |
| `GET /{admin}/reward/fetch` | `RewardController@fetch` | 查看签到与娱乐配置 | 签到与娱乐 |
| `GET /{admin}/risk/gateway/config` | `SubscribeCleanGatewayController@config` | 查看配置：订阅清洗网关 | 订阅清洗网关 |
| `GET /{admin}/risk/gateway/fetch` | `SubscribeCleanGatewayController@fetch` | 查看订阅清洗网关 | 订阅清洗网关 |
| `GET /{admin}/risk/gateway/history` | `SubscribeCleanGatewayController@history` | 查看历史：订阅清洗网关 | 订阅清洗网关 |
| `GET /{admin}/risk/gateway/options` | `SubscribeCleanGatewayController@options` | 查看选项：订阅清洗网关 | 订阅清洗网关 |
| `GET /{admin}/risk/gateway/risk` | `SubscribeCleanGatewayController@riskPending` | 查看待处理风险账号 | 订阅清洗网关 |
| `GET /{admin}/risk/gateway/rules` | `SubscribeCleanGatewayController@rules` | 查看规则：订阅清洗网关 | 订阅清洗网关 |
| `GET /{admin}/risk/shared-ip/detail` | `RiskSharedIpController@detail` | 查看详情：多账号同 IP 记录 | 多账号同 IP |
| `GET /{admin}/risk/shared-ip/fetch` | `RiskSharedIpController@fetch` | 查看多账号同 IP 记录 | 多账号同 IP |
| `GET /{admin}/risk/trace/fetch` | `RiskTraceController@fetch` | 查看订阅溯源记录 | 订阅溯源 |
| `GET /{admin}/risk/trace/history` | `RiskTraceController@history` | 查看历史：订阅溯源记录 | 订阅溯源 |
| `GET /{admin}/security/account/sessions` | `User\UserController@getActiveSession` | 查看自身登录会话 | 自身账号 |
| `GET /{admin}/security/administrators` | `SecurityController@administrators` | 查看管理员账号 | 账号与安全审计 |
| `GET /{admin}/security/asset` | `SecurityController@asset` | 加载管理员页面资源 | 账号与安全审计 |
| `GET /{admin}/security/audit/detail` | `SecurityController@auditDetail` | 查看安全审计操作详情 | 账号与安全审计 |
| `GET /{admin}/security/audit` | `SecurityController@audit` | 查询安全审计记录 | 账号与安全审计 |
| `GET /{admin}/security/bootstrap` | `SecurityController@bootstrap` | 读取管理员身份与菜单 | 账号与安全审计 |
| `GET /{admin}/security/options/groups` | `SecurityController@groupOptions` | 查看套餐权限组选项 | 账号与安全审计 |
| `GET /{admin}/security/options/plan-config` | `SecurityController@planConfig` | 查看套餐货币设置 | 账号与安全审计 |
| `GET /{admin}/security/options/plans` | `SecurityController@planOptions` | 查看订单套餐选项 | 账号与安全审计 |
| `GET /{admin}/server/group/fetch` | `Server\GroupController@fetch` | 查看权限组 | 节点权限组 |
| `GET /{admin}/server/manage/getNodes` | `Server\ManageController@getNodes` | 查看节点列表 | 节点管理 |
| `GET /{admin}/server/route/fetch` | `Server\RouteController@fetch` | 查看路由 | 节点路由 |
| `GET /{admin}/stat/getOrder` | `StatController@getOrder` | 查看订单统计：统计数据 | 统计查询 |
| `GET /{admin}/stat/getOverride` | `StatController@getOverride` | 查看概览：统计数据 | 统计查询 |
| `GET /{admin}/stat/getRanking` | `StatController@getRanking` | 查看排行：统计数据 | 统计查询 |
| `GET /{admin}/stat/getServerLastRank` | `StatController@getServerLastRank` | 查看昨日节点排行：统计数据 | 统计查询 |
| `GET /{admin}/stat/getServerTodayRank` | `StatController@getServerTodayRank` | 查看今日节点排行：统计数据 | 统计查询 |
| `GET /{admin}/stat/getStatRecord` | `StatController@getStatRecord` | 查看历史：统计数据 | 统计查询 |
| `GET /{admin}/stat/getStatUser` | `StatController@getStatUser` | 查看用户统计：统计数据 | 统计查询 |
| `GET /{admin}/stat/getStat` | `StatController@getStat` | 查看统计数据 | 统计查询 |
| `GET /{admin}/stat/getUserLastRank` | `StatController@getUserLastRank` | 查看昨日用户排行：统计数据 | 统计查询 |
| `GET /{admin}/stat/getUserTodayRank` | `StatController@getUserTodayRank` | 查看今日用户排行：统计数据 | 统计查询 |
| `GET /{admin}/system/getQueueMasters` | `\Horizon:MasterSupervisorController@index` | 队列监控：查看主进程 | 队列监控 |
| `GET /{admin}/system/getQueueStats` | `SystemController@getQueueStats` | 查看队列统计 | 系统运行信息 |
| `GET /{admin}/system/getQueueWorkload` | `SystemController@getQueueWorkload` | 查看队列负载 | 系统运行信息 |
| `GET /{admin}/system/getSystemLog` | `SystemController@getSystemLog` | 查看系统日志 | 系统运行信息 |
| `GET /{admin}/system/getSystemStatus` | `SystemController@getSystemStatus` | 查看系统状态 | 系统运行信息 |
| `GET /{admin}/theme/getThemes` | `ThemeController@getThemes` | 查看可用主题 | 主题设置 |
| `GET /{admin}/ticket/fetch` | `TicketController@fetch` | 查看工单 | 工单管理 |
| `GET /{admin}/user/checkLogin` | `User\UserController@checkLogin` | 检查登录状态 | 自身账号 |
| `GET /{admin}/user/fetch` | `UserController@fetch` | 查看用户资料 | 用户管理 |
| `GET /{admin}/user/getUserInfoById` | `UserController@getUserInfoById` | 查看用户编辑资料 | 用户管理 |
| `GET /{admin}/user/info` | `User\UserController@info` | 查看自身账号信息 | 自身账号 |
| `GET /{admin}/user/subscribe-requests` | `UserController@subscribeRequests` | 查看用户订阅请求记录 | 用户管理 |
| `GET /{admin}/user/telegramInfo` | `UserController@telegramInfo` | 查看用户 Telegram 绑定 | 用户管理 |
| `GET monitor/api/batches/{id}` | `Horizon:BatchesController@show` | 队列监控：查看详情任务批次 | 队列监控 |
| `GET monitor/api/batches` | `Horizon:BatchesController@index` | 队列监控：查看任务批次 | 队列监控 |
| `GET monitor/api/jobs/completed` | `Horizon:CompletedJobsController@index` | 队列监控：查看已完成任务 | 队列监控 |
| `GET monitor/api/jobs/failed/{id}` | `Horizon:FailedJobsController@show` | 队列监控：查看详情失败任务 | 队列监控 |
| `GET monitor/api/jobs/failed` | `Horizon:FailedJobsController@index` | 队列监控：查看失败任务 | 队列监控 |
| `GET monitor/api/jobs/pending` | `Horizon:PendingJobsController@index` | 队列监控：查看待处理任务 | 队列监控 |
| `GET monitor/api/jobs/silenced` | `Horizon:SilencedJobsController@index` | 队列监控：查看静默任务 | 队列监控 |
| `GET monitor/api/jobs/{id}` | `Horizon:JobsController@show` | 队列监控：查看详情任务 | 队列监控 |
| `GET monitor/api/masters` | `Horizon:MasterSupervisorController@index` | 队列监控：查看主进程 | 队列监控 |
| `GET monitor/api/metrics/jobs/{id}` | `Horizon:JobMetricsController@show` | 队列监控：查看详情任务指标 | 队列监控 |
| `GET monitor/api/metrics/jobs` | `Horizon:JobMetricsController@index` | 队列监控：查看任务指标 | 队列监控 |
| `GET monitor/api/metrics/queues/{id}` | `Horizon:QueueMetricsController@show` | 队列监控：查看详情队列指标 | 队列监控 |
| `GET monitor/api/metrics/queues` | `Horizon:QueueMetricsController@index` | 队列监控：查看队列指标 | 队列监控 |
| `GET monitor/api/monitoring/{tag}` | `Horizon:MonitoringController@paginate` | 队列监控：分页查看监控标签 | 队列监控 |
| `GET monitor/api/monitoring` | `Horizon:MonitoringController@index` | 队列监控：查看监控标签 | 队列监控 |
| `GET monitor/api/stats` | `Horizon:DashboardStatsController@index` | 队列监控：查看运行统计 | 队列监控 |
| `GET monitor/api/workload` | `Horizon:WorkloadController@index` | 队列监控：查看工作负载 | 队列监控 |
| `GET monitor/{view?}` | `Horizon:HomeController@index` | 队列监控：查看运行信息 | 队列监控 |
| `POST /{admin}/2fa/confirm` | `User\TwoFactorController@confirm` | 确认绑定二步验证器 | 二步验证 |
| `POST /{admin}/2fa/disable` | `User\TwoFactorController@disable` | 关闭二步验证 | 二步验证 |
| `POST /{admin}/2fa/recovery-codes/regenerate` | `User\TwoFactorController@regenerateRecoveryCodes` | 重新生成二步验证恢复码 | 二步验证 |
| `POST /{admin}/2fa/setup` | `User\TwoFactorController@setup` | 开始绑定二步验证器 | 二步验证 |
| `POST /{admin}/config/save` | `ConfigController@save` | 修改系统配置 | 系统设置 |
| `POST /{admin}/config/setTelegramWebhook` | `ConfigController@setTelegramWebhook` | 设置 Telegram 回调地址 | 系统设置 |
| `POST /{admin}/config/testSendMail` | `ConfigController@testSendMail` | 发送测试邮件 | 系统设置 |
| `POST /{admin}/coupon/drop` | `CouponController@drop` | 删除优惠券 | 优惠券 |
| `POST /{admin}/coupon/generate` | `CouponController@generate` | 新增优惠券 | 优惠券 |
| `POST /{admin}/coupon/show` | `CouponController@show` | 切换启用状态：优惠券 | 优惠券 |
| `POST /{admin}/external/source/drop` | `ExternalSourceController@dropSource` | 删除外部订阅源 | 外部订阅源 |
| `POST /{admin}/external/source/refresh` | `ExternalSourceController@refreshSource` | 刷新外部订阅源 | 外部订阅源 |
| `POST /{admin}/external/source/save` | `ExternalSourceController@saveSource` | 新增外部订阅源 | 外部订阅源 |
| `POST /{admin}/giftcard/drop` | `GiftcardController@drop` | 删除礼品卡 | 礼品卡 |
| `POST /{admin}/giftcard/generate` | `GiftcardController@generate` | 新增礼品卡 | 礼品卡 |
| `POST /{admin}/knowledge/drop` | `KnowledgeController@drop` | 删除知识库 | 知识库 |
| `POST /{admin}/knowledge/save` | `KnowledgeController@save` | 新增知识库 | 知识库 |
| `POST /{admin}/knowledge/show` | `KnowledgeController@show` | 切换启用状态：知识库 | 知识库 |
| `POST /{admin}/knowledge/sort` | `KnowledgeController@sort` | 调整排序：知识库 | 知识库 |
| `POST /{admin}/notice/drop` | `NoticeController@drop` | 删除公告 | 公告管理 |
| `POST /{admin}/notice/save` | `NoticeController@save` | 新增公告 | 公告管理 |
| `POST /{admin}/notice/show` | `NoticeController@show` | 切换启用状态：公告 | 公告管理 |
| `POST /{admin}/notice/update` | `NoticeController@update` | 修改公告 | 公告管理 |
| `POST /{admin}/order/assign` | `OrderController@assign` | 分配订单 | 订单管理 |
| `POST /{admin}/order/cancel` | `OrderController@cancel` | 取消订单 | 订单管理 |
| `POST /{admin}/order/detail` | `OrderController@detail` | 查看详情：订单 | 订单管理 |
| `POST /{admin}/order/paid` | `OrderController@paid` | 确认订单支付 | 订单管理 |
| `POST /{admin}/order/reconcile` | `OrderController@reconcile` | 补单 | 订单管理 |
| `POST /{admin}/order/update` | `OrderController@update` | 修改订单佣金状态 | 订单管理 |
| `POST /{admin}/payment/drop` | `PaymentController@drop` | 删除支付配置 | 支付设置 |
| `POST /{admin}/payment/getPaymentForm` | `PaymentController@getPaymentForm` | 查看支付配置表单 | 支付设置 |
| `POST /{admin}/payment/save` | `PaymentController@save` | 新增支付配置 | 支付设置 |
| `POST /{admin}/payment/show` | `PaymentController@show` | 切换启用状态：支付配置 | 支付设置 |
| `POST /{admin}/payment/sort` | `PaymentController@sort` | 调整排序：支付配置 | 支付设置 |
| `POST /{admin}/plan/drop` | `PlanController@drop` | 删除订阅套餐 | 订阅套餐 |
| `POST /{admin}/plan/save` | `PlanController@save` | 新增订阅套餐 | 订阅套餐 |
| `POST /{admin}/plan/sort` | `PlanController@sort` | 调整排序：订阅套餐 | 订阅套餐 |
| `POST /{admin}/plan/update` | `PlanController@update` | 修改套餐显示与续费状态 | 订阅套餐 |
| `POST /{admin}/rate/binding/apply` | `RateController@applyBinding` | 修改节点倍率策略绑定 | 动态倍率 |
| `POST /{admin}/rate/binding/preview` | `RateController@previewBinding` | 预览节点倍率策略绑定 | 动态倍率 |
| `POST /{admin}/rate/explain` | `RateController@explain` | 查看订阅倍率计算依据 | 动态倍率 |
| `POST /{admin}/rate/policy/drop` | `RateController@dropPolicy` | 删除动态倍率场景策略 | 动态倍率 |
| `POST /{admin}/rate/policy/save` | `RateController@savePolicy` | 新增动态倍率场景策略 | 动态倍率 |
| `POST /{admin}/rate/rule/drop` | `RateController@dropRule` | 删除动态倍率时段规则 | 动态倍率 |
| `POST /{admin}/rate/rule/save` | `RateController@saveRule` | 新增动态倍率时段规则 | 动态倍率 |
| `POST /{admin}/rate/settings/save` | `RateController@saveSettings` | 保存动态倍率全局设置 | 动态倍率 |
| `POST /{admin}/reseller/accounts/reset-password` | `ResellerController@resetPassword` | 重置倒卖商密码 | 倒卖商管理 |
| `POST /{admin}/reseller/accounts/review` | `ResellerController@review` | 审核倒卖商配置 | 倒卖商管理 |
| `POST /{admin}/reseller/payment-drivers` | `ResellerController@savePaymentDrivers` | 保存支付方式：倒卖商配置 | 倒卖商管理 |
| `POST /{admin}/reseller/stores/review` | `ResellerController@review` | 审核倒卖商配置 | 倒卖商管理 |
| `POST /{admin}/reseller/template/save` | `ResellerController@saveTemplate` | 新增模板：倒卖商配置 | 倒卖商管理 |
| `POST /{admin}/reseller/templates/save` | `ResellerController@saveTemplate` | 新增模板：倒卖商配置 | 倒卖商管理 |
| `POST /{admin}/reseller/update` | `ResellerController@update` | 修改倒卖商配置 | 倒卖商管理 |
| `POST /{admin}/reward/save` | `RewardController@save` | 修改签到与娱乐配置 | 签到与娱乐 |
| `POST /{admin}/risk/gateway/block` | `SubscribeCleanGatewayController@block` | 阻断订阅请求 | 订阅清洗网关 |
| `POST /{admin}/risk/gateway/config/save` | `SubscribeCleanGatewayController@saveConfig` | 保存配置：订阅清洗网关 | 订阅清洗网关 |
| `POST /{admin}/risk/gateway/export` | `SubscribeCleanGatewayController@export` | 导出订阅清洗网关 | 订阅清洗网关 |
| `POST /{admin}/risk/gateway/release` | `SubscribeCleanGatewayController@release` | 解除订阅请求阻断 | 订阅清洗网关 |
| `POST /{admin}/risk/gateway/risk/handle` | `SubscribeCleanGatewayController@handleRisk` | 标记风险账号已处理 | 订阅清洗网关 |
| `POST /{admin}/risk/trace/token/lookup` | `RiskTraceController@lookup` | 查询订阅溯源 | 订阅溯源 |
| `POST /{admin}/risk/trace/token/reveal` | `RiskTraceController@reveal` | 查看订阅溯源敏感信息 | 订阅溯源 |
| `POST /{admin}/security/account/password` | `User\UserController@changePassword` | 修改自身密码 | 自身账号 |
| `POST /{admin}/security/account/sessions/remove` | `User\UserController@removeActiveSession` | 撤销自身登录会话 | 自身账号 |
| `POST /{admin}/security/administrators/role` | `SecurityController@assignRole` | 修改管理员身份 | 账号与安全审计 |
| `POST /{admin}/security/audit/export` | `SecurityController@exportAudit` | 导出安全审计记录 | 账号与安全审计 |
| `POST /{admin}/security/audit/verify` | `SecurityController@verifyAudit` | 校验安全审计完整性 | 账号与安全审计 |
| `POST /{admin}/security/logout` | `SecurityController@logout` | 退出后台登录 | 账号与安全审计 |
| `POST /{admin}/server/anytls/copy` | `Server\AnyTLSController@copy` | 复制AnyTLS 节点 | 节点管理 |
| `POST /{admin}/server/anytls/drop` | `Server\AnyTLSController@drop` | 删除AnyTLS 节点 | 节点管理 |
| `POST /{admin}/server/anytls/save` | `Server\AnyTLSController@save` | 新增AnyTLS 节点 | 节点管理 |
| `POST /{admin}/server/anytls/update` | `Server\AnyTLSController@update` | 修改AnyTLS 节点显示状态 | 节点管理 |
| `POST /{admin}/server/group/drop` | `Server\GroupController@drop` | 删除权限组 | 节点权限组 |
| `POST /{admin}/server/group/save` | `Server\GroupController@save` | 新增权限组 | 节点权限组 |
| `POST /{admin}/server/hysteria/copy` | `Server\HysteriaController@copy` | 复制Hysteria 节点 | 节点管理 |
| `POST /{admin}/server/hysteria/drop` | `Server\HysteriaController@drop` | 删除Hysteria 节点 | 节点管理 |
| `POST /{admin}/server/hysteria/save` | `Server\HysteriaController@save` | 新增Hysteria 节点 | 节点管理 |
| `POST /{admin}/server/hysteria/update` | `Server\HysteriaController@update` | 修改Hysteria 节点显示状态 | 节点管理 |
| `POST /{admin}/server/manage/host/preview` | `Server\ManageController@previewHostReplacement` | 预览批量替换节点地址 | 节点管理 |
| `POST /{admin}/server/manage/host/replace` | `Server\ManageController@replaceHost` | 批量替换节点地址 | 节点管理 |
| `POST /{admin}/server/manage/nodes/copy` | `Server\ManageController@copyNodes` | 批量复制节点 | 节点管理 |
| `POST /{admin}/server/manage/nodes/delete` | `Server\ManageController@deleteNodes` | 批量删除节点 | 节点管理 |
| `POST /{admin}/server/manage/ports/apply` | `Server\ManageController@applyPorts` | 批量修改节点端口 | 节点管理 |
| `POST /{admin}/server/manage/ports/preview` | `Server\ManageController@previewPorts` | 预览批量修改节点端口 | 节点管理 |
| `POST /{admin}/server/manage/protocol/apply` | `Server\ManageController@applyProtocolSettings` | 批量修改节点协议设置 | 节点管理 |
| `POST /{admin}/server/manage/protocol/preview` | `Server\ManageController@previewProtocolSettings` | 预览批量修改节点协议设置 | 节点管理 |
| `POST /{admin}/server/manage/rate/apply` | `Server\ManageController@applyRate` | 批量修改节点倍率 | 节点管理 |
| `POST /{admin}/server/manage/rate/preview` | `Server\ManageController@previewRate` | 预览批量修改节点倍率 | 节点管理 |
| `POST /{admin}/server/manage/rename/apply` | `Server\ManageController@applyRename` | 批量修改节点名称 | 节点管理 |
| `POST /{admin}/server/manage/rename/preview` | `Server\ManageController@previewRename` | 预览批量修改节点名称 | 节点管理 |
| `POST /{admin}/server/manage/server-port/apply` | `Server\ManageController@applyServerPort` | 批量修改节点服务端口 | 节点管理 |
| `POST /{admin}/server/manage/server-port/preview` | `Server\ManageController@previewServerPort` | 预览批量修改节点服务端口 | 节点管理 |
| `POST /{admin}/server/manage/sort` | `Server\ManageController@sort` | 调整排序：节点 | 节点管理 |
| `POST /{admin}/server/manage/tls-fields/apply` | `Server\ManageController@applyTlsFields` | 批量修改节点 TLS 字段 | 节点管理 |
| `POST /{admin}/server/manage/tls-fields/inspect` | `Server\ManageController@inspectTlsFields` | 查看节点 TLS 字段 | 节点管理 |
| `POST /{admin}/server/manage/tls-fields/preview` | `Server\ManageController@previewTlsFields` | 预览批量修改节点 TLS 字段 | 节点管理 |
| `POST /{admin}/server/route/drop` | `Server\RouteController@drop` | 删除路由 | 节点路由 |
| `POST /{admin}/server/route/save` | `Server\RouteController@save` | 新增路由 | 节点路由 |
| `POST /{admin}/server/shadowsocks/copy` | `Server\ShadowsocksController@copy` | 复制Shadowsocks 节点 | 节点管理 |
| `POST /{admin}/server/shadowsocks/drop` | `Server\ShadowsocksController@drop` | 删除Shadowsocks 节点 | 节点管理 |
| `POST /{admin}/server/shadowsocks/save` | `Server\ShadowsocksController@save` | 新增Shadowsocks 节点 | 节点管理 |
| `POST /{admin}/server/shadowsocks/update` | `Server\ShadowsocksController@update` | 修改Shadowsocks 节点显示状态 | 节点管理 |
| `POST /{admin}/server/trojan/copy` | `Server\TrojanController@copy` | 复制Trojan 节点 | 节点管理 |
| `POST /{admin}/server/trojan/drop` | `Server\TrojanController@drop` | 删除Trojan 节点 | 节点管理 |
| `POST /{admin}/server/trojan/save` | `Server\TrojanController@save` | 新增Trojan 节点 | 节点管理 |
| `POST /{admin}/server/trojan/update` | `Server\TrojanController@update` | 修改Trojan 节点显示状态 | 节点管理 |
| `POST /{admin}/server/tuic/copy` | `Server\TuicController@copy` | 复制Tuic 节点 | 节点管理 |
| `POST /{admin}/server/tuic/drop` | `Server\TuicController@drop` | 删除Tuic 节点 | 节点管理 |
| `POST /{admin}/server/tuic/save` | `Server\TuicController@save` | 新增Tuic 节点 | 节点管理 |
| `POST /{admin}/server/tuic/update` | `Server\TuicController@update` | 修改Tuic 节点显示状态 | 节点管理 |
| `POST /{admin}/server/v2node/copy` | `Server\V2nodeController@copy` | 复制V2node 节点 | 节点管理 |
| `POST /{admin}/server/v2node/drop` | `Server\V2nodeController@drop` | 删除V2node 节点 | 节点管理 |
| `POST /{admin}/server/v2node/save` | `Server\V2nodeController@save` | 新增V2node 节点 | 节点管理 |
| `POST /{admin}/server/v2node/update` | `Server\V2nodeController@update` | 修改V2node 节点显示状态 | 节点管理 |
| `POST /{admin}/server/vless/copy` | `Server\VlessController@copy` | 复制Vless 节点 | 节点管理 |
| `POST /{admin}/server/vless/drop` | `Server\VlessController@drop` | 删除Vless 节点 | 节点管理 |
| `POST /{admin}/server/vless/save` | `Server\VlessController@save` | 新增Vless 节点 | 节点管理 |
| `POST /{admin}/server/vless/update` | `Server\VlessController@update` | 修改Vless 节点显示状态 | 节点管理 |
| `POST /{admin}/server/vmess/copy` | `Server\VmessController@copy` | 复制Vmess 节点 | 节点管理 |
| `POST /{admin}/server/vmess/drop` | `Server\VmessController@drop` | 删除Vmess 节点 | 节点管理 |
| `POST /{admin}/server/vmess/save` | `Server\VmessController@save` | 新增Vmess 节点 | 节点管理 |
| `POST /{admin}/server/vmess/update` | `Server\VmessController@update` | 修改Vmess 节点显示状态 | 节点管理 |
| `POST /{admin}/theme/getThemeConfig` | `ThemeController@getThemeConfig` | 查看主题配置 | 主题设置 |
| `POST /{admin}/theme/saveThemeConfig` | `ThemeController@saveThemeConfig` | 保存主题配置 | 主题设置 |
| `POST /{admin}/ticket/close` | `TicketController@close` | 关闭工单 | 工单管理 |
| `POST /{admin}/ticket/reply` | `TicketController@reply` | 回复工单 | 工单管理 |
| `POST /{admin}/user/allDel` | `UserController@allDel` | 批量删除用户 | 用户管理 |
| `POST /{admin}/user/ban` | `UserController@ban` | 批量停用用户 | 用户管理 |
| `POST /{admin}/user/delUser` | `UserController@delUser` | 删除用户 | 用户管理 |
| `POST /{admin}/user/dumpCSV` | `UserController@dumpCSV` | 导出用户列表 | 用户管理 |
| `POST /{admin}/user/generate` | `UserController@generate` | 新增用户资料 | 用户管理 |
| `POST /{admin}/user/resetPassword` | `UserController@resetPassword` | 重置用户密码 | 用户管理 |
| `POST /{admin}/user/resetSecret` | `UserController@resetSecret` | 重置用户订阅链接与 UUID | 用户管理 |
| `POST /{admin}/user/sendMail` | `UserController@sendMail` | 向用户发送邮件 | 用户管理 |
| `POST /{admin}/user/setInviteUser` | `UserController@setInviteUser` | 修改用户邀请人 | 用户管理 |
| `POST /{admin}/user/subscribe-audit/clear` | `UserController@clearSubscribeAudit` | 清理用户订阅请求记录 | 用户管理 |
| `POST /{admin}/user/subscription-cleanup` | `UserController@subscriptionCleanup` | 扫描无有效订阅的用户 | 用户管理 |
| `POST /{admin}/user/subscription/revoke` | `UserController@revokeSubscription` | 撤销用户订阅 | 用户管理 |
| `POST /{admin}/user/subscription/set-primary` | `UserController@setPrimarySubscription` | 修改用户主订阅 | 用户管理 |
| `POST /{admin}/user/telegramUnbind` | `UserController@telegramUnbind` | 解绑用户 Telegram 账号 | 用户管理 |
| `POST /{admin}/user/update` | `UserController@update` | 修改用户资料 | 用户管理 |
| `POST monitor/api/batches/retry/{id}` | `Horizon:BatchesController@retry` | 队列监控：重试任务批次 | 队列监控 |
| `POST monitor/api/jobs/retry/{id}` | `Horizon:RetryController@store` | 队列监控：重试失败任务 | 队列监控 |
| `POST monitor/api/monitoring` | `Horizon:MonitoringController@store` | 队列监控：添加监控标签 | 队列监控 |
