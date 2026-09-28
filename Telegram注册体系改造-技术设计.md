# Telegram 注册体系改造 · 技术设计

> v0.1 · 2026-09-28 · 配套文档：`Telegram注册体系改造-需求文档.md`
>
> 状态：**待审批**。审批通过后按第 12 节的工作拆分开工。

---

## 0. 决策归档

### 已定（本次确认）

| 编号 | 结论 |
|---|---|
| D1 | **只移除传统注册，不移除邮箱登录**。存量用户通过弹窗引导绑定 Telegram；管理员后台的邮箱 + 密码 + 二步验证登录保持不动。 |
| D2 | 自动核验即时完成，「30 秒内」按 SLA 上限理解；发码延迟做成配置项，**默认 10 秒**（填 0 = 立即发码）。注意延迟走队列，`QUEUE_CONNECTION=sync` 时会被忽略。 |

### 按假设推进（审批时可一键否决）

| 编号 | 我的取舍 | 理由 |
|---|---|---|
| D3 | 虚拟邮箱保留邮箱格式校验（≤64 字符），全局唯一，不允许与现存 `v2_user.email` 撞车 | 邮箱已不是凭据通道，占位不影响安全；保留格式便于客服识别、导出与工单显示 |
| D4 | 服务端移除注册路由（硬闸门）+ 前端注入 CSS 隐藏邮箱注册表单（体验） | 三套主题产物里只有 ez 认「仅第三方注册」开关，靠开关藏不掉表单 |
| D5 | 机器人内购买走**方案 A**：发支付链接 / 二维码按钮，用户在站外完成支付 | 兼容现有全部支付通道；Telegram Payments 留作后续扩展点 |
| D6 | 复用现有单机器人（单 token、单 webhook），命令按目录分组便于将来拆分 | 代码里只有一套机器人基础设施 |
| D7 | 存量 `v2_oauth_identity` 的 google / github 记录保留归档，存量真实邮箱保留原值 | 删除不可逆，保留不影响新流程 |
| D8 | 应急通道 = **管理员后台**（已有建号 / 重置密码 / 改邮箱） | 已核实后台存在 `user/generate`、`user/resetPassword`，无需额外保留邮箱注册 |

### 审批时需你确认（见第 13 节）

A1 员工是否也要收工单通知 · A2 发码延迟默认值 · A3 深链形态 · A4 虚拟邮箱能否与他人邮箱重名 · A5 是否保留 `/traffic` 等旧命令别名

---

## 1. 架构与一个关键澄清

**本仓库的「机器人」就是面板自身。** Telegram 的 webhook 是面板的一个路由（`POST /api/v1/guest/telegram/webhook`），命令以插件类形式挂在 `app/Plugins/Telegram/Commands/` 下，与业务代码同进程、同数据库。

因此「机器人通过 Telegram API 向面板端完成交互请求」在实现上**不是跨服务调用**，而是：

- 收发消息：调 Telegram Bot API（`TelegramService`）
- 业务动作：直接调用面板的 Service（同进程）

这决定了工作量远小于「独立机器人服务」的设想——不需要新接口层、不需要内网鉴权、不需要队列同步。风险也随之集中在一点：**面板挂了机器人也挂**，这与 D8 的应急通道设计一致。

| 组件 | 现状 | 本次动作 |
|---|---|---|
| webhook 入口 | `Guest/TelegramController::webhook`，含 `update_id` 幂等 | 扩展：新增注册会话分支 |
| 命令分发 | `handle()` 扫描插件目录 | 不变 |
| 命令插件 | 11 个 | 新增 2 个、改造 2 个、重命名 1 个 |
| 消息发送 | `TelegramService` / `SendTelegramJob` | 不变 |
| 业务服务 | Service 层 | 新增 `TelegramRegistrationService`；抽取下单与发起支付为服务 |

---

## 2. 数据模型

### 2.1 迁移 DDL

```sql
-- ① 账号与 Telegram 一对一（可空唯一列，存量 NULL 互不冲突）
--    执行前先查重：SELECT telegram_id, COUNT(*) FROM v2_user
--                 WHERE telegram_id IS NOT NULL GROUP BY telegram_id HAVING COUNT(*) > 1;
ALTER TABLE `v2_user` ADD UNIQUE KEY `uniq_telegram_id` (`telegram_id`);

-- ② 注册申请
CREATE TABLE `v2_telegram_registration` (
  `id`                int(11)     NOT NULL AUTO_INCREMENT,
  `telegram_id`       bigint(20)  NOT NULL COMMENT '机器人私聊 chat_id',
  `telegram_username` varchar(64) DEFAULT NULL,
  `email`             varchar(64) NOT NULL COMMENT '用户提交的虚拟邮箱',
  `code_hash`         char(64)    DEFAULT NULL COMMENT 'sha256(验证码+盐)，不存明文',
  `status`            tinyint(4)  NOT NULL DEFAULT '0' COMMENT '0待核验 1已发码 2已完成 3已作废',
  `attempts`          tinyint(4)  NOT NULL DEFAULT '0' COMMENT '验证码校验失败次数',
  `sent_at`           int(11)     DEFAULT NULL COMMENT '最近一次发码时间，节流用',
  `expires_at`        int(11)     DEFAULT NULL COMMENT '验证码过期时间',
  `user_id`           int(11)     DEFAULT NULL COMMENT '建号后回填',
  `created_at`        int(11)     NOT NULL,
  `updated_at`        int(11)     NOT NULL,
  PRIMARY KEY (`id`),
  KEY `telegram_id` (`telegram_id`),
  KEY `email` (`email`),
  KEY `status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Telegram 注册申请';
```

**为什么用表而不是缓存**：流程横跨「机器人 → 网页 → 面板」三段，中途 Redis 被清空或重启就断链；表还能让客服按邮箱/UID 查申请状态。验证码本身仍只在表里存哈希。

**邮箱唯一性的两层保证**：申请层面在建号事务里 `lockForUpdate` 查重，最终以 `v2_user.email` 的唯一索引兜底。并发提交同一邮箱时，后者在建号那一刻失败并提示邮箱被占用。

### 2.2 缓存键（需登记进 `CacheKey::KEYS` 白名单，否则 `CacheKey::get` 会直接 abort）

| 键 | 用途 | TTL |
|---|---|---|
| `TELEGRAM_REGISTER_SESSION` | 记录「该私聊正在等待提交邮箱」的会话状态 | 300 秒 |
| `TELEGRAM_REGISTER_THROTTLE` | 同一 UID 的发码节流 | 60 秒 |

> 上一轮已经加过 `TELEGRAM_ACCOUNT_BINDING`、`TELEGRAM_FORGET_CODE`、`LAST_SEND_TELEGRAM_FORGET_TIMESTAMP` 三个键，本次在其后追加。

---

## 3. 注册状态机

```
        /regedit 提交邮箱
[无] ──────────────────────► 0 待核验
                              │ 自动核验（唯一性 / 限流 / 开关 / 黑名单）
                              ▼
                          1 已发码 ── 超时未用 ──► 3 已作废
                              │  ▲
              网页校验码通过   │  │ 重发（60 秒节流，覆盖 code_hash）
                              ▼  │
                          2 已完成（建号 + 回填 user_id + 写 telegram_id）

  失败次数 ≥ 5 ──► 3 已作废（需重新 /regedit）
```

**同一 `telegram_id` 同时只允许一条活跃申请**（status ∈ {0,1}）。再次 `/regedit` 时作废旧记录、新建一条，避免用户卡在过期申请上。

**核验的四个检查项**（全部通过才算「已通过核验申请」）：

1. 邮箱未被 `v2_user` 占用，也未被其它活跃申请占用
2. 该 `telegram_id` 尚未绑定任何账号（`v2_user.telegram_id` 无记录）
3. IP 与 UID 维度的注册限流未超（复用 `REGISTER_IP_RATE_LIMIT`）
4. 注册开关开启、机器人配置完整

---

## 4. 机器人侧设计

### 4.1 命令总表

| 命令 | 前置 | 实现 | 文件 |
|---|---|---|---|
| `/start` | — | 改造：先记录 UID 关联，再展示功能菜单 | 改 `Commands/Start.php` |
| `/regedit` | 无账号 | 新增：提交邮箱 → 核验 → 发码 | 新建 `Commands/Regedit.php` |
| `/resetpassword` | 已绑账号 | 新增：找回密码发码入口 | 新建 `Commands/ResetPassword.php` |
| `/login` | 已绑账号 | **已存在**，直接用 | 不改 |
| `/get_subscription` | 已绑账号 | 新增：套餐列表 → 下单 → 支付按钮 | 新建 `Commands/GetSubscription.php` |
| `/get_traffic` | 已绑账号 | 重命名自现有 `/traffic`，保留旧命令作别名 | 改 `Commands/Traffic.php` |
| `/reset` | 已绑账号 | 新增：按套餐重置包价格重置流量 | 新建 `Commands/Reset.php` |
| `/bind` `/unbind` `/reply` `/checkin` `/dice` `/slots` `/reward` | — | 不动 | — |

命令与账号的映射沿用现有约定：`User::where('telegram_id', $chatId)->first()`。

### 4.2 `/start` 的行为变化与兼容

现状：`Start.php` 直接调 `TelegramRewardService::showMenu()` 展示娱乐菜单。

改造后按参数分流（顺序不能颠倒）：

| 入参 | 行为 |
|---|---|
| `/start ubind_<nonce>` | 账号绑定（上一轮已实现），**优先匹配**，命中即返回 |
| `/start reg_<nonce>` | 注册深链（见 A3），进入 `/regedit` 等价的等待邮箱状态 |
| `/start`（无参） | 记录 UID 交互时间 → 展示菜单：未绑账号时菜单顶部为「注册账号」按钮，已绑账号时为娱乐入口 |

### 4.3 `/regedit` 交互

1. 已在等待邮箱状态或刚进入 → 回一条提示，要求**直接回复一个邮箱**（用 `ForceReply` 降低误操作）
2. 收到邮箱：
   - 格式不合法 → 提示重填（会话状态保留）
   - 邮箱已占用 → 提示「该邮箱已被使用，请换一个虚拟邮箱」（不区分是"已注册"还是"被他人占用"，避免枚举）
   - 通过 → 写入申请（status=0），立刻回「已通过核验申请」，按配置延迟发码（默认 10 秒）
3. 发码文案包含：六位验证码、有效期、以及**一个直达注册页的链接**（带 ticket，见 A3）
4. 用户若再次 `/regedit`：作废旧申请、重新开始

### 4.4 消息文案要点

新增文案集中在 `resources/lang/*.json`，中文为准、其余语言回落。需要覆盖：等待邮箱、格式错误、邮箱被占用、已通过核验、验证码下发、验证码有效期、重发过于频繁、失败次数超限、已完成注册、账号已存在（引导 `/login`）、未绑定账号（引导 `/regedit`）。

---

## 5. 面板侧接口契约

### 5.1 新增

**`POST /passport/auth/register/telegram`**

| 项 | 内容 |
|---|---|
| 入参 | `code`（必填，6 位）、`ticket`（可选，深链携带） |
| 定位申请 | 有 `ticket` 按其定位；无 `ticket` 时按 `code_hash` 反查活跃申请 |
| 成功出参 | 与现有 `register` 一致：登录 `token` + 用户信息 |
| 失败 | `422` 验证码错误/过期（**不区分两者**）、`409` 邮箱已被占用、`429` 触发限流 |
| 副作用 | 建号（虚拟邮箱 + 随机密码 + `telegram_id`）、申请置 2、清空 code_hash、写登录态 |
| 限流 | IP 维度 3 次/分钟；同一申请失败 5 次即作废 |

**`GET /passport/auth/register/telegram/status`**

供网页轮询（用户先打开网页、再回机器人取码的场景）。入参 `ticket`，返回 `{status, email_masked, expires_at}`。不返回验证码本身。

**`POST /passport/auth/forget/telegram`**

上一轮已实现（校验验证码 + 重置密码），本次仅调整入口：**发起改到机器人侧**，网页只负责提交「验证码 + 新密码」。

### 5.2 移除

| 路由 | 说明 |
|---|---|
| `POST /passport/auth/register` | 邮箱注册入口 |
| `POST /passport/comm/sendEmailVerify` | 邮件验证码下发 |
| `POST /passport/auth/forget` | 邮箱找回密码 |
| `GET /passport/oauth/{provider}/redirect\|callback\|state`（仅 google / github） | 保留 telegram 分支 |

**保留不动**：`/passport/auth/login`（邮箱登录）、`/passport/auth/verify2fa`、`/passport/auth/forget/telegram`、`/passport/comm/sendTelegramForgetCode`（若按 A3 改为机器人发起，此接口降级为内部调用或一并移除）。

---

## 6. Web 前端设计

前端产物是编译后的主题包（三套：default / ez / signature），源码不在仓库，因此统一走**独立脚本注入**，不碰主题 bundle。

| 页面 | 改造方式 |
|---|---|
| 注册页 | 注入 CSS 隐藏邮箱注册表单与 OAuth 按钮区（只留 Telegram），插入「用 Telegram 注册」引导块（按钮 + 深链 + 二维码） |
| 登录页 | 邮箱 + 密码表单**保留**；「忘记密码」链接改为引导弹窗：「请在机器人里发送 /resetpassword」 |
| 找回密码页 | 改为「验证码 + 新密码」两个字段（不再提交邮箱验证码） |
| 全站 | 复用上一轮的 `telegram-bind-widget.js`：未绑定 Telegram 的存量用户弹出引导（开关 `telegram_account_binding_enable`） |

挂载点与上一轮一致：三套主题的 `dashboard.blade.php` 各加一行 `<script>`，新增文件 `public/assets/telegram-register-widget.js`。

---

## 7. 移除清单（文件级）

| 对象 | 动作 | 备注 |
|---|---|---|
| `Passport/CommController::sendEmailVerify` | 删除方法 + 路由 | 同文件的 `sendTelegramForgetCode` 保留 |
| `Passport/AuthController::register` + `AuthRegister` | 删除 | `Store/Controller` 复用此方法，需同步改为调用新的 Telegram 注册或直接关闭店面注册（**波及面，需确认**） |
| `Passport/AuthController::forget` + `AuthForget` | 删除 | 被 `forgetByTelegram` 取代 |
| `AuthController` 邮箱验证码段 | 删除 | `EMAIL_VERIFY_CODE` 相关 |
| `OAuthService` google / github 分支 | 删除 | telegram 分支保留 |
| `OAuthController` 中 google / github 处理 | 删除 | 保留 telegram 与通用的 `complete` |
| 后台配置项 | 删除 | `email_verify`、`email_whitelist_*`、`email_gmail_limit_enable`、`oauth_google_*`、`oauth_github_*` |
| 后台 UI（编译产物） | 改 | 系统配置里移除上述字段、OAuth 页只留 Telegram |
| 三套主题产物 | 不改 | 由注入脚本收口 |

> **店面注册的波及面**：`Store/Controller::register` 直接调用 `AuthController::register`，删掉它会让所有 reseller 店面无法注册。这是本次最容易被忽略的连带影响，需要你在审批时选：店面同步改为 Telegram 注册，或直接关闭店面注册。

---

## 8. 安全设计

| 威胁 | 对策 |
|---|---|
| 验证码枚举 | 6 位码 + 每申请 5 次失败即作废 + IP 3 次/分钟；错误响应不区分「不存在」与「不匹配」 |
| 重放 | 校验通过即清空 `code_hash`、状态置 2；`ticket` 一次性 |
| 并发抢注同一邮箱 | 建号事务内 `lockForUpdate` 查重 + `v2_user.email` 唯一索引兜底 |
| 并发抢注同一 Telegram | `v2_user.telegram_id` 唯一索引兜底（本次新增） |
| 冒用他人 UID 注册 | 码只发到该 UID 的私聊；申请与 UID 强绑定，跨 UID 提交无效 |
| 机器人消息伪造 | 沿用现有 webhook 的 `X-Telegram-Bot-Api-Secret-Token` 校验与 `update_id` 幂等 |
| 暴力刷申请 | 同一 UID 同时仅一条活跃申请；发码 60 秒节流；IP 注册限流复用现有开关 |
| 验证码泄漏在库里 | 只存 `sha256(code + 盐)`，日志不落码 |

---

## 9. 通知、购买、查询、重置

**通知收口**：订单与工单通知已由 `sendMessageWithAdmin($msg, $isStaff)` 发出，按 `is_admin`（可选 `is_staff`）筛选收件人且要求已绑定 `telegram_id`。本次只做两件事：调用点集中到一处、显式鉴权。**注意**：工单通知当前传 `true`（含员工），按「只有管理员」的要求需改为 `false` —— 这会取消员工的工单提醒，见 A1。

**机器人内购买**：需要把现有 `OrderController::save` / `checkout` 里的下单与「发起支付」逻辑抽成服务（现在耦合在控制器与 `$request->user` 上），机器人侧调用服务后把支付链接作为 inline 按钮回给用户。这是本阶段唯一需要重构现有代码的地方。

**`/get_traffic`**：复用现有 `/traffic` 的输出格式，重命名并保留旧命令别名。

**`/reset`**：复用 `period=reset_price` 的下单路径，价格取套餐 `reset_price`；套餐未配置重置包时回「当前订阅不支持重置」。

**娱乐并入**：命令与运算逻辑都不动，仅把 `Start` 菜单入口与注册引导并列。

---

## 10. 兼容与迁移

| 对象 | 处理 |
|---|---|
| 存量邮箱账号 | 登录方式不变；登录后若未绑定 Telegram 且非 OAuth，弹出引导（复用已有 widget 与开关） |
| 存量 OAuth（google / github）账号 | 保留可登录；`v2_oauth_identity` 记录归档不动（D7） |
| 管理员 / 员工 | 完全不受影响（`required()` 已豁免，后台登录独立） |
| 存量 `telegram_id` 重复 | 加唯一索引前先查重；如有重复需人工清理后再加 |
| 回滚 | 数据层可逆（删表、删索引）；代码层按阶段回滚。**移除邮箱注册后如需临时恢复，只能回滚代码**——这是 D8 选「管理员后台兜底」的代价，可接受 |

---

## 11. 测试要点

**正常链路**：`/start` → `/regedit` → 提交邮箱 → 收码 → 网页提交 → 建号成功 → `/login` 免密登录 → `/get_traffic` 有数据。

**异常链路**：邮箱格式错 / 邮箱被占 / 同一 UID 重复 `/regedit` / 验证码错 5 次作废 / 验证码过期 / 60 秒内重复发码 / 未 `/start` 直接 `/regedit` / 已注册 UID 再次 `/regedit`（应引导 `/login`）/ 已绑账号用户 `/resetpassword` 后重置密码并踢会话。

**并发**：两个 UID 同时提交同一邮箱（一个成功一个 409）；同一 UID 并发提交两次申请（只留一条活跃）。

**安全**：错误响应不泄漏邮箱是否存在；`code_hash` 不落明文；重复使用已消费的验证码失败；伪造 webhook 被 401。

**回归**：邮箱登录仍可用；管理员后台登录与二步验证不受影响；订阅下发、节点接口不受影响；已有娱乐命令仍可用。

---

## 12. 工作拆分与工期

| 阶段 | 任务 | 交付 | 估算 |
|---|---|---|---|
| 一 · 数据层 | 迁移脚本（索引 + 新表）、`CacheKey` 登记、`TelegramRegistrationService` 骨架 | 可跑的迁移 + 单测 | 1 人日 |
| 二 · 注册闭环 | `/start` 改造、`/regedit`、注册接口、深链、`telegram-register-widget.js`、注册页收口 | 端到端可注册 | 3–4 人日 |
| 三 · 移除与找回 | 移除邮箱注册/邮件验证码/Google·GitHub OAuth、后台配置与 UI 清理、`/resetpassword` 与找回页改造 | 旧通道下线 | 2 人日 |
| 四 · 机器人功能 | 下单与支付服务抽取、`/get_subscription`、`/get_traffic`、`/reset`、通知收口 | 机器人自助闭环 | 2–3 人日 |
| 五 · 测试与联调 | 第 11 节用例全跑 + 三套主题回归 | 验收 | 1–2 人日 |

**合计约 9–12 人日**。阶段一、二完成即可上线注册闭环，三、四可分批灰度。

---

## 13. 审批时需你确认

| 编号 | 问题 | 我的建议 |
|---|---|---|
| A1 | 工单通知是否只发管理员（当前含员工） | 按需求改为仅管理员；若员工也需知情，保留 `is_staff` |
| A2 | 发码延迟默认值 | 10 秒（可后台配置，0 = 立即发码） |
| A3 | 是否要「深链直达注册页」（机器人发带 ticket 的链接，网页自动填好邮箱，用户只输 6 位码） | 要，明显降低操作成本；没有它用户需在网页手填邮箱 + 码 |
| A4 | 虚拟邮箱能否与他人邮箱重名 | 不能（沿用唯一索引），换一个虚拟邮箱即可 |
| A5 | `/traffic` 等旧命令是否保留为别名 | 保留，避免存量用户找不到 |
| A6 | **店面（reseller）注册**：同步改为 Telegram 注册，还是直接关闭 | 建议直接关闭并提示「请前往主站注册」，改动最小 |
