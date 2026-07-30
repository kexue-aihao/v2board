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
| POST | /auth/register | 无 | email、password；可选 invite_code、email_code、recaptcha_data、arithmetic_challenge_id、arithmetic_answer | 注册成功返回 auth_data；算术验证开启时服务端强制校验 |
| POST | /auth/login | 无 | email、password | 密码正确时返回 auth_data 或二步验证 challenge |
| POST | /auth/verify2fa | 无 | challenge、code 或 recovery_code | 完成登录二步验证并返回 auth_data |
| POST | /auth/2fa/setup | setup_token | setup_token | 管理员强制二步验证初始化 |
| POST | /auth/2fa/confirm | setup_token | setup_token、code | 确认管理员二步验证 |
| GET | /auth/token2Login | 无 | token 或 verify，可选 redirect | 临时 Token 登录或跳转 |
| POST | /auth/forget | 无 | email、email_code、password | 重置密码 |
| POST | /auth/getQuickLoginUrl | 用户 | 可选 redirect | 生成临时快捷登录地址 |
| POST | /comm/sendEmailVerify | 无 | 以控制器校验为准 | 发送邮箱验证码 |
| POST | /comm/pv | 无 | 页面访问参数 | 记录页面访问 |

### 4.2 Guest 接口

基础路径：/api/v1/guest

| 方法 | 接口路径 | 鉴权 | 请求参数 | 返回/说明 |
| --- | --- | --- | --- | --- |
| GET | /comm/config | 无 | 无 | 公开站点配置 |
| GET | /comm/arithmetic | 无 | 无 | 算术验证开启时获取题目；不返回答案 |
| POST | /comm/arithmetic/verify | 无 | challenge_id、answer | 返回 correct、verified；不返回正确答案 |
| GET | /plan/fetch | 无 | 无 | Signature 首页套餐列表 |
| POST/GET | /telegram/webhook | 无 | Telegram 回调参数 | Telegram Webhook |
| POST/GET | /payment/notify/{method}/{uuid} | 无 | 支付回调参数 | 支付平台异步通知 |

### 4.3 公共配置扩展字段

`GET /api/v1/guest/comm/config` 返回的 `data` 包含以下功能字段，并带有 `Cache-Control: no-store`：

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| is_arithmetic_verification | integer | 是否开启注册算术验证，0/1 |
| site_status.mode | string | `normal`、`maintenance` 或 `shutdown` |
| site_status.title | string | 状态页标题，纯文本 |
| site_status.message | string | 状态页说明，纯文本 |
| site_status.recovery_at | integer/null | 预计恢复 Unix 时间戳 |
| site_status.server_time | integer | 服务端当前 Unix 时间戳，用于客户端校准倒计时 |
| site_status.support_url | string/null | 支持入口地址 |

`maintenance` 和 `shutdown` 状态由服务端中间件阻断普通注册、登录、下单、支付和订阅业务；公共配置、管理员接口、节点通信和已创建支付回调保持可用。

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
| GET | /getStat | 无 | 用户流量统计 |
| GET | /checkLogin | 无 | 登录状态 |
| POST | /update | 以控制器校验为准 | 修改用户资料 |
| POST | /changePassword | 旧密码、新密码等 | 修改密码 |
| GET | /resetSecurity | 无 | 重置安全信息 |
| GET | /unbindTelegram | 无 | 解绑 Telegram |
| GET | /getActiveSession | 无 | 获取当前会话 |
| POST | /removeActiveSession | 会话参数 | 删除会话 |
| POST | /transfer | 账户转移参数 | 转移账户流量 |
| POST | /redeemgiftcard | 礼品卡参数 | 兑换礼品卡 |
| POST | /getQuickLoginUrl | 可选 redirect | 快捷登录地址 |

### 5.2 用户二步验证

| 方法 | 接口路径 | 请求参数 | 返回/说明 |
| --- | --- | --- | --- |
| GET | /2fa/status | 无 | 二步验证状态 |
| POST | /2fa/setup | 无 | 生成密钥和二维码数据 |
| POST | /2fa/confirm | code | 启用二步验证并返回恢复码 |
| POST | /2fa/disable | code 或 recovery_code | 关闭二步验证 |
| POST | /2fa/recovery-codes/regenerate | code 或 recovery_code | 重新生成恢复码 |

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

设置主订阅和撤销接口都必须由服务端校验 subscription_id 属于当前账户。主订阅不能直接撤销。

### 5.4 套餐、订单和支付

| 方法 | 接口路径 | 请求参数 | 返回/说明 |
| --- | --- | --- | --- |
| GET | /plan/fetch | 无 | 可购买套餐 |
| POST | /order/save | plan_id、period；可选 subscription_id、new_subscription | 创建订单 |
| POST | /order/checkout | trade_no、method；Stripe 可需要 token | 发起支付 |
| GET | /order/check | trade_no | 返回订单状态 |
| GET | /order/detail | trade_no | 返回订单详情 |
| GET | /order/fetch | 可选 status | 订单列表 |
| GET | /order/getPaymentMethod | 无 | 可用支付方式 |
| POST | /order/cancel | trade_no | 取消待支付订单 |
| POST | /coupon/check | 优惠券参数 | 检查优惠券 |

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
| GET | /comm/config | 用户端配置 |
| POST | /comm/getStripePublicKey | Stripe 公钥 |
| GET | /stat/getTrafficLog | 流量日志 |
| POST | /ticket/save | 创建工单 |
| GET | /ticket/fetch | 工单列表 |
| POST | /ticket/reply | 回复工单 |
| POST | /ticket/close | 关闭工单 |
| POST | /ticket/withdraw | 撤回工单 |

## 六、管理员接口

基础路径：/api/v1/{secure_path}，要求管理员鉴权。

### 6.1 配置与套餐

| 方法 | 接口路径 | 请求参数 | 返回/说明 |
| --- | --- | --- | --- |
| GET | /config/fetch | 可选 key | 系统配置 |
| POST | /config/save | 配置字段 | 保存配置并可能重启 Webman |
| GET | /config/getEmailTemplate | 无 | 邮件模板 |
| GET | /config/getThemeTemplate | 无 | 主题模板 |
| POST | /config/setTelegramWebhook | Webhook 参数 | 设置 Telegram Webhook |
| POST | /config/testSendMail | 邮件参数 | 测试发信 |
| GET | /plan/fetch | 无 | 套餐列表 |
| POST | /plan/save | 套餐字段 | 创建套餐 |
| POST | /plan/update | 套餐字段 | 修改套餐 |
| POST | /plan/drop | id | 删除套餐 |
| POST | /plan/sort | 排序参数 | 调整套餐顺序 |

订阅配置字段：

| 字段 | 类型 | 默认值 | 说明 |
| --- | --- | --- | --- |
| multi_subscription_enable | 0/1 | 0 | 是否允许单账户新购多个独立订阅 |
| allow_new_period | 0/1 | 0 | 是否允许提前开启流量周期 |
| show_subscribe_method | integer | 0 | 订阅 Token 展示方式 |
| show_subscribe_expire | integer | 5 | 临时订阅有效时间，分钟 |
| reset_traffic_method | integer | 0 | 流量重置方式 |
| plan_change_enable | 0/1 | 1 | 是否允许套餐变更 |

### 6.2 用户管理与审计

| 方法 | 接口路径 | 请求参数 | 返回/说明 |
| --- | --- | --- | --- |
| GET | /user/fetch | current、pageSize、sort、filter | 用户列表和风险摘要 |
| POST | /user/update | 用户编辑字段 | 修改用户 |
| GET | /user/getUserInfoById | id | 用户详情、订阅和风险 |
| POST | /user/generate | 用户生成字段 | 创建用户 |
| POST | /user/dumpCSV | filter | 导出用户 |
| POST | /user/sendMail | 用户和邮件参数 | 发送邮件 |
| POST | /user/ban | id、状态参数 | 封禁/解封 |
| POST | /user/resetSecret | id | 重置主订阅 Token/UUID |
| POST | /user/delUser | id | 删除用户 |
| POST | /user/allDel | 用户 ID 列表 | 批量删除 |
| POST | /user/setInviteUser | 用户关系参数 | 设置邀请关系 |
| POST | /user/subscription/set-primary | user_id、subscription_id | 设置指定用户主订阅 |
| POST | /user/subscription/revoke | user_id、subscription_id | 撤销指定用户订阅 |
| GET | /user/subscribe-requests | user_id 等筛选参数 | 历史 UA、IP 和归属地 |
| GET | /user/risk | user_id、subscription_id、cycle_start | 风险周期和摘要 |

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
| ip_count | 当前用户/订阅下该 IP 出现次数 |
| ip_location | MMDB 查询出的归属信息 |
| summary | 请求数、UA 数、不同 IP 数 |
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
| 倒卖商审批 | /reseller/summary、accounts、stores、review-logs、accounts/review、stores/review、accounts/reset-password |
| 倒卖商销售权限 | /reseller/templates、templates/save、payment-drivers、orders |

协议节点的 save、update、drop、copy 均为 POST 请求。

### 6.4 管理员二步验证

| 方法 | 接口路径 | 请求参数 | 说明 |
| --- | --- | --- | --- |
| GET | /2fa/status | 无 | 当前管理员二步验证状态 |
| POST | /2fa/setup | 无 | 开始配置 |
| POST | /2fa/confirm | code | 确认配置 |
| POST | /2fa/disable | code 或 recovery_code | 关闭 |
| POST | /2fa/recovery-codes/regenerate | code 或 recovery_code | 重置恢复码 |

## 七、员工接口

基础路径：/api/v1/staff，需要员工鉴权。

| 模块 | 接口 |
| --- | --- |
| 二步验证 | /2fa/status、setup、confirm、disable、recovery-codes/regenerate |
| 工单 | /ticket/fetch、reply、close |
| 用户 | /user/update、getUserInfoById、sendMail、ban |
| 套餐 | /plan/fetch |
| 公告 | /notice/fetch、save、update、drop |

员工权限不能直接调用管理员路径。

## 八、客户端订阅接口

| 方法 | 接口路径 | 鉴权 | 说明 |
| --- | --- | --- | --- |
| GET | /api/v1/client/subscribe | client | 默认订阅地址 |
| GET | 配置中的 subscribe_path | client | 自定义订阅地址 |
| GET | /api/v1/client/app/getConfig | client | 客户端配置 |
| GET | /api/v1/client/app/getVersion | client | 客户端版本 |

订阅中间件处理流程：

| 步骤 | 处理 |
| --- | --- |
| 1 | 读取 token |
| 2 | 按配置处理普通、一次性或临时 Token |
| 3 | 查询独立订阅或旧用户 Token |
| 4 | 检查 active、过期和 revoked 状态 |
| 5 | 生成订阅上下文 |
| 6 | 记录 User-Agent、REMOTE_ADDR 和订阅 ID |
| 7 | 返回对应节点协议内容 |

## 九、节点接口

### 9.1 V2 配置接口

| 方法 | 接口路径 | 参数 | 返回 |
| --- | --- | --- | --- |
| GET | /api/v2/server/config | token、node_id | 节点监听、协议、TLS、路由和轮询配置 |

支持 If-None-Match；配置未变化时返回 304。

### 9.2 V1 动态节点接口

| 路径 | 说明 |
| --- | --- |
| /api/v1/server/{class}/{action} | 动态调用节点控制器 |
| UniProxy | 通用节点和流量相关接口 |
| Deepbwork | Deepbwork 节点接口 |
| TrojanTidalab | Trojan 节点接口 |
| ShadowsocksTidalab | Shadowsocks 节点接口 |

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
| php artisan v2board:update | 数据库升级 |
| php artisan ip:clear-location-cache | 清理 IP 归属缓存 |
| php artisan ip:backfill-subscribe-locations | 回填历史 IP 归属 |
| php artisan subscription:risk | 计算已完成风险周期 |
| php artisan horizon:terminate | 终止 Horizon Worker |

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
| GET | /customers | page、pageSize | 当前店铺客户列表，仅返回必要客户字段 |
| GET | /orders | page、pageSize | 当前店铺订单列表和金额快照 |

套餐和支付规则：

- `base_plan_id` 只能选择管理员发布且启用的基础套餐，倒卖商不能修改节点、流量、速度和设备限制。
- 周期价格在 API 中必须为大于 0 的整数分；倒卖工作区输入框使用人民币元并在提交时转换为分。周期字段为 `month_price`、`quarter_price`、`half_year_price`、`year_price`、`two_year_price`、`three_year_price`、`onetime_price`。
- `shared_member_limit` 默认为 1；大于 1 时为共享套餐，人数包含购买者。
- 支付驱动必须在管理员 `reseller_allowed_payment_drivers` 白名单内，禁止提交任意 PHP 类或代码。
- 支付密钥使用服务端加密保存，不在列表或日志返回。已经被订单使用的支付配置不能修改密钥或删除，可停用以阻止新订单。

共享群组审计接口：

| 方法 | 接口路径 | 请求参数 | 说明 |
| --- | --- | --- | --- |
| GET | /shared-subscriptions | page、pageSize | 当前倒卖商共享群组汇总 |
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

兼容旧版管理员前端的别名仍保留：`/reseller/fetch`、`/reseller/update`、`/reseller/template/fetch`、`/reseller/template/save`。

### 14.4 店铺前台接口

店铺地址为 `/store/{slug}`，API 基础路径为 `/api/v1/store/{slug}`。店铺必须存在且账号、店铺均为 `active`；否则公开接口返回 404。`reseller_enable=0` 时公开销售接口返回 503。

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

登录后接口使用现有用户 `auth_data`：

| 方法 | 接口路径 | 请求参数 | 说明 |
| --- | --- | --- | --- |
| POST | /order/save | plan_id、period；可选 subscription_id | 创建新购或同店同基础套餐续费订单，返回 trade_no |
| POST | /order/checkout | trade_no、method；Stripe 可传 token | 使用店铺支付配置发起支付 |
| GET | /order/check | trade_no | 查询订单状态 |
| GET | /order/detail | trade_no | 查询当前店铺订单详情 |
| GET | /order/fetch | page、pageSize | 当前客户在当前店铺的订单 |
| POST | /order/cancel | trade_no | 取消待支付订单 |
| GET | /subscription | 无 | 获取当前店铺订阅及流量汇总 |

支付回调是已创建订单的异步入口：店铺停用或倒卖商总开关关闭后，已绑定支付配置的回调仍允许完成验签；普通公开、注册、下单和支付发起接口不会绕过店铺状态。

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
| arithmetic_verification_enable | 0 | 主站注册算术验证开关，不影响倒卖商注册 |
| site_status | normal | `maintenance` 或 `shutdown` 时阻断普通公共业务 |
| reseller_allowed_payment_drivers | [] | 管理员允许倒卖商使用的支付驱动白名单 |

倒卖商功能关闭不会删除账号、客户、订单、共享群组或已有订阅。站点维护和停运期间，公共配置接口仍用于展示状态页，管理员接口、节点通信和已有支付回调按当前中间件规则处理。

接口变更时应同步更新本文档，并以当前控制器和 FormRequest 校验规则为最终依据。
