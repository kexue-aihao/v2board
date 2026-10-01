    riskrulepage: function(e, t, n) {
        "use strict";
        n.r(t);
        // 手工补丁：订阅清洗网关页面（原「风控规则」）。故意不建 dva model —— 数据访问直接走 t3Un 请求助手，
        // 与 d1ca 里的订阅审计补丁同一路数。维度与运算符列表全部来自 /risk/rule/fetch
        // 的响应，前端不留第二份副本（唯一事实源是 RiskRuleService 的类常量）。
        var r = n("jehZ")
          , i = n.n(r)
          , o = (n("g9YV"),
        n("wCAj"))
          , a = (n("+L6B"),
        n("2/Rp"))
          , s = (n("5NDa"),
        n("5rEg"))
          , l = (n("Pwec"),
        n("CtXQ"))
          , c = (n("2qtc"),
        n("kLXV"))
          , u = (n("OaEy"),
        n("2fM7"))
          , h = (n("BoS7"),
        n("Sdc0"))
          , f = (n("/zsF"),
        n("PArb"))
          , d = n("q1tI")
          , p = n.n(d)
          , m = n("Bl7J")
          , g = n("v32e");
        function riskUrl(path) {
            return "/" + window.settings.secure_path + path
        }
        function riskGet(path, params) {
            return Object(n("t3Un")["a"])(riskUrl(path), params)
        }
        function riskPost(path, params) {
            return Object(n("t3Un")["b"])(riskUrl(path), params)
        }
        // enabled 是 tinyint，从 PDO 出来可能是 int 也可能是字符串，统一收口。
        function riskEnabled(value) {
            return !0 === value || 1 === value || "1" === value
        }
        // threshold 是 decimal(18,8)，回来是 "3.00000000"；展示与回填都要去掉尾零。
        function riskNumberText(value) {
            if (null === value || void 0 === value || "" === value)
                return "";
            var num = Number(value);
            return isNaN(num) ? String(value) : String(num)
        }
        // 权重是后加列：未升级的库与老记录里没有它，展示与回填一律按默认 20% 处理
        // （后端缺列时同样按默认值计分，两边口径一致）。
        function riskWeightText(value) {
            if (null === value || void 0 === value || "" === value)
                return "20";
            var num = Number(value);
            return isNaN(num) ? "20" : String(Math.max(0, Math.min(100, Math.round(num))))
        }
        // 重算会改写被冻结的判定结果，确认文案必须带全部四行保真度警告。
        var RISK_RECOMPUTE_WARNING = ["重算会用当前规则重新判定所有已完成周期，覆盖此前的判定结果。", "若审计证据已被保留期清理，重算结果可能低于当初的真实值，原本「疑似内鬼」的周期可能被改为「正常」。", "节点连接记录按 last_seen_at 清理，历史周期的连接指标尤其容易失真。", "此操作不可撤销。"];
        // 手动评估与重算是两回事：前者纯计算不落库，后者改写账本。说明文案必须把
        // 「不影响 30 天账本与风险列」讲清，免得管理员误以为按钮之间可以互替。
        var MANUAL_EVALUATE_NOTES = ["手动评估用当前启用的规则对所选时间窗做一次全站体检，结果落库并刷新用户列表的「风险」列（整体覆盖上一次手动评估），30 天周期账本不受影响。", "流量使用率按天级统计聚合（覆盖窗口的整天流量除以套餐总量），短窗口下数值含义有限，含流量使用率维度的规则请自行斟酌。", "评估会分批进行，期间请保持本页面打开。"];
        // 手动评估结果表的指标展示顺序：拉取侧 → 流量 → 节点侧，与后端维度注册表分组一致。
        var MANUAL_METRIC_KEYS = ["distinct_ip_count", "user_agent_count", "city_count", "region_count", "country_count", "used_ratio", "node_ip_count", "node_new_ip_count", "node_count", "node_country_count", "node_region_count", "node_city_count"];
        function manualPad(n) {
            return (n < 10 ? "0" : "") + n
        }
        // 刻意用 YYYY-MM-DD HH:mm 的语言中立格式：不含汉字，覆盖翻译层不会碰它。
        function manualTimeText(ts) {
            if (!ts)
                return "-";
            var d = new Date(1e3 * ts);
            return d.getFullYear() + "-" + manualPad(d.getMonth() + 1) + "-" + manualPad(d.getDate()) + " " + manualPad(d.getHours()) + ":" + manualPad(d.getMinutes())
        }
        class RiskRulePage extends p.a.Component {
            constructor(props) {
                super(props),
                this.defaultSubmit = {
                    label: "",
                    dimension: void 0,
                    operator: ">",
                    threshold: "",
                    weight: "20",
                    enabled: !0
                },
                this.state = {
                    rules: [],
                    dimensions: {},
                    operators: {},
                    // 表缺失（未升级的库）与表存在但为空是两种状态：前者引擎走内置兜底
                    // 规则，后者才真的一条都不命中。横幅文案必须分开，不能混为一谈。
                    available: !0,
                    fetchLoading: !0,
                    saveLoading: !1,
                    // 待处理区块：提醒台账里未处理的高风险订阅。分值来自 30 天周期账本或
                    // 管理员手动评估，不实时更新；达到阈值时管理员会收到 Telegram 私聊提醒。
                    pending: [],
                    pendingTotal: 0,
                    pendingThreshold: 60,
                    // null = 还没取到；0 = 一个绑定 Telegram 的管理员都没有（提醒发不出去）。
                    pendingNotifiable: null,
                    pendingAvailable: !0,
                    pendingLoading: !0,
                    visible: !1,
                    submit: i()({}, this.defaultSubmit),
                    recomputeVisible: !1,
                    recomputeRunning: !1,
                    recomputeProgress: null,
                    manualVisible: !1,
                    manualStarted: !1,
                    manualRunning: !1,
                    manualProgress: null,
                    manualResults: [],
                    manualPreset: "168",
                    manualCustomValue: "",
                    manualCustomUnit: "days"
                },
                // 全站重算是前端驱动的游标循环。每次启动领一个 token，组件卸载或弹窗
                // 关闭时把 token 推进一格，在飞的那一批响应就会被丢弃、循环停下来。
                this.recomputeToken = 0,
                // 手动评估的游标循环同一套 token 机制，但独立计数：两个循环互不干扰。
                this.manualToken = 0,
                // restart 响应下发的轮次号，后续 step 逐一回带；游标被别的轮次接管后
                // 服务端据此终止本客户端的迟到请求，绝不跨轮嫁接。
                this.manualRunId = ""
            }
            componentDidMount() {
                this.fetch(),
                this.fetchPending()
            }
            componentWillUnmount() {
                this.recomputeToken++,
                this.manualToken++
            }
            fetch() {
                this.setState({
                    fetchLoading: !0
                }),
                riskGet("/risk/rule/fetch").then(res=>{
                    // 非 200 已由请求助手弹出带服务端消息的提示，这里只收 loading。
                    if (200 !== res.code)
                        return void this.setState({
                            fetchLoading: !1
                        });
                    this.setState({
                        rules: res.data || [],
                        dimensions: res.dimensions || {},
                        operators: res.operators || {},
                        available: !1 !== res.available,
                        fetchLoading: !1
                    })
                }
                ).catch(()=>this.setState({
                    fetchLoading: !1
                }))
            }
            fetchPending() {
                this.setState({
                    pendingLoading: !0
                }),
                riskGet("/risk/rule/high-risk", {
                    current: 1,
                    pageSize: 20
                }).then(res=>{
                    if (200 !== res.code)
                        return void this.setState({
                            pendingLoading: !1
                        });
                    this.setState({
                        pending: res.data || [],
                        pendingTotal: res.total || 0,
                        pendingThreshold: res.threshold || 60,
                        pendingNotifiable: null === res.notifiable_admins || void 0 === res.notifiable_admins ? null : Number(res.notifiable_admins),
                        pendingAvailable: !1 !== res.available,
                        pendingLoading: !1
                    })
                }
                ).catch(()=>this.setState({
                    pendingLoading: !1
                }))
            }
            handlePending(record) {
                riskPost("/risk/rule/high-risk/handle", {
                    id: record.id
                }).then(res=>{
                    200 === res.code && this.fetchPending()
                }
                )
            }
            openModal(record) {
                this.setState({
                    visible: !0,
                    submit: record ? {
                        id: record.id,
                        label: record.label || "",
                        dimension: record.dimension,
                        operator: record.operator,
                        threshold: riskNumberText(record.threshold),
                        weight: riskWeightText(record.weight),
                        enabled: riskEnabled(record.enabled)
                    } : i()({}, this.defaultSubmit)
                })
            }
            closeModal() {
                this.setState({
                    visible: !1,
                    submit: i()({}, this.defaultSubmit)
                })
            }
            submitChange(key, value) {
                var patch = {};
                patch[key] = value,
                this.setState({
                    submit: i()({}, this.state.submit, patch)
                })
            }
            save() {
                var submit = this.state.submit
                  , label = String(submit.label || "").trim();
                if (!label)
                    return void c["a"].warning({
                        title: "提示",
                        content: "请填写策略名称"
                    });
                if (!submit.dimension)
                    return void c["a"].warning({
                        title: "提示",
                        content: "请选择判定维度"
                    });
                if (!submit.operator)
                    return void c["a"].warning({
                        title: "提示",
                        content: "请选择运算符"
                    });
                if ("" === submit.threshold || null === submit.threshold || void 0 === submit.threshold || isNaN(Number(submit.threshold)))
                    return void c["a"].warning({
                        title: "提示",
                        content: "请填写有效的阈值"
                    });
                var weightText = String(null === submit.weight || void 0 === submit.weight ? "20" : submit.weight).trim();
                if ("" === weightText || isNaN(Number(weightText)) || Number(weightText) < 0 || 100 < Number(weightText))
                    return void c["a"].warning({
                        title: "提示",
                        content: "请填写 0-100 之间的权重"
                    });
                this.setState({
                    saveLoading: !0
                }),
                riskPost("/risk/rule/save", {
                    id: submit.id,
                    label: label,
                    dimension: submit.dimension,
                    operator: submit.operator,
                    threshold: Number(submit.threshold),
                    weight: Number(weightText),
                    enabled: submit.enabled ? 1 : 0
                }).then(res=>{
                    this.setState({
                        saveLoading: !1
                    }),
                    200 === res.code && (this.closeModal(),
                    this.fetch())
                }
                ).catch(()=>this.setState({
                    saveLoading: !1
                }))
            }
            show(record) {
                riskPost("/risk/rule/show", {
                    id: record.id,
                    show: riskEnabled(record.enabled) ? 0 : 1
                }).then(res=>{
                    200 === res.code && this.fetch()
                }
                )
            }
            drop(record) {
                c["a"].confirm({
                    title: "警告",
                    content: "确定要删除规则「" + (record.label || "") + "」吗？已判定的历史周期不受影响，除非重算。",
                    okText: "确定删除",
                    okType: "danger",
                    cancelText: "取消",
                    onOk: ()=>riskPost("/risk/rule/drop", {
                        id: record.id
                    }).then(res=>{
                        200 === res.code && this.fetch()
                    }
                    )
                })
            }
            move(index, offset) {
                var rules = this.state.rules.slice()
                  , target = index + offset;
                if (target < 0 || target >= rules.length)
                    return;
                var swap = rules[index];
                rules[index] = rules[target],
                rules[target] = swap,
                // 先乐观交换本地顺序，再把完整有序 id 列表交给后端。
                this.setState({
                    rules: rules
                }),
                riskPost("/risk/rule/sort", {
                    ids: rules.map(rule=>rule.id)
                }).then(()=>this.fetch()).catch(()=>this.fetch())
            }
            confirmRecompute() {
                c["a"].confirm({
                    title: "重算历史周期",
                    width: 560,
                    content: p.a.createElement("div", null, RISK_RECOMPUTE_WARNING.map((line,index)=>p.a.createElement("p", {
                        key: index,
                        className: index === RISK_RECOMPUTE_WARNING.length - 1 ? "mb-0 font-w600" : "mb-2"
                    }, line))),
                    okText: "开始重算",
                    okType: "danger",
                    cancelText: "取消",
                    onOk: ()=>this.startRecompute()
                })
            }
            startRecompute() {
                var token = ++this.recomputeToken;
                this.setState({
                    recomputeVisible: !0,
                    recomputeRunning: !0,
                    recomputeProgress: null
                }),
                this.recomputeStep(token, !0)
            }
            recomputeStep(token, restart) {
                riskPost("/risk/rule/recompute", restart ? {
                    restart: 1
                } : {}).then(res=>{
                    if (token !== this.recomputeToken)
                        return;
                    if (200 !== res.code)
                        return void this.setState({
                            recomputeRunning: !1
                        });
                    var data = res.data || {};
                    this.setState({
                        recomputeProgress: data,
                        recomputeRunning: !data.done
                    }),
                    data.done ? this.fetch() : this.recomputeStep(token, !1)
                }
                ).catch(()=>{
                    token === this.recomputeToken && this.setState({
                        recomputeRunning: !1
                    })
                }
                )
            }
            closeRecompute() {
                this.recomputeToken++,
                this.setState({
                    recomputeVisible: !1,
                    recomputeRunning: !1
                })
            }
            renderRecomputeBody() {
                var progress = this.state.recomputeProgress || {}
                  , subscriptions = progress.subscriptions || 0
                  , cycles = progress.cycles || 0
                  , total = progress.total || 0
                  , percent = total > 0 ? Math.min(100, Math.round(subscriptions / total * 100)) : 0;
                return p.a.createElement("div", null, p.a.createElement("p", {
                    className: "mb-2"
                }, this.state.recomputeRunning ? "正在分批重算，请保持本页面打开……" : progress.done ? "重算完成。" : "重算已停止，重新点击「重算历史周期」会从头开始。"), p.a.createElement("p", {
                    className: "mb-2"
                }, "已处理订阅 " + subscriptions + (total > 0 ? " / " + total : "") + "，重算周期 " + cycles + " 个" + (total > 0 ? "（" + percent + "%）" : "")), p.a.createElement("p", {
                    className: "mb-0 text-muted font-size-sm"
                }, "关闭本弹窗只会停止后续分批，已重算的周期不会回滚。"))
            }
            openManual() {
                // 每次打开都回到配置视图并清掉上一轮结果：结果本来就是即时体检的快照，
                // 不落库、不跨弹窗保留。
                this.setState({
                    manualVisible: !0,
                    manualStarted: !1,
                    manualRunning: !1,
                    manualProgress: null,
                    manualResults: []
                })
            }
            closeManual() {
                this.manualToken++,
                this.setState({
                    manualVisible: !1,
                    manualRunning: !1
                })
            }
            // 返回整数小时数；非法输入返回 null。上限 2208 小时（92 天）与后端校验一致。
            manualHours() {
                var state = this.state;
                if ("custom" !== state.manualPreset)
                    return parseInt(state.manualPreset, 10);
                var value = Number(state.manualCustomValue);
                if ("" === String(state.manualCustomValue).trim() || isNaN(value) || value <= 0)
                    return null;
                var hours = "days" === state.manualCustomUnit ? Math.round(24 * value) : Math.round(value);
                return hours >= 1 && hours <= 2208 ? hours : null
            }
            startManual() {
                var hours = this.manualHours();
                if (null === hours)
                    return void c["a"].warning({
                        title: "提示",
                        content: "请输入 1 小时到 92 天之间的评估窗口"
                    });
                var token = ++this.manualToken;
                this.manualRunId = "",
                this.setState({
                    manualStarted: !0,
                    manualRunning: !0,
                    manualProgress: null,
                    manualResults: []
                }),
                this.manualStep(token, hours)
            }
            // 窗口只随首个 restart 请求发送，后端把它冻结在游标状态里；后续分批回带
            // restart 下发的 run_id，轮次不符会被服务端终止。
            manualStep(token, hours) {
                riskPost("/risk/rule/manual-evaluate", null !== hours ? {
                    restart: 1,
                    hours: hours
                } : {
                    run_id: this.manualRunId
                }).then(res=>{
                    if (token !== this.manualToken)
                        return;
                    if (200 !== res.code)
                        // 一批都没跑成（典型：60 秒并发守卫拒了 restart）就退回配置视图，
                        // 别把人困在 0/0 的进度页上；服务端消息已由请求助手弹出。
                        return void this.setState(this.state.manualProgress ? {
                            manualRunning: !1
                        } : {
                            manualRunning: !1,
                            manualStarted: !1
                        });
                    var data = res.data || {};
                    data.run_id && (this.manualRunId = data.run_id),
                    this.setState({
                        manualProgress: data,
                        manualRunning: !data.done,
                        manualResults: data.done ? data.results || [] : this.state.manualResults
                    }),
                    data.done || this.manualStep(token, null)
                }
                ).catch(()=>{
                    token === this.manualToken && this.setState({
                        manualRunning: !1
                    })
                }
                )
            }
            renderManualConfig() {
                var state = this.state;
                return p.a.createElement("div", null, p.a.createElement("div", {
                    className: "form-group"
                }, p.a.createElement("label", null, "评估窗口"), p.a.createElement(u["a"], {
                    style: {
                        width: "100%"
                    },
                    value: state.manualPreset,
                    onChange: value=>this.setState({
                        manualPreset: value
                    })
                }, p.a.createElement(u["a"].Option, {
                    value: "24"
                }, "近 24 小时"), p.a.createElement(u["a"].Option, {
                    value: "72"
                }, "近 3 天"), p.a.createElement(u["a"].Option, {
                    value: "168"
                }, "近 7 天"), p.a.createElement(u["a"].Option, {
                    value: "336"
                }, "近 14 天"), p.a.createElement(u["a"].Option, {
                    value: "720"
                }, "近 30 天"), p.a.createElement(u["a"].Option, {
                    value: "custom"
                }, "自定义"))), "custom" === state.manualPreset && p.a.createElement("div", {
                    className: "form-group"
                }, p.a.createElement("label", null, "自定义窗口长度"), p.a.createElement("div", {
                    className: "d-flex"
                }, p.a.createElement(s["a"], {
                    type: "number",
                    min: 1,
                    style: {
                        flex: 1,
                        marginRight: 8
                    },
                    placeholder: "请输入数值",
                    value: state.manualCustomValue,
                    onChange: e=>this.setState({
                        manualCustomValue: e.target.value
                    })
                }), p.a.createElement(u["a"], {
                    style: {
                        width: 90
                    },
                    value: state.manualCustomUnit,
                    onChange: value=>this.setState({
                        manualCustomUnit: value
                    })
                }, p.a.createElement(u["a"].Option, {
                    value: "hours"
                }, "小时"), p.a.createElement(u["a"].Option, {
                    value: "days"
                }, "天")))), MANUAL_EVALUATE_NOTES.map((line,index)=>p.a.createElement("p", {
                    key: index,
                    className: (index === MANUAL_EVALUATE_NOTES.length - 1 ? "mb-0" : "mb-2") + " text-muted font-size-sm"
                }, line)))
            }
            renderManualResults() {
                // 布局教训（生产 134 行实测）：指标堆 12 个 div 会把行高撑到几百像素，而
                // antd 单元格默认垂直居中，其余列内容被顶出 y 滚动可视区，看起来像空表；
                // 双滚动下不给列宽，指标列还会被挤到几十像素逐字断行。所以：列给显式宽、
                // 单元格顶部对齐、指标改行内流式（对内不断行、对间可换行）、长串 break-all。
                var dimensions = this.state.dimensions
                  , cellTop = ()=>({
                    style: {
                        verticalAlign: "top"
                    }
                })
                  , columns = [{
                    title: "用户",
                    key: "user",
                    width: 190,
                    onCell: cellTop,
                    render: (value,record)=>p.a.createElement("div", {
                        style: {
                            wordBreak: "break-all"
                        }
                    }, record.email ? record.email : p.a.createElement(p.a.Fragment, null, "#" + record.user_id + " ", "用户已删除"))
                }, {
                    title: "订阅",
                    dataIndex: "subscription_id",
                    key: "subscription_id",
                    width: 72,
                    onCell: cellTop,
                    render: value=>"#" + value
                }, {
                    title: "命中理由",
                    key: "reasons",
                    // 刻意不给宽：吸收剩余宽度与 y 滚动条沟槽，表头表体才对得齐。
                    onCell: cellTop,
                    render: (value,record)=>p.a.createElement("div", null, (record.reasons || []).map((reason,index)=>p.a.createElement("div", {
                        key: index,
                        className: index ? "mt-1" : "",
                        style: {
                            wordBreak: "break-all"
                        }
                    }, reason)))
                }, {
                    title: "关键指标",
                    key: "metrics",
                    width: 270,
                    onCell: cellTop,
                    render: (value,record)=>{
                        var metrics = record.metrics || {};
                        // 维度标签复用 /risk/rule/fetch 下发的注册表；值与标签是分开的
                        // 文本节点，标签词条已在覆盖翻译层字典里。刻意不带单位。
                        return p.a.createElement("div", {
                            className: "text-muted font-size-sm"
                        }, MANUAL_METRIC_KEYS.filter(key=>void 0 !== metrics[key] && null !== metrics[key]).map(key=>{
                            var meta = dimensions[key] || {}
                              , text = "used_ratio" === key ? Math.round(1e4 * Number(metrics[key])) / 100 + "%" : String(metrics[key]);
                            return p.a.createElement("span", {
                                key: key,
                                style: {
                                    display: "inline-block",
                                    whiteSpace: "nowrap",
                                    marginRight: 10
                                }
                            }, (meta.label || key) + "：", text)
                        }
                        ))
                    }
                }];
                return p.a.createElement(o["a"], {
                    size: "small",
                    rowKey: record=>record.subscription_id,
                    dataSource: this.state.manualResults,
                    columns: columns,
                    pagination: !1,
                    scroll: {
                        y: 320
                    }
                })
            }
            renderManualBody() {
                if (!this.state.manualStarted)
                    return this.renderManualConfig();
                var state = this.state
                  , progress = state.manualProgress || {}
                  , scanned = progress.scanned || 0
                  , total = progress.total || 0
                  , flagged = progress.flagged || 0
                  , percent = total > 0 ? Math.min(100, Math.round(scanned / total * 100)) : 0;
                return p.a.createElement("div", null, p.a.createElement("p", {
                    className: "mb-2"
                }, state.manualRunning ? "正在分批评估，请保持本页面打开……" : progress.done ? "评估完成。" : "评估已停止，重新打开本弹窗可再次发起。"), progress.start_at ? p.a.createElement("p", {
                    className: "mb-2 text-muted font-size-sm"
                }, "评估窗口：", manualTimeText(progress.start_at) + " ~ " + manualTimeText(progress.end_at)) : null, p.a.createElement("p", {
                    className: "mb-2"
                }, "已扫描订阅 " + scanned + " / " + total + "，发现可疑 " + flagged + " 个（" + percent + "%）"), progress.done ? p.a.createElement("p", {
                    className: "mb-2"
                }, "共扫描 " + scanned + " 个订阅，窗口内有数据 " + (progress.with_evidence || 0) + " 个，命中规则 " + flagged + " 个。") : null, progress.done && progress.overflow > 0 ? p.a.createElement("div", {
                    className: "alert alert-warning",
                    role: "alert"
                }, p.a.createElement("p", {
                    className: "mb-0"
                }, "可疑结果超过 200 条上限，仅列出前 200 条，其余 " + progress.overflow + " 条未展示；建议收窄评估窗口或调高规则阈值。")) : null, progress.done ? state.manualResults.length ? this.renderManualResults() : p.a.createElement("p", {
                    className: "mb-0"
                }, "所选窗口内未发现命中规则的订阅。") : p.a.createElement("p", {
                    className: "mb-0 text-muted font-size-sm"
                }, "评估边跑边落库，完成后「风险」列以本轮结果为准；中途关闭本弹窗只停止后续分批。"))
            }
            render() {
                var state = this.state
                  , rules = state.rules
                  , dimensions = state.dimensions
                  , operators = state.operators
                  , enabledCount = rules.filter(rule=>riskEnabled(rule.enabled)).length
                  , currentDimension = dimensions[state.submit.dimension] || {}
                  , columns = [{
                    title: "#",
                    dataIndex: "id",
                    key: "id"
                }, {
                    title: "名称",
                    dataIndex: "label",
                    key: "label"
                }, {
                    title: "维度",
                    dataIndex: "dimension",
                    key: "dimension",
                    render: value=>{
                        // 库里留着已从注册表移除的旧维度时退化成显示原始 key，不白屏。
                        var dimension = dimensions[value];
                        return dimension ? dimension.label : value
                    }
                }, {
                    title: "条件",
                    key: "condition",
                    render: (value,record)=>{
                        var dimension = dimensions[record.dimension] || {};
                        return (operators[record.operator] || record.operator) + " " + riskNumberText(record.threshold) + (dimension.unit || "")
                    }
                }, {
                    title: "权重",
                    dataIndex: "weight",
                    key: "weight",
                    render: value=>{
                        return riskWeightText(value) + "%"
                    }
                }, {
                    title: "启用",
                    dataIndex: "enabled",
                    key: "enabled",
                    render: (value,record)=>{
                        return p.a.createElement(h["a"], {
                            size: "small",
                            checked: riskEnabled(value),
                            onChange: ()=>this.show(record)
                        })
                    }
                }, {
                    title: "优先级",
                    dataIndex: "sort",
                    key: "sort",
                    render: (value,record,index)=>{
                        return p.a.createElement("div", null, p.a.createElement("span", {
                            style: {
                                marginRight: 8
                            }
                        }, null === value || void 0 === value ? "-" : value), p.a.createElement(a["a"], {
                            size: "small",
                            icon: "arrow-up",
                            title: "上移",
                            disabled: 0 === index,
                            onClick: ()=>this.move(index, -1)
                        }), p.a.createElement(a["a"], {
                            size: "small",
                            icon: "arrow-down",
                            title: "下移",
                            style: {
                                marginLeft: 4
                            },
                            disabled: index === rules.length - 1,
                            onClick: ()=>this.move(index, 1)
                        }))
                    }
                }, {
                    title: "操作",
                    key: "action",
                    align: "right",
                    render: (value,record)=>{
                        return p.a.createElement(p.a.Fragment, null, p.a.createElement("a", {
                            href: "javascript:void(0);",
                            onClick: ()=>this.openModal(record)
                        }, "编辑"), p.a.createElement(f["a"], {
                            type: "vertical"
                        }), p.a.createElement("a", {
                            href: "javascript:void(0);",
                            onClick: ()=>this.drop(record)
                        }, "删除"))
                    }
                }]
                  , pendingColumns = [{
                    title: "用户",
                    key: "email",
                    render: (value,record)=>(record.email || "#" + record.user_id) + "（订阅 #" + record.subscription_id + "）"
                }, {
                    title: "风险值",
                    key: "risk_score",
                    align: "center",
                    render: (value,record)=>{
                        var score = null === record.risk_score || void 0 === record.risk_score ? null : Number(record.risk_score);
                        return null === score || isNaN(score) ? p.a.createElement("span", {
                            className: "text-muted"
                        }, "—") : p.a.createElement("span", {
                            style: {
                                color: "#c0392b",
                                fontWeight: 600
                            }
                        }, score + "%")
                    }
                }, {
                    title: "命中理由",
                    key: "reasons",
                    render: (value,record)=>{
                        return p.a.createElement("div", null, (record.reasons || []).slice(0, 3).map((reason,index)=>p.a.createElement("div", {
                            key: index,
                            className: "text-muted font-size-sm"
                        }, reason)))
                    }
                }, {
                    title: "窗口",
                    key: "window",
                    render: (value,record)=>manualTimeText(record.window_start) + " ~ " + manualTimeText(record.window_end)
                }, {
                    title: "提醒",
                    key: "sent_at",
                    render: (value,record)=>record.sent_at ? manualTimeText(record.sent_at) : "未发送"
                }, {
                    title: "操作",
                    key: "action",
                    align: "right",
                    render: (value,record)=>p.a.createElement("a", {
                        href: "javascript:void(0);",
                        onClick: ()=>this.handlePending(record)
                    }, "标记已处理")
                }];
                // 必须展开路由 props，否则侧边栏会在 location.pathname 上崩。
                return p.a.createElement(m["a"], i()({}, this.props, {
                    title: "订阅清洗网关"
                }), p.a.createElement(g["a"], {
                    loading: state.fetchLoading
                }, p.a.createElement("div", {
                    className: "block block-rounded"
                }, p.a.createElement("div", {
                    className: "bg-white"
                }, p.a.createElement("div", {
                    className: "d-flex justify-content-between align-items-center",
                    style: {
                        padding: 15
                    }
                }, p.a.createElement(a["a"], {
                    onClick: ()=>this.openModal(null)
                }, p.a.createElement(l["a"], {
                    type: "plus"
                }), " 新增策略"), p.a.createElement("div", null, p.a.createElement(a["a"], {
                    style: {
                        marginRight: 8
                    },
                    onClick: ()=>this.openManual()
                }, p.a.createElement(l["a"], {
                    type: "clock-circle"
                }), " 自定义周期评估"), p.a.createElement(a["a"], {
                    type: "danger",
                    onClick: ()=>this.confirmRecompute()
                }, p.a.createElement(l["a"], {
                    type: "reload"
                }), " 重算历史周期"))), p.a.createElement("div", {
                    style: {
                        padding: "0 15px 15px"
                    }
                }, p.a.createElement("p", {
                    className: "mb-1 text-muted font-size-sm"
                }, "规则改动只影响之后新完成的周期；要让改动应用到历史周期，请点击「重算历史周期」。"), p.a.createElement("p", {
                    className: "mb-0 text-muted font-size-sm"
                }, "「自定义周期评估」用当前规则对最近一段时间做全站体检，结果落库并驱动用户列表的「风险」列与筛选，30 天周期账本不受影响。"), p.a.createElement("p", {
                    className: "mb-0 text-muted font-size-sm"
                }, "风险值（0-100%）由命中策略的权重累加得到；达到提醒阈值（默认 60%）时会给已绑定 Telegram 的管理员发私聊提醒。判定来自 30 天周期账本与管理员手动评估，不实时更新。"), !state.fetchLoading && !state.available && p.a.createElement("div", {
                    className: "alert alert-warning mb-0",
                    role: "alert",
                    style: {
                        marginTop: 12
                    }
                }, p.a.createElement("p", {
                    className: "mb-0"
                }, "清洗策略表尚未安装（数据库尚未升级），当前仍按内置默认策略判定；升级数据库后才能增删策略。")), !state.fetchLoading && state.available && 0 === enabledCount && p.a.createElement("div", {
                    className: "alert alert-warning mb-0",
                    role: "alert",
                    style: {
                        marginTop: 12
                    }
                }, p.a.createElement("p", {
                    className: "mb-0"
                }, "当前没有启用任何清洗策略，之后完成的周期都不会再触发动作。"))), p.a.createElement("div", {
                    className: "block block-rounded"
                }, p.a.createElement("div", {
                    className: "bg-white"
                }, p.a.createElement("div", {
                    className: "d-flex justify-content-between align-items-center",
                    style: {
                        padding: 15
                    }
                }, p.a.createElement("h3", {
                    className: "block-title mb-0"
                }, "待处理高风险订阅（≥ ", String(state.pendingThreshold), "%）"), p.a.createElement("span", {
                    className: "text-muted font-size-sm"
                }, state.pendingLoading ? "加载中…" : "共 " + state.pendingTotal + " 条")), !state.pendingLoading && state.pendingAvailable && 0 === state.pendingNotifiable && p.a.createElement("div", {
                    className: "alert alert-warning mb-0",
                    role: "alert",
                    style: {
                        margin: "0 15px 15px"
                    }
                }, "没有管理员绑定 Telegram，提醒发不出去；请先让管理员在机器人里发送 /bind 完成绑定。"), !state.pendingLoading && state.pendingAvailable && 0 === state.pending.length && p.a.createElement("div", {
                    className: "alert alert-success mb-0",
                    role: "alert",
                    style: {
                        margin: "0 15px 15px"
                    }
                }, "当前没有待处理的高风险订阅。"), p.a.createElement(o["a"], {
                    tableLayout: "auto",
                    rowKey: record=>record.id,
                    dataSource: state.pending,
                    columns: pendingColumns,
                    pagination: !1,
                    locale: {
                        emptyText: state.pendingAvailable ? "暂无待处理订阅" : "数据库尚未升级，风险值与待办不可用"
                    },
                    scroll: {
                        x: 900
                    }
                }))), p.a.createElement(o["a"], {
                    tableLayout: "auto",
                    rowKey: record=>record.id,
                    dataSource: rules,
                    columns: columns,
                    pagination: !1,
                    locale: {
                        emptyText: "暂无清洗策略"
                    },
                    scroll: {
                        x: 900
                    }
                })))), p.a.createElement(c["a"], {
                    title: state.submit.id ? "编辑策略" : "新增策略",
                    visible: state.visible,
                    onCancel: ()=>this.closeModal(),
                    onOk: ()=>this.save(),
                    okText: "提交",
                    cancelText: "取消",
                    okButtonProps: {
                        loading: state.saveLoading
                    }
                }, p.a.createElement("div", null, p.a.createElement("div", {
                    className: "form-group"
                }, p.a.createElement("label", null, "名称"), p.a.createElement(s["a"], {
                    placeholder: "会原样出现在风险理由里，例如：跨省/州请求过多",
                    value: state.submit.label,
                    onChange: e=>this.submitChange("label", e.target.value)
                })), p.a.createElement("div", {
                    className: "form-group"
                }, p.a.createElement("label", null, "判定维度"), p.a.createElement(u["a"], {
                    style: {
                        width: "100%"
                    },
                    placeholder: "请选择判定维度",
                    value: state.submit.dimension,
                    onChange: value=>this.submitChange("dimension", value)
                }, Object.keys(dimensions).map(key=>{
                    return p.a.createElement(u["a"].Option, {
                        key: key,
                        value: key
                    }, dimensions[key].label)
                }
                ))), p.a.createElement("div", {
                    className: "form-group"
                }, p.a.createElement("label", null, "运算符"), p.a.createElement(u["a"], {
                    style: {
                        width: "100%"
                    },
                    placeholder: "请选择运算符",
                    value: state.submit.operator,
                    onChange: value=>this.submitChange("operator", value)
                }, Object.keys(operators).map(key=>{
                    return p.a.createElement(u["a"].Option, {
                        key: key,
                        value: key
                    }, operators[key])
                }
                ))), p.a.createElement("div", {
                    className: "form-group"
                }, p.a.createElement("label", null, "阈值"), p.a.createElement(s["a"], {
                    type: "number",
                    placeholder: "请输入阈值",
                    addonAfter: currentDimension.unit || void 0,
                    value: state.submit.threshold,
                    onChange: e=>this.submitChange("threshold", e.target.value)
                }), p.a.createElement("p", {
                    className: "mb-0 mt-1 text-muted font-size-sm"
                }, "流量使用率填 0 ~ 1 的小数（如 0.4），计数类维度填整数。")), p.a.createElement("div", {
                    className: "form-group"
                }, p.a.createElement("label", null, "权重"), p.a.createElement(s["a"], {
                    type: "number",
                    placeholder: "请输入权重（0-100）",
                    addonAfter: "%",
                    value: state.submit.weight,
                    onChange: e=>this.submitChange("weight", e.target.value)
                }), p.a.createElement("p", {
                    className: "mb-0 mt-1 text-muted font-size-sm"
                }, "命中该规则给风险值加多少分；多条命中累加、封顶 100%。默认 20%，三条内置规则全中即 60%，正好等于提醒阈值。")), p.a.createElement("div", {
                    className: "form-group"
                }, p.a.createElement("label", {
                    style: {
                        display: "block"
                    }
                }, "启用"), p.a.createElement(h["a"], {
                    checked: !!state.submit.enabled,
                    onChange: value=>this.submitChange("enabled", value)
                })))), p.a.createElement(c["a"], {
                    title: "重算历史周期",
                    visible: state.recomputeVisible,
                    maskClosable: !1,
                    closable: !state.recomputeRunning,
                    okText: state.recomputeRunning ? "停止并关闭" : "关闭",
                    okType: state.recomputeRunning ? "danger" : "primary",
                    cancelButtonProps: {
                        style: {
                            display: "none"
                        }
                    },
                    onOk: ()=>this.closeRecompute(),
                    onCancel: ()=>this.closeRecompute()
                }, this.renderRecomputeBody()), p.a.createElement(c["a"], {
                    title: "自定义周期评估",
                    // 配置视图窄、评估/结果视图宽：结果表四列显式宽度合计约 820，
                    // 880 的弹窗身体（-48 内边距）刚好容纳，不再出现横向挤压。
                    width: state.manualStarted ? 880 : 640,
                    visible: state.manualVisible,
                    maskClosable: !1,
                    closable: !state.manualRunning,
                    okText: state.manualStarted ? state.manualRunning ? "停止并关闭" : "关闭" : "开始评估",
                    okType: state.manualStarted && state.manualRunning ? "danger" : "primary",
                    cancelText: "取消",
                    cancelButtonProps: state.manualStarted ? {
                        style: {
                            display: "none"
                        }
                    } : void 0,
                    onOk: ()=>state.manualStarted ? this.closeManual() : this.startManual(),
                    onCancel: ()=>this.closeManual()
                }, this.renderManualBody()))
            }
        }
        t["default"] = RiskRulePage
    },
