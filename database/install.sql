-- Adminer 4.8.1 MySQL 5.7.29 dump

SET NAMES utf8;
SET time_zone = '+00:00';
SET foreign_key_checks = 0;
SET sql_mode = 'NO_AUTO_VALUE_ON_ZERO';

SET NAMES utf8mb4;

DROP TABLE IF EXISTS `failed_jobs`;
CREATE TABLE `failed_jobs` (
                               `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                               `connection` text COLLATE utf8mb4_unicode_ci NOT NULL,
                               `queue` text COLLATE utf8mb4_unicode_ci NOT NULL,
                               `payload` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
                               `exception` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
                               `failed_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                               PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `v2_commission_log`;
CREATE TABLE `v2_commission_log` (
                                     `id` int(11) NOT NULL AUTO_INCREMENT,
                                     `invite_user_id` int(11) NOT NULL,
                                     `user_id` int(11) NOT NULL,
                                     `trade_no` char(36) NOT NULL,
                                     `order_amount` int(11) NOT NULL,
                                     `get_amount` int(11) NOT NULL,
                                     `created_at` int(11) NOT NULL,
                                     `updated_at` int(11) NOT NULL,
                                     PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


DROP TABLE IF EXISTS `v2_coupon`;
CREATE TABLE `v2_coupon` (
                             `id` int(11) NOT NULL AUTO_INCREMENT,
                             `code` varchar(255) NOT NULL,
                             `name` varchar(255) CHARACTER SET utf8mb4 NOT NULL,
                             `type` tinyint(1) NOT NULL,
                             `value` int(11) NOT NULL,
                             `show` tinyint(1) NOT NULL DEFAULT '0',
                             `limit_use` int(11) DEFAULT NULL,
                             `limit_use_with_user` int(11) DEFAULT NULL,
                             `limit_plan_ids` varchar(255) DEFAULT NULL,
                             `limit_period` varchar(255) DEFAULT NULL,
                             `started_at` int(11) NOT NULL,
                             `ended_at` int(11) NOT NULL,
                             `created_at` int(11) NOT NULL,
                             `updated_at` int(11) NOT NULL,
                             PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;


DROP TABLE IF EXISTS `v2_giftcard`;
CREATE TABLE `v2_giftcard` (
                             `id` int(11) NOT NULL AUTO_INCREMENT,
                             `code` varchar(255) NOT NULL,
                             `name` varchar(255) CHARACTER SET utf8mb4 NOT NULL,
                             `type` tinyint(1) NOT NULL,
                             `value` int(11) DEFAULT NULL,
                             `plan_id` int(11) DEFAULT NULL,
                             `limit_use` int(11) DEFAULT NULL,
                             `used_user_ids` varchar(16384) DEFAULT NULL,
                             `started_at` int(11) NOT NULL,
                             `ended_at` int(11) NOT NULL,
                             `created_at` int(11) NOT NULL,
                             `updated_at` int(11) NOT NULL,
                             PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;


DROP TABLE IF EXISTS `v2_invite_code`;
CREATE TABLE `v2_invite_code` (
                                  `id` int(11) NOT NULL AUTO_INCREMENT,
                                  `user_id` int(11) NOT NULL,
                                  `code` char(32) NOT NULL,
                                  `status` tinyint(1) NOT NULL DEFAULT '0',
                                  `pv` int(11) NOT NULL DEFAULT '0',
                                  `created_at` int(11) NOT NULL,
                                  `updated_at` int(11) NOT NULL,
                                  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;


DROP TABLE IF EXISTS `v2_knowledge`;
CREATE TABLE `v2_knowledge` (
                                `id` int(11) NOT NULL AUTO_INCREMENT,
                                `language` char(5) NOT NULL COMMENT '語言',
                                `category` varchar(255) NOT NULL COMMENT '分類名',
                                `title` varchar(255) NOT NULL COMMENT '標題',
                                `body` text NOT NULL COMMENT '內容',
                                `sort` int(11) DEFAULT NULL COMMENT '排序',
                                `show` tinyint(1) NOT NULL DEFAULT '0' COMMENT '顯示',
                                `created_at` int(11) NOT NULL COMMENT '創建時間',
                                `updated_at` int(11) NOT NULL COMMENT '更新時間',
                                PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='知識庫';


DROP TABLE IF EXISTS `v2_log`;
CREATE TABLE `v2_log` (
                          `id` int(11) NOT NULL AUTO_INCREMENT,
                          `title` text NOT NULL,
                          `level` varchar(11) DEFAULT NULL,
                          `host` varchar(255) DEFAULT NULL,
                          `uri` varchar(255) NOT NULL,
                          `method` varchar(11) NOT NULL,
                          `data` text,
                          `ip` varchar(128) DEFAULT NULL,
                          `context` text,
                          `created_at` int(11) NOT NULL,
                          `updated_at` int(11) NOT NULL,
                          PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


DROP TABLE IF EXISTS `v2_mail_log`;
CREATE TABLE `v2_mail_log` (
                               `id` int(11) NOT NULL AUTO_INCREMENT,
                               `email` varchar(64) NOT NULL,
                               `subject` varchar(255) NOT NULL,
                               `template_name` varchar(255) NOT NULL,
                               `error` text,
                               `created_at` int(11) NOT NULL,
                               `updated_at` int(11) NOT NULL,
                               PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


DROP TABLE IF EXISTS `v2_notice`;
CREATE TABLE `v2_notice` (
                             `id` int(11) NOT NULL AUTO_INCREMENT,
                             `title` varchar(255) NOT NULL,
                             `content` text NOT NULL,
                             `show` tinyint(1) NOT NULL DEFAULT '0',
                             `img_url` varchar(255) DEFAULT NULL,
                             `tags` varchar(255) DEFAULT NULL,
                             `created_at` int(11) NOT NULL,
                             `updated_at` int(11) NOT NULL,
                             PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;


DROP TABLE IF EXISTS `v2_order`;
CREATE TABLE `v2_order` (
                            `id` int(11) NOT NULL AUTO_INCREMENT,
                            `invite_user_id` int(11) DEFAULT NULL,
                            `user_id` int(11) NOT NULL,
                            `plan_id` int(11) NOT NULL,
                            `subscription_id` bigint(20) DEFAULT NULL,
                            `coupon_id` int(11) DEFAULT NULL,
                            `payment_id` int(11) DEFAULT NULL,
                            `type` int(11) NOT NULL COMMENT '1新购2续费3升级',
                            `period` varchar(255) NOT NULL,
                            `trade_no` varchar(36) NOT NULL,
                            `callback_no` varchar(255) DEFAULT NULL,
                            `total_amount` int(11) NOT NULL,
                            `handling_amount` int(11) DEFAULT NULL,
                            `discount_amount` int(11) DEFAULT NULL,
                            `surplus_amount` int(11) DEFAULT NULL COMMENT '剩余价值',
                            `refund_amount` int(11) DEFAULT NULL COMMENT '退款金额',
                            `balance_amount` int(11) DEFAULT NULL COMMENT '使用余额',
                            `surplus_order_ids` text COMMENT '折抵订单',
                            `status` tinyint(1) NOT NULL DEFAULT '0' COMMENT '0待支付1开通中2已取消3已完成4已折抵',
                            `commission_status` tinyint(1) NOT NULL DEFAULT '0' COMMENT '0待确认1发放中2有效3无效',
                            `commission_balance` int(11) NOT NULL DEFAULT '0',
                            `actual_commission_balance` int(11) DEFAULT NULL COMMENT '实际支付佣金',
                            `paid_at` int(11) DEFAULT NULL,
                            `created_at` int(11) NOT NULL,
                            `updated_at` int(11) NOT NULL,
                            PRIMARY KEY (`id`),
                            UNIQUE KEY `trade_no` (`trade_no`),
                            INDEX idx_user (`user_id`),
                            INDEX idx_user_status (`user_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;


DROP TABLE IF EXISTS `v2_payment`;
CREATE TABLE `v2_payment` (
                              `id` int(11) NOT NULL AUTO_INCREMENT,
                              `uuid` char(32) NOT NULL,
                              `payment` varchar(16) NOT NULL,
                              `name` varchar(255) NOT NULL,
                              `icon` varchar(255) DEFAULT NULL,
                              `config` text NOT NULL,
                              `notify_domain` varchar(128) DEFAULT NULL,
                              `handling_fee_fixed` int(11) DEFAULT NULL,
                              `handling_fee_percent` decimal(5,2) DEFAULT NULL,
                              `enable` tinyint(1) NOT NULL DEFAULT '0',
                              `sort` int(11) DEFAULT NULL,
                              `created_at` int(11) NOT NULL,
                              `updated_at` int(11) NOT NULL,
                              PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


DROP TABLE IF EXISTS `v2_plan`;
CREATE TABLE `v2_plan` (
                           `id` int(11) NOT NULL AUTO_INCREMENT,
                           `group_id` int(11) NOT NULL,
                           `transfer_enable` int(11) NOT NULL,
                           `device_limit` int(11) DEFAULT NULL,
                           `name` varchar(255) NOT NULL,
                           `speed_limit` int(11) DEFAULT NULL,
                           `show` tinyint(1) NOT NULL DEFAULT '0',
                           `sort` int(11) DEFAULT NULL,
                           `renew` tinyint(1) NOT NULL DEFAULT '1',
                           `content` text,
                           `month_price` int(11) DEFAULT NULL,
                           `quarter_price` int(11) DEFAULT NULL,
                           `half_year_price` int(11) DEFAULT NULL,
                           `year_price` int(11) DEFAULT NULL,
                           `two_year_price` int(11) DEFAULT NULL,
                           `three_year_price` int(11) DEFAULT NULL,
                           `onetime_price` int(11) DEFAULT NULL,
                           `reset_price` int(11) DEFAULT NULL,
                           `reset_traffic_method` tinyint(1) DEFAULT NULL,
                           `capacity_limit` int(11) DEFAULT NULL,
                           `created_at` int(11) NOT NULL,
                           `updated_at` int(11) NOT NULL,
                           PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


DROP TABLE IF EXISTS `v2_server_group`;
CREATE TABLE `v2_server_group` (
                                   `id` int(11) NOT NULL AUTO_INCREMENT,
                                   `name` varchar(255) NOT NULL,
                                   `created_at` int(11) NOT NULL,
                                   `updated_at` int(11) NOT NULL,
                                   PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;


DROP TABLE IF EXISTS `v2_server_tuic`;
CREATE TABLE `v2_server_tuic` (
                                      `id` int(11) NOT NULL AUTO_INCREMENT,
                                      `group_id` varchar(255) NOT NULL,
                                      `route_id` varchar(255) DEFAULT NULL,
                                      `name` varchar(255) NOT NULL,
                                      `parent_id` int(11) DEFAULT NULL,
                                      `host` varchar(255) NOT NULL,
                                      `port` varchar(11) NOT NULL,
                                      `server_port` int(11) NOT NULL,
                                      `tags` varchar(255) DEFAULT NULL,
                                      `rate` varchar(11) NOT NULL,
                                      `show` tinyint(1) NOT NULL DEFAULT '0',
                                      `sort` int(11) DEFAULT NULL,
                                      `server_name` varchar(64) DEFAULT NULL,
                                      `insecure` tinyint(1) NOT NULL DEFAULT '0',
                                      `disable_sni` tinyint(1) NOT NULL DEFAULT '0',
                                      `udp_relay_mode` varchar(64) DEFAULT NULL,
                                      `zero_rtt_handshake` tinyint(1) NOT NULL DEFAULT '0',
                                      `congestion_control` varchar(64) DEFAULT NULL,
                                      `created_at` int(11) NOT NULL,
                                      `updated_at` int(11) NOT NULL,
                                      PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


DROP TABLE IF EXISTS `v2_server_hysteria`;
CREATE TABLE `v2_server_hysteria` (
                                      `id` int(11) NOT NULL AUTO_INCREMENT,
                                      `version` int(11) NOT NULL,
                                      `group_id` varchar(255) NOT NULL,
                                      `route_id` varchar(255) DEFAULT NULL,
                                      `name` varchar(255) NOT NULL,
                                      `parent_id` int(11) DEFAULT NULL,
                                      `host` varchar(255) NOT NULL,
                                      `port` varchar(11) NOT NULL,
                                      `server_port` int(11) NOT NULL,
                                      `tags` varchar(255) DEFAULT NULL,
                                      `rate` varchar(11) NOT NULL,
                                      `show` tinyint(1) NOT NULL DEFAULT '0',
                                      `sort` int(11) DEFAULT NULL,
                                      `up_mbps` int(11) NOT NULL,
                                      `down_mbps` int(11) NOT NULL,
                                      `obfs` varchar(64) DEFAULT NULL,
                                      `obfs_password` varchar(255) DEFAULT NULL,
                                      `server_name` varchar(64) DEFAULT NULL,
                                      `insecure` tinyint(1) NOT NULL DEFAULT '0',
                                      `created_at` int(11) NOT NULL,
                                      `updated_at` int(11) NOT NULL,
                                      PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


DROP TABLE IF EXISTS `v2_server_route`;
CREATE TABLE `v2_server_route` (
                                   `id` int(11) NOT NULL AUTO_INCREMENT,
                                   `remarks` varchar(255) NOT NULL,
                                   `match` text NOT NULL,
                                   `action` varchar(11) NOT NULL,
                                   `action_value` text DEFAULT NULL,
                                   `created_at` int(11) NOT NULL,
                                   `updated_at` int(11) NOT NULL,
                                   PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


DROP TABLE IF EXISTS `v2_server_shadowsocks`;
CREATE TABLE `v2_server_shadowsocks` (
                                         `id` int(11) NOT NULL AUTO_INCREMENT,
                                         `group_id` varchar(255) NOT NULL,
                                         `route_id` varchar(255) DEFAULT NULL,
                                         `parent_id` int(11) DEFAULT NULL,
                                         `tags` varchar(255) DEFAULT NULL,
                                         `name` varchar(255) NOT NULL,
                                         `rate` varchar(11) NOT NULL,
                                         `host` varchar(255) NOT NULL,
                                         `port` varchar(11) NOT NULL,
                                         `server_port` int(11) NOT NULL,
                                         `cipher` varchar(255) NOT NULL,
                                         `obfs` char(11) DEFAULT NULL,
                                         `obfs_settings` varchar(255) DEFAULT NULL,
                                         `show` tinyint(4) NOT NULL DEFAULT '0',
                                         `sort` int(11) DEFAULT NULL,
                                         `created_at` int(11) NOT NULL,
                                         `updated_at` int(11) NOT NULL,
                                         PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


DROP TABLE IF EXISTS `v2_server_trojan`;
CREATE TABLE `v2_server_trojan` (
                                    `id` int(11) NOT NULL AUTO_INCREMENT COMMENT '节点ID',
                                    `group_id` varchar(255) NOT NULL COMMENT '节点组',
                                    `route_id` varchar(255) DEFAULT NULL,
                                    `parent_id` int(11) DEFAULT NULL COMMENT '父节点',
                                    `tags` varchar(255) DEFAULT NULL COMMENT '节点标签',
                                    `name` varchar(255) NOT NULL COMMENT '节点名称',
                                    `rate` varchar(11) NOT NULL COMMENT '倍率',
                                    `host` varchar(255) NOT NULL COMMENT '主机名',
                                    `port` varchar(11) NOT NULL COMMENT '连接端口',
                                    `server_port` int(11) NOT NULL COMMENT '服务端口',
                                    `network` varchar(11) DEFAULT NULL COMMENT '传输方式',
                                    `network_settings` text COMMENT '传输配置',
                                    `allow_insecure` tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否允许不安全',
                                    `server_name` varchar(255) DEFAULT NULL,
                                    `show` tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否显示',
                                    `sort` int(11) DEFAULT NULL,
                                    `created_at` int(11) NOT NULL,
                                    `updated_at` int(11) NOT NULL,
                                    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='trojan伺服器表';


DROP TABLE IF EXISTS `v2_server_vless`;
CREATE TABLE `v2_server_vless` (
                                   `id` int(11) NOT NULL AUTO_INCREMENT,
                                   `group_id` text NOT NULL,
                                   `route_id` text,
                                   `name` varchar(255) NOT NULL,
                                   `parent_id` int(11) DEFAULT NULL,
                                   `host` varchar(255) NOT NULL,
                                   `port` int(11) NOT NULL,
                                   `server_port` int(11) NOT NULL,
                                   `tls` tinyint(1) NOT NULL,
                                   `tls_settings` text,
                                   `flow` varchar(64) DEFAULT NULL,
                                   `network` varchar(11) NOT NULL,
                                   `network_settings` text,
                                   `encryption` varchar(64) DEFAULT NULL,
                                   `encryption_settings` text,
                                   `tags` text,
                                   `rate` varchar(11) NOT NULL,
                                   `show` tinyint(1) NOT NULL DEFAULT '0',
                                   `sort` int(11) DEFAULT NULL,
                                   `created_at` int(11) NOT NULL,
                                   `updated_at` int(11) NOT NULL,
                                   PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


DROP TABLE IF EXISTS `v2_server_vmess`;
CREATE TABLE `v2_server_vmess` (
                                   `id` int(11) NOT NULL AUTO_INCREMENT,
                                   `group_id` varchar(255) NOT NULL,
                                   `route_id` varchar(255) DEFAULT NULL,
                                   `name` varchar(255) NOT NULL,
                                   `parent_id` int(11) DEFAULT NULL,
                                   `host` varchar(255) NOT NULL,
                                   `port` varchar(11) NOT NULL,
                                   `server_port` int(11) NOT NULL,
                                   `tls` tinyint(4) NOT NULL DEFAULT '0',
                                   `tags` varchar(255) DEFAULT NULL,
                                   `rate` varchar(11) NOT NULL,
                                   `network` varchar(11) NOT NULL,
                                   `rules` text,
                                   `networkSettings` text,
                                   `tlsSettings` text,
                                   `ruleSettings` text,
                                   `dnsSettings` text,
                                   `show` tinyint(1) NOT NULL DEFAULT '0',
                                   `sort` int(11) DEFAULT NULL,
                                   `created_at` int(11) NOT NULL,
                                   `updated_at` int(11) NOT NULL,
                                   PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `v2_server_anytls`;
CREATE TABLE `v2_server_anytls` (
                                      `id` int(11) NOT NULL AUTO_INCREMENT,
                                      `group_id` varchar(255) NOT NULL,
                                      `route_id` varchar(255) DEFAULT NULL,
                                      `name` varchar(255) NOT NULL,
                                      `parent_id` int(11) DEFAULT NULL,
                                      `host` varchar(255) NOT NULL,
                                      `port` varchar(11) NOT NULL,
                                      `server_port` int(11) NOT NULL,
                                      `tags` varchar(255) DEFAULT NULL,
                                      `rate` varchar(11) NOT NULL,
                                      `show` tinyint(1) NOT NULL DEFAULT '0',
                                      `sort` int(11) DEFAULT NULL,
                                      `server_name` varchar(64) DEFAULT NULL,
                                      `insecure` tinyint(1) NOT NULL DEFAULT '0',
                                      `padding_scheme` text,
                                      `created_at` int(11) NOT NULL,
                                      `updated_at` int(11) NOT NULL,
                                      PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `v2_server_v2node`;
CREATE TABLE `v2_server_v2node` (
                                    `id` int(11) NOT NULL AUTO_INCREMENT,
                                    `group_id` varchar(255) NOT NULL,
                                    `route_id` varchar(255) DEFAULT NULL,
                                    `name` varchar(255) NOT NULL,
                                    `parent_id` int(11) DEFAULT NULL,
                                    `host` varchar(255) NOT NULL,
                                    `listen_ip` varchar(255) NOT NULL DEFAULT '0.0.0.0',
                                    `port` varchar(11) NOT NULL,
                                    `server_port` int(11) NOT NULL,
                                    `tags` varchar(255) DEFAULT NULL,
                                    `rate` varchar(11) NOT NULL,
                                    `show` tinyint(1) NOT NULL DEFAULT '0',
                                    `sort` int(11) DEFAULT NULL,
                                    `protocol` varchar(24) NOT NULL COMMENT '协议类型',
                                    `tls` tinyint(1) NOT NULL COMMENT 'tls类型',
                                    `tls_settings` text COMMENT 'tls配置',
                                    `flow` varchar(64) DEFAULT NULL COMMENT 'vless流控',
                                    `network` varchar(11) NOT NULL COMMENT '传输类型',
                                    `network_settings` text COMMENT '传输配置',
                                    `trusted_x_forwarded_for` varchar(255) DEFAULT NULL COMMENT '信任的x-forwarded-for头部',
                                    `encryption` varchar(64) DEFAULT NULL COMMENT 'vless加密',
                                    `encryption_settings` text COMMENT 'vless加密配置',
                                    `disable_sni` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'tuic禁用sni',
                                    `udp_relay_mode` varchar(64) DEFAULT NULL COMMENT 'tuic udp中继模式',
                                    `zero_rtt_handshake` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'tuic 0rtt握手',
                                    `congestion_control` varchar(64) DEFAULT NULL COMMENT 'tuic拥塞控制',
                                    `cipher` varchar(64) DEFAULT NULL COMMENT 'shadowsocks加密方式',
                                    `up_mbps` int(11) NOT NULL COMMENT 'hysteria上行带宽',
                                    `down_mbps` int(11) NOT NULL COMMENT 'hysteria下行带宽',
                                    `obfs` varchar(64) DEFAULT NULL COMMENT 'hysteria1混淆密码/hysteria2混淆类型',
                                    `obfs_password` varchar(255) DEFAULT NULL COMMENT 'hysteria2混淆密码',
                                    `padding_scheme` text COMMENT 'anytls填充配置',
                                    `created_at` int(11) NOT NULL,
                                    `updated_at` int(11) NOT NULL,
                                    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `v2_stat`;
CREATE TABLE `v2_stat` (
                           `id` int(11) NOT NULL AUTO_INCREMENT,
                           `record_at` int(11) NOT NULL,
                           `record_type` char(1) NOT NULL,
                           `order_count` int(11) NOT NULL COMMENT '订单数量',
                           `order_total` int(11) NOT NULL COMMENT '订单合计',
                           `commission_count` int(11) NOT NULL,
                           `commission_total` int(11) NOT NULL COMMENT '佣金合计',
                           `paid_count` int(11) NOT NULL,
                           `paid_total` int(11) NOT NULL,
                           `register_count` int(11) NOT NULL,
                           `invite_count` int(11) NOT NULL,
                           `transfer_used_total` varchar(32) NOT NULL,
                           `created_at` int(11) NOT NULL,
                           `updated_at` int(11) NOT NULL,
                           PRIMARY KEY (`id`),
                           UNIQUE KEY `record_at` (`record_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='订单统计';


DROP TABLE IF EXISTS `v2_stat_server`;
CREATE TABLE `v2_stat_server` (
                                  `id` int(11) NOT NULL AUTO_INCREMENT,
                                  `server_id` int(11) NOT NULL COMMENT '节点id',
                                  `server_type` char(11) NOT NULL COMMENT '节点类型',
                                  `u` bigint(20) NOT NULL,
                                  `d` bigint(20) NOT NULL,
                                  `record_type` char(1) NOT NULL COMMENT 'd day m month',
                                  `record_at` int(11) NOT NULL COMMENT '记录时间',
                                  `created_at` int(11) NOT NULL,
                                  `updated_at` int(11) NOT NULL,
                                  PRIMARY KEY (`id`),
                                  UNIQUE KEY `server_id_server_type_record_at` (`server_id`,`server_type`,`record_at`),
                                  KEY `record_at` (`record_at`),
                                  KEY `server_id` (`server_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='节点数据统计';


DROP TABLE IF EXISTS `v2_stat_user`;
CREATE TABLE `v2_stat_user` (
                                `id` int(11) NOT NULL AUTO_INCREMENT,
                                `user_id` int(11) NOT NULL,
                                `subscription_id` bigint(20) DEFAULT NULL,
                                `server_rate` decimal(10,2) NOT NULL,
                                `u` bigint(20) NOT NULL,
                                `d` bigint(20) NOT NULL,
                                `record_type` char(2) NOT NULL,
                                `record_at` int(11) NOT NULL,
                                `created_at` int(11) NOT NULL,
                                `updated_at` int(11) NOT NULL,
                                PRIMARY KEY (`id`),
                                UNIQUE KEY `server_rate_user_id_subscription_record_at` (`server_rate`,`user_id`,`subscription_id`,`record_at`),
                                KEY `user_id` (`user_id`),
                                KEY `record_at` (`record_at`),
                                KEY `server_rate` (`server_rate`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


DROP TABLE IF EXISTS `v2_ticket`;
CREATE TABLE `v2_ticket` (
                             `id` int(11) NOT NULL AUTO_INCREMENT,
                             `user_id` int(11) NOT NULL,
                             `subject` varchar(255) NOT NULL,
                             `level` tinyint(1) NOT NULL,
                             `status` tinyint(1) NOT NULL DEFAULT '0' COMMENT '0:已开启 1:已关闭',
                             `reply_status` tinyint(1) NOT NULL DEFAULT '0' COMMENT '0:待回复 1:已回复',
                             `created_at` int(11) NOT NULL,
                             `updated_at` int(11) NOT NULL,
                             PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;


DROP TABLE IF EXISTS `v2_ticket_message`;
CREATE TABLE `v2_ticket_message` (
                                     `id` int(11) NOT NULL AUTO_INCREMENT,
                                     `user_id` int(11) NOT NULL,
                                     `ticket_id` int(11) NOT NULL,
                                     `message` text CHARACTER SET utf8mb4 NOT NULL,
                                     `created_at` int(11) NOT NULL,
                                     `updated_at` int(11) NOT NULL,
                                     PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;


DROP TABLE IF EXISTS `v2_user`;
CREATE TABLE `v2_user` (
                           `id` int(11) NOT NULL AUTO_INCREMENT,
                           `invite_user_id` int(11) DEFAULT NULL,
                           `telegram_id` bigint(20) DEFAULT NULL,
                           `email` varchar(64) NOT NULL,
                           `password` varchar(64) NOT NULL,
                           `password_algo` char(10) DEFAULT NULL,
                           `password_salt` char(10) DEFAULT NULL,
                           `password_reset_required` tinyint(1) NOT NULL DEFAULT '0',
                           `balance` int(11) NOT NULL DEFAULT '0',
                           `discount` int(11) DEFAULT NULL,
                           `commission_type` tinyint(4) NOT NULL DEFAULT '0' COMMENT '0: system 1: period 2: onetime',
                           `commission_rate` int(11) DEFAULT NULL,
                           `commission_balance` int(11) NOT NULL DEFAULT '0',
                           `t` int(11) NOT NULL DEFAULT '0',
                           `u` bigint(20) NOT NULL DEFAULT '0',
                           `d` bigint(20) NOT NULL DEFAULT '0',
                           `transfer_enable` bigint(20) NOT NULL DEFAULT '0',
                           `device_limit` int(11) DEFAULT NULL,
                           `banned` tinyint(1) NOT NULL DEFAULT '0',
                           `is_admin` tinyint(1) NOT NULL DEFAULT '0',
                           `last_login_at` int(11) DEFAULT NULL,
                           `is_staff` tinyint(1) NOT NULL DEFAULT '0',
                           `last_login_ip` int(11) DEFAULT NULL,
                           `uuid` varchar(36) NOT NULL,
                           `group_id` int(11) DEFAULT NULL,
                           `plan_id` int(11) DEFAULT NULL,
                           `speed_limit` int(11) DEFAULT NULL,
                           `auto_renewal` tinyint(4) DEFAULT '0',
                           `remind_expire` tinyint(4) DEFAULT '1',
                           `remind_traffic` tinyint(4) DEFAULT '1',
                           `token` char(32) NOT NULL,
                           `expired_at` bigint(20) DEFAULT '0',
                           `remarks` text,
                           `created_at` int(11) NOT NULL,
                           `updated_at` int(11) NOT NULL,
                           PRIMARY KEY (`id`),
                           UNIQUE KEY `email` (`email`),
                           UNIQUE KEY `token` (`token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

DROP TABLE IF EXISTS `v2_subscription`;
CREATE TABLE `v2_subscription` (
                                  `id` bigint(20) NOT NULL AUTO_INCREMENT,
                                  `user_id` int(11) NOT NULL,
                                  `plan_id` int(11) NOT NULL,
                                  `token` char(32) NOT NULL,
                                  `uuid` varchar(36) NOT NULL,
                                  `node_user_id` bigint(20) NOT NULL,
                                  `group_id` int(11) DEFAULT NULL,
                                  `speed_limit` int(11) DEFAULT NULL,
                                  `device_limit` int(11) DEFAULT NULL,
                                  `transfer_enable` bigint(20) NOT NULL DEFAULT '0',
                                  `u` bigint(20) NOT NULL DEFAULT '0',
                                  `d` bigint(20) NOT NULL DEFAULT '0',
                                  `status` varchar(16) NOT NULL DEFAULT 'active',
                                  `is_primary` tinyint(1) NOT NULL DEFAULT '0',
                                  `auto_renewal` tinyint(1) NOT NULL DEFAULT '0',
                                  `started_at` bigint(20) DEFAULT NULL,
                                  `expired_at` bigint(20) DEFAULT NULL,
                                  `last_reset_at` bigint(20) DEFAULT NULL,
                                  `next_reset_at` bigint(20) DEFAULT NULL,
                                  `created_at` int(11) NOT NULL,
                                  `updated_at` int(11) NOT NULL,
                                  PRIMARY KEY (`id`),
                                  UNIQUE KEY `token` (`token`),
                                  UNIQUE KEY `node_user_id` (`node_user_id`),
                                  KEY `user_id_status` (`user_id`,`status`),
                                  KEY `user_id_primary` (`user_id`,`is_primary`),
                                  KEY `plan_id` (`plan_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `v2_subscribe_request_log`;
CREATE TABLE `v2_subscribe_request_log` (
                                  `id` bigint(20) NOT NULL AUTO_INCREMENT,
                                  `user_id` int(11) NOT NULL,
                                  `subscription_id` bigint(20) DEFAULT NULL,
                                  `user_agent` varchar(1000) NOT NULL,
                                  `ua_hash` char(64) NOT NULL,
                                  `request_ip` varchar(45) NOT NULL,
                                  `requested_at` bigint(20) NOT NULL,
                                  `created_at` int(11) NOT NULL,
                                  `updated_at` int(11) NOT NULL,
                                  PRIMARY KEY (`id`),
                                  KEY `user_subscription_requested_at` (`user_id`,`subscription_id`,`requested_at`),
                                  KEY `subscription_requested_at` (`subscription_id`,`requested_at`),
                                  KEY `subscription_ua_requested_at` (`subscription_id`,`ua_hash`,`requested_at`),
                                  KEY `user_requested_at` (`user_id`,`requested_at`),
                                  KEY `requested_at` (`requested_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `v2_subscription_risk_cycle`;
CREATE TABLE `v2_subscription_risk_cycle` (
                                  `id` bigint(20) NOT NULL AUTO_INCREMENT,
                                  `user_id` int(11) NOT NULL,
                                  `subscription_id` bigint(20) NOT NULL,
                                  `cycle_start` bigint(20) NOT NULL,
                                  `cycle_end` bigint(20) NOT NULL,
                                  `transfer_enable` bigint(20) NOT NULL DEFAULT '0',
                                  `used_traffic` bigint(20) NOT NULL DEFAULT '0',
                                  `used_ratio` decimal(12,8) DEFAULT NULL,
                                  `user_agent_count` int(11) NOT NULL DEFAULT '0',
                                  `distinct_ip_count` int(11) NOT NULL DEFAULT '0',
                                  `city_count` int(11) NOT NULL DEFAULT '0',
                                  `region_count` int(11) NOT NULL DEFAULT '0',
                                  `country_count` int(11) NOT NULL DEFAULT '0',
                                  `status` varchar(16) NOT NULL DEFAULT 'pending',
                                  `risk_reasons` text DEFAULT NULL,
                                  `metrics` text DEFAULT NULL,
                                  `evaluated_at` bigint(20) DEFAULT NULL,
                                  `created_at` int(11) NOT NULL,
                                  `updated_at` int(11) NOT NULL,
                                  PRIMARY KEY (`id`),
                                  UNIQUE KEY `subscription_cycle_start` (`subscription_id`,`cycle_start`),
                                  KEY `user_cycle_end` (`user_id`,`cycle_end`),
                                  KEY `status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `v2_ip_location_cache`;
CREATE TABLE `v2_ip_location_cache` (
    `id` bigint(20) NOT NULL AUTO_INCREMENT,
    `ip` varchar(45) NOT NULL,
    `ip_version` tinyint(1) NOT NULL,
    `country_code` varchar(8) NOT NULL DEFAULT '',
    `country_name` varchar(128) NOT NULL DEFAULT '',
    `region` varchar(128) NOT NULL DEFAULT '',
    `province` varchar(128) NOT NULL DEFAULT '',
    `city` varchar(128) NOT NULL DEFAULT '',
    `district` varchar(128) NOT NULL DEFAULT '',
    `isp` varchar(128) NOT NULL DEFAULT '',
    `idc_vendor` varchar(128) NOT NULL DEFAULT '',
    `location_key` varchar(384) NOT NULL DEFAULT '',
    `latitude` decimal(10,6) DEFAULT NULL,
    `longitude` decimal(10,6) DEFAULT NULL,
    `source` varchar(64) NOT NULL DEFAULT '',
    `status` varchar(16) NOT NULL DEFAULT 'unknown',
    `resolved_at` bigint(20) DEFAULT NULL,
    `created_at` int(11) NOT NULL,
    `updated_at` int(11) NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `ip` (`ip`),
    KEY `location_status` (`status`),
    KEY `location_key` (`location_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `v2_node_connection_log`;
CREATE TABLE `v2_node_connection_log` (
    `id` bigint(20) NOT NULL AUTO_INCREMENT,
    `user_id` int(11) NOT NULL,
    `subscription_id` bigint(20) DEFAULT NULL,
    `node_user_id` bigint(20) NOT NULL,
    `node_type` varchar(16) NOT NULL,
    `node_id` int(11) NOT NULL,
    `ip` varchar(45) NOT NULL,
    `report_count` bigint(20) NOT NULL DEFAULT '0',
    `first_seen_at` bigint(20) NOT NULL,
    `last_seen_at` bigint(20) NOT NULL,
    `created_at` int(11) NOT NULL,
    `updated_at` int(11) NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `node_user_node_ip` (`node_user_id`,`node_type`,`node_id`,`ip`),
    KEY `user_id_last_seen_at` (`user_id`,`last_seen_at`),
    KEY `subscription_id_last_seen_at` (`subscription_id`,`last_seen_at`),
    KEY `last_seen_at` (`last_seen_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `v2_risk_rule`;
CREATE TABLE `v2_risk_rule` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `label` varchar(255) NOT NULL,
    `dimension` varchar(32) NOT NULL,
    `operator` varchar(2) NOT NULL,
    `threshold` decimal(18,8) NOT NULL,
    `enabled` tinyint(1) NOT NULL DEFAULT '1',
    `sort` int(11) DEFAULT NULL,
    `created_at` int(11) NOT NULL,
    `updated_at` int(11) NOT NULL,
    PRIMARY KEY (`id`),
    KEY `enabled_sort` (`enabled`,`sort`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `v2_risk_rule` (`label`,`dimension`,`operator`,`threshold`,`enabled`,`sort`,`created_at`,`updated_at`) VALUES
('订阅 UA 种类过多','user_agent_count','>',3,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('跨省/州请求过多','region_count','>=',3,1,2,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('跨市请求过多','city_count','>=',3,1,3,UNIX_TIMESTAMP(),UNIX_TIMESTAMP());

DROP TABLE IF EXISTS `v2_subscription_token_history`;
CREATE TABLE `v2_subscription_token_history` (
    `id` bigint(20) NOT NULL AUTO_INCREMENT,
    `token_hash` char(64) NOT NULL,
    `token_prefix` char(8) NOT NULL DEFAULT '',
    `token_encrypted` text DEFAULT NULL,
    `user_id` int(11) NOT NULL,
    `subscription_id` bigint(20) DEFAULT NULL,
    `issued_at` bigint(20) NOT NULL,
    `issued_at_exact` tinyint(1) NOT NULL DEFAULT '1',
    `issued_reason` varchar(32) NOT NULL DEFAULT 'unknown',
    `issued_actor_type` varchar(16) NOT NULL DEFAULT 'unknown',
    `issued_actor_user_id` int(11) DEFAULT NULL,
    `retired_at` bigint(20) DEFAULT NULL,
    `retired_at_exact` tinyint(1) DEFAULT NULL,
    `retired_reason` varchar(32) DEFAULT NULL,
    `retired_actor_type` varchar(16) DEFAULT NULL,
    `retired_actor_user_id` int(11) DEFAULT NULL,
    `created_at` int(11) NOT NULL,
    `updated_at` int(11) NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `token_hash` (`token_hash`),
    KEY `user_id_issued_at` (`user_id`,`issued_at`),
    KEY `subscription_id_issued_at` (`subscription_id`,`issued_at`),
    KEY `token_prefix` (`token_prefix`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `v2_schema_migrations` (
    `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    `version` varchar(80) NOT NULL,
    `checksum` char(64) NOT NULL,
    `applied_at` int(11) NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `version` (`version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- 2025-09-12 10:05:00

CREATE TABLE `v2_user_two_factor` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `user_id` int(11) NOT NULL,
    `secret_encrypted` text DEFAULT NULL,
    `pending_secret_encrypted` text DEFAULT NULL,
    `enabled` tinyint(1) NOT NULL DEFAULT '0',
    `confirmed_at` int(11) DEFAULT NULL,
    `recovery_codes` text DEFAULT NULL,
    `last_used_step` bigint(20) DEFAULT NULL,
    `created_at` int(11) NOT NULL,
    `updated_at` int(11) NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `v2_two_factor_audit` (
    `id` bigint(20) NOT NULL AUTO_INCREMENT,
    `user_id` int(11) NOT NULL,
    `actor_user_id` int(11) DEFAULT NULL,
    `action` varchar(64) NOT NULL,
    `ip` varchar(64) DEFAULT NULL,
    `user_agent` varchar(500) DEFAULT NULL,
    `metadata` text DEFAULT NULL,
    `created_at` int(11) NOT NULL,
    PRIMARY KEY (`id`),
    KEY `user_id` (`user_id`),
    KEY `action` (`action`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `v2_reseller_account` (
    `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    `email` varchar(128) NOT NULL,
    `password` varchar(255) NOT NULL,
    `store_slug` varchar(32) NOT NULL,
    `store_name` varchar(128) NOT NULL,
    `store_description` text DEFAULT NULL,
    `status` varchar(16) NOT NULL DEFAULT 'pending',
    `reseller_status` varchar(16) DEFAULT 'pending',
    `store_status` varchar(16) DEFAULT 'pending',
    `reseller_review_reason` varchar(500) DEFAULT NULL,
    `store_review_reason` varchar(500) DEFAULT NULL,
    `reseller_reviewed_by` int(11) DEFAULT NULL,
    `reseller_reviewed_at` int(11) DEFAULT NULL,
    `store_reviewed_by` int(11) DEFAULT NULL,
    `store_reviewed_at` int(11) DEFAULT NULL,
    `last_login_at` bigint(20) DEFAULT NULL,
    `last_login_ip` varchar(45) DEFAULT NULL,
    `created_at` int(11) NOT NULL,
    `updated_at` int(11) NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `email` (`email`),
    UNIQUE KEY `store_slug` (`store_slug`),
    KEY `status` (`status`),
    KEY `reseller_status` (`reseller_status`),
    KEY `store_status` (`store_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `v2_reseller_review_log` (
    `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    `reseller_id` bigint(20) unsigned NOT NULL,
    `target_type` varchar(16) NOT NULL,
    `from_status` varchar(16) DEFAULT NULL,
    `to_status` varchar(16) NOT NULL,
    `reason` varchar(500) DEFAULT NULL,
    `operator_id` int(11) NOT NULL,
    `created_at` int(11) NOT NULL,
    PRIMARY KEY (`id`),
    KEY `reseller_target_created` (`reseller_id`,`target_type`,`created_at`),
    KEY `operator_created` (`operator_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `v2_reseller_plan_template` (
    `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    `base_plan_id` int(11) NOT NULL,
    `enabled` tinyint(1) NOT NULL DEFAULT '0',
    `sort` int(11) NOT NULL DEFAULT '0',
    `created_at` int(11) NOT NULL,
    `updated_at` int(11) NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `base_plan_id` (`base_plan_id`),
    KEY `enabled_sort` (`enabled`,`sort`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `v2_reseller_plan` (
    `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    `reseller_id` bigint(20) unsigned NOT NULL,
    `base_plan_id` int(11) NOT NULL,
    `name` varchar(255) NOT NULL,
    `content` text DEFAULT NULL,
    `month_price` int(11) DEFAULT NULL,
    `quarter_price` int(11) DEFAULT NULL,
    `half_year_price` int(11) DEFAULT NULL,
    `year_price` int(11) DEFAULT NULL,
    `two_year_price` int(11) DEFAULT NULL,
    `three_year_price` int(11) DEFAULT NULL,
    `onetime_price` int(11) DEFAULT NULL,
    `enabled` tinyint(1) NOT NULL DEFAULT '1',
    `sort` int(11) NOT NULL DEFAULT '0',
    `created_at` int(11) NOT NULL,
    `updated_at` int(11) NOT NULL,
    PRIMARY KEY (`id`),
    KEY `reseller_enabled_sort` (`reseller_id`,`enabled`,`sort`),
    KEY `base_plan_id` (`base_plan_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `v2_reseller_payment` (
    `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    `reseller_id` bigint(20) unsigned NOT NULL,
    `uuid` char(32) NOT NULL,
    `driver` varchar(64) NOT NULL,
    `name` varchar(255) NOT NULL,
    `config_encrypted` text NOT NULL,
    `enabled` tinyint(1) NOT NULL DEFAULT '0',
    `sort` int(11) NOT NULL DEFAULT '0',
    `created_at` int(11) NOT NULL,
    `updated_at` int(11) NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uuid` (`uuid`),
    KEY `reseller_enabled_sort` (`reseller_id`,`enabled`,`sort`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `v2_reseller_customer` (
    `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    `reseller_id` bigint(20) unsigned NOT NULL,
    `user_id` int(11) NOT NULL,
    `created_at` int(11) NOT NULL,
    `updated_at` int(11) NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `reseller_user` (`reseller_id`,`user_id`),
    KEY `user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `v2_reseller_order` (
    `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    `reseller_id` bigint(20) unsigned NOT NULL,
    `reseller_plan_id` bigint(20) unsigned NOT NULL,
    `reseller_payment_id` bigint(20) unsigned DEFAULT NULL,
    `platform_order_id` int(11) NOT NULL,
    `user_id` int(11) NOT NULL,
    `period` varchar(32) NOT NULL,
    `amount_snapshot` int(11) NOT NULL,
    `created_at` int(11) NOT NULL,
    `updated_at` int(11) NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `platform_order_id` (`platform_order_id`),
    KEY `reseller_user_created` (`reseller_id`,`user_id`,`created_at`),
    KEY `reseller_plan` (`reseller_id`,`reseller_plan_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
