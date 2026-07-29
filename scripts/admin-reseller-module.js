resellerpage: function(e, t, n) {
    "use strict";
    n.r(t);
    var React = n("q1tI")
      , ReactDefault = n.n(React)
      , h = ReactDefault.a.createElement;

    var ADMIN_API = "/api/v1/";
    var STYLE_ID = "reseller-admin-design";

    function ensureStyles() {
        if (document.getElementById(STYLE_ID)) return;
        var style = document.createElement("style");
        style.id = STYLE_ID;
        style.textContent = ""
            + ".reseller-admin-page{--ra-ink:#172033;--ra-muted:#687386;--ra-border:#e5e9f0;--ra-surface:#fff;--ra-canvas:#f5f7fb;--ra-primary:#246bce;--ra-primary-soft:#edf5ff;--ra-teal:#0f766e;--ra-teal-soft:#e7f7f4;--ra-warn:#a15c00;--ra-warn-soft:#fff5dc;--ra-danger:#b42318;--ra-danger-soft:#fff0ee;--ra-shadow:0 8px 24px rgba(23,32,51,.06);color:var(--ra-ink);font-size:14px;line-height:1.5;padding:24px;background:var(--ra-canvas);min-height:100%;box-sizing:border-box}"
            + ".reseller-admin-page *{box-sizing:border-box}.reseller-admin-page button,.reseller-admin-page input,.reseller-admin-page select,.reseller-admin-page textarea{font:inherit}.reseller-admin-page button{cursor:pointer}.ra-header{display:flex;align-items:flex-start;justify-content:space-between;gap:24px;margin:0 auto 22px;max-width:1440px}.ra-eyebrow{margin:0 0 5px;color:var(--ra-primary);font-size:11px;font-weight:700;letter-spacing:.12em;text-transform:uppercase}.ra-title{margin:0;color:var(--ra-ink);font-size:24px;font-weight:700;letter-spacing:0}.ra-subtitle{margin:5px 0 0;color:var(--ra-muted);font-size:13px}.ra-header-actions{display:flex;align-items:center;gap:8px}.ra-btn{display:inline-flex;align-items:center;justify-content:center;gap:7px;min-height:36px;padding:0 13px;border:1px solid transparent;border-radius:5px;font-size:13px;font-weight:600;transition:background .15s,border-color .15s,color .15s,box-shadow .15s}.ra-btn:focus-visible,.ra-input:focus-visible,.ra-select:focus-visible,.ra-textarea:focus-visible{outline:2px solid var(--ra-primary);outline-offset:2px}.ra-btn:disabled{cursor:not-allowed;opacity:.58}.ra-btn-primary{color:#fff;background:var(--ra-primary)}.ra-btn-primary:hover:not(:disabled){background:#1d5bb2}.ra-btn-quiet{color:#334155;border-color:var(--ra-border);background:#fff}.ra-btn-quiet:hover:not(:disabled){border-color:#b9c7da;background:#f8fafc}.ra-btn-danger{color:var(--ra-danger);border-color:#f2c8c3;background:#fff}.ra-btn-danger:hover:not(:disabled){background:var(--ra-danger-soft)}.ra-btn-icon{width:36px;padding:0}.ra-error,.ra-notice{max-width:1440px;margin:0 auto 16px;padding:11px 13px;border:1px solid;border-radius:5px}.ra-error{color:var(--ra-danger);border-color:#f2c8c3;background:var(--ra-danger-soft)}.ra-notice{color:var(--ra-teal);border-color:#b9e6de;background:var(--ra-teal-soft)}"
            + ".ra-summary{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;max-width:1440px;margin:0 auto 18px}.ra-summary-card{position:relative;min-height:96px;padding:16px 17px;border:1px solid var(--ra-border);border-radius:6px;background:var(--ra-surface);box-shadow:var(--ra-shadow);overflow:hidden}.ra-summary-card:before{position:absolute;inset:0 auto 0 0;width:3px;background:var(--ra-primary);content:\"\"}.ra-summary-card:nth-child(2):before{background:#d18a00}.ra-summary-card:nth-child(3):before{background:var(--ra-teal)}.ra-summary-card:nth-child(4):before{background:#7a8699}.ra-summary-label{color:var(--ra-muted);font-size:12px}.ra-summary-value{margin-top:6px;color:var(--ra-ink);font-size:26px;font-weight:700;line-height:1}.ra-workspace{max-width:1440px;margin:0 auto}.ra-tabs{display:flex;gap:20px;margin-bottom:14px;border-bottom:1px solid var(--ra-border)}.ra-tab{position:relative;padding:10px 2px 11px;border:0;color:var(--ra-muted);background:transparent;font-size:14px;font-weight:600}.ra-tab:hover{color:var(--ra-primary)}.ra-tab-active{color:var(--ra-primary)}.ra-tab-active:after{position:absolute;right:0;bottom:-1px;left:0;height:2px;background:var(--ra-primary);content:\"\"}.ra-toolbar{display:flex;align-items:center;gap:8px;margin-bottom:14px;padding:12px;border:1px solid var(--ra-border);border-radius:6px;background:#fff}.ra-input,.ra-select,.ra-textarea{width:100%;border:1px solid #d3dae5;border-radius:5px;color:var(--ra-ink);background:#fff}.ra-input,.ra-select{height:36px;padding:0 10px}.ra-textarea{min-height:100px;padding:9px 10px;resize:vertical}.ra-toolbar .ra-input{max-width:340px}.ra-toolbar .ra-select{max-width:150px}.ra-panel{border:1px solid var(--ra-border);border-radius:6px;background:var(--ra-surface);box-shadow:var(--ra-shadow);overflow:hidden}.ra-panel-head{display:flex;align-items:flex-start;justify-content:space-between;gap:18px;padding:17px 18px;border-bottom:1px solid var(--ra-border)}.ra-panel-title{margin:0;color:var(--ra-ink);font-size:15px;font-weight:700}.ra-panel-help{margin:4px 0 0;color:var(--ra-muted);font-size:12px}.ra-panel-body{padding:16px 18px}.ra-table-wrap{overflow-x:auto}.ra-table{width:100%;min-width:760px;border-collapse:collapse}.ra-table th{padding:10px 12px;color:#778399;background:#fbfcfe;font-size:11px;font-weight:700;letter-spacing:.04em;text-align:left;text-transform:uppercase;white-space:nowrap}.ra-table td{padding:13px 12px;border-top:1px solid #edf0f4;color:#344054;vertical-align:middle}.ra-table tbody tr:hover{background:#fbfdff}.ra-table .ra-number{text-align:right}.ra-table .ra-actions{text-align:right;white-space:nowrap}.ra-cell-primary{color:var(--ra-ink);font-weight:600}.ra-cell-secondary{display:block;margin-top:2px;color:var(--ra-muted);font-size:12px}.ra-tag{display:inline-flex;align-items:center;min-height:24px;padding:0 8px;border-radius:999px;font-size:12px;font-weight:600;white-space:nowrap}.ra-tag-pending{color:var(--ra-warn);background:var(--ra-warn-soft)}.ra-tag-active{color:var(--ra-teal);background:var(--ra-teal-soft)}.ra-tag-rejected{color:var(--ra-danger);background:var(--ra-danger-soft)}.ra-tag-suspended,.ra-tag-neutral{color:#5d6879;background:#eef1f5}.ra-inline-actions{display:inline-flex;align-items:center;gap:6px}.ra-link{color:var(--ra-primary);font-weight:600;text-decoration:none}.ra-link:hover{text-decoration:underline}.ra-empty{padding:42px 16px;color:var(--ra-muted);text-align:center}.ra-empty-title{margin:0 0 4px;color:var(--ra-ink);font-weight:600}.ra-empty-copy{margin:0;font-size:12px}.ra-loading{padding:44px;color:var(--ra-muted);text-align:center}.ra-pager{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-top:14px;color:var(--ra-muted);font-size:12px}.ra-pager-actions{display:flex;align-items:center;gap:7px}.ra-small-btn{min-height:30px;padding:0 9px;font-size:12px}.ra-permissions-grid{display:grid;grid-template-columns:minmax(0,1.15fr) minmax(280px,.85fr);gap:14px}.ra-permissions-grid .ra-panel-wide{grid-column:1 / -1}.ra-form-row{display:flex;align-items:flex-end;gap:8px;margin-bottom:16px}.ra-field{display:flex;flex:1;flex-direction:column;gap:6px;min-width:0}.ra-field-label{color:#596579;font-size:12px;font-weight:600}.ra-checkbox-list{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}.ra-checkbox{display:flex;align-items:center;gap:8px;min-height:36px;padding:0 10px;border:1px solid var(--ra-border);border-radius:5px;color:#344054;background:#fbfcfe}.ra-checkbox:focus-within{border-color:var(--ra-primary);box-shadow:0 0 0 2px rgba(36,107,206,.1)}.ra-checkbox input{width:16px;height:16px;margin:0;accent-color:var(--ra-primary)}.ra-modal-backdrop{position:fixed;inset:0;z-index:3000;display:flex;align-items:center;justify-content:center;padding:20px;background:rgba(23,32,51,.48)}.ra-modal{width:min(480px,100%);border:1px solid var(--ra-border);border-radius:6px;background:#fff;box-shadow:0 20px 60px rgba(23,32,51,.24)}.ra-modal-head{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;padding:17px 18px;border-bottom:1px solid var(--ra-border)}.ra-modal-title{margin:0;color:var(--ra-ink);font-size:16px;font-weight:700}.ra-modal-copy{margin:3px 0 0;color:var(--ra-muted);font-size:12px}.ra-modal-body{padding:18px}.ra-modal-footer{display:flex;justify-content:flex-end;gap:8px;padding:12px 18px;border-top:1px solid var(--ra-border)}.ra-close{width:32px;height:32px;padding:0;border:0;border-radius:5px;color:#687386;background:transparent;font-size:20px;line-height:1}.ra-close:hover{color:var(--ra-ink);background:#f1f4f8}.ra-required{color:var(--ra-danger)}@media (max-width:900px){.reseller-admin-page{padding:18px}.ra-summary{grid-template-columns:repeat(2,minmax(0,1fr))}.ra-permissions-grid{grid-template-columns:1fr}}@media (max-width:600px){.ra-header{display:block}.ra-header-actions{margin-top:14px}.ra-header-actions .ra-btn{width:100%}.ra-summary{gap:8px}.ra-summary-card{min-height:84px;padding:13px}.ra-summary-value{font-size:22px}.ra-tabs{gap:14px;overflow-x:auto}.ra-tab{flex:0 0 auto}.ra-toolbar{display:grid;grid-template-columns:1fr}.ra-toolbar .ra-input,.ra-toolbar .ra-select{max-width:none}.ra-form-row{display:grid;grid-template-columns:1fr}.ra-checkbox-list{grid-template-columns:1fr}.ra-panel-head{display:block}.ra-panel-head .ra-btn{margin-top:10px}.ra-pager{align-items:flex-start;flex-direction:column}.ra-table{min-width:700px}}@media (prefers-reduced-motion:reduce){.ra-btn{transition:none}}";
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
        var value = Number(status);
        var map = {0: ["pending", "待支付"], 1: ["pending", "开通中"], 2: ["suspended", "已取消"], 3: ["active", "已完成"]};
        var item = map[value] || ["neutral", "未知"];
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
        return h("tr", null, h("td", {colSpan: colSpan || 1}, h("div", {className: "ra-empty"}, h("p", {className: "ra-empty-title"}, title), h("p", {className: "ra-empty-copy"}, copy))));
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
                if (!self.unmounted) self.setState({loading: false, error: error.message || "请求失败，请稍后重试"});
            });
        }

        submitFilter(event) {
            event.preventDefault();
            var tab = this.state.tab;
            var patch = {keyword: this.state.draftKeyword.trim(), notice: ""};
            if (tab === "accounts") patch.accountStatus = this.state.draftStatus;
            if (tab === "stores") patch.storeStatus = this.state.draftStatus;
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
            event.preventDefault();
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
            var self = this;
            var body = {base_plan_id: Number(form.base_plan_id.value), enabled: Number(form.enabled.value), sort: Number(form.sort.value || 0)};
            if (!body.base_plan_id) {
                this.setState({error: "请输入有效的基础套餐 ID"});
                return;
            }
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
                buttons.push(h("button", {key: "approve", type: "button", className: "ra-btn ra-btn-primary ra-small-btn", onClick: this.reviewAction.bind(this, item, target, "active")}, "审核通过"));
                buttons.push(h("button", {key: "reject", type: "button", className: "ra-btn ra-btn-danger ra-small-btn", onClick: this.reviewAction.bind(this, item, target, "rejected")}, "拒绝"));
            } else if (status === "active") {
                buttons.push(h("button", {key: "suspend", type: "button", className: "ra-btn ra-btn-danger ra-small-btn", onClick: this.reviewAction.bind(this, item, target, "suspended")}, "停用"));
            } else if (status === "suspended") {
                buttons.push(h("button", {key: "enable", type: "button", className: "ra-btn ra-btn-quiet ra-small-btn", onClick: this.reviewAction.bind(this, item, target, "active")}, "重新启用"));
            } else {
                buttons.push(h("button", {key: "retry", type: "button", className: "ra-btn ra-btn-quiet ra-small-btn", onClick: this.reviewAction.bind(this, item, target, "pending")}, "重新审核"));
            }
            return h("span", {className: "ra-inline-actions"}, buttons);
        }

        pager(data, kind) {
            var current = Number(data.current_page || 1);
            var last = Number(data.last_page || 1);
            return h("div", {className: "ra-pager"}, h("span", null, "共 ", Number(data.total || 0), " 条"), h("div", {className: "ra-pager-actions"}, h("button", {type: "button", className: "ra-btn ra-btn-quiet ra-small-btn", disabled: current <= 1 || this.state.loading, onClick: this.fetchTable.bind(this, kind, current - 1)}, "上一页"), h("span", null, current, " / ", last), h("button", {type: "button", className: "ra-btn ra-btn-quiet ra-small-btn", disabled: current >= last || this.state.loading, onClick: this.fetchTable.bind(this, kind, current + 1)}, "下一页")));
        }

        filterBar() {
            if (this.state.tab === "permissions") return null;
            return h("form", {className: "ra-toolbar", onSubmit: this.submitFilter.bind(this)}, h("input", {className: "ra-input", name: "keyword", value: this.state.draftKeyword, onChange: function (event) { this.setState({draftKeyword: event.target.value}); }.bind(this), placeholder: "搜索邮箱、店铺名称或 Slug", "aria-label": "搜索倒卖商"}), h("select", {className: "ra-select", value: this.state.draftStatus, onChange: function (event) { this.setState({draftStatus: event.target.value}); }.bind(this), "aria-label": "筛选状态"}, h("option", {value: ""}, "全部状态"), h("option", {value: "pending"}, "待审核"), h("option", {value: "active"}, "已启用"), h("option", {value: "rejected"}, "已拒绝"), h("option", {value: "suspended"}, "已停用")), h("button", {className: "ra-btn ra-btn-primary", type: "submit"}, "筛选"));
        }

        accountsTable() {
            var self = this;
            var rows = this.state.accounts.data || [];
            var head = h("div", {className: "ra-panel-head"}, h("div", null, h("h2", {className: "ra-panel-title"}, "倒卖商账号审批"), h("p", {className: "ra-panel-help"}, "账号审批通过后才能登录工作台。")), h("span", {className: "ra-tag ra-tag-neutral"}, "账号状态"));
            var table = h("div", {className: "ra-table-wrap"}, h("table", {className: "ra-table"}, h("thead", null, h("tr", null, h("th", null, "账号"), h("th", null, "店铺"), h("th", null, "注册时间"), h("th", null, "客户 / 订单"), h("th", null, "状态"), h("th", {className: "ra-actions"}, "操作"))), h("tbody", null, rows.length ? rows.map(function (item) { return h("tr", {key: item.id}, h("td", null, h("span", {className: "ra-cell-primary"}, item.email), h("span", {className: "ra-cell-secondary"}, "ID ", item.id)), h("td", null, h("span", {className: "ra-cell-primary"}, item.store_name || "-"), h("span", {className: "ra-cell-secondary"}, "/", item.store_slug || "-")), h("td", null, dateText(item.created_at)), h("td", null, Number(item.customers_count || 0), " / ", Number(item.orders_count || 0)), h("td", null, statusTag(item.reseller_status || item.status)), h("td", {className: "ra-actions"}, self.actionButtons(item, "account"))); }) : [emptyState("暂无倒卖商申请", "新的注册申请会显示在这里。", 6)])));
            return h("section", {className: "ra-panel"}, head, h("div", {className: "ra-panel-body"}, table), this.pager(this.state.accounts, "accounts"));
        }

        storesTable() {
            var self = this;
            var rows = this.state.stores.data || [];
            var head = h("div", {className: "ra-panel-head"}, h("div", null, h("h2", {className: "ra-panel-title"}, "店铺注册审批"), h("p", {className: "ra-panel-help"}, "店铺和账号都启用后，才允许对外销售。")), h("span", {className: "ra-tag ra-tag-neutral"}, "店铺状态"));
            var table = h("div", {className: "ra-table-wrap"}, h("table", {className: "ra-table"}, h("thead", null, h("tr", null, h("th", null, "店铺"), h("th", null, "所属账号"), h("th", null, "销售资源"), h("th", null, "状态"), h("th", {className: "ra-actions"}, "操作"))), h("tbody", null, rows.length ? rows.map(function (item) { return h("tr", {key: item.id}, h("td", null, h("span", {className: "ra-cell-primary"}, item.store_name || "-"), h("span", {className: "ra-cell-secondary"}, "/", item.store_slug || "-")), h("td", null, item.email || "-"), h("td", null, Number(item.plan_count || 0), " 套套餐 / ", Number(item.payment_count || 0), " 个支付"), h("td", null, statusTag(item.store_status || item.status)), h("td", {className: "ra-actions"}, h("span", {className: "ra-inline-actions"}, self.actionButtons(item, "store"), item.store_status === "active" && item.reseller_status === "active" ? h("a", {className: "ra-link", href: "/store/" + encodeURIComponent(item.store_slug), target: "_blank", rel: "noreferrer"}, "预览") : null))); }) : [emptyState("暂无店铺申请", "店铺注册申请会显示在这里。", 5)])));
            return h("section", {className: "ra-panel"}, head, h("div", {className: "ra-panel-body"}, table), this.pager(this.state.stores, "stores"));
        }

        permissions() {
            var self = this;
            var templates = this.state.templates || [];
            var drivers = this.state.installedDrivers || [];
            var orders = this.state.orders.data || [];
            return h("div", {className: "ra-permissions-grid"}, h("section", {className: "ra-panel"}, h("div", {className: "ra-panel-head"}, h("div", null, h("h2", {className: "ra-panel-title"}, "基础套餐发布"), h("p", {className: "ra-panel-help"}, "倒卖商只能销售管理员发布的基础套餐。"))), h("div", {className: "ra-panel-body"}, h("form", {className: "ra-form-row", onSubmit: this.saveTemplate.bind(this)}, h("label", {className: "ra-field"}, h("span", {className: "ra-field-label"}, "基础套餐 ID"), h("input", {className: "ra-input", name: "base_plan_id", type: "number", min: "1", required: true, placeholder: "例如 1"})), h("label", {className: "ra-field"}, h("span", {className: "ra-field-label"}, "状态"), h("select", {className: "ra-select", name: "enabled", defaultValue: "1"}, h("option", {value: "1"}, "发布"), h("option", {value: "0"}, "撤下"))), h("label", {className: "ra-field"}, h("span", {className: "ra-field-label"}, "排序"), h("input", {className: "ra-input", name: "sort", type: "number", defaultValue: "0"})), h("button", {className: "ra-btn ra-btn-primary", type: "submit", disabled: this.state.saving}, this.state.saving ? "保存中..." : "保存")), h("div", {className: "ra-table-wrap"}, h("table", {className: "ra-table"}, h("thead", null, h("tr", null, h("th", null, "基础套餐"), h("th", null, "排序"), h("th", null, "状态"))), h("tbody", null, templates.length ? templates.map(function (item) { return h("tr", {key: item.id}, h("td", null, h("span", {className: "ra-cell-primary"}, item.plan ? item.plan.name : "#" + item.base_plan_id)), h("td", null, Number(item.sort || 0)), h("td", null, statusTag(item.enabled ? "active" : "suspended"))); }) : [emptyState("暂无套餐模板", "先发布基础套餐，倒卖商才能配置销售价格。", 3)]))))), h("section", {className: "ra-panel"}, h("div", {className: "ra-panel-head"}, h("div", null, h("h2", {className: "ra-panel-title"}, "支付驱动白名单"), h("p", {className: "ra-panel-help"}, "只允许已安装且明确放行的驱动。"))), h("div", {className: "ra-panel-body"}, h("form", {onSubmit: this.saveDrivers.bind(this)}, drivers.length ? h("div", {className: "ra-checkbox-list"}, drivers.map(function (driver) { return h("label", {className: "ra-checkbox", key: driver}, h("input", {type: "checkbox", checked: self.state.allowedDrivers.indexOf(driver) !== -1, onChange: function (event) { self.toggleDriver(driver, event.target.checked); }}), h("span", null, driver)); })) : h("div", {className: "ra-empty"}, h("p", {className: "ra-empty-title"}, "暂无支付驱动"), h("p", {className: "ra-empty-copy"}, "安装支付驱动后再配置白名单。")), h("button", {className: "ra-btn ra-btn-primary", type: "submit", disabled: this.state.saving, style: {marginTop: "14px"}}, this.state.saving ? "保存中..." : "保存白名单")))), h("section", {className: "ra-panel ra-panel-wide"}, h("div", {className: "ra-panel-head"}, h("div", null, h("h2", {className: "ra-panel-title"}, "倒卖商订单审计"), h("p", {className: "ra-panel-help"}, "仅展示订单摘要，不返回支付密钥。"))), h("div", {className: "ra-panel-body"}, h("div", {className: "ra-table-wrap"}, h("table", {className: "ra-table"}, h("thead", null, h("tr", null, h("th", null, "交易号"), h("th", null, "倒卖商"), h("th", null, "周期"), h("th", {className: "ra-number"}, "金额"), h("th", null, "状态"), h("th", null, "创建时间"))), h("tbody", null, orders.length ? orders.map(function (item) { return h("tr", {key: item.trade_no || item.created_at}, h("td", null, h("code", null, item.trade_no || "-")), h("td", null, h("span", {className: "ra-cell-primary"}, item.reseller_email || "-"), h("span", {className: "ra-cell-secondary"}, item.store_name || "-")), h("td", null, item.period || "-"), h("td", {className: "ra-number"}, money(item.amount)), h("td", null, orderStatus(item.status)), h("td", null, dateText(item.created_at))); }) : [emptyState("暂无倒卖商订单", "订单创建后会显示在这里。", 6)])), this.pager(this.state.orders, "orders")))));
        }

        reviewModal() {
            var self = this;
            var modal = this.state.modal;
            if (!modal) return null;
            var required = modal.status === "rejected" || modal.status === "suspended";
            return h("div", {className: "ra-modal-backdrop", role: "presentation", onMouseDown: function (event) { if (event.target === event.currentTarget && !self.state.saving) self.closeModal(); }}, h("section", {className: "ra-modal", role: "dialog", "aria-modal": "true", "aria-labelledby": "reseller-review-title", onMouseDown: function (event) { event.stopPropagation(); }}, h("div", {className: "ra-modal-head"}, h("div", null, h("h2", {id: "reseller-review-title", className: "ra-modal-title"}, statusLabel(modal.status), "：", modal.name), h("p", {className: "ra-modal-copy"}, "审批操作会记录管理员、时间和备注。")), h("button", {className: "ra-close", type: "button", onClick: this.closeModal.bind(this), disabled: this.state.saving, "aria-label": "关闭弹窗"}, "×")), h("form", {onSubmit: this.submitReview.bind(this)}, h("div", {className: "ra-modal-body"}, h("label", {className: "ra-field"}, h("span", {className: "ra-field-label"}, "审核备注", required ? h("span", {className: "ra-required"}, " *") : null), h("textarea", {className: "ra-textarea", value: modal.reason, required: required, onChange: function (event) { self.setState({modal: Object.assign({}, self.state.modal, {reason: event.target.value})}); }, placeholder: required ? "请填写拒绝或停用原因" : "可选：填写本次审核备注", autoFocus: true}))), h("div", {className: "ra-modal-footer"}, h("button", {className: "ra-btn ra-btn-quiet", type: "button", onClick: this.closeModal.bind(this), disabled: this.state.saving}, "取消"), h("button", {className: "ra-btn ra-btn-primary", type: "submit", disabled: this.state.saving}, this.state.saving ? "提交中..." : "确认")))));
        }

        render() {
            var tab = this.state.tab;
            var content = tab === "accounts" ? this.accountsTable() : tab === "stores" ? this.storesTable() : this.permissions();
            return h("div", {className: "reseller-admin-page"}, h("header", {className: "ra-header"}, h("div", null, h("p", {className: "ra-eyebrow"}, "CHANNEL OPERATIONS"), h("h1", {className: "ra-title"}, "倒卖商管理"), h("p", {className: "ra-subtitle"}, "在统一后台完成账号、店铺和销售权限审核。")), h("div", {className: "ra-header-actions"}, h("button", {className: "ra-btn ra-btn-quiet", type: "button", onClick: this.fetchAll.bind(this), disabled: this.state.loading}, h("i", {className: "si si-refresh"}), this.state.loading ? "刷新中..." : "刷新数据"))), this.state.error ? h("div", {className: "ra-error", role: "alert"}, this.state.error) : null, this.state.notice ? h("div", {className: "ra-notice", role: "status"}, this.state.notice) : null, h("section", {className: "ra-summary", "aria-label": "倒卖商概览"}, h("div", {className: "ra-summary-card"}, h("div", {className: "ra-summary-label"}, "待审核倒卖商"), h("div", {className: "ra-summary-value"}, Number(this.state.summary.pending_resellers || 0))), h("div", {className: "ra-summary-card"}, h("div", {className: "ra-summary-label"}, "待审核店铺"), h("div", {className: "ra-summary-value"}, Number(this.state.summary.pending_stores || 0))), h("div", {className: "ra-summary-card"}, h("div", {className: "ra-summary-label"}, "已启用店铺"), h("div", {className: "ra-summary-value"}, Number(this.state.summary.active_stores || 0))), h("div", {className: "ra-summary-card"}, h("div", {className: "ra-summary-label"}, "停用倒卖商"), h("div", {className: "ra-summary-value"}, Number(this.state.summary.suspended_resellers || 0)))), h("main", {className: "ra-workspace"}, h("nav", {className: "ra-tabs", role: "tablist", "aria-label": "倒卖商管理模块"}, h("button", {className: "ra-tab" + (tab === "accounts" ? " ra-tab-active" : ""), type: "button", role: "tab", "aria-selected": tab === "accounts", onClick: this.switchTab.bind(this, "accounts")}, "倒卖商审批"), h("button", {className: "ra-tab" + (tab === "stores" ? " ra-tab-active" : ""), type: "button", role: "tab", "aria-selected": tab === "stores", onClick: this.switchTab.bind(this, "stores")}, "店铺审批"), h("button", {className: "ra-tab" + (tab === "permissions" ? " ra-tab-active" : ""), type: "button", role: "tab", "aria-selected": tab === "permissions", onClick: this.switchTab.bind(this, "permissions")}, "销售权限")), this.filterBar(), this.state.loading ? h("div", {className: "ra-panel ra-loading", role: "status"}, "正在加载倒卖商数据...") : content), this.reviewModal());
        }
    }
    t.default = ResellerPage;
}
