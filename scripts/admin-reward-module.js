rewardpage: function(e, t, n) {
    "use strict";
    n.r(t);
    var React = n("q1tI")
      , ReactDefault = n.n(React)
      , Page = n("Bl7J")
      , Spin = n("v32e")
      , h = ReactDefault.a.createElement;
    var STYLE_ID = "reward-admin-umi-style";
    function ensureStyles() {
        if (document.getElementById(STYLE_ID)) return;
        var style = document.createElement("style");
        style.id = STYLE_ID;
        style.textContent = ".reward-admin-page{color:#495057;font-size:14px;line-height:1.5}.reward-admin-page *{box-sizing:border-box}.rw-head{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;padding:18px 20px;border-bottom:1px solid #edf0f2}.rw-head h2{margin:0;color:#343a40;font-size:18px;font-weight:600}.rw-head p{margin:5px 0 0;color:#6c757d;font-size:13px}.rw-save{min-height:38px;padding:0 16px;border:0;border-radius:4px;background:#0667d9;color:#fff;font:inherit;cursor:pointer}.rw-save:disabled{opacity:.55;cursor:wait}.rw-body{display:grid;grid-template-columns:minmax(0,1fr) minmax(280px,.72fr);gap:16px;padding:20px}.rw-section{border:1px solid #e9ecef;background:#fff}.rw-section h3{margin:0;padding:13px 15px;border-bottom:1px solid #e9ecef;color:#343a40;font-size:15px}.rw-section-copy{margin:0;padding:0 15px;color:#868e96;font-size:12px}.rw-fields{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px;padding:15px}.rw-field{display:flex;flex-direction:column;gap:6px;color:#495057;font-size:13px}.rw-field small{color:#868e96;font-size:12px}.rw-field input{width:100%;height:38px;padding:0 10px;border:1px solid #ced4da;border-radius:4px;color:#343a40;background:#fff;font:inherit}.rw-switch{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:15px;border-bottom:1px solid #edf0f2}.rw-switch:last-child{border-bottom:0}.rw-switch strong{display:block;color:#343a40;font-size:14px}.rw-switch span{display:block;margin-top:3px;color:#868e96;font-size:12px}.rw-switch input{width:18px;height:18px;margin:0;accent-color:#0667d9}.rw-note{margin:0 20px 20px;padding:11px 13px;border-left:3px solid #2f80ed;background:#eef6ff;color:#3f5f7c;font-size:12px}.rw-alert{margin:15px 20px 0;padding:10px 12px;border:1px solid #f5c6cb;background:#fff0f1;color:#9b1c25}.rw-success{margin:15px 20px 0;padding:10px 12px;border:1px solid #b9e4d5;background:#edfff8;color:#087f72}@media(max-width:780px){.rw-head{display:block}.rw-save{margin-top:12px}.rw-body{grid-template-columns:1fr;padding:12px}.rw-fields{grid-template-columns:1fr}.rw-note{margin:0 12px 12px}}";
        document.head.appendChild(style);
    }
    function number(value, fallback) {
        var parsed = Number(value);
        return isFinite(parsed) ? parsed : fallback;
    }
    class RewardPage extends ReactDefault.a.Component {
        constructor(props) {
            super(props);
            this.state = {loading: true, saving: false, error: "", notice: "", values: {reward_enable: 1, reward_daily_game_limit: 3, reward_dice_six_gb: 10, reward_dice_win_face: 6, reward_slots_jackpot_rate: 100, reward_slots_triple_gb: 10, reward_poker_winner_gb: 5, reward_group_enable: 0}};
        }
        componentDidMount() { ensureStyles(); this.load(); }
        api(path, options) {
            options = options || {};
            var headers = {Accept: "application/json"};
            var authorization = window.localStorage.getItem("authorization");
            if (authorization) headers.authorization = authorization;
            if (options.body) headers["Content-Type"] = "application/json";
            return fetch("/api/v1/" + window.settings.secure_path + path, {method: options.method || "GET", headers: headers, body: options.body}).then(function(response) {
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
            this.setState({loading: true, error: "", notice: ""});
            this.api("/reward/fetch").then(function(result) {
                self.setState({loading: false, values: Object.assign({}, self.state.values, result.data || {})});
            }).catch(function(error) { self.setState({loading: false, error: error.message || "无法读取奖励配置"}); });
        }
        setValue(key, value) {
            var values = Object.assign({}, this.state.values); values[key] = value; this.setState({values: values, notice: ""});
        }
        save(event) {
            event.preventDefault();
            var values = this.state.values;
            var body = {
                reward_enable: values.reward_enable ? 1 : 0,
                reward_daily_game_limit: number(values.reward_daily_game_limit, 3),
                reward_dice_six_gb: number(values.reward_dice_six_gb, 10),
                reward_dice_win_face: number(values.reward_dice_win_face, 6),
                reward_slots_jackpot_rate: number(values.reward_slots_jackpot_rate, 100),
                reward_slots_triple_gb: number(values.reward_slots_triple_gb, 10),
                reward_poker_winner_gb: number(values.reward_poker_winner_gb, 5),
                reward_group_enable: values.reward_group_enable ? 1 : 0
            };
            var self = this;
            this.setState({saving: true, error: "", notice: ""});
            this.api("/reward/save", {method: "POST", body: JSON.stringify(body)}).then(function(result) {
                self.setState({saving: false, values: Object.assign({}, self.state.values, result.data || body), notice: "奖励配置已保存，Webman 将加载新配置。"});
            }).catch(function(error) { self.setState({saving: false, error: error.message || "保存失败，请重试"}); });
        }
        toggle(key, label, copy) {
            var values = this.state.values;
            return h("label", {className: "rw-switch"}, h("span", null, h("strong", null, label), h("span", null, copy)), h("input", {type: "checkbox", checked: Number(values[key]) === 1, onChange: this.setValue.bind(this, key, Number(values[key]) === 1 ? 0 : 1)}));
        }
        field(key, label, hint, min, max) {
            var self = this;
            return h("label", {className: "rw-field"}, label, h("input", {type: "number", min: min, max: max, value: this.state.values[key], onChange: function(event) { self.setValue(key, event.target.value); }}), h("small", null, hint));
        }
        render() {
            var values = this.state.values;
            var form = h("form", {onSubmit: this.save.bind(this)}, this.state.error ? h("div", {className: "rw-alert", role: "alert"}, this.state.error) : null, this.state.notice ? h("div", {className: "rw-success", role: "status"}, this.state.notice) : null, h("div", {className: "block block-rounded reward-admin-page"}, h("div", {className: "rw-head"}, h("div", null, h("h2", null, "签到与娱乐配置"), h("p", null, "配置每日奖励、游戏赔率和 Telegram 群组娱乐权限。所有流量奖励会在订阅周期重置时失效。")), h("button", {className: "rw-save", type: "submit", disabled: this.state.loading || this.state.saving}, this.state.saving ? "正在保存..." : "保存配置")), h("div", {className: "rw-body"}, h("section", {className: "rw-section"}, h("h3", null, "每日签到与单人游戏"), h("div", {className: "rw-fields"}, this.field("reward_daily_game_limit", "每日游戏次数上限", "设为 0 可禁止骰子和老虎机；签到不受该数值限制。", 0, 100), this.field("reward_dice_win_face", "骰子中奖点数", "只有掷出该点数可获得骰子高额奖励。", 1, 6), this.field("reward_dice_six_gb", "骰子中奖奖励（GB）", "中奖奖励范围 1-10 GB。未中奖时固定获得 1 GB。", 1, 10), this.field("reward_slots_jackpot_rate", "老虎机大奖概率（万分比）", "例如 100 表示 1%，有效范围 1-10000。", 1, 10000), this.field("reward_slots_triple_gb", "老虎机三连奖励（GB）", "三连符号的奖励范围 1-10 GB。", 1, 10), this.field("reward_poker_winner_gb", "炸金花获胜奖励（GB）", "牌局结算获胜者奖励范围 1-10 GB。", 1, 10))), h("section", {className: "rw-section"}, h("h3", null, "功能开关"), this.toggle("reward_enable", "启用签到与娱乐", "关闭后网站与 Telegram 的签到和游戏请求都会被拒绝。"), this.toggle("reward_group_enable", "启用 Telegram 群组娱乐", "开启后，已将订阅绑定至当前群组的用户可在群中操作游戏。"))), h("p", {className: "rw-note"}, "保存时会更新 v2board 配置缓存；若 Webman 由 Supervisor 管理，进程重启后将自动使用新配置。")));
            return h(Page["a"], Object.assign({}, this.props, {title: "签到与娱乐"}), h(Spin["a"], {loading: this.state.loading}, form));
        }
    }
    t.default = RewardPage;
}
