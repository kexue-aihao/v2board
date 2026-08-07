# V2Board API 管理文档

本文档根据当前 dev 分支源码整理，接口以当前路由、控制器和 FormRequest 校验规则为准。

## 一、接口总览

| 项目 | 当前值 |
| --- | --- |
| V1 API 前缀 | /api/v1 |
| V2 API 前缀 | /api/v2 |
| 管理员 API 前缀 | /api/v1/{secure_path} |
| 用户鉴权 | Authorization 或 auth_data |
| 管理员鉴权 | Authorization 或 auth_data + is_admin |
| 员工鉴权 | Authorization 或 auth_data + staff 权限 |
| 倒卖商鉴权 | Authorization 或 auth_data + reseller 会话 |
| 店铺客户鉴权 | Authorization 或 auth_data + user 会话 |
| 订阅鉴权 | token 参数 |
| 节点鉴权 | server_token + node_id |
| 默认订阅地址 | /api/v1/client/subscribe |

管理员 secure_path 读取 config('v2board.secure_path')，生产环境应设置为不可预测的值。

## 二、通用响应与状态码

### 2.1 响应格式

| 类型 | 格式 |
| --- | --- |
| 普通成功 | { "data": {} } |
| 列表成功 | { "data": [] } |
| 分页成功 | { "data": [], "total": 0 } |
| 空数据 | { "data": [] } |
| 错误 | 由 Webman/Laravel 异常处理器返回 |

### 2.2 HTTP 状态码

| 状态码 | 含义 | 常见场景 |
| --- | --- | --- |
| 200 | 成功 | 查询、保存、删除成功 |
| 302 | 跳转 | 临时登录或页面跳转 |
| 403 | 无权访问 | 未登录、权限不足、Token 无效 |
| 404 | 资源不存在 | 用户、订阅、订单不存在 |
| 422 | 参数或业务校验失败 | 参数格式错误、状态不允许 |
| 503 | 服务暂不可用 | 站点维护、倒卖商服务关闭、缓存或算术验证不可用 |
| 500 | 业务处理失败 | 支付、保存、配置处理失败 |

## 三、鉴权参数表

| 类型 | 请求位置 | 参数或请求头 | 适用接口 | 说明 |
| --- | --- | --- | --- | --- |
| 用户 | Header | Authorization: {auth_data} | /api/v1/user/* | 推荐方式 |
| 用户 | Query/Body | auth_data={auth_data} | /api/v1/user/* | 兼容前端 |
| 管理员 | Header | Authorization: {auth_data} | /api/v1/{secure_path}/* | 必须具备 is_admin |
| 员工 | Header | Authorization: {auth_data} | /api/v1/staff/* | 使用 staff 中间件 |
| 倒卖商 | Header/Body | Authorization: {auth_data} 或 auth_data={auth_data} | /api/v1/reseller/* | 使用 reseller 中间件，不能访问管理员接口 |
| 店铺客户 | Header/Body | Authorization: {auth_data} 或 auth_data={auth_data} | /api/v1/store/{slug}/* | 使用现有 user 中间件并校验店铺归属 |
| 客户端 | Query/Body | token={subscription_token} | 订阅接口 | 订阅地址敏感 |
| 节点 | Query/Body | token、node_id | /api/v1/server/*、/api/v2/server/* | token 为 server_token |

禁止把 auth_data、订阅 Token、server_token、支付密钥写入日志或前端公开代码。

## 四、认证与公开接口

### 4.1 Passport 接口

基础路径：/api/v1/passport

| 方法 | 接口路径 | 鉴权 | 请求参数 | 返回/说明 |
| --- | --- | --- | --- | --- |
| POST | /auth/register | 无 | email、password；可选 invite_code、email_code、recaptcha_data、arithmetic_challenge_id、arithmetic_answer | 注册成功返回 auth_data；算术验证开启时服务端强制校验；开启 oauth_register_only 时本接口直接返回 403（仅允许第三方注册）；@telegram.invalid、@github.io 为保留域，拒绝注册 |
| POST | /auth/login | 无 | email、password | 密码正确时返回 auth_data 或二步验证 challenge |
| POST | /auth/verify2fa | 无 | challenge、code 或 recovery_code | 完成登录二步验证并返回 auth_data |
| POST | /auth/2fa/setup | setup_token | setup_token | 管理员/员工（is_admin 或 is_staff）强制二步验证初始化 |
| POST | /auth/2fa/confirm | setup_token | setup_token、code | 确认管理员/员工二步验证，返回 auth_data 及 recovery_codes |
| GET | /auth/token2Login | 无 | token 或 verify，可选 redirect | 临时 Token 登录或跳转 |
| POST | /auth/forget | 无 | email、email_code、password | 重置密码 |
| POST | /auth/getQuickLoginUrl | 用户（auth_data） | 可选 redirect | 生成临时快捷登录地址 |
| GET | /oauth/{provider}/redirect | 无 | 路径 provider（google/github/telegram） | 跳转到第三方授权页；provider 未启用/未配置返回 503，未知 provider 返回 404 |
| GET | /oauth/{provider}/state | 无 | 路径 provider（仅 telegram） | 为 Telegram 登录控件签发一次性 state；非 telegram 返回 404 |
| GET | /oauth/{provider}/callback | 无 | 路径 provider（google/github）；query code、state | 第三方回调，换取 ticket 后 302 回前端 /#/login（oauth_ticket 或 oauth_error）；telegram 返回 422 |
| POST | /oauth/complete | 无 | ticket；或 provider=telegram 时传 data、state；可选 email、email_code、recaptcha_data、invite_code | 消费 ticket 完成登录/注册；可能返回 requires_email、link_required、registration_required，或 auth_data / 二步验证 challenge |
| POST | /comm/sendEmailVerify | 无 | email；可选 isforget（0/1）、recaptcha_data | 发送邮箱验证码；按 IP 限流，isforget=0 校验邮箱未注册、isforget=1 校验已注册 |
| POST | /comm/pv | 无 | invite_code | 邀请码页面访问计数（pv+1） |

> 管理员登录入口注册在同一路由文件（PassportRoute），但位于密钥路径下，基础路径为 /api/v1/{secure_path}/passport（secure_path 取 config `v2board.secure_path`，缺省回退 `frontend_admin_path` 或 crc32b(app.key)）。这些接口无 admin 中间件，管理员身份在控制器内校验：

| 方法 | 接口路径 | 鉴权 | 请求参数 | 返回/说明 |
| --- | --- | --- | --- | --- |
| POST | /auth/login | 无（控制器校验 is_admin） | email、password | 管理员登录，非管理员返回 403 |
| POST | /auth/verify2fa | 无 | challenge、code 或 recovery_code | 管理员登录二步验证（校验 challenge 归属管理员） |
| POST | /auth/2fa/setup | setup_token | setup_token | 管理员强制二步验证初始化（校验 setup_token 归属管理员） |
| POST | /auth/2fa/confirm | setup_token | setup_token、code | 确认管理员二步验证 |

### 4.2 Guest 接口

基础路径：/api/v1/guest

| 方法 | 接口路径 | 鉴权 | 请求参数 | 返回/说明 |
| --- | --- | --- | --- | --- |
| GET | /comm/config | 无 | 无 | 公开站点配置，响应头 `Cache-Control: no-store, no-cache, must-revalidate` |
| GET | /comm/arithmetic | 无 | 无 | 算术验证开启时获取题目；关闭时返回 `{enabled:false}`；不返回答案 |
| POST | /comm/arithmetic/verify | 无 | challenge_id、answer | 返回 correct、verified；关闭时直接返回 `{correct:true,verified:true}`；不返回正确答案 |
| GET | /plan/fetch | 无 | 无 | Signature 首页套餐列表（show=1，按 sort 升序） |
| POST | /telegram/webhook | access_token | access_token（=md5(telegram_bot_token)）及 Telegram 回调参数 | Telegram Webhook；仅接受 POST；access_token 校验失败返回 401 |
| POST/GET | /payment/notify/{method}/{uuid} | 无 | 支付回调参数 | 支付平台异步通知，由驱动验签。已取消订单收到真实回调时记日志并通知管理员人工核实；驱动回传 paid_amount 时校验实付金额，欠款则拒绝开通并告警 |

### 4.3 公共配置扩展字段

`GET /api/v1/guest/comm/config` 返回的 `data` 包含以下字段，并带有 `Cache-Control: no-store, no-cache, must-revalidate`：

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| tos_url | string/null | 服务条款地址 |
| is_email_verify | integer | 是否开启邮箱验证，0/1 |
| is_invite_force | integer | 是否强制邀请码注册，0/1 |
| email_whitelist_suffix | array/integer | 开启邮箱白名单时为允许的后缀数组，未开启时为 0 |
| is_recaptcha | integer | 是否开启 reCAPTCHA，0/1 |
| recaptcha_site_key | string/null | reCAPTCHA 站点 Key |
| is_arithmetic_verification | integer | 是否开启注册算术验证，0/1 |
| oauth_register_only | integer | 是否仅允许第三方(OAuth)注册，0/1；开启时主题注册页隐藏邮箱注册表单，只保留 OAuth 区 |
| oauth.google | boolean | 是否开启 Google 第三方登录 |
| oauth.github | boolean | 是否开启 GitHub 第三方登录 |
| oauth.telegram | boolean | 是否开启 Telegram 第三方登录 |
| oauth.telegram_bot_username | string/null | Telegram 登录使用的 Bot 用户名 |
| oauth.telegram_login_domain | string/null | Telegram 登录授权域名 |
| site_status.mode | string | `normal`、`maintenance` 或 `shutdown` |
| site_status.title | string | 状态页标题，纯文本 |
| site_status.message | string | 状态页说明，纯文本 |
| site_status.recovery_at | integer/null | 预计恢复 Unix 时间戳 |
| site_status.server_time | integer | 服务端当前 Unix 时间戳，用于客户端校准倒计时 |
| site_status.support_url | string/null | 支持入口地址 |
| app_description | string/null | 站点描述 |
| app_url | string/null | 站点地址 |
| logo | string/null | 站点 Logo 地址 |
| frontend_theme_color | string | 前端主题色，取自主题配置的 theme_color，缺省 `default` |

`maintenance` 和 `shutdown` 状态由服务端 `site.status` 中间件阻断普通注册、登录、下单、支付和订阅业务；公共配置、管理员接口、节点通信、Telegram Webhook 和已创建支付回调保持可用。

### 4.4 公开套餐字段

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| id | integer | 套餐 ID |
| name | string | 套餐名称 |
| content | string/array | 套餐权益 |
| transfer_enable | integer | 总流量，单位 GB 配置值 |
| device_limit | integer | 设备限制，0 通常表示不限 |
| speed_limit | integer | 速度限制 |
| month_price | integer | 月付价格，单位为分 |
| quarter_price | integer | 季付价格，单位为分 |
| half_year_price | integer | 半年付价格，单位为分 |
| year_price | integer | 年付价格，单位为分 |
| two_year_price | integer | 两年付价格，单位为分 |
| three_year_price | integer | 三年付价格，单位为分 |
| onetime_price | integer | 一次性价格，单位为分 |
| capacity_limit | integer/null | 剩余容量 |
| show | integer | 是否展示 |
| renew | integer | 是否允许续费 |

套餐价格字段在 API 和订单中使用整数分；前端页面按 `currency_symbol` 展示为人民币元。例如页面输入 `12.50` 元，接口提交 `1250`。

## 五、用户接口

基础路径：/api/v1/user，要求用户鉴权。

### 5.1 用户资料与账户

| 方法 | 接口路径 | 请求参数 | 返回/说明 |
| --- | --- | --- | --- |
| GET | /info | 无 | 当前账户信息 |
| GET | /getSubscribe | 无 | 旧版主订阅兼容信息 |
| GET | /getStat | 无 | 用户统计（待支付订单、待处理工单、邀请人数） |
| GET | /checkLogin | 无 | 登录状态 |
| POST | /update | auto_renewal、remind_expire、remind_traffic（均 0/1） | 修改用户资料 |
| POST | /changePassword | old_password、new_password（≥8 位） | 修改密码，成功后注销全部会话 |
| POST | /resetPassword | current_password | 系统生成新密码并返回明文，成功后注销全部会话 |
| GET | /resetSecurity | 无 | 重置 UUID/Token 并返回新订阅地址 |
| GET | /unbindTelegram | 无 | 解绑 Telegram |
| POST | /newPeriod | 无 | 提前开启新的流量周期（需开启 allow_new_period） |
| GET | /getActiveSession | 无 | 获取当前会话 |
| POST | /removeActiveSession | session_id | 删除指定会话 |
| POST | /transfer | transfer_amount（整数，≥1） | 将佣金余额划转到可用余额 |
| POST | /redeemgiftcard | giftcard | 兑换礼品卡 |
| POST | /getQuickLoginUrl | 可选 redirect | 快捷登录地址 |
| POST | /oauth/link | ticket | 将第三方登录身份绑定到当前账户 |

transfer 与 redeemgiftcard 已加锁 + 幂等，涉及的余额变更统一走加锁入账并记 v2_balance_log 流水，对外参数保持不变。oauth/link 会校验第三方身份对应邮箱与当前账户一致（或通过邮箱验证码），且该第三方身份未被其他账户绑定。

### 5.2 用户二步验证

| 方法 | 接口路径 | 请求参数 | 返回/说明 |
| --- | --- | --- | --- |
| GET | /2fa/status | 无 | 二步验证状态 |
| POST | /2fa/setup | 无 | 生成密钥和二维码数据 |
| POST | /2fa/confirm | code | 启用二步验证并返回恢复码 |
| POST | /2fa/disable | current_password，及 code 或 recovery_code | 关闭二步验证 |
| POST | /2fa/recovery-codes/regenerate | current_password，及 code 或 recovery_code | 重新生成恢复码 |

关闭二步验证与重新生成恢复码都必须同时校验当前登录密码（current_password）。

### 5.3 多订阅接口

| 方法 | 接口路径 | 请求参数 | 返回/说明 |
| --- | --- | --- | --- |
| GET | /subscription/fetch | 无 | 当前账户全部订阅 |
| POST | /subscription/set-primary | subscription_id | 设置主订阅 |
| POST | /subscription/revoke | subscription_id | 撤销指定订阅 |

订阅列表字段：

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| id | integer | 订阅 ID |
| plan_id | integer | 套餐 ID |
| plan_name | string | 套餐名称 |
| status | string | active、expired、revoked |
| transfer_enable | integer | 订阅总流量，字节 |
| u | integer | 上传流量，字节 |
| d | integer | 下载流量，字节 |
| expired_at | integer/null | 到期时间戳 |
| device_limit | integer | 设备限制 |
| group_id | integer | 节点分组 |
| subscribe_url | string | 独立订阅地址 |
| is_primary | boolean | 是否主订阅 |
| auto_renewal | boolean | 是否自动续费 |

设置主订阅和撤销接口都必须由服务端校验 subscription_id 属于当前账户。主订阅不能直接撤销，需先另设主订阅；已撤销的订阅不能再设为主订阅；共享订阅成员不能撤销共享订阅。

### 5.4 套餐、订单和支付

| 方法 | 接口路径 | 请求参数 | 返回/说明 |
| --- | --- | --- | --- |
| GET | /plan/fetch | 可选 id | 可购买套餐（传 id 返回单个套餐） |
| POST | /order/save | plan_id、period；可选 subscription_id、new_subscription、coupon_code；plan_id=0（充值）时必填 deposit_amount | 创建订单 |
| POST | /order/checkout | trade_no、method；Stripe 可需要 token | 发起支付 |
| GET | /order/check | trade_no | 返回订单状态 |
| GET | /order/detail | trade_no | 返回订单详情 |
| GET | /order/fetch | 可选 status | 订单列表 |
| GET | /order/getPaymentMethod | 无 | 可用支付方式 |
| POST | /order/cancel | trade_no | 取消待支付订单（仅待支付状态可取消，幂等，自动退还余额抵扣） |
| POST | /coupon/check | code；可选 plan_id | 检查优惠券 |

订单保存示例：

    {
      "plan_id": 3,
      "period": "month_price",
      "subscription_id": 12,
      "new_subscription": true
    }

period 支持值：

| 值 | 用途 |
| --- | --- |
| month_price | 月付 |
| quarter_price | 季付 |
| half_year_price | 半年付 |
| year_price | 年付 |
| two_year_price | 两年付 |
| three_year_price | 三年付 |
| onetime_price | 一次性 |
| reset_price | 流量重置 |
| deposit | 充值 |

充值订单（`plan_id` 为 0、`period` 为 `deposit`）需额外提交 `deposit_amount`（充值金额，单位为分，必须是 1~9999998 的整数）。

### 5.5 内容、节点和工单

| 方法 | 接口路径 | 说明 |
| --- | --- | --- |
| GET | /server/fetch | 当前用户可用节点 |
| GET | /notice/fetch | 公告 |
| GET | /knowledge/fetch | 知识库文章 |
| GET | /knowledge/getCategory | 知识库分类 |
| GET | /invite/save | 生成邀请码 |
| GET | /invite/fetch | 邀请信息 |
| GET | /invite/details | 邀请明细 |
| GET | /telegram/getBotInfo | Telegram Bot 信息 |
| GET | /telegram/binding | 查询当前账户 Telegram 绑定状态 |
| POST | /telegram/binding/prepare | 生成 Telegram 绑定信息（需 subscription_id） |
| POST | /telegram/binding/revoke | 撤销 Telegram 绑定 |
| GET | /comm/config | 用户端配置 |
| POST | /comm/getStripePublicKey | Stripe 公钥 |
| GET | /stat/getTrafficLog | 流量日志 |
| POST | /ticket/save | 创建工单 |
| GET | /ticket/fetch | 工单列表（传 id 返回工单详情） |
| POST | /ticket/reply | 回复工单 |
| POST | /ticket/close | 关闭工单 |
| POST | /ticket/withdraw | 提交佣金提现申请（创建系统工单） |

## 六、管理员接口

基础路径：/api/v1/{secure_path}，要求管理员鉴权。

### 6.1 配置与套餐

| 方法 | 接口路径 | 请求参数 | 返回/说明 |
| --- | --- | --- | --- |
| GET | /config/fetch | 可选 key | 系统配置（分组返回，命中 key 时只返回该分组） |
| POST | /config/save | 配置字段 | 保存配置并可能重启 Webman |
| GET | /config/getEmailTemplate | 无 | 邮件模板 |
| GET | /config/getThemeTemplate | 无 | 主题模板 |
| POST | /config/setTelegramWebhook | telegram_bot_token | 设置 Telegram Webhook |
| POST | /config/testSendMail | 无 | 向当前管理员邮箱发送测试邮件 |
| GET | /plan/fetch | 无 | 套餐列表（含各套餐在用人数） |
| POST | /plan/save | 套餐字段 | 创建或编辑套餐（带 id 为编辑，可选 force_update 同步到用户） |
| POST | /plan/update | id、show、renew | 切换套餐上/下架与续费开关 |
| POST | /plan/drop | id | 删除套餐 |
| POST | /plan/sort | plan_ids | 调整套餐顺序 |

订阅配置字段：

| 字段 | 类型 | 默认值 | 说明 |
| --- | --- | --- | --- |
| multi_subscription_enable | 0/1 | 0 | 是否允许单账户新购多个独立订阅 |
| allow_new_period | 0/1 | 0 | 是否允许提前开启流量周期 |
| show_subscribe_method | integer | 0 | 订阅 Token 展示方式 |
| show_subscribe_expire | integer | 5 | 临时订阅有效时间，分钟 |
| reset_traffic_method | integer | 0 | 流量重置方式 |
| plan_change_enable | 0/1 | 1 | 是否允许套餐变更 |

安全、注册与 OAuth 配置字段（config/save 支持，fetch 归入 safe / deposit 分组）：

| 字段 | 类型 | 默认值 | 说明 |
| --- | --- | --- | --- |
| email_verify | 0/1 | 0 | 注册邮箱验证 |
| oauth_register_only | 0/1 | 0 | 仅允许第三方 OAuth 注册，关闭邮箱注册入口 |
| admin_2fa_force_enable | 0/1 | 0 | 强制管理员/员工绑定二步验证；开启前若仍有管理员或员工未绑定，保存返回 422 |
| subscribe_audit_retention_days | integer | 180 | 订阅审计保留天数，0=不清理，否则须在 35-3650 之间 |
| deposit_bounus | array | [] | 充值赠送阶梯，每项格式「充值金额:奖励金额」 |
| reseller_enable | 0/1 | 0 | 启用分销/倒卖商模块 |
| reseller_allowed_payment_drivers | array | [] | 倒卖商可用支付驱动白名单 |
| telegram_subscription_binding_enable | 0/1 | 0 | 启用 Telegram 订阅绑定 |
| telegram_binding_check_interval | integer | 300 | Telegram 绑定校验间隔（秒），60-3600 |
| oauth_google_enable / oauth_google_client_id / oauth_google_client_secret / oauth_google_redirect_uri | 0/1、string | — | Google OAuth 登录；fetch 仅回传 oauth_google_client_secret_configured 布尔位，save 传空串保留原密钥 |
| oauth_github_enable / oauth_github_client_id / oauth_github_client_secret / oauth_github_redirect_uri | 0/1、string | — | GitHub OAuth 登录，同上 |
| oauth_telegram_enable / oauth_telegram_login_domain / oauth_telegram_bot_username | 0/1、string | — | Telegram Widget 登录 |

### 6.2 用户管理与审计

| 方法 | 接口路径 | 请求参数 | 返回/说明 |
| --- | --- | --- | --- |
| GET | /user/fetch | current、pageSize、sort、sort_type、filter | 用户列表，含在线设备、订阅地址和风险摘要 |
| POST | /user/update | 用户编辑字段 | 修改用户（改密码会要求重置并同步主订阅） |
| GET | /user/getUserInfoById | id | 用户详情、订阅和风险 |
| POST | /user/generate | 用户生成字段 | 创建用户（单个或批量，最多 500） |
| POST | /user/dumpCSV | filter | 导出用户 |
| POST | /user/sendMail | subject、content、filter | 按筛选批量发送邮件 |
| POST | /user/ban | filter、sort | 批量封禁并清会话 |
| POST | /user/resetSecret | id | 重置主订阅 Token/UUID |
| POST | /user/resetPassword | id | 生成新随机密码并一次性返回明文，同时清会话 |
| POST | /user/delUser | id | 删除用户及其订单/邀请/工单与审计数据 |
| POST | /user/allDel | filter | 按筛选批量删除 |
| POST | /user/setInviteUser | id；可选 invite_user_id 或 invite_user_email | 设置推荐关系；两个可选参数均未传时清空推荐人。推荐人必须存在且不能是用户本人，成功返回 `data: true` |
| POST | /user/subscription/set-primary | user_id、subscription_id | 设置指定用户主订阅 |
| POST | /user/subscription/revoke | user_id、subscription_id | 撤销指定用户订阅 |
| GET | /user/subscribe-requests | user_id 等筛选参数 | 历史 UA、IP、归属地与节点连接记录 |
| GET | /user/risk | user_id、subscription_id、cycle_start | 风险周期和摘要 |
| POST | /user/subscribe-audit/clear | user_id | 清空该用户的订阅审计记录（会记录操作者） |
| GET | /user/checkLogin | 无 | 管理端会话校验（编译后台登录后调用） |
| GET | /user/info | 无 | 当前管理员本人信息（编译后台登录后调用） |

历史订阅请求筛选字段：

| 参数 | 必填 | 说明 |
| --- | --- | --- |
| user_id | 是 | 目标用户 ID |
| subscription_id | 否 | 指定订阅，必须属于该用户 |
| page | 否 | 页码，默认 1 |
| pageSize | 否 | 每页数量，默认 20，最大 100 |
| user_agent | 否 | User-Agent 模糊查询 |
| request_ip | 否 | IP 模糊查询 |
| cycle_start | 否 | 周期开始时间戳 |
| cycle_end | 否 | 周期结束时间戳 |

历史请求返回字段：

| 字段 | 说明 |
| --- | --- |
| user_agent | 原始 User-Agent |
| request_ip | 应用服务器看到的真实连接 IP |
| requested_at | 请求时间戳 |
| subscription_id | 订阅 ID |
| subscription_name | 该记录所属订阅的套餐名 |
| ip_count | 当前用户/订阅下该 IP 出现次数 |
| ip_location | MMDB 查询出的归属信息 |
| connections | 节点上报的真实连接 IP 列表（含 node_name、ip_location） |
| summary | 请求数、UA 数、订阅拉取的不同 IP 数、节点连接的不同 IP 数及 UA 汇总列表 |
| risk | 用户风险摘要 |

IP 归属字段：

| 字段 | 说明 |
| --- | --- |
| ip_version | 4 或 6 |
| country_code | 国家代码 |
| country_name | 国家名称 |
| province | 中国省份 |
| region | 国家/地区 |
| city | 城市 |
| district | 区县 |
| isp | 运营商 |
| idc_vendor | IDC 或云厂商 |
| is_idc | 是否 IDC/云地址：命中为 true，住宅为 false，未解析为 null |
| status | resolved 或 unknown |

### 6.3 节点和业务运营

| 模块 | 接口路径 |
| --- | --- |
| 节点分组 | /server/group/fetch、save、drop |
| 节点路由 | /server/route/fetch、save、drop |
| 节点管理 | /server/manage/getNodes、sort |
| 协议节点 | /server/trojan/*、vmess/*、shadowsocks/*、tuic/*、hysteria/*、vless/*、anytls/*、v2node/* |
| 订单 | /order/fetch、update、assign、paid、cancel、detail |
| 支付 | /payment/fetch、getPaymentMethods、getPaymentForm、save、drop、show、sort |
| 统计 | /stat/* |
| 公告 | /notice/fetch、save、update、drop、show |
| 工单 | /ticket/fetch、reply、close |
| 优惠券 | /coupon/fetch、generate、drop、show |
| 礼品卡 | /giftcard/fetch、generate、drop |
| 知识库 | /knowledge/fetch、getCategory、save、show、drop、sort |
| 系统 | /system/getSystemStatus、getQueueStats、getQueueWorkload、getQueueMasters、getSystemLog |
| 主题 | /theme/getThemes、saveThemeConfig、getThemeConfig |
| 风控规则 | /risk/rule/fetch、save、show、sort、drop、recompute、manual-evaluate |
| 订阅溯源 | /risk/trace/fetch、history、token/lookup、token/reveal |
| 多账号同 IP | /risk/shared-ip/fetch、detail |
| 倒卖商审批 | /reseller/summary、accounts、stores、review-logs、accounts/review、stores/review、accounts/reset-password |
| 倒卖商销售权限 | /reseller/templates、templates/save、payment-drivers、orders |

协议节点的 save、update、drop、copy 均为 POST 请求。

风控模块中：/risk/rule 的 save、show、sort、drop、recompute、manual-evaluate 为 POST；/risk/trace/fetch、/risk/trace/history 与 /risk/shared-ip/fetch、detail 为只读 GET；/risk/trace/token/lookup、/risk/trace/token/reveal 刻意使用 POST（而非 GET），以避免订阅 token 被拼进 query string 落入 nginx 访问日志、浏览器历史与 Referer。多账号同 IP 面板只读，数据来自 audit:ip-link 离线聚合出的 v2_ip_account_link 累积表。

`POST /risk/rule/manual-evaluate` 为管理员发起的全站自定义时间窗订阅风险评估。首个请求传 `restart=1` 和整数 `hours`（1-2208，即 1 小时至 92 天）；响应返回 `run_id`、进度计数和时间窗。未完成时以返回的 `run_id` 发起后续请求推进批处理；完成后返回最多 200 条可疑订阅明细。评估按启动时的规则快照执行，并将结果写入手动风险结果表，供管理端用户列表的风险列和筛选使用。

倒卖商模块另注册了 /reseller/fetch、/reseller/update，以及旧版单数别名 /reseller/template/fetch、/reseller/template/save（与 templates、templates/save 等价，为兼容旧版前端保留）。

### 6.4 管理员二步验证

| 方法 | 接口路径 | 请求参数 | 说明 |
| --- | --- | --- | --- |
| GET | /2fa/status | 无 | 当前管理员二步验证状态 |
| POST | /2fa/setup | 无 | 开始配置 |
| POST | /2fa/confirm | code | 确认配置 |
| POST | /2fa/disable | code 或 recovery_code | 关闭 |
| POST | /2fa/recovery-codes/regenerate | code 或 recovery_code | 重置恢复码 |

## 七、员工接口

基础路径：/api/v1/staff，需要员工鉴权（staff 中间件校验 auth_data/Authorization 且 is_staff=1）。

| 模块 | 接口 |
| --- | --- |
| 二步验证 | /2fa/status、setup、confirm、disable、recovery-codes/regenerate |
| 工单 | /ticket/fetch、reply、close |
| 用户 | /user/update、getUserInfoById、sendMail、ban |
| 套餐 | /plan/fetch |
| 公告 | /notice/fetch、save、update、drop |

员工权限不能直接调用管理员路径。公告模块复用管理员控制器 Admin\NoticeController，二步验证复用 User\TwoFactorController。

## 八、客户端订阅接口

基础路径：/api/v1/client，需要 client 鉴权；所有接口还经过全局 api、site.status 中间件（维护/停服模式下同样返回 503）。

| 方法 | 接口路径 | 鉴权 | 说明 |
| --- | --- | --- | --- |
| GET | /api/v1/client/subscribe | client | 默认订阅地址；仅当未配置 subscribe_path 时注册 |
| GET | 配置中的 subscribe_path | client | 自定义订阅地址；配置非空时改由 routes/web.php 注册（web、site.status、client 中间件），与默认地址二选一 |
| GET | /api/v1/client/app/getConfig | client | 客户端配置（返回 Clash YAML） |
| GET | /api/v1/client/app/getVersion | client | 客户端版本 |

订阅处理流程（步骤 1–5 由 client 中间件完成，步骤 6–7 在 subscribe 控制器内完成）：

| 步骤 | 处理 |
| --- | --- |
| 1 | 读取 token |
| 2 | 按 show_subscribe_method 处理普通(0)、一次性(1)或临时(2)Token |
| 3 | 查询独立订阅或旧版用户 Token |
| 4 | 检查 active、过期和 revoked 状态 |
| 5 | 生成订阅上下文 |
| 6 | 由订阅审计服务记录 user_id、subscription_id、User-Agent、请求 IP 和 requested_at（需存在 v2_subscribe_request_log 表；IP 经可信代理解析而非裸 REMOTE_ADDR） |
| 7 | 按 flag/User-Agent 匹配并返回对应节点协议内容 |

## 九、节点接口

### 9.1 V2 配置接口

| 方法 | 接口路径 | 参数 | 返回 |
| --- | --- | --- | --- |
| ANY（客户端应使用 GET） | /api/v2/server/config | token、node_id | 节点监听、协议、TLS、路由和轮询配置；当前路由会将所有 HTTP 方法分发至同一配置处理器 |

token 必须等于配置 server_token，node_id 定位 v2node；支持 If-None-Match，配置未变化时直接返回 304（response('',304)，不走 abort）。

### 9.2 V1 动态节点接口

| 路径 | 说明 |
| --- | --- |
| /api/v1/server/{class}/{action} | 动态调用节点控制器（any 方法，class 经 ucfirst 拼接控制器名，鉴权在控制器构造函数校验 server_token） |
| UniProxy | 通用节点接口：user、push、config；另含在线设备 alive、alivelist |
| Deepbwork | Deepbwork(V2ray) 节点接口：user、submit、config |
| TrojanTidalab | Trojan 节点接口：user、submit、config |
| ShadowsocksTidalab | Shadowsocks 节点接口：user、submit（无 config） |

节点流量上报优先按订阅级 node_user_id、subscription_id 处理，同时保留旧用户 ID 兼容。

## 十、风险判定字段

| 统计项 | 说明 |
| --- | --- |
| cycle_start | 订阅生效时间 + N × 30 天 |
| cycle_end | 周期结束时间 |
| transfer_enable | 套餐总流量 |
| used_traffic | 周期上传加下载流量 |
| used_ratio | used_traffic / transfer_enable |
| user_agent_count | 不同 UA 数量 |
| distinct_ip_count | 不同 IP 数量 |
| city_count | 不同城市数量 |
| region_count | 不同地区数量 |
| country_count | 不同国家数量 |
| status | pending、normal、suspicious |
| risk_reasons | 风险原因 JSON |

风险数据只对已完成的固定 30 天周期计算。IP 归属查询失败不会阻断订阅接口。

## 十一、运维命令

| 命令 | 用途 |
| --- | --- |
| php artisan v2board:install | 初始化安装 |
| php artisan v2board:update | 数据库升级（默认幂等 schema 迁移，见下） |
| php artisan ip:clear-location-cache | 清理 IP 归属缓存 |
| php artisan ip:backfill-subscribe-locations | 回填历史 IP 归属 |
| php artisan subscription:risk | 计算已完成风险周期（--force 重算已评估周期） |
| php artisan audit:ip-link | 手动聚合「IP + 账号 + UA」累积记录（选项 --full/--force/--prune-days/--dry-run） |
| php artisan audit:clean | 手动按保留期清理订阅审计日志（选项 --days/--dry-run） |
| php artisan token-history:reconcile | 手动补齐订阅凭证历史（选项 --dry-run） |
| php artisan telegram:verify-bindings | 手动校验 Telegram 绑定 |
| php artisan order:recover-free | 恢复卡在「待开通」的已付免费订单（可选 trade_no 参数） |
| php artisan two-factor:disable {user} | 应急关闭指定账号（ID 或邮箱）的两步验证 |
| php artisan password:require-reset | 标记用户待重置密码（选项 --all / --email=） |
| php artisan horizon:terminate | 终止 Horizon Worker |
| php artisan schedule:run | 执行一次当前到期的计划任务，由 cron 每分钟调用 |
| php artisan schedule:list | 列出全部计划任务及其执行时间 |

`php artisan v2board:update` 默认走幂等 schema 迁移（`SchemaUpgradeService::run`，执行记录落 `v2_schema_migrations`），按需建表且可反复执行。除订阅、风控、IP 归属缓存等历史表外，还会建资金流水审计表 `v2_balance_log`（每次余额变更留痕，`unique_key` 唯一键保证同一笔只入账一次）、订阅凭证历史 `v2_subscription_token_history`、IP+账号累积 `v2_ip_account_link`，以及 OAuth 身份、Telegram 绑定等表。加 `--legacy` 才回退执行 `database/update.sql`。首次升级到含资金流水的版本时，建表窗口内的余额变更会静默跳过写流水，表建好后自动开始记录。

aaPanel 升级示例：

    PHP_BIN=/www/server/php/85/bin/php \
    PHP_INI=/www/server/php/85/etc/php.ini \
    DEPLOY_BRANCH=dev bash update.sh

update.sh 会执行 Git 拉取、Composer 安装、数据库升级、缓存清理、IP 缓存清理和 Webman 重启；不会每次自动执行历史 IP 回填和风险计算。

PHP 配置分两套，不要混用：

| 变量 | 默认值 | 用途 |
| --- | --- | --- |
| PHP_INI | aaPanel 的 etc/php.ini，探测失败时回退 cli-php.ini | artisan 与 composer，需要完整扩展 |
| WEBMAN_PHP_INI | cli-php.ini | Webman/AdapterMan，必须带 disable_functions |

AdapterMan 要求 php.ini 通过 disable_functions 屏蔽 header、session 等原生函数，否则启动时报 `Functions not disabled in php.ini` 并拒绝运行。禁止把 disable_functions 加进 aaPanel 与 PHP-FPM 共用的 php.ini，那会破坏同版本 PHP 下的其它站点；Webman 使用仓库自带的 cli-php.ini 即可。deploy_check_webman_runtime 会在启动前校验该文件的 disable_functions 与 pdo_mysql、redis、pcntl 扩展。

Webman 由 supervisor 托管时，update.sh 会自动识别并改用 supervisorctl 停启，不再自行 `webman.php start -d`：

- supervisorctl 二进制按 PATH、`/www/server/panel/pyenv/bin`、`/usr/local/bin`、`/usr/bin` 顺序查找，可用 `SUPERVISORCTL` 覆盖。
- 程序名从 `/www/server/panel/plugin/supervisor/profile/*.ini`、`/etc/supervisor/conf.d/*.conf`、`/etc/supervisord.d/*.ini` 中反查（取同时提到 webman.php 与本项目目录的那个文件），可用 `SUPERVISOR_PROGRAM` 覆盖。配置了 numprocs 时进程名是 `<程序名>_00`，脚本会自动用 `<程序名>:*` 这种组形式定位。

托管情况下必须走 supervisorctl：supervisor 配置通常是 `autorestart=true`，手工 `webman.php stop` 之后 supervisord 会在几秒内把它重新拉起来占住端口，随后部署脚本自己的 start 就会撞上 `Address already in use`，并且起出一套 supervisord 不认、进程属主也不对的实例。

另需注意 supervisor 配置里的 `command=` 用的是哪个 PHP。若写成 `command=php -c cli-php.ini webman.php start`，实际生效的是 PATH 上的 php，可能与 PHP_BIN 指向的版本不同，deploy_check_webman_runtime 校验的则是 PHP_BIN。两者版本不一致时请把 command 改成绝对路径。

### 11.1 计划任务（部署必需）

计划任务没有常驻载体：`config/` 下没有 process.php，webman.php 里也没有 Timer，`app/Console/Kernel.php` 里的全部定时任务都依赖系统 cron 每分钟调用一次 `artisan schedule:run`。**缺这条 cron 时站点表面完全正常**，前台能开、能下单、能订阅，但下面那张表里的任务一个都不会跑。

init.sh 与 update.sh 会自动写入这条 cron（`deploy_install_cron`），正常情况下无需手工配置。写入失败时脚本不会静默跳过，会打印 WARNING 并给出可直接粘贴的条目，形如：

    # v2board-schedule /www/wwwroot/v2board
    * * * * * { cd '/www/wwwroot/v2board' && '/www/server/php/85/bin/php' -c '/www/server/php/85/etc/php.ini' artisan schedule:run; } >> /dev/null 2>> '/www/wwwroot/v2board/storage/logs/schedule-cron.log'

| 项目 | 说明 |
| --- | --- |
| 标记行 | `# v2board-schedule <项目目录>`，脚本靠它识别自己写过的条目 |
| 幂等 | 下列任一命中就整段跳过、一个字都不改：标记行已存在；crontab 里已有指向本目录的 schedule:run（含运维手写的）；`/etc/crontab` 或 `/etc/cron.d/*` 里已有同类条目；**`/var/spool/cron/*` 或 `/var/spool/cron/crontabs/*`（即别的用户的 crontab）里已有同类条目**；`/www/server/cron/*` 里已有提到本目录与 schedule:run 的面板计划任务脚本。追加时已有 crontab 内容逐字保留，只在末尾追加，不覆盖运维其它条目 |
| 别的用户的 crontab | 运维常把调度装在 www 名下（`crontab -u www -e`，好让 storage/logs 里新建文件的属主与 Webman 一致），而 `bash update.sh` 一般以 root 跑：只看 `crontab -l`（root 自己那份）就看不见 www 的条目。脚本因此在 root 下额外扫 `/var/spool/cron`，否则会给一个本来配好的站点再追加一条。非 root 时这些文件读不到，会自动跳过 |
| 面板计划任务 | aaPanel 把命令正文写进 `/www/server/cron/<id>`，crontab 里只留一行 `/bin/bash /www/server/cron/<id>`，光看 crontab 会误判成缺失。脚本因此额外扫这批文件 —— 否则会重复追加一条，每分钟两次 schedule:run，`v2board:statistics`、`reset:traffic`、`send:remindMail` 这些没有 withoutOverlapping 的命令会在同一分钟跑两遍 |
| 重复告警 | crontab 里已有 schedule:run、但没有一条提到本目录时，脚本照旧追加（同机多站点各需一条），同时打印 WARNING。若其中某条其实就是本站点（典型是 docroot 为软链，路径与解析后的目录不一致），请手工删掉重复的那条 |
| SKIP_CRON | `SKIP_CRON=1 bash update.sh` 让脚本完全不碰 crontab。**用别的载体跑调度时请一直带上它**：典型是 systemd timer、外部调度器、容器 sidecar —— 这些脚本认不出来（systemd 单元只会打印 WARNING 后照旧追加，因为「单元文件存在」并不等于「timer 已启用」，认成已配置反而可能让调度彻底不跑） |
| PHP 路径 | 沿用 PHP_BIN / PHP_INI 的探测结果并落成绝对路径（cron 的 PATH 很短，写 `php` 容易解析到别的版本）。用的是 artisan 那套 PHP_INI，不是带 disable_functions 的 WEBMAN_PHP_INI |
| CRON_USER | 可选，把条目写进指定用户的 crontab，例如 `CRON_USER=www bash update.sh`；默认写当前用户 |
| 日志属主 | 以 root 跑 cron 时 storage/logs 里新建的文件可能属 root，导致 Webman（www）写日志失败。aaPanel 环境建议 `CRON_USER=www`，或部署后由 update.sh 末尾的 `chown -R www .` 收尾 |

条目的输出去向是分开的，不是 `>> /dev/null 2>&1`：

| 流 | 去向 | 原因 |
| --- | --- | --- |
| stdout | `/dev/null` | 没有到期任务时 `schedule:run` 每分钟都会往 stdout 打一行 `No scheduled commands are ready to run.`，落盘就是每年几十 MB 纯噪音，也会让 cron 每分钟发一封邮件 |
| stderr | `storage/logs/schedule-cron.log` | 这条 cron 最常见的真实故障 —— PHP 绝对路径写错、php.ini 读不到、cron 用户对项目目录没权限 —— 全都发生在 Laravel 启动之前，`storage/logs/laravel.log` 里一个字都不会有。全扔 `/dev/null` 的话，条目看着装好了却永远不干活，唯一症状只剩后台「系统状态」一颗红灯，没有任何现场可查 |

要点：

* 整条命令用 `{ ...; }` 包起来再重定向，这样 `cd` 失败（目录被删、权限不足）的报错也会进日志，而不是只有 php 的报错进日志。
* 写 crontab 之前脚本会先建好 `schedule-cron.log`（指定了 `CRON_USER` 且以 root 运行时把属主交给它）。这一步是必需的：`2>> 文件` 打不开时 shell 会在执行 `schedule:run` 之前就放弃整条命令，等于调度彻底不跑。建不出来（storage/logs 不可写等）时脚本打印 WARNING 并退回历史写法 `>> /dev/null 2>&1`，宁可没有现场也不让调度停摆。
* 后来改用别的 `CRON_USER` 时，请顺手确认 `schedule-cron.log` 对新用户可写（`ls -l storage/logs/schedule-cron.log`）；属主不对会让整条 cron 静默失效。
* 这个文件不参与 Laravel 的 daily 轮转。健康站点它长期是空的；一旦有内容说明每分钟都在报错，修好后自行 `: > storage/logs/schedule-cron.log` 清空，或纳入 logrotate。
* 存量站点的条目不会被改写：幂等检查一命中脚本就整段返回，既不动 crontab 也不建日志文件，所以本次改动之前装好的 `>> /dev/null 2>&1` 条目保持原样。想给它加上日志，手工把那一行改成上面的形式即可（或删掉条目与标记行后重跑 update.sh）。

漏配 cron 的实际后果（下表即 Kernel.php 中的全部条目）：

| 计划任务 | 频率 | 漏配后果 |
| --- | --- | --- |
| traffic:update | 每分钟 | 节点上报的流量不入账，用户用量与统计长期为 0 |
| v2board:statistics | 0:10 | 每日统计（收入、流量排行、节点统计）停更 |
| subscription:risk | 0:20 | 已完成的固定 30 天风险周期永不评估，风险状态卡在 pending |
| check:order | 每分钟 | 订单不结算，用户付款后套餐不开通 |
| check:commission | 每 15 分钟 | 佣金不确认，推广结算停摆 |
| check:ticket | 每分钟 | 工单提醒不发送 |
| check:renewal | 22:30 | 续费提醒不发送 |
| reset:traffic | 每日 | **流量不重置**，用户跨周期后流量不恢复 |
| reset:log | 每日 | 日志表无限增长 |
| audit:ip-link | 每小时 | 「IP + 账号 + UA」累积记录停更，v2_ip_account_link 不再累加命中与时间区间 |
| audit:clean | 0:40 | 订阅审计日志保留期不生效，原始日志表无限增长 |
| token-history:reconcile | 0:50 | 订阅凭证历史不补齐，缺失记录不会被发现 |
| send:remindMail | 11:30 | 到期与流量提醒邮件不发送 |
| horizon:snapshot | 每 5 分钟 | 队列指标图表空白 |
| telegram:verify-bindings | 按 telegram_binding_check_interval（默认 300 秒） | Telegram 绑定校验停摆 |

其中 audit:ip-link 与 audit:clean 是一对：聚合每小时整点跑，清理在 0:40，正常顺序下当天要被保留期删掉的原始行一定已经进过累积表。若只补了清理却没有聚合（例如手工只配了 audit:clean），被删掉的那段历史无法再恢复。

给长期漏配的站点补上 cron 后，第一次 schedule:run 会把当前时刻到期的任务照常跑一遍（例如当天的提醒邮件、到期的流量重置），这是预期行为，不是重复执行；补配后的第一天建议留意邮件与队列量。

验证计划任务真的在跑：

    # 1. 条目是否写进去了（以 root 跑时别忘了看 www 那份，脚本可能写在别的用户名下）
    crontab -l | grep -A 1 'v2board-schedule'
    crontab -u www -l | grep -A 1 'v2board-schedule'

    # 1b. 全机器一共有几条指向本目录的 schedule:run —— 应当只有 1 条。多于 1 条就是重复调度，
    #     send:remindMail / reset:traffic / v2board:statistics 会在同一分钟跑两遍
    grep -rl 'schedule:run' /var/spool/cron /etc/crontab /etc/cron.d /www/server/cron 2>/dev/null \
        | xargs -r grep -H 'schedule:run' | grep -v '^[^:]*: *#' | grep '/www/wwwroot/v2board'

    # 2. cron 守护进程是否在跑（Debian/Ubuntu 是 cron）
    systemctl status crond || pgrep -x crond || pgrep -x cron

    # 3. 手动跑一次，看到期任务是否逐条执行
    /www/server/php/85/bin/php -c /www/server/php/85/etc/php.ini artisan schedule:run -v

    # 4. 列出全部条目与执行时间
    php artisan schedule:list

    # 5. cron 是否真的触发过（CentOS 用 /var/log/cron，Debian 用 journalctl）
    grep 'schedule:run' /var/log/cron | tail -n 5
    journalctl -u cron -S -10min | tail -n 20

    # 6. 条目在 Laravel 启动之前就失败了？（PHP 路径、php.ini、目录权限）
    #    健康站点这个文件长期是空的；有内容就是每分钟都在报错
    tail -n 20 /www/wwwroot/v2board/storage/logs/schedule-cron.log
    ls -l /www/wwwroot/v2board/storage/logs/schedule-cron.log

最直接的信号是管理员接口 `GET /api/v1/{secure_path}/system/getSystemStatus`（`secure_path` 读取 `config('v2board.secure_path')`，全部管理员路由都挂在这个前缀下，写成 `/api/v1/admin/...` 会 404）：`Kernel::schedule()` 每次被调用都会写入缓存里的「计划任务最后检查时间」，所以返回里 `schedule` 为 true、`schedule_last_runtime` 是 120 秒内的时间戳，就说明 cron 确实每分钟在调用 schedule:run；后台「系统状态」面板读的就是这个接口。刚配好 cron 后请等满一分钟再看。

### 11.2 全新安装后的必做手工步骤

init.sh 负责的是「代码依赖 + 数据库 + 管理员 + 计划任务」，它刻意不启动任何常驻进程：全新安装时进程托管方式还没定，若脚本先 `webman.php start -d` 起一个裸守护进程，运维随后配好 supervisor 再 start 就会撞上 `Address already in use`，并留下一套 supervisord 不认、属主也不对的实例。脚本结束时会把下面这几步原样打印出来。

| 步骤 | 说明 |
| --- | --- |
| Web 服务器 | 站点根目录指向 `public/`，并把动态请求反代到 `http://127.0.0.1:6600`（端口取自 webman.php） |
| 启动 Webman | supervisor 托管（推荐）或 `PHP_BIN -c cli-php.ini webman.php start -d`；supervisor 的 `command=` 必须用绝对路径的同一个 PHP 与 cli-php.ini |
| 启动队列 | `PHP_BIN -c PHP_INI artisan horizon`，同样建议交给 supervisor。缺它则邮件、支付回调等队列任务堆积不消费 |
| 计划任务 | init.sh 已写入，按 11.1 验证一遍 |
| Redis | `.env.example` 里 CACHE_DRIVER、QUEUE_CONNECTION、SESSION_DRIVER 全是 redis，且安装器不会询问 Redis 参数。Redis 未装或不在 127.0.0.1:6379 时，装完就是 500 —— 需手工改 .env 的 REDIS_* 后 `artisan config:clear` |

init.sh 生成的 `.env` 来自 `.env.example`，只会写入 APP_KEY 与 DB_HOST/DB_DATABASE/DB_USERNAME/DB_PASSWORD 四项；DB_PORT、Redis、邮件、APP_URL 都保持模板默认值，需要按站点情况自行修改。`public/theme/` 下 default、ez、signature 三套主题产物随仓库发布，无需构建；`config/theme/*.php` 由前台或后台首次访问主题时自动生成（仓库里只有一个 .gitignore），因此该目录必须对运行用户可写。

## 十二、安全要求

| 项目 | 要求 |
| --- | --- |
| 认证数据 | 不写入日志、URL 或普通数据库字段 |
| 订阅地址 | 按敏感凭证保护 |
| subscription_id | 服务端必须校验归属关系 |
| server_token | 仅供节点使用，不暴露给用户 |
| 管理员路径 | 使用不可预测的 secure_path |
| IP 数据库 | 放在 resources/ipdb，不放入 public |
| 配置变更 | 修改多订阅开关后清理缓存并重启 Webman |
| 资金变更 | 余额只经 UserService::addBalance 原语：事务内加锁读用户行、拒绝透支、写 v2_balance_log 流水审计；传 unique_key 时同键幂等，保证并发/重试下同一笔只入账一次 |
| 支付回调 | 校验支付驱动一致性与实付金额：驱动回传 paid_amount 时欠款（超四舍五入误差）拒绝开通并告警；已取消订单再收到真实回调记日志并通知管理员，不再静默吞掉 |

### 12.1 Cloudflare 免费版防护 CC 与刷注册

Cloudflare 免费版能够承担基础 DDoS 缓解、缓存和浏览器挑战，但它不是业务限流的替代品。建议按“源站不暴露、边缘先过滤、应用再校验”的顺序部署；先在低峰期用自己的浏览器、客户端、支付驱动和 Telegram Bot 完整验收，再提高挑战或限速强度。

#### 前置条件：禁止绕过 Cloudflare

1. 将主站域名的 A/AAAA/CNAME 记录设为橙色云朵（`Proxied`），只让 Cloudflare 回源 HTTP(S)；API 与前台同域时一并受保护。
2. 源站防火墙仅放行 Cloudflare 的 [官方回源 IP 段](https://www.cloudflare.com/ips/) 到 80/443；Webman 的 6600 端口只监听本地 nginx，不开放公网。SSH、数据库、Redis 和面板端口不得经公网暴露。
3. Cloudflare SSL/TLS 使用 `Full (strict)`，源站安装有效证书或 Cloudflare Origin Certificate；不要使用 `Flexible`，否则 Cloudflare 到源站仍是明文。
4. 将 Cloudflare 的 IPv4/IPv6 回源 CIDR 加入 `.env` 的 `TRUSTED_PROXIES`，并保留 `127.0.0.1,::1`。例如：`TRUSTED_PROXIES=127.0.0.1,::1,<Cloudflare CIDR 列表>`。变更后执行 `php artisan config:clear` 并重启 Webman。不要设为 `*`，否则直连源站的请求可以伪造 `X-Forwarded-For`。

第 4 步不能省略：当前注册次数、邮件验证码限流、算术题绑定和订阅审计均依赖 `$request->ip()`。未信任 Cloudflare 回源段时，应用看到的是 Cloudflare 边缘 IP，所有访客可能共用同一个限流桶，审计 IP 也会失真。

#### 免费版控制项

在 Cloudflare 控制台启用以下能力；免费套餐的规则数量和 Rate Limiting 可用配额可能调整，以控制台当前显示为准。

| 位置 | 建议 | 目的与注意事项 |
| --- | --- | --- |
| Security / Bots | 开启 Bot Fight Mode，并观察 Security Events | 降低通用爬虫和简单 CC。它可能影响非浏览器调用，先验证支付、Telegram、订阅和节点通信；出现误拦时优先收窄自定义规则或关闭此开关，不要让关键回调依赖浏览器挑战。 |
| Security / WAF / Custom rules | 为注册和发信接口创建 `Managed Challenge` 规则 | 对访问注册页的正常浏览器透明度较高，同时提高脚本刷接口成本。先用 Challenge 观察事件，确认无误后才对明显恶意特征使用 Block。 |
| Security / Settings | 正常时期保持默认或 Medium 安全级别；攻击期间临时启用 Under Attack Mode | Under Attack Mode 会挑战几乎所有访问者，可能影响客户端和第三方回调，只作为短时间止血措施。恢复后关闭并保留精确的接口规则。 |
| Caching | 只缓存静态资源和主题产物 | 不缓存 `/api/*`、订阅地址、支付回调或带鉴权的响应；缓存动态 API 会导致登录、订单或订阅数据串扰。 |

#### 注册接口挑战规则

在 **Security > WAF > Custom rules** 创建一条规则，动作为 **Managed Challenge**。以下表达式覆盖主站和倒卖商店铺的注册、邮箱验证码与找回密码入口：

```
http.request.method eq "POST" and (
  http.request.uri.path eq "/api/v1/passport/auth/register" or
  http.request.uri.path eq "/api/v1/passport/comm/sendEmailVerify" or
  http.request.uri.path eq "/api/v1/passport/auth/forget" or
  (
    starts_with(http.request.uri.path, "/api/v1/store/") and
    ends_with(http.request.uri.path, "/passport/register")
  )
)
```

不要把整段 `/api/v1/*` 统一设为 Challenge，也不要把下列机器接口纳入规则：

| 必须豁免的路径 | 原因 |
| --- | --- |
| `/api/v1/guest/payment/notify/*`、`/api/v1/store/*/payment/notify/*` | 支付平台无法完成浏览器 JavaScript 挑战；业务层已做驱动验签和金额校验。 |
| `/api/v1/guest/telegram/webhook` | Telegram 回调不是浏览器请求。 |
| `/api/v1/client/subscribe`、配置的自定义订阅路径 | 订阅客户端无法完成挑战。 |
| `/api/v1/server/*`、`/api/v2/server/*` | 节点程序通过 `server_token` 调用，不能要求浏览器挑战。 |

若控制台提供 **Rate Limiting rules**，在上述 Challenge 之外按 IP 增加限速。可从以下保守阈值开始，并根据 Security Events、真实注册转化率和邮件队列负载调整：

| 目标 | 起始阈值 | 超限动作 |
| --- | --- | --- |
| `/api/v1/passport/auth/register` 与店铺 `/passport/register` | 每 IP 10 分钟 5 次 | Managed Challenge；持续命中再短时 Block |
| `/api/v1/passport/comm/sendEmailVerify` | 每 IP 1 分钟 3 次、每小时 10 次 | Block 或 Managed Challenge |
| `/api/v1/passport/auth/login` | 每 IP 1 分钟 20 次 | Managed Challenge；不要按邮箱直接在边缘封禁，以免被恶意者利用来锁定他人 |

边缘 IP 限速挡不住分布式代理池，因此必须同时打开现有服务端能力：管理端配置中启用 `register_limit_by_ip_enable`，设置合理的 `register_limit_count` 与 `register_limit_expire`；启用 `email_verify`、`recaptcha_enable` 和 `arithmetic_verification_enable`；必要时启用 `invite_force` 或邮箱后缀白名单。当前后端对 `/passport/comm/sendEmailVerify` 已限制为每 IP 每分钟 3 次，算术题按 IP 绑定、5 分钟过期且最多 5 次作答；Cloudflare 规则应作为其前置防线。

Cloudflare Turnstile 本身可免费使用，但**不能**直接填入本项目的 `recaptcha_data`：当前服务端使用 Google reCAPTCHA SDK 校验该字段。若要改用 Turnstile，必须同时修改前端提交逻辑与后端 Siteverify 校验；在未完成该改造前，继续使用已配置的 reCAPTCHA 或内置算术验证。

#### 监控与应急

1. 每次调整后查看 Cloudflare Security Events，确认挑战命中的是注册攻击而非支付、节点、订阅或 Telegram 回调。
2. CC 发生时先临时启用 Under Attack Mode，并将注册/发信规则从 Challenge 提升为短时 Block；同时检查源站 CPU、带宽、nginx 日志、Redis 与 Horizon 队列。
3. 攻击结束后关闭 Under Attack Mode，保留精确接口规则；不要长期封禁 Cloudflare IP，也不要根据单一 `User-Agent` 作为唯一拦截条件。
4. 若源站 IP 已泄露，先在防火墙收紧为 Cloudflare IP 段，再更换源站 IP 或通过新的受控入口回源；仅修改 DNS 不能阻止攻击者直连旧 IP。

## 十三、代码来源

| 类型 | 文件 |
| --- | --- |
| V1 路由 | app/Http/Routes/V1/*.php |
| V2 路由 | app/Http/Routes/V2/ServerRoute.php |
| 用户控制器 | app/Http/Controllers/V1/User |
| 管理员控制器 | app/Http/Controllers/V1/Admin |
| 鉴权中间件 | app/Http/Middleware/User.php、Admin.php、Client.php |
| 倒卖商与店铺中间件 | app/Http/Middleware/Reseller.php、Storefront.php |
| 多订阅服务 | app/Services/SubscriptionService.php |
| IP 归属服务 | app/Services/IpLocationService.php |
| 风险服务 | app/Services/SubscriptionRiskService.php |
| 订单与退款服务 | app/Services/OrderService.php |
| 支付驱动与回调 | app/Services/PaymentService.php |
| 余额原语与资金流水 | app/Services/UserService.php、app/Models/BalanceLog.php |
| 风控规则与共享 IP | app/Services/RiskRuleService.php、app/Http/Controllers/V1/Admin/RiskSharedIpController.php |

## 十四、倒卖商与店铺 API

本节对应 `app/Http/Routes/V1/ResellerRoute.php`、`StoreRoute.php` 和管理员路由中的倒卖商模块。

### 14.1 倒卖商注册与登录

基础路径：`/api/v1/reseller`。

| 方法 | 接口路径 | 鉴权 | 请求参数 | 返回/说明 |
| --- | --- | --- | --- | --- |
| POST | /auth/register | 无 | email、store_slug、store_name | 提交账号和店铺申请；账号、店铺均为 pending |
| POST | /auth/login | 无 | email、password | 账号审核通过后返回独立 reseller `auth_data` |
| POST | /auth/logout | 无 | auth_data 或 Authorization | 注销当前倒卖商会话 |

注册规则：

- `reseller_enable=0` 时注册和登录返回 503；开启后才允许提交申请。
- `store_slug` 必须匹配 `^[a-z0-9][a-z0-9-]{2,31}$`，邮箱和 Slug 均不可重复。
- 注册请求不接收自定义密码，成功响应一次性返回 64 位随机密码和 `password_length`，接口不返回登录 Token。
- 倒卖商账号和店铺分别审批；账号与店铺均为 `active` 后才能销售。
- 倒卖商 Token 使用独立会话命名空间，不能访问管理员接口；账号停用、拒绝或管理员重置密码后旧 Token 失效。

### 14.2 倒卖商工作区

以下接口要求倒卖商鉴权：`Authorization: {reseller_auth_data}` 或 `auth_data={reseller_auth_data}`。

| 方法 | 接口路径 | 请求参数 | 返回/说明 |
| --- | --- | --- | --- |
| GET | /me | 无 | 当前倒卖商、店铺状态和可用支付驱动 |
| GET | /plan-template | 无 | 管理员已发布的基础套餐模板 |
| GET | /plans | 无 | 当前倒卖商套餐 |
| POST | /plans | id、base_plan_id、name、content、周期价格（整数分）、shared_member_limit、enabled、sort | 创建或修改自定义销售套餐 |
| GET | /payments | 无 | 当前倒卖商支付配置的脱敏列表 |
| POST | /payments/form | driver | 获取管理员白名单驱动的配置字段 |
| GET | /payments/{id}/edit | id | 获取编辑字段；敏感字段只返回脱敏占位符 |
| POST | /payments | id、driver、name、config、enabled、sort | 保存支付配置 |
| DELETE | /payments/{id} | id | 删除未被订单使用的支付配置 |
| POST | /store | store_slug、store_name、store_description | 修改店铺资料 |
| GET | /customers | page | 当前店铺客户列表，仅返回必要客户字段；每页固定 50 条 |
| GET | /orders | page | 当前店铺订单列表和金额快照；每页固定 50 条 |

套餐和支付规则：

- `base_plan_id` 只能选择管理员发布且启用的基础套餐，倒卖商不能修改节点、流量、速度和设备限制。
- 周期价格在 API 中必须为大于 0 的整数分；倒卖工作区输入框使用人民币元并在提交时转换为分。周期字段为 `month_price`、`quarter_price`、`half_year_price`、`year_price`、`two_year_price`、`three_year_price`、`onetime_price`。
- `shared_member_limit` 默认为 1，最大 100；大于 1 时为共享套餐，人数包含购买者。
- 支付驱动必须在管理员 `reseller_allowed_payment_drivers` 白名单内，禁止提交任意 PHP 类或代码。
- 支付密钥使用服务端加密保存，不在列表或日志返回。已经被订单使用的支付配置不能修改密钥或删除，可停用以阻止新订单。

共享群组审计接口：

| 方法 | 接口路径 | 请求参数 | 说明 |
| --- | --- | --- | --- |
| GET | /shared-subscriptions | page | 当前倒卖商共享群组汇总；每页固定 50 条 |
| GET | /shared-subscriptions/{id}/members | id | 查看群组成员状态，不返回订阅凭据 |
| POST | /shared-subscriptions/{id}/suspend | id、reason | 停用共享群组，必须填写原因 |
| POST | /shared-subscriptions/{groupId}/members/{memberId}/remove | groupId、memberId、reason | 强制移除成员，必须填写原因 |

### 14.3 管理员倒卖商接口

基础路径：`/api/v1/{secure_path}/reseller`，要求管理员鉴权。`secure_path` 读取 `config('v2board.secure_path')`。

| 方法 | 接口路径 | 请求参数 | 说明 |
| --- | --- | --- | --- |
| GET | /summary | 无 | 待审核倒卖商、待审核店铺、启用店铺、停用账号统计 |
| GET | /accounts | page、pageSize、status、keyword | 倒卖商账号分页和筛选 |
| POST | /accounts/review | id、target、status、reason | 审批账号；target 为 account，拒绝或停用必须填写 reason |
| POST | /accounts/reset-password | id | 生成新的 64 位随机密码并撤销旧会话，明文仅返回一次 |
| GET | /stores | page、pageSize、status、keyword | 店铺分页和筛选 |
| POST | /stores/review | id、target、status、reason | 审批店铺；target 为 store，拒绝或停用必须填写 reason |
| GET | /review-logs | page、pageSize、reseller_id、target | 审批操作记录 |
| GET | /templates | 无 | 基础套餐销售模板 |
| POST | /templates/save | id、base_plan_id、enabled、sort | 发布或撤下基础套餐模板 |
| GET | /payment-drivers | 无 | 已安装与已允许支付驱动 |
| POST | /payment-drivers | allowed[] | 保存支付驱动白名单 |
| GET | /orders | page、pageSize、status、keyword | 倒卖商订单审计，不返回支付密钥 |
| GET | /fetch | 无 | 旧版管理员前端兼容的倒卖商账号列表（固定每页 50 条） |
| POST | /update | id、status；可选 store_slug、store_name、store_description | 旧版兼容的账号与店铺统一更新；会撤销该倒卖商全部会话，存在待回调订单时不可修改 store_slug |
| GET | /template/fetch | 无 | `/templates` 的旧版单数别名 |
| POST | /template/save | id、base_plan_id、enabled、sort | `/templates/save` 的旧版单数别名 |

以上四个兼容接口仅为支持旧版管理员前端保留；新接入应使用 `/accounts`、`/stores`、`/templates` 等现行接口。

### 14.4 店铺前台接口

店铺地址为 `/store/{slug}`，API 基础路径为 `/api/v1/store/{slug}`。店铺必须存在且账号、店铺均为 `active`；否则公开接口返回 404。`reseller_enable=0` 时，除支付回调（`payment/notify/{payment_uuid}`）外的所有店铺接口（含登录后接口）返回 503。

公开接口：

| 方法 | 接口路径 | 请求参数 | 说明 |
| --- | --- | --- | --- |
| GET | /config | 无 | 店铺名称、描述、Logo 等公开信息 |
| GET | /plans | 无 | 已启用且基础模板仍在售的套餐；价格字段为整数分 |
| GET | /payments | 无 | 已启用且被管理员允许的支付方式，仅返回 id、name、driver |
| POST | /passport/register | email、password；以及主站注册所需字段 | 使用现有 `v2_user` 注册，并建立当前店铺客户关联 |
| POST | /passport/login | email、password | 登录现有 `v2_user` 并建立当前店铺客户关联 |
| POST | /passport/verify2fa | challenge、code 或 recovery_code | 完成店铺用户二步验证 |
| GET/POST | /payment/notify/{payment_uuid} | 支付平台回调参数 | 校验支付配置、店铺、订单和金额后开通订阅 |

店铺注册复用主站 `passport/register` 逻辑：跳过平台级邮箱验证开关（`email_verify`），但 `oauth_register_only=1` 时店铺邮箱注册同样被 403 拦截（店铺页面不含第三方登录入口，等于关闭全部店铺新客注册），`arithmetic_verification_enable=1` 时仍需提交 `arithmetic_challenge_id`、`arithmetic_answer` 算术验证。

登录后接口使用现有用户 `auth_data`：

| 方法 | 接口路径 | 请求参数 | 说明 |
| --- | --- | --- | --- |
| POST | /order/save | plan_id、period；可选 subscription_id | 创建新购或同店同基础套餐续费订单，返回 trade_no |
| POST | /order/checkout | trade_no、method；Stripe 可传 token | 使用店铺支付配置发起支付 |
| GET | /order/check | trade_no | 查询订单状态 |
| GET | /order/detail | trade_no | 查询当前店铺订单详情 |
| GET | /order/fetch | page（每页固定 50 条） | 当前客户在当前店铺的订单 |
| POST | /order/cancel | trade_no | 取消待支付订单 |
| GET | /subscription | 无 | 获取当前店铺订阅及流量汇总 |

支付回调是已创建订单的异步入口：店铺停用或倒卖商总开关关闭后，已绑定支付配置的回调仍允许完成验签；普通公开、注册、下单和支付发起接口不会绕过店铺状态。回调除校验平台订单 `total_amount` 与订单快照 `amount_snapshot` 一致外，若支付驱动回传实付金额（`payment_amount_cents`）还会与快照比对，金额不符即拒绝开通。

### 14.5 共享套餐接口

共享套餐购买后以群主的一条真实订阅承载全组流量和设备限制。成员不会创建额外订单、订阅或节点身份。

| 方法 | 接口路径 | 请求参数 | 权限/说明 |
| --- | --- | --- | --- |
| GET | /shared/subscription | 无 | 群主或成员获取共享套餐、人数和流量汇总 |
| GET | /shared/members | 无 | 群主查看成员状态，不返回成员流量记录 |
| POST | /shared/invitations | email | 群主创建单次邀请 |
| GET | /shared/invitations | 无 | 群主查看邀请状态 |
| POST | /shared/invitations/{id}/revoke | id | 群主撤销未接受邀请 |
| POST | /shared/invitations/accept | token | 受邀用户接受与邮箱绑定的邀请 |
| POST | /shared/members/{id}/remove | id、reason | 群主移除成员；reason 可选 |
| POST | /shared/credential/rotate | 无 | 群主轮换共享订阅凭据 |

共享返回字段包括 `total`、`used`、`remaining`、`usage_percent`、`member_limit`、`member_count`、`expired_at` 和 `subscribe_url`，并返回 `shared_subscription=true`、`traffic_log_available=false`。共享成员可以查看总量进度，但 `/api/v1/user/stat/getTrafficLog` 不返回共享订阅的流量明细。移除成员、倒卖商强制移除或停用群组后会轮换订阅凭据，旧地址失效。

### 14.6 倒卖商功能开关和状态

| 配置 | 默认值 | 影响 |
| --- | --- | --- |
| reseller_enable | 0 | 倒卖商注册、登录、管理和店铺公开访问总开关 |
| arithmetic_verification_enable | 0 | 主站注册算术验证开关，不影响倒卖商账号注册（但店铺顾客注册复用主站 passport/register，开关开启时同样要求算术验证） |
| site_status | normal | `maintenance` 或 `shutdown` 时阻断普通公共业务（含店铺接口，支付回调除外） |
| reseller_allowed_payment_drivers | [] | 管理员允许倒卖商使用的支付驱动白名单 |

倒卖商功能关闭不会删除账号、客户、订单、共享群组或已有订阅。站点维护和停运期间，公共配置接口仍用于展示状态页，管理员接口、节点通信和已有支付回调按当前中间件规则处理。

接口变更时应同步更新本文档，并以当前控制器和 FormRequest 校验规则为最终依据。
