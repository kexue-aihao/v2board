resellerpage: function(e, t, n) {
    "use strict";
    n.r(t);
    var lodash = n("jehZ")
      , assign = n.n(lodash)
      , Table = (n("g9YV"), n("wCAj"))
      , Button = (n("+L6B"), n("2/Rp"))
      , Modal = (n("2qtc"), n("kLXV"))
      , React = n("q1tI")
      , ReactDefault = n.n(React)
      , Page = n("Bl7J")
      , Spin = n("v32e")
      , h = ReactDefault.a.createElement;

    var ADMIN_API = "/api/v1/";
    var STYLE_ID = "reseller-admin-umi-style";

    function ensureStyles() {
        if (document.getElementById(STYLE_ID)) return;
        var style = document.createElement("style");
        style.id = STYLE_ID;
        style.textContent = ""
            + ".reseller-admin-page{color:#495057;font-size:14px;line-height:1.5}"
            + ".reseller-admin-page *{box-sizing:border-box}.ra-toolbar{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;padding:15px}"
            + ".ra-toolbar h2{margin:0;color:#343a40;font-size:18px;font-weight:600}.ra-toolbar p{margin:4px 0 0;color:#868e96;font-size:13px}"
            + ".ra-toolbar-actions{display:flex;gap:8px;flex-wrap:wrap}.ra-summary{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;padding:0 15px 15px}"
            + ".ra-summary-card{padding:12px 14px;border:1px solid #e9ecef;border-left:3px solid #2f80ed;background:#fff}"
            + ".ra-summary-card:nth-child(2){border-left-color:#d99a00}.ra-summary-card:nth-child(3){border-left-color:#008f83}.ra-summary-card:nth-child(4){border-left-color:#8792a2}"
            + ".ra-summary-label{color:#868e96;font-size:12px}.ra-summary-value{margin-top:4px;color:#212529;font-size:22px;font-weight:600;line-height:1}"
            + ".ra-tabs{display:flex;gap:24px;padding:0 15px;border-bottom:1px solid #e9ecef}.ra-tab{position:relative;padding:11px 0 10px;border:0;color:#6c757d;background:transparent;font-size:14px;cursor:pointer}"
            + ".ra-tab:hover,.ra-tab-active{color:#0667d9}.ra-tab-active:after{position:absolute;right:0;bottom:-1px;left:0;height:2px;background:#0667d9;content:\"\"}"
            + ".ra-filter{display:flex;align-items:center;gap:8px;padding:15px}.ra-filter input,.ra-filter select{height:38px;padding:0 10px;border:1px solid #ced4da;border-radius:4px;color:#495057;background:#fff}"
            + ".ra-filter input{width:340px;max-width:100%}.ra-filter select{min-width:150px}.ra-table-wrap{padding:0 0 15px;overflow-x:auto}.ra-table{width:100%;min-width:820px;border-collapse:collapse}"
            + ".ra-table th{padding:11px 15px;border-top:1px solid #f1f3f5;border-bottom:1px solid #e9ecef;color:#6c757d;background:#f8f9fa;font-size:12px;font-weight:500;text-align:left;white-space:nowrap}"
            + ".ra-table td{padding:13px 15px;border-bottom:1px solid #edf0f2;color:#495057;vertical-align:middle}.ra-table tbody tr:hover{background:#fbfcfe}"
            + ".ra-table .ra-right{text-align:right}.ra-primary{color:#212529;font-weight:600}.ra-secondary{display:block;margin-top:3px;color:#868e96;font-size:12px}"
            + ".ra-actions{white-space:nowrap;text-align:right}.ra-actions a{margin-left:12px;color:#0667d9;cursor:pointer;text-decoration:none}.ra-actions a:hover{text-decoration:underline}.ra-actions a.ra-danger{color:#c53030}"
            + ".ra-tag{display:inline-flex;align-items:center;min-height:22px;padding:0 8px;border-radius:3px;font-size:12px;font-weight:500}.ra-tag-pending{color:#946200;background:#fff3cd}.ra-tag-active{color:#087f72;background:#dff7f3}.ra-tag-rejected{color:#b42318;background:#fde7e5}.ra-tag-suspended,.ra-tag-neutral{color:#667085;background:#eef1f5}"
            + ".ra-empty{padding:48px 15px;color:#868e96;text-align:center}.ra-empty strong{display:block;margin-bottom:5px;color:#495057;font-weight:600}.ra-empty span{font-size:12px}.ra-pager{display:flex;align-items:center;justify-content:space-between;padding:0 15px;color:#868e96;font-size:12px}.ra-pager-actions{display:flex;align-items:center;gap:7px}"
            + ".ra-permissions{display:grid;grid-template-columns:minmax(0,1fr) minmax(300px,.9fr);gap:15px;padding:15px}.ra-permission-block{border:1px solid #e9ecef}.ra-permission-head{padding:13px 15px;border-bottom:1px solid #e9ecef}.ra-permission-head h3{margin:0;color:#343a40;font-size:15px}.ra-permission-head p{margin:4px 0 0;color:#868e96;font-size:12px}.ra-permission-body{padding:15px}.ra-form-row{display:flex;align-items:flex-end;gap:8px;margin-bottom:15px}.ra-field{display:flex;flex:1;flex-direction:column;gap:6px;min-width:0;color:#6c757d;font-size:12px}.ra-field input,.ra-field select{width:100%;height:36px;padding:0 9px;border:1px solid #ced4da;border-radius:4px}.ra-checkbox-list{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}.ra-checkbox{display:flex;align-items:center;gap:8px;min-height:34px;padding:0 9px;border:1px solid #e9ecef;color:#495057;font-size:13px}.ra-checkbox input{width:16px;height:16px;margin:0}.ra-permission-wide{grid-column:1 / -1}.ra-modal-body textarea{width:100%;min-height:100px;padding:9px;border:1px solid #ced4da;border-radius:4px;resize:vertical}@media (max-width:900px){.ra-summary{grid-template-columns:repeat(2,minmax(0,1fr))}.ra-permissions{grid-template-columns:1fr}}@media (max-width:600px){.ra-toolbar{display:block}.ra-toolbar-actions{margin-top:12px}.ra-tabs{gap:18px;overflow-x:auto}.ra-filter{display:grid;grid-template-columns:1fr}.ra-filter input,.ra-filter select{width:100%}.ra-summary{gap:8px}.ra-summary-card{padding:11px}.ra-permissions{padding:10px}.ra-form-row{display:grid;grid-template-columns:1fr}.ra-checkbox-list{grid-template-columns:1fr}.ra-pager{align-items:flex-start;flex-direction:column;gap:10px}}";
        document.head.appendChild(style);
    }

    function statusLabel(status) {
        return {pending: "待审核", active: "已启用", rejected: "已拒绝", suspended: "已停用"}[status] || status || "未知";
    }

    function statusTag(status) {
        var normalized = status || "neutral";
        return h("span", {className: "ra-tag ra-tag-" + normalized}, statusLabel(status));
    }

    function orderStatus(status) {
        var item = {0: ["pending", "待支付"], 1: ["pending", "开通中"], 2: ["suspended", "已取消"], 3: ["active", "已完成"]}[Number(status)] || ["neutral", "未知"];
        return h("span", {className: "ra-tag ra-tag-" + item[0]}, item[1]);
    }

    function dateText(value) {
        if (!value) return "-";
        var number = Number(value);
        var date = new Date(number > 1000000000000 ? number : number * 1000);
        if (isNaN(date.getTime())) date = new Date(value);
        return isNaN(date.getTime()) ? "-" : date.toLocaleString();
    }

    function money(value) {
        return "¥" + (Number(value || 0) / 100).toFixed(2);
    }

    function emptyState(title, copy, colSpan) {
        return h("tr", null, h("td", {colSpan: colSpan || 1}, h("div", {className: "ra-empty"}, h("strong", null, title), h("span", null, copy))));
    }

    class ResellerPage extends ReactDefault.a.Component {
        constructor(props) {
            super(props);
            this.state = {
                tab: "accounts",
                keyword: "",
                draftKeyword: "",
                accountStatus: "",
                storeStatus: "",
                draftStatus: "",
                summary: {},
                accounts: {data: [], total: 0, current_page: 1, last_page: 1},
                stores: {data: [], total: 0, current_page: 1, last_page: 1},
                orders: {data: [], total: 0, current_page: 1, last_page: 1},
                templates: [],
                installedDrivers: [],
                allowedDrivers: [],
                modal: null,
                loading: true,
                saving: false,
                error: "",
                notice: ""
            };
            this.unmounted = false;
            this.onKeyDown = this.onKeyDown.bind(this);
        }

        componentDidMount() {
            ensureStyles();
            document.addEventListener("keydown", this.onKeyDown);
            this.fetchAll();
        }

        componentWillUnmount() {
            this.unmounted = true;
            document.removeEventListener("keydown", this.onKeyDown);
        }

        onKeyDown(event) {
            if (event.key === "Escape" && this.state.modal && !this.state.saving) this.closeModal();
        }

        api(path, options) {
            options = options || {};
            var headers = {Accept: "application/json"};
            var authorization = window.localStorage.getItem("authorization");
            if (authorization) headers.authorization = authorization;
            if (options.body) headers["Content-Type"] = "application/json";
            return fetch(ADMIN_API + window.settings.secure_path + path, {method: options.method || "GET", headers: headers, body: options.body}).then(function (response) {
                return response.text().then(function (body) {
                    var data = {};
                    try { data = body ? JSON.parse(body) : {}; } catch (error) {}
                    if (!response.ok) throw new Error(data.message || data.error || "请求失败，请稍后重试");
                    return data;
                });
            });
        }

        query(kind, page) {
            var params = ["current=" + (page || 1), "pageSize=20"];
            var status = kind === "accounts" ? this.state.accountStatus : kind === "stores" ? this.state.storeStatus : "";
            if (status) params.push("status=" + encodeURIComponent(status));
            if (this.state.keyword) params.push("keyword=" + encodeURIComponent(this.state.keyword));
            return "?" + params.join("&");
        }

        fetchAll() {
            var self = this;
            this.setState({loading: true, error: ""});
            Promise.all([
                this.api("/reseller/summary"),
                this.api("/reseller/accounts" + this.query("accounts", 1)),
                this.api("/reseller/stores" + this.query("stores", 1)),
                this.api("/reseller/templates"),
                this.api("/reseller/payment-drivers"),
                this.api("/reseller/orders" + this.query("orders", 1))
            ]).then(function (results) {
                if (self.unmounted) return;
                self.setState({
                    summary: results[0].data || {},
                    accounts: results[1].data || self.state.accounts,
                    stores: results[2].data || self.state.stores,
                    templates: results[3].data || [],
                    installedDrivers: (results[4].data || {}).installed || [],
                    allowedDrivers: (results[4].data || {}).allowed || [],
                    orders: results[5].data || self.state.orders,
                    loading: false
                });
            }).catch(function (error) {
                if (!self.unmounted) self.setState({loading: false, error: error.message || "请求失败，请稍后重试"});
            });
        }

        fetchTable(kind, page) {
            var self = this;
            this.setState({loading: true, error: ""});
            this.api("/reseller/" + kind + this.query(kind, page)).then(function (result) {
                if (self.unmounted) return;
                var patch = {loading: false};
                patch[kind] = result.data || self.state[kind];
                self.setState(patch);
            }).catch(function (error) {
                if (!self.unmounted) self.setState({loading: false, error: error.message || "请求失败，请重试"});
            });
        }

        submitFilter(event) {
            event.preventDefault();
            var patch = {keyword: this.state.draftKeyword.trim(), notice: ""};
            if (this.state.tab === "accounts") patch.accountStatus = this.state.draftStatus;
            if (this.state.tab === "stores") patch.storeStatus = this.state.draftStatus;
            this.setState(patch, this.fetchAll.bind(this));
        }

        switchTab(tab) {
            this.setState({tab: tab, draftStatus: tab === "accounts" ? this.state.accountStatus : this.state.storeStatus, notice: ""});
        }

        reviewAction(item, target, status) {
            this.setState({modal: {id: item.id, target: target, status: status, name: target === "account" ? item.email : item.store_name, reason: ""}, error: ""});
        }

        closeModal() {
            this.setState({modal: null, saving: false});
        }

        submitReview(event) {
            if (event && event.preventDefault) event.preventDefault();
            var modal = this.state.modal;
            var reason = (modal.reason || "").trim();
            if ((modal.status === "rejected" || modal.status === "suspended") && !reason) {
                this.setState({error: "拒绝或停用必须填写审核原因"});
                return;
            }
            var self = this;
            this.setState({saving: true, error: ""});
            this.api(modal.target === "account" ? "/reseller/accounts/review" : "/reseller/stores/review", {method: "POST", body: JSON.stringify({id: modal.id, target: modal.target, status: modal.status, reason: reason})}).then(function () {
                if (self.unmounted) return;
                self.setState({modal: null, saving: false, notice: "审批状态已更新"}, self.fetchAll.bind(self));
            }).catch(function (error) {
                if (!self.unmounted) self.setState({saving: false, error: error.message || "审批失败，请重试"});
            });
        }

        saveTemplate(event) {
            event.preventDefault();
            var form = event.currentTarget;
            var body = {base_plan_id: Number(form.base_plan_id.value), enabled: Number(form.enabled.value), sort: Number(form.sort.value || 0)};
            if (!body.base_plan_id) {
                this.setState({error: "请输入有效的基础套餐 ID"});
                return;
            }
            var self = this;
            this.setState({saving: true, error: ""});
            this.api("/reseller/templates/save", {method: "POST", body: JSON.stringify(body)}).then(function () {
                if (!self.unmounted) self.setState({saving: false, notice: "套餐发布权限已保存"}, self.fetchAll.bind(self));
            }).catch(function (error) {
                if (!self.unmounted) self.setState({saving: false, error: error.message || "保存失败，请重试"});
            });
        }

        toggleDriver(driver, enabled) {
            var drivers = this.state.allowedDrivers.slice();
            var index = drivers.indexOf(driver);
            if (enabled && index === -1) drivers.push(driver);
            if (!enabled && index !== -1) drivers.splice(index, 1);
            this.setState({allowedDrivers: drivers, notice: ""});
        }

        saveDrivers(event) {
            event.preventDefault();
            var self = this;
            this.setState({saving: true, error: ""});
            this.api("/reseller/payment-drivers", {method: "POST", body: JSON.stringify({allowed: this.state.allowedDrivers})}).then(function () {
                if (!self.unmounted) self.setState({saving: false, notice: "支付驱动白名单已保存"});
            }).catch(function (error) {
                if (!self.unmounted) self.setState({saving: false, error: error.message || "保存失败，请重试"});
            });
        }

        actionButtons(item, target) {
            var status = target === "account" ? (item.reseller_status || item.status) : (item.store_status || item.status);
            var buttons = [];
            if (status === "pending") {
                buttons.push(h("a", {key: "approve", onClick: this.reviewAction.bind(this, item, target, "active")}, "审核通过"));
                buttons.push(h("a", {key: "reject", className: "ra-danger", onClick: this.reviewAction.bind(this, item, target, "rejected")}, "拒绝"));
            } else if (status === "active") {
                buttons.push(h("a", {key: "suspend", className: "ra-danger", onClick: this.reviewAction.bind(this, item, target, "suspended")}, "停用"));
            } else if (status === "suspended") {
                buttons.push(h("a", {key: "enable", onClick: this.reviewAction.bind(this, item, target, "active")}, "重新启用"));
            } else {
                buttons.push(h("a", {key: "retry", onClick: this.reviewAction.bind(this, item, target, "pending")}, "重新审核"));
            }
            return h("span", {className: "ra-actions"}, buttons);
        }

        pager(data, kind) {
            var current = Number(data.current_page || 1);
            var last = Number(data.last_page || 1);
            return h("div", {className: "ra-pager"}, h("span", null, "共 ", Number(data.total || 0), " 条"), h("div", {className: "ra-pager-actions"}, h(Button["a"], {size: "small", disabled: current <= 1 || this.state.loading, onClick: this.fetchTable.bind(this, kind, current - 1)}, "上一页"), h("span", null, current, " / ", last), h(Button["a"], {size: "small", disabled: current >= last || this.state.loading, onClick: this.fetchTable.bind(this, kind, current + 1)}, "下一页")));
        }

        filterBar() {
            if (this.state.tab === "permissions") return null;
            return h("form", {className: "ra-filter", onSubmit: this.submitFilter.bind(this)}, h("input", {value: this.state.draftKeyword, onChange: function (event) { this.setState({draftKeyword: event.target.value}); }.bind(this), placeholder: "搜索邮箱、店铺名称或 Slug", "aria-label": "搜索倒卖商"}), h("select", {value: this.state.draftStatus, onChange: function (event) { this.setState({draftStatus: event.target.value}); }.bind(this), "aria-label": "筛选状态"}, h("option", {value: ""}, "全部状态"), h("option", {value: "pending"}, "待审核"), h("option", {value: "active"}, "已启用"), h("option", {value: "rejected"}, "已拒绝"), h("option", {value: "suspended"}, "已停用")), h(Button["a"], {type: "primary", htmlType: "submit"}, "筛选"));
        }

        accountsTable() {
            var self = this;
            var rows = this.state.accounts.data || [];
            var body = rows.length ? rows.map(function (item) {
                return h("tr", {key: item.id}, h("td", null, h("span", {className: "ra-primary"}, item.email || "-"), h("span", {className: "ra-secondary"}, "ID ", item.id)), h("td", null, h("span", {className: "ra-primary"}, item.store_name || "-"), h("span", {className: "ra-secondary"}, "/", item.store_slug || "-")), h("td", null, dateText(item.created_at)), h("td", null, Number(item.customers_count || 0), " / ", Number(item.orders_count || 0)), h("td", null, statusTag(item.reseller_status || item.status)), h("td", {className: "ra-right"}, self.actionButtons(item, "account")));
            }) : [emptyState("暂无倒卖商申请", "新的注册申请会显示在这里。", 6)];
            return h("div", {className: "ra-table-wrap"}, h("table", {className: "ra-table"}, h("thead", null, h("tr", null, h("th", null, "账号"), h("th", null, "店铺"), h("th", null, "注册时间"), h("th", null, "客户 / 订单"), h("th", null, "状态"), h("th", {className: "ra-right"}, "操作"))), h("tbody", null, body)), this.pager(this.state.accounts, "accounts"));
        }

        storesTable() {
            var self = this;
            var rows = this.state.stores.data || [];
            var body = rows.length ? rows.map(function (item) {
                var preview = item.store_status === "active" && item.reseller_status === "active" ? h("a", {key: "preview", href: "/store/" + encodeURIComponent(item.store_slug), target: "_blank", rel: "noreferrer"}, "打开店铺") : null;
                return h("tr", {key: item.id}, h("td", null, h("span", {className: "ra-primary"}, item.store_name || "-"), h("span", {className: "ra-secondary"}, "/", item.store_slug || "-")), h("td", null, item.email || "-"), h("td", null, Number(item.plan_count || 0), " 套套餐 / ", Number(item.payment_count || 0), " 个支付"), h("td", null, statusTag(item.store_status || item.status)), h("td", {className: "ra-right"}, self.actionButtons(item, "store"), preview));
            }) : [emptyState("暂无店铺申请", "店铺注册申请会显示在这里。", 5)];
            return h("div", {className: "ra-table-wrap"}, h("table", {className: "ra-table"}, h("thead", null, h("tr", null, h("th", null, "店铺"), h("th", null, "所属账号"), h("th", null, "销售资源"), h("th", null, "状态"), h("th", {className: "ra-right"}, "操作"))), h("tbody", null, body)), this.pager(this.state.stores, "stores"));
        }

        permissions() {
            var self = this;
            var templates = this.state.templates || [];
            var drivers = this.state.installedDrivers || [];
            var orders = this.state.orders.data || [];
            return h("div", {className: "ra-permissions"}, h("section", {className: "ra-permission-block"}, h("div", {className: "ra-permission-head"}, h("h3", null, "基础套餐发布"), h("p", null, "倒卖商只能销售管理员发布的基础套餐。")), h("div", {className: "ra-permission-body"}, h("form", {className: "ra-form-row", onSubmit: this.saveTemplate.bind(this)}, h("label", {className: "ra-field"}, "基础套餐 ID", h("input", {name: "base_plan_id", type: "number", min: "1", required: true, placeholder: "例如 1"})), h("label", {className: "ra-field"}, "状态", h("select", {name: "enabled", defaultValue: "1"}, h("option", {value: "1"}, "发布"), h("option", {value: "0"}, "撤下"))), h("label", {className: "ra-field"}, "排序", h("input", {name: "sort", type: "number", defaultValue: "0"})), h(Button["a"], {type: "primary", htmlType: "submit", loading: this.state.saving}, "保存")), templates.length ? h("table", {className: "ra-table"}, h("thead", null, h("tr", null, h("th", null, "基础套餐"), h("th", null, "排序"), h("th", null, "状态"))), h("tbody", null, templates.map(function (item) { return h("tr", {key: item.id}, h("td", null, item.plan ? item.plan.name : "#" + item.base_plan_id), h("td", null, Number(item.sort || 0)), h("td", null, statusTag(item.enabled ? "active" : "suspended"))); }))) : h("div", {className: "ra-empty"}, h("strong", null, "暂无套餐模板"), h("span", null, "先发布基础套餐，倒卖商才能配置销售价格。")))), h("section", {className: "ra-permission-block"}, h("div", {className: "ra-permission-head"}, h("h3", null, "支付驱动白名单"), h("p", null, "只允许已安装且明确放行的驱动。")), h("div", {className: "ra-permission-body"}, h("form", {onSubmit: this.saveDrivers.bind(this)}, drivers.length ? h("div", {className: "ra-checkbox-list"}, drivers.map(function (driver) { return h("label", {className: "ra-checkbox", key: driver}, h("input", {type: "checkbox", checked: self.state.allowedDrivers.indexOf(driver) !== -1, onChange: function (event) { self.toggleDriver(driver, event.target.checked); }}), driver); })) : h("div", {className: "ra-empty"}, h("strong", null, "暂无支付驱动"), h("span", null, "安装支付驱动后再配置白名单。")), h(Button["a"], {type: "primary", htmlType: "submit", loading: this.state.saving, style: {marginTop: 14}}, "保存白名单"))), h("section", {className: "ra-permission-block ra-permission-wide"}, h("div", {className: "ra-permission-head"}, h("h3", null, "倒卖商订单审计"), h("p", null, "仅展示订单摘要，不返回支付密钥。")), h("div", {className: "ra-table-wrap"}, h("table", {className: "ra-table"}, h("thead", null, h("tr", null, h("th", null, "交易号"), h("th", null, "倒卖商"), h("th", null, "周期"), h("th", null, "金额"), h("th", null, "状态"), h("th", null, "创建时间"))), h("tbody", null, orders.length ? orders.map(function (item) { return h("tr", {key: item.trade_no || item.created_at}, h("td", null, item.trade_no || "-"), h("td", null, h("span", {className: "ra-primary"}, item.reseller_email || "-"), h("span", {className: "ra-secondary"}, item.store_name || "-")), h("td", null, item.period || "-"), h("td", null, money(item.amount)), h("td", null, orderStatus(item.status)), h("td", null, dateText(item.created_at))); }) : [emptyState("暂无倒卖商订单", "订单创建后会显示在这里。", 6)]))))));
        }

        reviewModal() {
            var self = this;
            var modal = this.state.modal;
            if (!modal) return null;
            var required = modal.status === "rejected" || modal.status === "suspended";
            return h(Modal["a"], {title: statusLabel(modal.status) + "：" + modal.name, visible: true, onCancel: this.closeModal.bind(this), onOk: this.submitReview.bind(this), confirmLoading: this.state.saving, okText: "确认", cancelText: "取消"}, h("div", {className: "ra-modal-body"}, h("p", {className: "text-muted"}, "审批操作会记录管理员、时间和备注。"), h("textarea", {value: modal.reason, required: required, onChange: function (event) { self.setState({modal: assign()({}, self.state.modal, {reason: event.target.value})}); }, placeholder: required ? "请填写拒绝或停用原因" : "可选：填写本次审核备注"})));
        }

        render() {
            var tab = this.state.tab;
            var content = tab === "accounts" ? this.accountsTable() : tab === "stores" ? this.storesTable() : this.permissions();
            var summary = this.state.summary || {};
            var toolbar = h("div", {className: "ra-toolbar"}, h("div", null, h("h2", null, "倒卖商管理"), h("p", null, "账号、店铺与销售权限审核")), h("div", {className: "ra-toolbar-actions"}, h(Button["a"], {onClick: this.fetchAll.bind(this), loading: this.state.loading}, "刷新数据")));
            var summaryCards = h("div", {className: "ra-summary"}, h("div", {className: "ra-summary-card"}, h("div", {className: "ra-summary-label"}, "待审核倒卖商"), h("div", {className: "ra-summary-value"}, Number(summary.pending_resellers || 0))), h("div", {className: "ra-summary-card"}, h("div", {className: "ra-summary-label"}, "待审核店铺"), h("div", {className: "ra-summary-value"}, Number(summary.pending_stores || 0))), h("div", {className: "ra-summary-card"}, h("div", {className: "ra-summary-label"}, "已启用店铺"), h("div", {className: "ra-summary-value"}, Number(summary.active_stores || 0))), h("div", {className: "ra-summary-card"}, h("div", {className: "ra-summary-label"}, "停用倒卖商"), h("div", {className: "ra-summary-value"}, Number(summary.suspended_resellers || 0))));
            var tabs = h("nav", {className: "ra-tabs", role: "tablist", "aria-label": "倒卖商管理模块"}, h("button", {className: "ra-tab" + (tab === "accounts" ? " ra-tab-active" : ""), type: "button", role: "tab", "aria-selected": tab === "accounts", onClick: this.switchTab.bind(this, "accounts")}, "倒卖商审批"), h("button", {className: "ra-tab" + (tab === "stores" ? " ra-tab-active" : ""), type: "button", role: "tab", "aria-selected": tab === "stores", onClick: this.switchTab.bind(this, "stores")}, "店铺审批"), h("button", {className: "ra-tab" + (tab === "permissions" ? " ra-tab-active" : ""), type: "button", role: "tab", "aria-selected": tab === "permissions", onClick: this.switchTab.bind(this, "permissions")}, "销售权限"));
            var page = h("div", {className: "reseller-admin-page"}, this.state.error ? h("div", {className: "alert alert-danger"}, this.state.error) : null, this.state.notice ? h("div", {className: "alert alert-success"}, this.state.notice) : null, h("div", {className: "block block-rounded"}, h("div", {className: "bg-white"}, toolbar, summaryCards, tabs, this.filterBar(), this.state.loading ? h("div", {className: "ra-empty"}, h("strong", null, "正在加载倒卖商数据...")) : content)));
            return h(Page["a"], assign()({}, this.props, {title: "倒卖商管理"}), h(Spin["a"], {loading: this.state.loading}, page), this.reviewModal());
        }
    }
    t.default = ResellerPage;
}
