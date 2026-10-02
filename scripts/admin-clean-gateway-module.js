    riskgatewaypage: function(e, t, n) {
        "use strict";
        n.r(t);
        // 订阅清洗网关。整块替换原「订阅风控网关」页面，与「订阅清洗网关（策略页）」
        // 合并成这一个栏目：一行 = 账号 × 订阅 × IP × User-Agent 的拉取记录。
        //
        // 页面不读取也不展示订阅凭证；时间一律由后端按 UTC+8 渲染好（*_text 字段），
        // 前端不做任何时区换算，筛选框里的时间也按 UTC+8 原样回传。
        var r = n("jehZ")
          , i = n.n(r)
          , o = (n("g9YV"), n("wCAj"))
          , a = (n("+L6B"), n("2/Rp"))
          , s = (n("5NDa"), n("5rEg"))
          , l = (n("Pwec"), n("CtXQ"))
          , c = (n("2qtc"), n("kLXV"))
          , u = (n("OaEy"), n("2fM7"))
          , y = (n("+BJd"), n("mr32"))
          , d = n("q1tI")
          , p = n.n(d)
          , m = n("Bl7J")
          , g = n("v32e")
          , w = n("wd/R");
        function gatewayUrl(path) {
            return "/" + window.settings.secure_path + path
        }
        function gatewayGet(path, params) {
            return Object(n("t3Un")["a"])(gatewayUrl(path), params)
        }
        function gatewayPost(path, params) {
            return Object(n("t3Un")["b"])(gatewayUrl(path), params)
        }
        // 空字符串一律不发给后端：后端把「参数缺失」和「参数为空」当同一件事处理，
        // 前端少发几个键能让 URL 与日志里少一堆噪声。
        function gatewayCompact(params) {
            var out = {};
            Object.keys(params || {}).forEach(function(key) {
                var value = params[key];
                if (null === value || void 0 === value || "" === value)
                    return;
                out[key] = value
            });
            return out
        }
        function gatewayPage(pagination) {
            var page = pagination || {}
              , current = Number(page.current)
              , pageSize = Number(page.pageSize);
            return {
                current: current > 0 ? current : 1,
                pageSize: pageSize > 0 ? pageSize : 20
            }
        }
        function gatewayScopeText(scope) {
            return {
                ip: "IP 地址",
                user_agent: "User-Agent",
                user: "账号",
                subscription: "订阅"
            }[scope] || scope || "-"
        }
        function gatewayRuleStatusText(status) {
            return {
                active: "生效中",
                expired: "已到期",
                released: "已解除"
            }[status] || status || "-"
        }
        // 订阅列表：本行那一条排在前面并高亮，其余陪衬 —— 需求要的是「这个账号买了
        // 哪些订阅」，同时还要能一眼看出这一行属于哪一条。
        function gatewaySubscriptions(record) {
            var list = record && record.subscriptions ? record.subscriptions.slice() : [];
            var currentId = Number(record && record.subscription_id || 0);
            list.sort(function(left, right) {
                var leftCurrent = Number(left.id) === currentId ? 0 : 1
                  , rightCurrent = Number(right.id) === currentId ? 0 : 1;
                return leftCurrent - rightCurrent || Number(left.id) - Number(right.id)
            });
            return list
        }
        function gatewaySubscriptionText(subscription) {
            var plan = subscription.plan_name || ("套餐 #" + subscription.plan_id);
            return "#" + subscription.id + " " + plan
        }
        // IP 下面那行。优先级：运营商三件套 → 国家/省/市 → 状态。
        //
        // 为什么需要地理这一档：全球 IP 库里相当一部分记录只给地理、不给 ISP
        // （海外 IP 尤其明显），只看运营商的话整格会写成「未知」，对排查毫无帮助 ——
        // 「这个 IP 在德国」比「未知」有用得多。
        //
        // 「解析中」和「未知」要分开：前者是这次还没查、后者是查过且库里没有，
        // 混成一个词会让运维以为库里真的没有这个 IP。
        function gatewayCarrierText(record) {
            if (!record)
                return "-";
            var parts = [];
            if (record.isp)
                parts.push(record.isp);
            if (record.organization && record.organization !== record.isp)
                parts.push(record.organization);
            if (record.asn)
                parts.push("AS" + record.asn);
            if (parts.length)
                return parts.join(" · ");
            if (record.country_name)
                parts.push(record.country_name);
            if (record.region && record.region !== record.country_name)
                parts.push(record.region);
            if (record.city && record.city !== record.region)
                parts.push(record.city);
            if (parts.length)
                return parts.join(" · ");
            return "pending" === record.location_status ? "解析中" : "未知"
        }
        // 留存统计的一格。标签与值是两个独立文本节点，这样翻译层只会翻标签；
        // 拼成一整句的话整句都不在字典里，英文界面会留一段中文。
        function statItem(label, value) {
            return p.a.createElement("div", {
                className: "mr-4"
            }, p.a.createElement("span", {
                className: "text-muted"
            }, label, "："), p.a.createElement("span", {
                className: "font-w600"
            }, String(value)))
        }
        function gatewayTimeText(value) {
            return value ? String(value) : "-"
        }
        // 百分比去掉无意义的尾零：87.50% 读起来像精确值，87.5% 才是人写的。
        // 后端给的是 decimal(5,2)，JSON 里就是 87.5 / 100 这样的数。
        function gatewayPercentText(value) {
            if (null === value || void 0 === value || "" === value)
                return "";
            var number = Number(value);
            if (isNaN(number))
                return String(value);
            return String(Math.round(number * 100) / 100)
        }
        // g["a"]（产物里的 v32e）**不是 Card**，而是一个把 antd Spin 包了一层的容器：
        //     spinning: this.props.loading
        // 而 antd 的 Spin 在 `spinning` 为 undefined 时是**默认转圈**的。所以这个容器
        // 必须显式传 loading: !1 —— 图省事写成 createElement(g["a"], null, ...) 的话，
        // 整块内容会被套进一层永不停止的 Spin，而 antd 给转圈容器加的 .ant-spin-blur
        // 是 opacity: .5 + pointer-events: none，表现就是「整页发灰、什么都点不动」，
        // 且侧栏（在容器之外）照常可点。各表格自己有 loading，外层只需要别转。
        var SPIN_OFF = {
            loading: !1
        };
        var CLEAN_FILTER_KEYS = ["email", "user_id", "plan_id", "subscription_id", "ip", "carrier", "asn", "ua_hash", "user_agent", "hit_condition", "hit_count", "risk_condition", "risk_percent", "blocked", "start_time", "end_time"];
        var CLEAN_EMPTY_FILTERS = {
            email: "",
            user_id: "",
            plan_id: void 0,
            subscription_id: "",
            ip: "",
            carrier: "",
            asn: "",
            ua_hash: void 0,
            user_agent: "",
            hit_condition: ">=",
            hit_count: "",
            risk_condition: ">=",
            risk_percent: "",
            blocked: void 0,
            start_time: "",
            end_time: ""
        };
        var CLEAN_HIT_CONDITIONS = [">=", ">", "=", "<", "<="];
        var CLEAN_BLOCKED_OPTIONS = [{
            key: "已阻断",
            value: "yes"
        }, {
            key: "未阻断",
            value: "no"
        }];
        var CLEAN_SCOPE_ORDER = ["ip", "user_agent", "user", "subscription"];
        class SubscribeCleanGatewayPage extends p.a.Component {
            constructor(props) {
                super(props),
                this.state = {
                    filters: i()({}, CLEAN_EMPTY_FILTERS),
                    // 输入框改动即改 state，但只有点「查询」才发请求：每次按键都打后端
                    // 会让筛选变成击键查询。
                    data: [],
                    total: 0,
                    loading: !0,
                    available: !0,
                    pagination: {
                        current: 1,
                        pageSize: 20,
                        total: 0
                    },
                    sort: "last_seen_at",
                    sortDir: "desc",
                    options: {
                        plans: [],
                        scopes: [],
                        // UA 下拉的候选：后端按 ua_hash 去重、按累计拉取次数倒序，最多 500 条。
                        user_agents: [],
                        retention_days: 180,
                        retention_min: 1,
                        retention_default: 180
                    },
                    uaTruncated: !1,
                    stats: {
                        records: 0,
                        raw_logs: 0,
                        earliest_text: "",
                        last_cleaned_text: ""
                    },
                    retentionInput: "",
                    retentionSaving: !1,
                    exporting: !1,
                    blockVisible: !1,
                    blockRecord: null,
                    blockScope: "ip",
                    blockReason: "",
                    blockExpires: "",
                    blockSaving: !1,
                    rules: [],
                    rulesTotal: 0,
                    rulesLoading: !0,
                    rulesPagination: {
                        current: 1,
                        pageSize: 10,
                        total: 0
                    },
                    history: [],
                    historyTotal: 0,
                    historyLoading: !0,
                    historyPagination: {
                        current: 1,
                        pageSize: 10,
                        total: 0
                    },
                    // 待处理风险账号：未处理且风险达到阈值的账号。与列表的「风险程度」
                    // 同源，但这里是待办视角，也是每 15 分钟那封提醒的收件名单。
                    risk: [],
                    riskTotal: 0,
                    riskLoading: !0,
                    riskThreshold: 60,
                    riskPagination: {
                        current: 1,
                        pageSize: 10,
                        total: 0
                    }
                }
            }
            componentDidMount() {
                this.fetchOptions(),
                this.fetchConfig(),
                this.fetch(),
                this.fetchRules(),
                this.fetchHistory(),
                this.fetchRisk()
            }
            setFilter(key, value) {
                var patch = {};
                patch[key] = value,
                this.setState({
                    filters: i()({}, this.state.filters, patch)
                })
            }
            resetFilters() {
                this.setState({
                    filters: i()({}, CLEAN_EMPTY_FILTERS),
                    pagination: i()({}, this.state.pagination, {
                        current: 1
                    })
                }, ()=>this.fetch(1))
            }
            // 只把非空条件发出去。sort/sort_dir 单独拼，不混进筛选对象 —— 导出要用
            // 同一份筛选条件，但导出按固定顺序（不跟随当前排序）走。
            requestParams(extra) {
                var filters = this.state.filters
                  , params = {}
                  , self = this;
                CLEAN_FILTER_KEYS.forEach(function(key) {
                    var value = filters[key];
                    if (null === value || void 0 === value || "" === value)
                        return;
                    params[key] = value
                });
                params.sort = self.state.sort,
                params.sort_dir = self.state.sortDir;
                return i()({}, params, gatewayCompact(extra || {}))
            }
            filtersOnly() {
                var params = this.requestParams();
                return gatewayCompact({
                    email: params.email,
                    user_id: params.user_id,
                    plan_id: params.plan_id,
                    subscription_id: params.subscription_id,
                    ip: params.ip,
                    carrier: params.carrier,
                    asn: params.asn,
                    ua_hash: params.ua_hash,
                    user_agent: params.user_agent,
                    hit_condition: params.hit_condition,
                    hit_count: params.hit_count,
                    risk_condition: params.risk_condition,
                    risk_percent: params.risk_percent,
                    blocked: params.blocked,
                    start_time: params.start_time,
                    end_time: params.end_time
                })
            }
            fetchOptions() {
                var self = this;
                gatewayGet("/risk/gateway/options").then(function(res) {
                    if (200 !== res.code || !res.data)
                        return;
                    var data = res.data;
                    self.setState({
                        options: {
                            plans: data.plans || [],
                            scopes: data.scopes || [],
                            user_agents: data.user_agents || [],
                            retention_days: Number(data.retention_days || 0),
                            retention_min: Number(data.retention_min || 1),
                            retention_default: Number(data.retention_default || 180),
                            risk_threshold: Number(null === data.risk_threshold || void 0 === data.risk_threshold ? 60 : data.risk_threshold)
                        },
                        uaTruncated: !0 === data.user_agents_truncated,
                        retentionInput: String(Number(data.retention_days || 0)),
                        riskThreshold: Number(null === data.risk_threshold || void 0 === data.risk_threshold ? 60 : data.risk_threshold)
                    })
                }).catch(function() {
                    // 没有 loading 要清，但也不能让 rejection 悬着：控制台会多一条
                    // unhandled rejection，排查真问题时是噪声。下拉空了不影响主流程。
                })
            }
            fetchConfig() {
                var self = this;
                gatewayGet("/risk/gateway/config").then(function(res) {
                    if (200 !== res.code || !res.data)
                        return;
                    self.setState({
                        stats: res.data,
                        retentionInput: String(Number(res.data.retention_days || 0))
                    })
                }).catch(function() {
                    // 同上：留存统计读不到就显示默认值，不弹错、不阻塞。
                })
            }
            fetch(page) {
                var self = this
                  , pagination = gatewayPage(this.state.pagination)
                  , target = page || pagination.current;
                // 参数先拼好，再置 loading。反过来的话，requestParams() 一旦抛异常
                // （状态被外部改坏、字典表缺失等），loading 会永远停在 true，
                // 表格就一直盖着那层吞点击的 ant-spin-blur。
                var params = i()({}, this.requestParams(), {
                    current: target,
                    pageSize: pagination.pageSize
                });
                this.setState({
                    loading: !0,
                    pagination: i()({}, this.state.pagination, {
                        current: target
                    })
                });
                gatewayGet("/risk/gateway/fetch", params).then(function(res) {
                    if (200 !== res.code) {
                        self.setState({
                            loading: !1
                        });
                        return
                    }
                    self.setState({
                        data: res.data || [],
                        total: Number(res.total || 0),
                        loading: !1,
                        available: !1 !== res.available,
                        pagination: i()({}, self.state.pagination, {
                            current: Number(res.page || target),
                            pageSize: Number(res.pageSize || pagination.pageSize),
                            total: Number(res.total || 0)
                        })
                    })
                }).catch(function() {
                    self.setState({
                        loading: !1
                    }),
                    c["a"].error({
                        title: "请求失败",
                        content: "读取订阅拉取记录失败，请稍后重试"
                    })
                })
            }
            tableChange(pagination, filters, sorter) {
                var nextPage = gatewayPage(pagination)
                  , patch = {
                    pagination: i()({}, this.state.pagination, {
                        current: nextPage.current,
                        pageSize: nextPage.pageSize
                    })
                };
                if (sorter && sorter.field && sorter.order) {
                    patch.sort = sorter.field,
                    patch.sortDir = "ascend" === sorter.order ? "asc" : "desc"
                }
                this.setState(patch, ()=>this.fetch(nextPage.current))
            }
            exportCsv() {
                var self = this;
                this.setState({
                    exporting: !0
                });
                // 导出可能跑几十秒（全量分块拼 CSV），按钮上的 loading 是唯一的进度提示，
                // 不再叠一层 Modal —— kLXV 是 antd 的 Modal，没有 message.loading。
                return gatewayPost("/risk/gateway/export", this.filtersOnly()).then(function(res) {
                    self.setState({
                        exporting: !1
                    });
                    if (200 !== res.code || !res.buffer) {
                        c["a"].error({
                            title: "导出失败",
                            content: "服务端没有返回可下载的数据"
                        });
                        return
                    }
                    var blob = new Blob([res.buffer], {
                        type: "text/csv;charset=UTF-8"
                    })
                      , url = window.URL.createObjectURL(blob)
                      , link = document.createElement("a");
                    link.href = url,
                    link.download = "订阅清洗网关-" + w().format("YYYYMMDD-HHmmss") + ".csv",
                    document.body.appendChild(link),
                    link.click(),
                    document.body.removeChild(link),
                    window.URL.revokeObjectURL(url),
                    c["a"].success({
                        title: "导出完成",
                        content: "导出的是当前筛选条件下的全部记录。"
                    })
                }).catch(function() {
                    self.setState({
                        exporting: !1
                    }),
                    c["a"].error({
                        title: "导出失败",
                        content: "请稍后重试"
                    })
                })
            }
            saveRetention() {
                var self = this
                  , raw = String(this.state.retentionInput === null || void 0 === this.state.retentionInput ? "" : this.state.retentionInput).trim();
                if (!/^\d+$/.test(raw)) {
                    c["a"].warning({
                        title: "提示",
                        content: "留存天数只能填数字，0 表示永久保留。"
                    });
                    return
                }
                var min = Number(this.state.options.retention_min || 1);
                if (0 !== Number(raw) && Number(raw) < min) {
                    c["a"].warning({
                        title: "提示",
                        content: "留存天数不能小于 " + min + " 天；填 0 表示永久保留。"
                    });
                    return
                }
                this.setState({
                    retentionSaving: !0
                });
                return gatewayPost("/risk/gateway/config/save", {
                    retention_days: Number(raw)
                }).then(function(res) {
                    self.setState({
                        retentionSaving: !1
                    });
                    if (200 !== res.code)
                        return;
                    c["a"].success({
                        title: "已保存",
                        content: "超出留存期的拉取记录会在每天 0:40 的清理任务中被删除。"
                    }),
                    self.setState({
                        stats: i()({}, self.state.stats, {
                            retention_days: Number(raw)
                        })
                    }),
                    self.fetchConfig()
                }).catch(function() {
                    self.setState({
                        retentionSaving: !1
                    }),
                    c["a"].error({
                        title: "保存失败",
                        content: "请检查 config 目录是否可写。"
                    })
                })
            }
            openBlock(record) {
                var scopes = []
                  , self = this;
                CLEAN_SCOPE_ORDER.forEach(function(scope) {
                    if ("subscription" === scope && !(Number(record.subscription_id) > 0))
                        return;
                    scopes.push(scope)
                });
                this.setState({
                    blockVisible: !0,
                    blockRecord: record,
                    blockScope: scopes.length ? scopes[0] : "ip",
                    blockReason: "",
                    blockExpires: ""
                })
            }
            closeBlock() {
                this.setState({
                    blockVisible: !1,
                    blockRecord: null
                })
            }
            submitBlock() {
                var self = this
                  , record = this.state.blockRecord;
                if (!record)
                    return;
                var reason = String(this.state.blockReason || "").trim();
                if (!reason) {
                    c["a"].warning({
                        title: "提示",
                        content: "请填写阻断原因，这条原因会写进操作留痕。"
                    });
                    return
                }
                var payload = {
                    summary_id: record.id,
                    scope: this.state.blockScope,
                    reason: reason
                }
                  , expires = String(this.state.blockExpires || "").trim();
                if (expires)
                    payload.expires_at = expires;
                this.setState({
                    blockSaving: !0
                });
                return gatewayPost("/risk/gateway/block", payload).then(function(res) {
                    self.setState({
                        blockSaving: !1
                    });
                    if (200 !== res.code)
                        return;
                    c["a"].success({
                        title: "已阻断",
                        content: "命中该条件的所有订阅请求都会收到 500 错误。"
                    }),
                    self.setState({
                        blockVisible: !1,
                        blockRecord: null
                    }),
                    self.fetch(),
                    self.fetchRules()
                }).catch(function() {
                    self.setState({
                        blockSaving: !1
                    })
                })
            }
            releaseRule(rule) {
                var self = this;
                c["a"].confirm({
                    title: "解除阻断",
                    content: "解除后该目标立即恢复正常下发。",
                    okText: "确认解除",
                    cancelText: "取消",
                    onOk() {
                        return gatewayPost("/risk/gateway/release", {
                            id: rule.id
                        }).then(function(res) {
                            if (200 !== res.code)
                                return;
                            c["a"].success({
                                title: "已解除",
                                content: "该目标已从阻断名单移除。"
                            }),
                            self.fetch(),
                            self.fetchRules()
                        })
                    }
                })
            }
            fetchRules(page) {
                var self = this
                  , pagination = gatewayPage(this.state.rulesPagination)
                  , target = page || pagination.current;
                this.setState({
                    rulesLoading: !0
                });
                gatewayGet("/risk/gateway/rules", {
                    status: "active",
                    current: target,
                    pageSize: pagination.pageSize
                }).then(function(res) {
                    if (200 !== res.code) {
                        self.setState({
                            rulesLoading: !1
                        });
                        return
                    }
                    self.setState({
                        rules: res.data || [],
                        rulesTotal: Number(res.total || 0),
                        rulesLoading: !1,
                        rulesPagination: i()({}, self.state.rulesPagination, {
                            current: target,
                            total: Number(res.total || 0)
                        })
                    })
                }).catch(function() {
                    // 必须清 loading：antd 的 Table 在 loading 时会给自己那层容器加
                    // ant-spin-blur，那是 opacity: 0.5 + pointer-events: none —— 表还在
                    // 屏幕上，但整块点不动、也输入不了。请求被拒（网络抖动、网关掐连接、
                    // 响应不是合法 JSON）时若不清，这张表就永远卡在「转圈且不可操作」。
                    self.setState({
                        rulesLoading: !1
                    }),
                    c["a"].error({
                        title: "请求失败",
                        content: "读取阻断名单失败，请稍后重试"
                    })
                })
            }
            fetchHistory(page) {
                var self = this
                  , pagination = gatewayPage(this.state.historyPagination)
                  , target = page || pagination.current;
                this.setState({
                    historyLoading: !0
                });
                gatewayGet("/risk/gateway/history", {
                    current: target,
                    pageSize: pagination.pageSize
                }).then(function(res) {
                    if (200 !== res.code) {
                        self.setState({
                            historyLoading: !1
                        });
                        return
                    }
                    self.setState({
                        history: res.data || [],
                        historyTotal: Number(res.total || 0),
                        historyLoading: !1,
                        historyPagination: i()({}, self.state.historyPagination, {
                            current: target,
                            total: Number(res.total || 0)
                        })
                    })
                }).catch(function() {
                    // 同 fetchRules：不清 loading 的话这张表会一直覆盖着不可点的模糊层。
                    self.setState({
                        historyLoading: !1
                    }),
                    c["a"].error({
                        title: "请求失败",
                        content: "读取阻断操作留痕失败，请稍后重试"
                    })
                })
            }
            fetchRisk(page) {
                var self = this
                  , pagination = gatewayPage(this.state.riskPagination)
                  , target = page || pagination.current;
                this.setState({
                    riskLoading: !0
                });
                gatewayGet("/risk/gateway/risk", {
                    current: target,
                    pageSize: pagination.pageSize
                }).then(function(res) {
                    if (200 !== res.code) {
                        self.setState({
                            riskLoading: !1
                        });
                        return
                    }
                    var threshold = Number(null === res.threshold || void 0 === res.threshold ? self.state.riskThreshold : res.threshold);
                    self.setState({
                        risk: res.data || [],
                        riskTotal: Number(res.total || 0),
                        riskThreshold: threshold,
                        riskLoading: !1,
                        riskPagination: i()({}, self.state.riskPagination, {
                            current: target,
                            total: Number(res.total || 0)
                        })
                    })
                }).catch(function() {
                    self.setState({
                        riskLoading: !1
                    }),
                    c["a"].error({
                        title: "请求失败",
                        content: "读取待处理风险账号失败，请稍后重试"
                    })
                })
            }
            // 「未处理就一直提醒」的终止动作。标记之后这个账号不再出现在待办里，
            // 也不再产生提醒 —— 所以确认文案要把这件事说清楚。
            handleRisk(row) {
                var self = this;
                c["a"].confirm({
                    title: "标记已处理",
                    content: "标记后该账号不再出现在待处理列表，也不会再收到风险提醒。风险程度本身继续照常统计。",
                    okText: "确认标记",
                    cancelText: "取消",
                    onOk() {
                        return gatewayPost("/risk/gateway/risk/handle", {
                            user_id: row.user_id
                        }).then(function(res) {
                            if (200 !== res.code)
                                return;
                            c["a"].success({
                                title: "已标记",
                                content: row.user_email || ("#" + row.user_id)
                            }),
                            self.fetchRisk(),
                            self.fetch()
                        })
                    }
                })
            }
            renderFilterBar() {
                var self = this
                  , filters = this.state.filters
                  , plans = this.state.options.plans || []
                  , planOptions = plans.map(function(plan) {
                    return p.a.createElement(u["a"].Option, {
                        key: String(plan.id),
                        value: String(plan.id)
                    }, "#" + plan.id + " " + plan.name)
                });
                var field = function(label, control) {
                    return p.a.createElement("div", {
                        className: "col-md-6 col-xl-3 mb-2"
                    }, p.a.createElement("label", {
                        className: "font-w600 small mb-1 d-block"
                    }, label), control)
                };
                return p.a.createElement("div", {
                    className: "row"
                },
                    field("账号（邮箱）", p.a.createElement(s["a"], {
                        allowClear: !0,
                        placeholder: "支持模糊匹配",
                        value: filters.email,
                        onChange: e=>this.setFilter("email", e.target.value),
                        onPressEnter: ()=>this.fetch(1)
                    })),
                    field("账号 ID", p.a.createElement(s["a"], {
                        allowClear: !0,
                        placeholder: "精确匹配",
                        value: filters.user_id,
                        onChange: e=>this.setFilter("user_id", e.target.value),
                        onPressEnter: ()=>this.fetch(1)
                    })),
                    field("套餐", p.a.createElement(u["a"], {
                        allowClear: !0,
                        placeholder: "全部套餐",
                        style: {
                            width: "100%"
                        },
                        value: filters.plan_id,
                        onChange: e=>this.setFilter("plan_id", e)
                    }, planOptions)),
                    field("订阅 ID", p.a.createElement(s["a"], {
                        allowClear: !0,
                        placeholder: "精确匹配",
                        value: filters.subscription_id,
                        onChange: e=>this.setFilter("subscription_id", e.target.value),
                        onPressEnter: ()=>this.fetch(1)
                    })),
                    field("IP 地址", p.a.createElement(s["a"], {
                        allowClear: !0,
                        placeholder: "完整 IP 精确匹配，否则模糊",
                        value: filters.ip,
                        onChange: e=>this.setFilter("ip", e.target.value),
                        onPressEnter: ()=>this.fetch(1)
                    })),
                    field("运营商 / 归属机构", p.a.createElement(s["a"], {
                        allowClear: !0,
                        placeholder: "支持模糊匹配",
                        value: filters.carrier,
                        onChange: e=>this.setFilter("carrier", e.target.value),
                        onPressEnter: ()=>this.fetch(1)
                    })),
                    field("ASN", p.a.createElement(s["a"], {
                        allowClear: !0,
                        placeholder: "如 4134 或 AS4134",
                        value: filters.asn,
                        onChange: e=>this.setFilter("asn", e.target.value),
                        onPressEnter: ()=>this.fetch(1)
                    })),
                    // 下拉选择而不是手输。值走 ua_hash —— 同一个客户端的大小写变体在库里
                    // 是同一个 hash，按原文列会出现两个长得一样的选项、选一个又只筛出
                    // 一部分行。showSearch 让候选多时能直接打字缩小范围。
                    field("User-Agent", p.a.createElement(u["a"], {
                        allowClear: !0,
                        showSearch: !0,
                        optionFilterProp: "children",
                        placeholder: "选择或输入关键字搜索",
                        style: {
                            width: "100%"
                        },
                        value: filters.ua_hash || void 0,
                        onChange: e=>this.setFilter("ua_hash", e)
                    }, (this.state.options.user_agents || []).map(function(option) {
                        return p.a.createElement(u["a"].Option, {
                            key: option.value,
                            value: option.value
                        }, String(option.user_agent) + "（" + Number(option.hits || 0) + " 次）")
                    }))),
                    field("拉取次数", p.a.createElement(s["a"].Group, {
                        compact: !0,
                        style: {
                            width: "100%"
                        }
                    }, p.a.createElement(u["a"], {
                        style: {
                            width: "30%"
                        },
                        value: filters.hit_condition,
                        onChange: e=>this.setFilter("hit_condition", e)
                    }, CLEAN_HIT_CONDITIONS.map(function(condition) {
                        return p.a.createElement(u["a"].Option, {
                            key: condition,
                            value: condition
                        }, condition)
                    })), p.a.createElement(s["a"], {
                        style: {
                            width: "70%"
                        },
                        allowClear: !0,
                        placeholder: "次数",
                        value: filters.hit_count,
                        onChange: e=>this.setFilter("hit_count", e.target.value),
                        onPressEnter: ()=>this.fetch(1)
                    }))),
                    field("风险程度（%）", p.a.createElement(s["a"].Group, {
                        compact: !0,
                        style: {
                            width: "100%"
                        }
                    }, p.a.createElement(u["a"], {
                        style: {
                            width: "30%"
                        },
                        value: filters.risk_condition,
                        onChange: e=>this.setFilter("risk_condition", e)
                    }, CLEAN_HIT_CONDITIONS.map(function(condition) {
                        return p.a.createElement(u["a"].Option, {
                            key: condition,
                            value: condition
                        }, condition)
                    })), p.a.createElement(s["a"], {
                        style: {
                            width: "70%"
                        },
                        allowClear: !0,
                        placeholder: "如 60",
                        value: filters.risk_percent,
                        onChange: e=>this.setFilter("risk_percent", e.target.value),
                        onPressEnter: ()=>this.fetch(1)
                    }))),
                    field("阻断状态", p.a.createElement(u["a"], {
                        allowClear: !0,
                        placeholder: "全部",
                        style: {
                            width: "100%"
                        },
                        value: filters.blocked,
                        onChange: e=>this.setFilter("blocked", e)
                    }, CLEAN_BLOCKED_OPTIONS.map(function(option) {
                        return p.a.createElement(u["a"].Option, {
                            key: option.value,
                            value: option.value
                        }, option.key)
                    }))),
                    field("开始时间（UTC+8）", p.a.createElement(s["a"], {
                        allowClear: !0,
                        placeholder: "YYYY-MM-DD HH:mm",
                        value: filters.start_time,
                        onChange: e=>this.setFilter("start_time", e.target.value),
                        onPressEnter: ()=>this.fetch(1)
                    })),
                    field("结束时间（UTC+8）", p.a.createElement(s["a"], {
                        allowClear: !0,
                        placeholder: "YYYY-MM-DD HH:mm",
                        value: filters.end_time,
                        onChange: e=>this.setFilter("end_time", e.target.value),
                        onPressEnter: ()=>this.fetch(1)
                    })),
                    p.a.createElement("div", {
                        className: "col-md-6 col-xl-3 mb-2 d-flex align-items-end"
                    }, p.a.createElement(a["a"], {
                        type: "primary",
                        icon: "search",
                        className: "mr-2",
                        onClick: ()=>this.fetch(1)
                    }, "查询"), p.a.createElement(a["a"], {
                        icon: "reload",
                        className: "mr-2",
                        onClick: ()=>this.resetFilters()
                    }, "重置"), p.a.createElement(a["a"], {
                        type: "success",
                        icon: "download",
                        loading: this.state.exporting,
                        onClick: ()=>this.exportCsv()
                    }, "导出 CSV")))
            }
            renderRetention() {
                var self = this
                  , options = this.state.options
                  , stats = this.state.stats
                  , days = Number(stats.retention_days === null || void 0 === stats.retention_days ? options.retention_days : stats.retention_days)
                  // 后端给行数设了上界（COUNT 到两万就停）。触顶时补一个「+」，
                  // 免得把「至少两万」当成精确值念。
                  , countSuffix = stats.counts_capped ? "+" : "";
                return p.a.createElement("div", {
                    className: "row align-items-end"
                }, p.a.createElement("div", {
                    className: "col-md-4 col-xl-3 mb-2"
                }, p.a.createElement("label", {
                    className: "font-w600 small mb-1 d-block"
                }, "日志留存天数（UTC+8 自然日）"), p.a.createElement(s["a"].Group, {
                    compact: !0,
                    style: {
                        width: "100%"
                    }
                }, p.a.createElement(s["a"], {
                    style: {
                        width: "60%"
                    },
                    value: this.state.retentionInput,
                    onChange: e=>this.setState({
                        retentionInput: e.target.value
                    })
                }), p.a.createElement(a["a"], {
                    style: {
                        width: "40%"
                    },
                    type: "primary",
                    loading: this.state.retentionSaving,
                    onClick: ()=>this.saveRetention()
                }, "保存"))), p.a.createElement("div", {
                    className: "col-md-8 col-xl-9 mb-2 text-muted small"
                }, p.a.createElement("div", null, "0 表示永久保留，上限 3650 天。超出留存期的拉取记录与清洗网关列表会一起被清理，页面看到的窗口与这个设置严格一致。"), p.a.createElement("div", {
                    className: "d-flex flex-wrap"
                }, statItem("当前生效（天）", days), statItem("列表行数", Number(stats.records || 0) + countSuffix), statItem("原始审计", Number(stats.raw_logs || 0) + countSuffix), stats.earliest_text ? statItem("最早一条", stats.earliest_text) : null, stats.last_cleaned_text ? statItem("上次清理", stats.last_cleaned_text) : null)))
            }
            renderTable() {
                var self = this
                  , columns = [{
                    title: "账号",
                    dataIndex: "user_email",
                    key: "user_email",
                    width: 220,
                    render: (value, record)=>p.a.createElement("div", null, p.a.createElement("div", {
                        className: "font-w600"
                    }, value || "-"), p.a.createElement("div", {
                        className: "text-muted small"
                    }, "#" + record.user_id))
                }, {
                    // 账号级指标：阻断次数 ÷ 拉取总次数。同一个账号的每一行都是同一个值
                    // （需求就是按账号算的）。超过阈值标红，与待处理区块同一个判据。
                    title: "风险程度",
                    dataIndex: "risk_percent",
                    key: "risk_percent",
                    width: 150,
                    render: (value, record)=>{
                        if (null === value || void 0 === value)
                            return p.a.createElement("span", {
                                className: "text-muted"
                            }, "—");
                        var percent = Number(value);
                        var over = !isNaN(percent) && percent >= Number(self.state.riskThreshold);
                        return p.a.createElement("div", null, p.a.createElement("div", {
                            className: "font-w600",
                            style: over ? {
                                color: "#f86c6b"
                            } : null
                        }, gatewayPercentText(value) + "%"), p.a.createElement("div", {
                            className: "text-muted small"
                        }, Number(record.risk_blocked_count || 0), " / ", Number(record.risk_total_count || 0)), record.risk_handled_at ? p.a.createElement("div", {
                            className: "text-muted small"
                        }, "已处理") : null)
                    }
                }, {
                    title: "订阅",
                    dataIndex: "subscriptions",
                    key: "subscriptions",
                    width: 240,
                    render: (value, record)=>{
                        var list = gatewaySubscriptions(record);
                        if (!list.length)
                            return p.a.createElement("span", {
                                className: "text-muted"
                            }, "无订阅");
                        var currentId = Number(record.subscription_id || 0);
                        return p.a.createElement("div", null, list.map(function(subscription) {
                            var isCurrent = Number(subscription.id) === currentId;
                            return p.a.createElement("div", {
                                key: String(subscription.id)
                            }, p.a.createElement(y["a"], {
                                color: isCurrent ? "blue" : "default",
                                className: "mb-1"
                            }, gatewaySubscriptionText(subscription)), "active" !== subscription.status ? p.a.createElement("span", {
                                className: "text-muted small ml-1"
                            }, subscription.status) : null)
                        }))
                    }
                }, {
                    title: "IP 记录",
                    dataIndex: "request_ip",
                    key: "request_ip",
                    width: 220,
                    render: (value, record)=>p.a.createElement("div", null, p.a.createElement("div", {
                        className: "font-w600"
                    }, value || "-"), p.a.createElement("div", {
                        className: "text-muted small"
                    }, gatewayCarrierText(record)))
                }, {
                    title: "User-Agent",
                    dataIndex: "user_agent",
                    key: "user_agent",
                    render: value=>p.a.createElement("div", {
                        style: {
                            wordBreak: "break-all"
                        },
                        title: value || ""
                    }, value || "-")
                }, {
                    title: "次数",
                    dataIndex: "hit_count",
                    key: "hit_count",
                    width: 100,
                    sorter: !0,
                    sortOrder: "hit_count" === self.state.sort ? ("asc" === self.state.sortDir ? "ascend" : "descend") : null,
                    render: value=>p.a.createElement("span", {
                        className: "font-w600"
                    }, Number(value || 0))
                }, {
                    title: "时间（UTC+8）",
                    // dataIndex 用 last_seen_at（而不是渲染用的 last_seen_text）：
                    // antd 表头排序传回来的 sorter.field 就是 dataIndex，后端只认
                    // last_seen_at，写 text 会永远落到默认排序。渲染改从 record 取。
                    dataIndex: "last_seen_at",
                    key: "last_seen_at",
                    width: 200,
                    sorter: !0,
                    sortOrder: "last_seen_at" === self.state.sort ? ("asc" === self.state.sortDir ? "ascend" : "descend") : null,
                    render: (value, record)=>p.a.createElement("div", null, p.a.createElement("div", null, gatewayTimeText(record.last_seen_text)), p.a.createElement("div", {
                        className: "text-muted small"
                    }, "首次", " ", gatewayTimeText(record.first_seen_text)))
                }, {
                    title: "阻断状态",
                    dataIndex: "block",
                    key: "block",
                    width: 150,
                    render: (value, record)=>{
                        if (!value)
                            return p.a.createElement(y["a"], {
                                color: "green"
                            }, "未阻断");
                        return p.a.createElement("div", null, p.a.createElement(y["a"], {
                            color: "red",
                            title: value.reason || ""
                        }, "已阻断", " · ", gatewayScopeText(value.scope)), p.a.createElement("div", {
                            className: "text-muted small"
                        }, value.expires_at_text ? ["至 ", value.expires_at_text] : "永久"))
                    }
                }, {
                    title: "操作",
                    key: "action",
                    width: 160,
                    render: (value, record)=>{
                        if (record.block) {
                            return p.a.createElement(a["a"], {
                                size: "small",
                                onClick: ()=>this.releaseRule(record.block)
                            }, "解除阻断")
                        }
                        return p.a.createElement(a["a"], {
                            size: "small",
                            type: "danger",
                            onClick: ()=>this.openBlock(record)
                        }, "阻断")
                    }
                }];
                return p.a.createElement(o["a"], {
                    rowKey: row=>String(row.id),
                    loading: this.state.loading,
                    columns: columns,
                    dataSource: this.state.data,
                    onChange: (pagination, filters, sorter)=>this.tableChange(pagination, filters, sorter),
                    pagination: {
                        current: this.state.pagination.current,
                        pageSize: this.state.pagination.pageSize,
                        total: this.state.total,
                        showSizeChanger: !0,
                        showTotal: value=>"共 " + value + " 条"
                    },
                    scroll: {
                        x: 1500
                    }
                })
            }
            // 待处理风险账号。列的就是每 15 分钟那封提醒的收件名单 —— 处理完（点右边
            // 的按钮）才会从这张表里消失，这是「未处理就一直提醒」唯一的终止动作。
            renderRiskPending() {
                var self = this
                  , columns = [{
                    title: "账号",
                    dataIndex: "user_email",
                    key: "user_email",
                    width: 240,
                    render: (value, record)=>p.a.createElement("div", null, p.a.createElement("div", {
                        className: "font-w600"
                    }, value || "-"), p.a.createElement("div", {
                        className: "text-muted small"
                    }, "#" + record.user_id))
                }, {
                    title: "风险程度",
                    dataIndex: "risk_percent",
                    key: "risk_percent",
                    width: 130,
                    render: value=>p.a.createElement("span", {
                        className: "font-w600",
                        style: {
                            color: "#f86c6b"
                        }
                    }, gatewayPercentText(value) + "%")
                }, {
                    title: "阻断 / 总次数",
                    key: "ratio",
                    width: 140,
                    render: (value, record)=>p.a.createElement("span", null, Number(record.blocked_count || 0), " / ", Number(record.total_count || 0))
                }, {
                    title: "最近拉取（UTC+8）",
                    dataIndex: "last_seen_text",
                    key: "last_seen_text",
                    width: 180,
                    render: value=>gatewayTimeText(value)
                }, {
                    title: "已提醒",
                    key: "notify",
                    width: 200,
                    render: (value, record)=>p.a.createElement("div", null, p.a.createElement("div", null, Number(record.notify_count || 0) + " 次"), p.a.createElement("div", {
                        className: "text-muted small"
                    }, record.notified_at_text ? ["最近 ", record.notified_at_text] : "尚未发送"))
                }, {
                    title: "操作",
                    key: "action",
                    width: 130,
                    render: (value, record)=>p.a.createElement(a["a"], {
                        size: "small",
                        type: "primary",
                        onClick: ()=>this.handleRisk(record)
                    }, "标记已处理")
                }];
                return p.a.createElement(o["a"], {
                    rowKey: row=>"risk-" + String(row.user_id),
                    size: "small",
                    loading: this.state.riskLoading,
                    columns: columns,
                    dataSource: this.state.risk,
                    pagination: {
                        current: this.state.riskPagination.current,
                        pageSize: this.state.riskPagination.pageSize,
                        total: this.state.riskTotal
                    },
                    onChange: pagination=>this.fetchRisk(pagination.current)
                })
            }
            renderRules() {
                var self = this
                  , columns = [{
                    title: "阻断目标",
                    dataIndex: "target",
                    key: "target",
                    render: (value, record)=>{
                        var text = "user" === record.scope ? record.user_email || ("#" + record.user_id) : "subscription" === record.scope ? "#" + record.subscription_id + " " + (record.subscription_label || "") : "ip" === record.scope ? record.ip : record.user_agent;
                        return p.a.createElement("div", null, p.a.createElement("div", {
                            className: "font-w600"
                        }, text || "-"), p.a.createElement("div", {
                            className: "text-muted small"
                        }, gatewayScopeText(record.scope)))
                    }
                }, {
                    title: "原因",
                    dataIndex: "reason",
                    key: "reason"
                }, {
                    title: "阻断时间（UTC+8）",
                    dataIndex: "blocked_at_text",
                    key: "blocked_at_text",
                    width: 190
                }, {
                    title: "到期时间（UTC+8）",
                    dataIndex: "expires_at_text",
                    key: "expires_at_text",
                    width: 190,
                    render: value=>value || "永久"
                }, {
                    title: "操作",
                    key: "action",
                    width: 120,
                    render: (value, record)=>p.a.createElement(a["a"], {
                        size: "small",
                        onClick: ()=>this.releaseRule(record)
                    }, "解除")
                }];
                return p.a.createElement(o["a"], {
                    rowKey: row=>String(row.id),
                    size: "small",
                    loading: this.state.rulesLoading,
                    columns: columns,
                    dataSource: this.state.rules,
                    pagination: {
                        current: this.state.rulesPagination.current,
                        pageSize: this.state.rulesPagination.pageSize,
                        total: this.state.rulesTotal
                    },
                    onChange: pagination=>this.fetchRules(pagination.current)
                })
            }
            renderHistory() {
                var columns = [{
                    title: "时间（UTC+8）",
                    dataIndex: "created_at_text",
                    key: "created_at_text",
                    width: 190
                }, {
                    title: "动作",
                    dataIndex: "action",
                    key: "action",
                    width: 100,
                    render: value=>p.a.createElement(y["a"], {
                        color: "block" === value ? "red" : "green"
                    }, "block" === value ? "阻断" : "解除")
                }, {
                    title: "目标",
                    dataIndex: "target",
                    key: "target",
                    render: (value, record)=>p.a.createElement("div", null, p.a.createElement("div", null, value || "-"), p.a.createElement("div", {
                        className: "text-muted small"
                    }, gatewayScopeText(record.scope)))
                }, {
                    title: "操作人",
                    dataIndex: "actor_email",
                    key: "actor_email",
                    render: value=>value || "-"
                }, {
                    title: "原因",
                    dataIndex: "reason",
                    key: "reason",
                    render: value=>value || "-"
                }];
                return p.a.createElement(o["a"], {
                    rowKey: row=>String(row.id),
                    size: "small",
                    loading: this.state.historyLoading,
                    columns: columns,
                    dataSource: this.state.history,
                    pagination: {
                        current: this.state.historyPagination.current,
                        pageSize: this.state.historyPagination.pageSize,
                        total: this.state.historyTotal
                    },
                    onChange: pagination=>this.fetchHistory(pagination.current)
                })
            }
            renderBlockModal() {
                var record = this.state.blockRecord
                  , self = this;
                if (!this.state.blockVisible || !record)
                    return null;
                var scopes = [];
                CLEAN_SCOPE_ORDER.forEach(function(scope) {
                    if ("subscription" === scope && !(Number(record.subscription_id) > 0))
                        return;
                    scopes.push(scope)
                });
                var targetText = "user" === this.state.blockScope ? record.user_email || ("#" + record.user_id) : "subscription" === this.state.blockScope ? "#" + record.subscription_id : "ip" === this.state.blockScope ? record.request_ip : record.user_agent;
                return p.a.createElement(c["a"], {
                    title: "阻断订阅拉取",
                    visible: !0,
                    onCancel: ()=>this.closeBlock(),
                    onOk: ()=>this.submitBlock(),
                    confirmLoading: this.state.blockSaving,
                    okText: "确认阻断",
                    okType: "danger",
                    cancelText: "取消",
                    width: 560
                }, p.a.createElement("div", {
                    className: "mb-3"
                }, p.a.createElement("div", {
                    className: "font-w600 mb-1"
                }, "阻断条件"), p.a.createElement(u["a"], {
                    style: {
                        width: "100%"
                    },
                    value: this.state.blockScope,
                    onChange: e=>this.setState({
                        blockScope: e
                    })
                }, scopes.map(function(scope) {
                    return p.a.createElement(u["a"].Option, {
                        key: scope,
                        value: scope
                    }, gatewayScopeText(scope))
                }))), p.a.createElement("p", {
                    className: "text-muted small"
                }, "全站生效：命中该条件的所有订阅请求都会返回 500 错误，不只是这个账号。"), p.a.createElement("div", {
                    className: "alert alert-warning small"
                }, "当前目标：", p.a.createElement("span", {
                    className: "font-w600",
                    style: {
                        wordBreak: "break-all"
                    }
                }, targetText || "-")), p.a.createElement("div", {
                    className: "mb-3"
                }, p.a.createElement("div", {
                    className: "font-w600 mb-1"
                }, "阻断原因"), p.a.createElement(s["a"].TextArea, {
                    rows: 3,
                    value: this.state.blockReason,
                    placeholder: "会写进操作留痕，便于日后复核",
                    onChange: e=>this.setState({
                        blockReason: e.target.value
                    })
                })), p.a.createElement("div", null, p.a.createElement("div", {
                    className: "font-w600 mb-1"
                }, "到期时间（UTC+8，留空为永久）"), p.a.createElement(s["a"], {
                    allowClear: !0,
                    placeholder: "YYYY-MM-DD HH:mm",
                    value: this.state.blockExpires,
                    onChange: e=>this.setState({
                        blockExpires: e.target.value
                    })
                })))
            }
            // 版式照原风控网关页：一个 Card 里若干 block block-rounded 区块 —— 这个主题的
            // 页面都是这个结构，直接套用省得跟其它页面风格打架。
            section(title, extra, body) {
                return p.a.createElement("div", {
                    className: "block block-rounded"
                }, p.a.createElement("div", {
                    className: "block-header"
                }, p.a.createElement("h3", {
                    className: "block-title"
                }, title), extra ? p.a.createElement("div", {
                    className: "block-options"
                }, extra) : null), p.a.createElement("div", {
                    className: "block-content"
                }, body))
            }
            render() {
                if (!this.state.available) {
                    return p.a.createElement(m["a"], i()({}, this.props, {
                        title: "订阅清洗网关"
                    }), p.a.createElement(g["a"], SPIN_OFF, p.a.createElement("div", {
                        className: "alert alert-warning mb-0",
                        role: "alert"
                    }, "订阅拉取记录表尚未安装。请先执行 php artisan v2board:update 完成数据库升级，升级后历史拉取记录会自动回填。")))
                }
                return p.a.createElement(m["a"], i()({}, this.props, {
                    title: "订阅清洗网关"
                }), p.a.createElement(g["a"], SPIN_OFF, this.section("日志留存", p.a.createElement(a["a"], {
                    size: "small",
                    onClick: ()=>this.fetch()
                }, p.a.createElement(l["a"], {
                    type: "reload"
                }), " 刷新"), this.renderRetention()), this.section("筛选", this.state.uaTruncated ? p.a.createElement("span", {
                    className: "text-muted small"
                }, "User-Agent 候选过多，下拉只列出拉取次数最多的那些") : null, this.renderFilterBar()), this.section("拉取记录", p.a.createElement("span", {
                    className: "text-muted small"
                }, "共 " + Number(this.state.total || 0) + " 条"), this.renderTable()), this.section("待处理风险账号", p.a.createElement("span", {
                    className: "text-muted small"
                }, "未处理的会持续提醒管理员（每 15 分钟一次），直到在这里标记已处理"), this.renderRiskPending()), this.section("生效中的阻断名单", null, this.renderRules()), this.section("阻断操作留痕", null, this.renderHistory()), this.renderBlockModal()))
            }
        }
        t["default"] = SubscribeCleanGatewayPage
    },
