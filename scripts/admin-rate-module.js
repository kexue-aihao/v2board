ratepage: function(e, t, n) {
    "use strict";
    n.r(t);
    var React = n("q1tI"), ReactDefault = n.n(React), Table = n("wCAj"), Button = n("2/Rp"), Page = n("Bl7J"), Spin = n("v32e"), Switch = n("Sdc0"), h = ReactDefault.a.createElement;

    var WEEKDAYS = [[1, "一"], [2, "二"], [3, "三"], [4, "四"], [5, "五"], [6, "六"], [7, "日"]];
    var STATE_LABELS = {normal: "正常", burst: "突发豁免", high: "高带宽", stacked: "已叠加"};

    function number(value, fallback) { var parsed = Number(value); return isFinite(parsed) ? parsed : fallback; }
    function enabled(value) { return value === !0 || value === 1 || value === "1"; }
    function pad(value) { return (value < 10 ? "0" : "") + value; }
    function minutesToText(minute) { minute = number(minute, 0); return pad(Math.floor(minute / 60) % 24) + ":" + pad(minute % 60); }
    function textToMinutes(text) { var parts = String(text || "").split(":"); if (parts.length !== 2) return null; var hours = number(parts[0], -1), minutes = number(parts[1], -1); if (hours < 0 || hours > 24 || minutes < 0 || minutes > 59) return null; return hours * 60 + minutes; }
    function mbps(bps) { return (number(bps, 0) / 1000000).toFixed(2); }
    function rateText(value) { var parsed = number(value, 1); return (Math.round(parsed * 1000) / 1000) + " x"; }

    class RatePage extends ReactDefault.a.Component {
        constructor(props) {
            super(props);
            this.state = {
                loading: !0, saving: !1, error: "", notice: "",
                rules: [], settings: null, states: {total: 0, rows: []}, nodes: [],
                onlyStacked: !0, keyword: "", page: 1, limit: 50,
                form: null, breakdown: null
            };
        }
        componentDidMount() { this.load(); }

        api(path, options) {
            options = options || {};
            var headers = {Accept: "application/json"}
              , authorization = window.localStorage.getItem("authorization");
            if (authorization) headers.authorization = authorization;
            if (options.body) headers["Content-Type"] = "application/json";
            return fetch("/api/v1/" + window.settings.secure_path + path, {
                method: options.method || "GET",
                headers: headers,
                body: options.body
            }).then(function(response) {
                return response.text().then(function(body) {
                    var data = {};
                    try { data = body ? JSON.parse(body) : {}; } catch (error) {}
                    if (!response.ok) throw new Error(data.message || data.error || "请求失败，请稍后重试");
                    return data;
                });
            });
        }

        load() {
            var self = this;
            this.setState({loading: !0, error: ""});
            var query = "?only_stacked=" + (this.state.onlyStacked ? 1 : 0)
                + "&keyword=" + encodeURIComponent(this.state.keyword || "")
                + "&page=" + this.state.page + "&limit=" + this.state.limit;
            this.api("/rate/fetch" + query).then(function(result) {
                var data = result.data || {};
                self.setState({
                    loading: !1,
                    rules: data.rules || [],
                    settings: data.settings || null,
                    states: data.states || {total: 0, rows: []},
                    nodes: data.nodes || []
                });
            }).catch(function(error) {
                self.setState({loading: !1, error: error.message || "无法读取动态倍率配置"});
            });
        }

        setSetting(key, value) {
            var settings = Object.assign({}, this.state.settings);
            settings[key] = value;
            this.setState({settings: settings, notice: ""});
        }

        saveSettings() {
            var self = this, settings = this.state.settings;
            if (!settings) return;
            this.setState({saving: !0, error: "", notice: ""});
            this.api("/rate/settings/save", {
                method: "POST",
                body: JSON.stringify({
                    enabled: enabled(settings.enabled) ? 1 : 0,
                    instant_mbps: number(settings.instant_mbps, 50),
                    sustained_mbps: number(settings.sustained_mbps, 10),
                    burst_exempt_minutes: number(settings.burst_exempt_minutes, 3),
                    stack_minutes: number(settings.stack_minutes, 5),
                    stack_multiplier: number(settings.stack_multiplier, 1.5),
                    decay_step: number(settings.decay_step, 1)
                })
            }).then(function(result) {
                self.setState({saving: !1, settings: result.data || settings, notice: "参数已保存。下一分钟的 rate:tick 就会按新参数判定。"});
            }).catch(function(error) {
                self.setState({saving: !1, error: error.message || "保存失败，请重试"});
            });
        }

        openForm(rule) {
            this.setState({
                error: "",
                notice: "",
                form: rule ? {
                    id: rule.id, scope: rule.scope, node_type: rule.node_type || "", node_id: rule.node_id || 0,
                    weekdays: String(rule.weekdays || "").split(",").map(function(day) { return number(day, 0); }).filter(function(day) { return day >= 1 && day <= 7; }),
                    start: minutesToText(rule.start_minute), end: minutesToText(rule.end_minute),
                    multiplier: String(rule.multiplier), enabled: number(rule.enabled, 1) === 1, remark: rule.remark || ""
                } : {
                    id: null, scope: "global", node_type: "", node_id: 0, weekdays: [1, 2, 3, 4, 5, 6, 7],
                    start: "20:00", end: "23:00", multiplier: "1.5", enabled: !0, remark: ""
                }
            });
        }
        closeForm() { this.setState({form: null}); }
        setForm(field, value) { var form = Object.assign({}, this.state.form); form[field] = value; this.setState({form: form, error: ""}); }
        toggleWeekday(day) {
            var days = (this.state.form.weekdays || []).slice()
              , index = days.indexOf(day);
            if (index >= 0) days.splice(index, 1); else days.push(day);
            days.sort(function(a, b) { return a - b; });
            this.setForm("weekdays", days);
        }

        submitForm(event) {
            if (event) event.preventDefault();
            var self = this, form = this.state.form;
            if (!form) return;
            var start = textToMinutes(form.start), end = textToMinutes(form.end)
              , multiplier = number(form.multiplier, NaN);
            if (form.scope === "node" && (!form.node_type || !number(form.node_id, 0))) {
                return void this.setState({error: "选择「指定节点」时必须选一个节点。"});
            }
            if (start === null || end === null) return void this.setState({error: "起止时间格式应为 HH:MM。"});
            if (!isFinite(multiplier) || multiplier < 0) return void this.setState({error: "倍率必须是不小于 0 的数字。"});

            this.setState({saving: !0, error: "", notice: ""});
            this.api("/rate/rule/save", {
                method: "POST",
                body: JSON.stringify({
                    id: form.id || undefined,
                    scope: form.scope,
                    node_type: form.scope === "node" ? form.node_type : "",
                    node_id: form.scope === "node" ? number(form.node_id, 0) : 0,
                    weekdays: form.weekdays,
                    start_minute: start,
                    end_minute: end,
                    multiplier: multiplier,
                    enabled: form.enabled ? 1 : 0,
                    remark: form.remark
                })
            }).then(function() {
                self.setState({saving: !1, form: null, notice: "规则已保存，下一分钟的 rate:tick 生效。"});
                self.load();
            }).catch(function(error) {
                self.setState({saving: !1, error: error.message || "保存失败，请重试"});
            });
        }

        dropRule(rule) {
            var self = this;
            if (!window.confirm("确认删除规则「" + (rule.remark || ("#" + rule.id)) + "」？")) return;
            this.setState({saving: !0, error: "", notice: ""});
            this.api("/rate/rule/drop", {method: "POST", body: JSON.stringify({id: rule.id})}).then(function() {
                self.setState({saving: !1, notice: "规则已删除。"});
                self.load();
            }).catch(function(error) {
                self.setState({saving: !1, error: error.message || "删除失败，请重试"});
            });
        }

        showBreakdown(userId) {
            var self = this;
            this.setState({saving: !0, error: ""});
            this.api("/rate/explain", {method: "POST", body: JSON.stringify({user_id: userId})}).then(function(result) {
                self.setState({saving: !1, breakdown: result.data || null});
            }).catch(function(error) {
                self.setState({saving: !1, error: error.message || "无法读取倍率构成"});
            });
        }

        nodeLabel(node) { return node.type + " #" + node.id + " " + node.name; }
        nodeText(rule) { return rule.scope === "node" ? rule.node_type + " #" + rule.node_id : "全局"; }

        ruleColumns() {
            var self = this;
            return [
                {title: "作用域", key: "scope", width: 110, render: function(value, rule) { return h("span", null, self.nodeText(rule)); }},
                {title: "生效时段", key: "window", width: 150, render: function(value, rule) { return h("span", null, minutesToText(rule.start_minute) + " - " + minutesToText(rule.end_minute)); }},
                {title: "星期", key: "weekdays", width: 130, render: function(value, rule) {
                    var days = String(rule.weekdays || "").split(",").filter(function(day) { return day !== ""; });
                    if (days.length === 7 || days.length === 0) return h("span", null, "每天");
                    return h("span", null, days.map(function(day) { return "周" + (WEEKDAYS[number(day, 1) - 1] || ["", "一", "二", "三", "四", "五", "六", "日"][number(day, 1)])[1]; }).join("、"));
                }},
                {title: "倍率", key: "multiplier", width: 90, render: function(value, rule) { return h("span", null, rateText(rule.multiplier)); }},
                {title: "状态", key: "enabled", width: 80, render: function(value, rule) { return h("span", {className: enabled(rule.enabled) ? "text-success" : "text-muted"}, enabled(rule.enabled) ? "启用" : "停用"); }},
                {title: "备注", key: "remark", render: function(value, rule) { return h("span", {className: "text-muted"}, rule.remark || "—"); }},
                {title: "操作", key: "action", width: 130, render: function(value, rule) {
                    return h("div", null,
                        h(Button["a"], {size: "sm", style: {marginRight: 8}, onClick: function() { self.openForm(rule); }}, "编辑"),
                        h(Button["a"], {size: "sm", type: "danger", disabled: self.state.saving, onClick: function() { self.dropRule(rule); }}, "删除"));
                }}
            ];
        }

        stateColumns() {
            var self = this;
            return [
                {title: "用户", key: "user", render: function(value, row) { return h("div", null, h("div", {className: "font-w600"}, row.email || ("#" + row.user_id)), h("div", {className: "text-muted font-size-sm"}, "#" + row.user_id)); }},
                {title: "实时速率", key: "rate", width: 120, render: function(value, row) { return h("span", null, mbps(row.rate_bps) + " Mbps"); }},
                {title: "判定", key: "state", width: 110, render: function(value, row) { return h("span", null, STATE_LABELS[row.state] || row.state); }},
                {title: "持续计数", key: "high", width: 100, render: function(value, row) { return h("span", null, row.high + (self.state.settings ? " / " + self.state.settings.stack_minutes : "")); }},
                {title: "突发计数", key: "burst", width: 100, render: function(value, row) { return h("span", null, row.burst); }},
                {title: "当前倍率", key: "multiplier", width: 100, render: function(value, row) { return h("span", {className: row.multiplier > 1 ? "text-danger font-w600" : ""}, rateText(row.multiplier)); }},
                {title: "采样时间", key: "sampled_at", width: 170, render: function(value, row) { return h("span", {className: "text-muted font-size-sm"}, row.sampled_at ? new Date(row.sampled_at * 1000).toLocaleString() : "—"); }},
                {title: "操作", key: "action", width: 110, render: function(value, row) { return h(Button["a"], {size: "sm", onClick: function() { self.showBreakdown(row.user_id); }}, "倍率构成"); }}
            ];
        }

        renderForm() {
            var self = this, form = this.state.form;
            if (!form) return null;
            var nodeOptions = this.state.nodes.map(function(node) { return h("option", {key: node.type + ":" + node.id, value: node.type + ":" + node.id}, self.nodeLabel(node)); });
            return h("div", {className: "block block-rounded"}, h("div", {className: "block-content"},
                h("h5", {className: "font-w600 mb-3"}, form.id ? "编辑规则" : "新建规则"),
                h("form", {onSubmit: this.submitForm.bind(this)},
                    h("div", {className: "row"},
                        h("div", {className: "col-md-4 mb-3"}, h("label", null, "作用域"),
                            h("select", {className: "form-control form-control-sm", value: form.scope, onChange: function(event) { self.setForm("scope", event.target.value); }},
                                h("option", {value: "global"}, "全局（所有节点）"),
                                h("option", {value: "node"}, "指定节点"))),
                        form.scope === "node" && h("div", {className: "col-md-8 mb-3"}, h("label", null, "节点"),
                            h("select", {className: "form-control form-control-sm", value: form.node_type ? form.node_type + ":" + form.node_id : "", onChange: function(event) {
                                var parts = String(event.target.value).split(":");
                                self.setForm("node_type", parts[0] || "");
                                self.setForm("node_id", number(parts[1], 0));
                            }}, h("option", {value: ""}, "请选择节点"), nodeOptions))),
                    h("div", {className: "row"},
                        h("div", {className: "col-md-3 mb-3"}, h("label", null, "开始时间"), h("input", {type: "time", className: "form-control form-control-sm", value: form.start, onChange: function(event) { self.setForm("start", event.target.value); }})),
                        h("div", {className: "col-md-3 mb-3"}, h("label", null, "结束时间"), h("input", {type: "time", className: "form-control form-control-sm", value: form.end, onChange: function(event) { self.setForm("end", event.target.value); }})),
                        h("div", {className: "col-md-3 mb-3"}, h("label", null, "倍率"), h("input", {type: "number", step: "0.01", min: "0", className: "form-control form-control-sm", value: form.multiplier, onChange: function(event) { self.setForm("multiplier", event.target.value); }})),
                        h("div", {className: "col-md-3 mb-3"}, h("label", {className: "d-block"}, "启用"),
                            h(Switch["a"], {checked: !!form.enabled, onChange: function(checked) { self.setForm("enabled", !!checked); }}))),
                    h("div", {className: "mb-3"}, h("label", {className: "d-block"}, "生效星期"),
                        h("div", null, WEEKDAYS.map(function(item) {
                            return h("label", {key: item[0], className: "mr-3 font-w400"},
                                h("input", {type: "checkbox", className: "mr-1", checked: (form.weekdays || []).indexOf(item[0]) >= 0, onChange: function() { self.toggleWeekday(item[0]); }}),
                                "周" + item[1]);
                        })),
                        h("div", {className: "text-muted font-size-sm mt-1"}, "一个都不勾表示「每天」；结束时间早于开始时间表示跨零点。")),
                    h("div", {className: "mb-3"}, h("label", null, "备注"),
                        h("input", {type: "text", maxLength: 255, className: "form-control form-control-sm", placeholder: "例如：晚高峰", value: form.remark, onChange: function(event) { self.setForm("remark", event.target.value); }})),
                    h("div", null,
                        h(Button["a"], {type: "primary", htmlType: "submit", loading: this.state.saving}, "保存规则"),
                        h(Button["a"], {style: {marginLeft: 8}, disabled: this.state.saving, onClick: function() { self.closeForm(); }}, "取消")))));
        }

        renderSettings() {
            var self = this, settings = this.state.settings;
            if (!settings) return null;
            function field(key, label, copy, step) {
                return h("div", {className: "col-md-4 mb-3"}, h("label", null, label),
                    h("input", {type: "number", step: step || "1", min: "0", className: "form-control form-control-sm", value: settings[key], onChange: function(event) { self.setSetting(key, event.target.value); }}),
                    h("div", {className: "text-muted font-size-sm mt-1"}, copy));
            }
            return h("div", {className: "block block-rounded"}, h("div", {className: "block-content"},
                h("h5", {className: "font-w600 mb-1"}, "峰值判定参数"),
                h("p", {className: "text-muted font-size-sm"}, "每分钟对每个有流量的用户算一次速率：超过瞬时阈值的分钟算突发，连续突发不超过豁免时长时不计入叠加；持续超过持续阈值累计到门槛后按叠加倍率计费。判定最长有 1 分钟延迟。"),
                h("div", {className: "d-flex justify-content-between align-items-center py-2 border-bottom mb-3"},
                    h("div", null, h("div", {className: "font-w600"}, "启用动态倍率"),
                        h("div", {className: "text-muted font-size-sm mt-1"}, "关闭时状态照常推进（便于观察），但倍率一律按 1.0 计费。")),
                    h(Switch["a"], {checked: enabled(settings.enabled), onChange: function(checked) { self.setSetting("enabled", checked ? 1 : 0); }})),
                h("div", {className: "row"},
                    field("instant_mbps", "瞬时阈值（Mbps）", "超过它的分钟算突发，默认 50。", "1"),
                    field("sustained_mbps", "持续阈值（Mbps）", "超过它才开始累计叠加计数，默认 10。", "1"),
                    field("burst_exempt_minutes", "突发豁免时长（分钟）", "连续突发超过这个时长就不再算瞬时，默认 3。", "1"),
                    field("stack_minutes", "叠加门槛（分钟）", "持续计数达到它才开始叠加，默认 5。", "1"),
                    field("stack_multiplier", "叠加倍率", "达到门槛后乘多少倍，默认 1.5。", "0.05"),
                    field("decay_step", "回落步长", "低于持续阈值时计数每轮减多少，默认 1。", "1")),
                h(Button["a"], {type: "primary", loading: this.state.saving, onClick: function() { self.saveSettings(); }}, "保存参数")));
        }

        renderBreakdown() {
            var breakdown = this.state.breakdown;
            if (!breakdown) return null;
            var self = this;
            return h("div", {className: "block block-rounded"}, h("div", {className: "block-content"},
                h("div", {className: "d-flex justify-content-between align-items-center mb-2"},
                    h("h5", {className: "font-w600 mb-0"}, "倍率构成 · 用户 #" + breakdown.user_id),
                    h(Button["a"], {size: "sm", onClick: function() { self.setState({breakdown: null}); }}, "关闭")),
                h("p", {className: "text-muted font-size-sm"},
                    breakdown.state
                        ? "动态倍率 " + rateText(breakdown.user_multiplier) + "，当前判定「" + (STATE_LABELS[breakdown.state.state] || breakdown.state.state) + "」，持续计数 " + breakdown.state.high + "、突发计数 " + breakdown.state.burst + "。"
                        : "该用户还没有采样记录。"),
                h(Table["a"], {
                    tableLayout: "auto", rowKey: function(row) { return row.type + ":" + row.id; }, dataSource: breakdown.nodes || [],
                    pagination: !1, scroll: {x: 760},
                    locale: {emptyText: "没有可展示的节点"},
                    columns: [
                        {title: "节点", key: "node", render: function(value, row) { return h("span", null, row.type + " #" + row.id + " " + row.name); }},
                        {title: "基础倍率", key: "rate", width: 100, render: function(value, row) { return h("span", null, rateText(row.rate)); }},
                        {title: "时段倍率", key: "band", width: 100, render: function(value, row) { return h("span", null, rateText(row.band_multiplier)); }},
                        {title: "动态倍率", key: "user", width: 100, render: function(value, row) { return h("span", null, rateText(breakdown.user_multiplier)); }},
                        {title: "实际计费倍率", key: "effective", width: 130, render: function(value, row) { return h("span", {className: "font-w600"}, rateText(row.effective)); }}
                    ]
                })));
        }

        render() {
            var self = this, state = this.state
              , feedback = state.error ? h("div", {className: "alert alert-danger mb-3", role: "alert"}, state.error)
                  : state.notice ? h("div", {className: "alert alert-success mb-3", role: "status"}, state.notice) : null;
            var pages = Math.max(1, Math.ceil(number(state.states.total, 0) / state.limit));
            var content = h("div", null,
                feedback,
                h("div", {className: "block block-rounded"}, h("div", {className: "block-content"},
                    h("div", {className: "d-flex justify-content-between align-items-center mb-2"},
                        h("div", null, h("h5", {className: "font-w600 mb-1"}, "时段倍率规则"),
                            h("p", {className: "text-muted font-size-sm mb-0"}, "命中多条时取倍率最大的那条；节点规则覆盖全局规则（不是相乘）。时间按站点时区判定。")),
                        h(Button["a"], {type: "primary", onClick: function() { self.openForm(null); }}, "新建规则")),
                    h(Table["a"], {
                        tableLayout: "auto", rowKey: "id", dataSource: state.rules, pagination: !1, scroll: {x: 900},
                        locale: {emptyText: "还没有规则，倍率按节点自身的值计费"},
                        columns: this.ruleColumns()
                    }))),
                this.renderForm(),
                this.renderSettings(),
                this.renderBreakdown(),
                h("div", {className: "block block-rounded"}, h("div", {className: "block-content"},
                    h("div", {className: "d-flex justify-content-between align-items-center mb-2"},
                        h("div", null, h("h5", {className: "font-w600 mb-1"}, "正在叠加的用户"),
                            h("p", {className: "text-muted font-size-sm mb-0"}, "每分钟刷新的实时台账。点「倍率构成」可以看到这个数字是怎么算出来的。")),
                        h("div", null,
                            h("label", {className: "mr-3 font-w400"},
                                h("input", {type: "checkbox", className: "mr-1", checked: state.onlyStacked, onChange: function(event) { self.setState({onlyStacked: event.target.checked, page: 1}, function() { self.load(); }); }}),
                                "只看叠加中的"),
                            h("input", {type: "text", className: "form-control form-control-sm d-inline-block", style: {width: 180}, placeholder: "邮箱或用户 ID", value: state.keyword, onChange: function(event) { self.setState({keyword: event.target.value}); }, onKeyDown: function(event) { if (event.key === "Enter") { self.setState({page: 1}, function() { self.load(); }); } }}),
                            h(Button["a"], {size: "sm", style: {marginLeft: 8}, onClick: function() { self.setState({page: 1}, function() { self.load(); }); }}, "查询"))),
                    h(Table["a"], {
                        tableLayout: "auto", rowKey: "user_id", dataSource: state.states.rows, scroll: {x: 900},
                        locale: {emptyText: state.onlyStacked ? "当前没有用户被叠加倍率" : "还没有采样记录"},
                        pagination: {current: state.page, pageSize: state.limit, total: number(state.states.total, 0), showSizeChanger: !1,
                            onChange: function(page) { self.setState({page: page}, function() { self.load(); }); }},
                        columns: this.stateColumns()
                    }))));
            return h(Page["a"], Object.assign({}, this.props, {title: "动态倍率"}), h(Spin["a"], {loading: state.loading}, content));
        }
    }
    t.default = RatePage;
}
