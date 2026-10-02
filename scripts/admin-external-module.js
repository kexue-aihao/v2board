externalpage: function(e, t, n) {
    "use strict";
    n.r(t);
    var React = n("q1tI"), ReactDefault = n.n(React), Table = n("wCAj"), Button = n("2/Rp"), Page = n("Bl7J"), Spin = n("v32e"), Switch = n("Sdc0"), h = ReactDefault.a.createElement;

    function number(value, fallback) { var parsed = Number(value); return isFinite(parsed) ? parsed : fallback; }
    function enabled(value) { return value === !0 || value === 1 || value === "1"; }
    function timeText(seconds) { return seconds ? new Date(seconds * 1000).toLocaleString() : "—"; }
    function truncate(value, max) { var text = String(value === null || value === void 0 ? "" : value); return text.length > max ? text.slice(0, max) + "…" : text; }

    var STATUS = {ok: {text: "正常", className: "text-success"}, error: {text: "抓取失败", className: "text-danger"}, never: {text: "还没抓过", className: "text-muted"}};

    class ExternalPage extends ReactDefault.a.Component {
        constructor(props) {
            super(props);
            this.state = {
                loading: !0, saving: !1, refreshing: 0, error: "", notice: "",
                sources: [], groups: [], nodes: [], previewSource: 0, form: null
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

        load(sourceId) {
            var self = this;
            var preview = sourceId === undefined ? this.state.previewSource : number(sourceId, 0);
            this.setState({loading: !0, error: ""});
            this.api("/external/fetch?source_id=" + preview).then(function(result) {
                var data = result.data || {};
                self.setState({
                    loading: !1,
                    sources: data.sources || [],
                    groups: data.groups || [],
                    nodes: data.nodes || [],
                    previewSource: preview
                });
            }).catch(function(error) {
                self.setState({loading: !1, error: error.message || "无法读取外部订阅源"});
            });
        }

        openForm(source) {
            this.setState({
                error: "", notice: "",
                form: source ? {
                    id: source.id, name: source.name, url: source.url,
                    group_id: number(source.group_id, 0), enabled: number(source.enabled, 1) === 1, remark: source.remark || ""
                } : {
                    id: null, name: "", url: "", group_id: this.state.groups.length ? number(this.state.groups[0].id, 0) : 0, enabled: !0, remark: ""
                }
            });
        }
        closeForm() { this.setState({form: null}); }
        setForm(field, value) { var form = Object.assign({}, this.state.form); form[field] = value; this.setState({form: form, error: ""}); }

        submitForm(event) {
            if (event) event.preventDefault();
            var self = this, form = this.state.form;
            if (!form) return;
            if (!/^https?:\/\/.+/i.test(String(form.url || "").trim())) {
                return void this.setState({error: "订阅链接必须是 http 或 https 地址。"});
            }
            if (!number(form.group_id, 0)) return void this.setState({error: "请选择归属权限组。"});

            this.setState({saving: !0, error: "", notice: ""});
            this.api("/external/source/save", {
                method: "POST",
                body: JSON.stringify({
                    id: form.id || undefined,
                    name: form.name,
                    url: String(form.url).trim(),
                    group_id: number(form.group_id, 0),
                    enabled: form.enabled ? 1 : 0,
                    remark: form.remark
                })
            }).then(function(result) {
                var id = result.data && result.data.id;
                self.setState({saving: !1, form: null, notice: "已保存。别忘了点「刷新」把节点抓下来。"});
                self.load(id || undefined);
            }).catch(function(error) {
                self.setState({saving: !1, error: error.message || "保存失败，请重试"});
            });
        }

        dropSource(source) {
            var self = this;
            if (!window.confirm("删除源「" + source.name + "」会一并删掉它导入的 " + source.node_count + " 个节点，确认？")) return;
            this.setState({saving: !0, error: "", notice: ""});
            this.api("/external/source/drop", {method: "POST", body: JSON.stringify({id: source.id})}).then(function() {
                self.setState({saving: !1, notice: "源已删除。"});
                self.load(0);
            }).catch(function(error) {
                self.setState({saving: !1, error: error.message || "删除失败，请重试"});
            });
        }

        refreshSource(source) {
            var self = this;
            this.setState({refreshing: source.id, error: "", notice: ""});
            this.api("/external/source/refresh", {method: "POST", body: JSON.stringify({id: source.id})}).then(function(result) {
                var data = result.data || {};
                self.setState({
                    refreshing: 0,
                    notice: data.ok ? ("「" + source.name + "」解析出 " + data.count + " 个节点。") : "",
                    error: data.ok ? "" : ("「" + source.name + "」抓取失败：" + (data.error || "未知错误"))
                });
                self.load();
            }).catch(function(error) {
                self.setState({refreshing: 0, error: error.message || "刷新失败，请重试"});
            });
        }

        // 串行刷新：服务端一次只抓一个源（每个最长 10 秒），并发会顶到 PHP 执行时限，
        // 而且失败时也看不出是哪一个源的问题。
        refreshAll() {
            var self = this
              , targets = this.state.sources.filter(function(source) { return enabled(source.enabled); });
            if (!targets.length) return void this.setState({notice: "没有启用的源。"});
            this.setState({saving: !0, error: "", notice: ""});
            var index = 0, total = 0, failed = [];
            function next() {
                if (index >= targets.length) {
                    self.setState({
                        saving: !1,
                        refreshing: 0,
                        notice: "全部刷新完成：" + total + " 个节点。",
                        error: failed.length ? ("失败的源：" + failed.join("、")) : ""
                    });
                    self.load();
                    return;
                }
                var source = targets[index++];
                self.setState({refreshing: source.id});
                self.api("/external/source/refresh", {method: "POST", body: JSON.stringify({id: source.id})})
                    .then(function(result) {
                        var data = result.data || {};
                        if (data.ok) total += number(data.count, 0); else failed.push(source.name);
                        next();
                    })
                    .catch(function() { failed.push(source.name); next(); });
            }
            next();
        }

        groupName(id) {
            for (var i = 0; i < this.state.groups.length; i++) {
                if (number(this.state.groups[i].id, 0) === number(id, 0)) return this.state.groups[i].name;
            }
            return "权限组 #" + id;
        }

        sourceColumns() {
            var self = this;
            return [
                {title: "名称", key: "name", render: function(value, source) {
                    return h("div", null,
                        h("div", {className: "font-w600"}, source.name),
                        source.remark ? h("div", {className: "text-muted font-size-sm"}, source.remark) : null);
                }},
                {title: "订阅链接", key: "url", render: function(value, source) {
                    return h("span", {className: "text-muted font-size-sm", title: source.url}, truncate(source.url, 48));
                }},
                {title: "归属权限组", key: "group", width: 140, render: function(value, source) { return h("span", null, self.groupName(source.group_id)); }},
                {title: "节点", key: "nodes", width: 80, render: function(value, source) { return h("span", null, number(source.node_count, 0)); }},
                {title: "状态", key: "status", width: 160, render: function(value, source) {
                    var status = STATUS[source.last_status] || STATUS.never;
                    return h("div", null,
                        h("div", {className: status.className}, status.text),
                        h("div", {className: "text-muted font-size-sm", title: source.last_error || ""}, timeText(source.last_fetch_at)));
                }},
                {title: "启用", key: "enabled", width: 70, render: function(value, source) {
                    return h(Switch["a"], {size: "small", checked: enabled(source.enabled), disabled: self.state.saving, onChange: function(checked) {
                        self.api("/external/source/save", {method: "POST", body: JSON.stringify({
                            id: source.id, name: source.name, url: source.url, group_id: number(source.group_id, 0),
                            enabled: checked ? 1 : 0, remark: source.remark || ""
                        })}).then(function() { self.load(); }).catch(function(error) { self.setState({error: error.message}); });
                    }});
                }},
                {title: "操作", key: "action", width: 250, render: function(value, source) {
                    return h("div", null,
                        h(Button["a"], {size: "sm", style: {marginRight: 6}, loading: self.state.refreshing === source.id, disabled: self.state.saving, onClick: function() { self.refreshSource(source); }}, "刷新"),
                        h(Button["a"], {size: "sm", style: {marginRight: 6}, onClick: function() { self.load(source.id); }}, "预览节点"),
                        h(Button["a"], {size: "sm", style: {marginRight: 6}, onClick: function() { self.openForm(source); }}, "编辑"),
                        h(Button["a"], {size: "sm", type: "danger", disabled: self.state.saving, onClick: function() { self.dropSource(source); }}, "删除"));
                }}
            ];
        }

        nodeColumns() {
            return [
                {title: "名称", key: "name", render: function(value, node) { return h("span", null, "【过渡】" + node.name); }},
                {title: "协议", key: "protocol", width: 120, render: function(value, node) { return h("span", null, node.protocol); }},
                {title: "地址", key: "host", render: function(value, node) { return h("span", null, node.host); }},
                {title: "端口", key: "port", width: 90, render: function(value, node) { return h("span", null, node.port); }}
            ];
        }

        renderForm() {
            var self = this, form = this.state.form;
            if (!form) return null;
            return h("div", {className: "block block-rounded"}, h("div", {className: "block-content"},
                h("h5", {className: "font-w600 mb-3"}, form.id ? "编辑源" : "新建源"),
                h("form", {onSubmit: this.submitForm.bind(this)},
                    h("div", {className: "row"},
                        h("div", {className: "col-md-4 mb-3"}, h("label", null, "名称"),
                            h("input", {type: "text", maxLength: 64, className: "form-control form-control-sm", placeholder: "留空则用域名", value: form.name, onChange: function(event) { self.setForm("name", event.target.value); }})),
                        h("div", {className: "col-md-5 mb-3"}, h("label", null, "订阅链接"),
                            h("input", {type: "text", maxLength: 512, className: "form-control form-control-sm", placeholder: "https://example.com/api/v1/client/subscribe?token=…", value: form.url, onChange: function(event) { self.setForm("url", event.target.value); }})),
                        h("div", {className: "col-md-3 mb-3"}, h("label", null, "归属权限组"),
                            h("select", {className: "form-control form-control-sm", value: form.group_id, onChange: function(event) { self.setForm("group_id", number(event.target.value, 0)); }},
                                h("option", {value: 0}, "请选择"),
                                this.state.groups.map(function(group) { return h("option", {key: group.id, value: group.id}, group.name); })))),
                    h("div", {className: "row"},
                        h("div", {className: "col-md-9 mb-3"}, h("label", null, "备注"),
                            h("input", {type: "text", maxLength: 255, className: "form-control form-control-sm", value: form.remark, onChange: function(event) { self.setForm("remark", event.target.value); }})),
                        h("div", {className: "col-md-3 mb-3"}, h("label", {className: "d-block"}, "启用"),
                            h(Switch["a"], {checked: !!form.enabled, onChange: function(checked) { self.setForm("enabled", !!checked); }}))),
                    h("p", {className: "text-muted font-size-sm"}, "归属权限组决定谁能看到这批过渡节点：用户勾了该分组才会下发。导入的节点不计流量、不占额度。"),
                    h("div", null,
                        h(Button["a"], {type: "primary", htmlType: "submit", loading: this.state.saving}, "保存"),
                        h(Button["a"], {style: {marginLeft: 8}, disabled: this.state.saving, onClick: function() { self.closeForm(); }}, "取消")))));
        }

        render() {
            var self = this, state = this.state
              , feedback = state.error ? h("div", {className: "alert alert-danger mb-3", role: "alert"}, state.error)
                  : state.notice ? h("div", {className: "alert alert-success mb-3", role: "status"}, state.notice) : null;
            var previewSource = null;
            for (var i = 0; i < state.sources.length; i++) {
                if (number(state.sources[i].id, 0) === state.previewSource) previewSource = state.sources[i];
            }

            var content = h("div", null,
                feedback,
                h("div", {className: "block block-rounded"}, h("div", {className: "block-content"},
                    h("div", {className: "d-flex justify-content-between align-items-center mb-2"},
                        h("div", null, h("h5", {className: "font-w600 mb-1"}, "外部订阅源"),
                            h("p", {className: "text-muted font-size-sm mb-0"}, "导入的节点作为过渡线路下发给对应权限组的用户；它们不在节点表里，因此不计流量、不占额度、不参与动态倍率。")),
                        h("div", null,
                            h(Button["a"], {style: {marginRight: 8}, loading: state.saving, disabled: state.loading, onClick: function() { self.refreshAll(); }}, "全部刷新"),
                            h(Button["a"], {type: "primary", onClick: function() { self.openForm(null); }}, "新建源"))),
                    h(Table["a"], {
                        tableLayout: "auto", rowKey: "id", dataSource: state.sources, pagination: !1, scroll: {x: 1100},
                        locale: {emptyText: "还没有配置任何源"},
                        columns: this.sourceColumns()
                    }))),
                this.renderForm(),
                h("div", {className: "block block-rounded"}, h("div", {className: "block-content"},
                    h("h5", {className: "font-w600 mb-1"}, previewSource ? ("节点预览 · " + previewSource.name) : "节点预览"),
                    h("p", {className: "text-muted font-size-sm"}, previewSource
                        ? ("解析出 " + state.nodes.length + " 个节点。当前只有 v2ray 系客户端（v2rayN/NG、SagerNet、PassWall、SSRPlus、Shadowrocket、v2RayTun 等）能看到它们；Clash 系与 sing-box 需要后续版本支持。")
                        : "在源列表里点「预览节点」查看某个源解析出来的线路。"),
                    previewSource ? h(Table["a"], {
                        tableLayout: "auto", rowKey: "id", dataSource: state.nodes, pagination: state.nodes.length > 50 ? {pageSize: 50} : !1,
                        scroll: {x: 720},
                        locale: {emptyText: "这个源还没有导入任何节点，点「刷新」抓一次"},
                        columns: this.nodeColumns()
                    }) : null)));

            return h(Page["a"], Object.assign({}, this.props, {title: "外部订阅源"}), h(Spin["a"], {loading: state.loading}, content));
        }
    }
    t.default = ExternalPage;
}
