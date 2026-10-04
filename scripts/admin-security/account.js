function(e, t, n) {
    n.r(t);
    var React = n('q1tI'), h = React.createElement, Page = n('Bl7J').a;
    class Account extends React.Component {
        constructor(props) { super(props); this.state = {status: {}, sessions: {}, setup: null, codes: [], current: '', next: '', confirm: '', code: '', recovery: '', busy: false, error: '', notice: ''}; }
        componentDidMount() { this.load(); }
        async load() {
            try { var results = await Promise.all([window.adminRequest('/2fa/status'), window.adminRequest('/security/account/sessions')]); this.setState({status: results[0], sessions: results[1]}); }
            catch (error) { this.setState({error: error.message}); }
        }
        async act(path, data, done) {
            this.setState({busy: true, error: '', notice: ''});
            try {
                var result = await window.adminRequest(path, {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(data || {})});
                if (done) done(result); else this.setState({notice: '操作成功'});
                if (result && result.recovery_codes) {
                    window.adminSecurityRecoveryPending = true;
                    this.setState({status: {enabled: true}, notice: '现有登录会话已撤销。请先保存恢复码，然后重新登录。'});
                } else if (path === '/2fa/disable') {
                    localStorage.removeItem('authorization'); location.reload();
                } else await this.load();
            } catch (error) { this.setState({error: error.message}); }
            finally { this.setState({busy: false}); }
        }
        render() {
            var s = this.state, self = this;
            function field(key, label, type) { return h('label', {className: 'd-block mb-3'}, label, h('input', {className: 'form-control mt-1', type: type || 'text', autoComplete: type === 'password' ? (key === 'current' ? 'current-password' : 'new-password') : 'one-time-code', value: s[key], onChange: function(e) { self.setState({[key]: e.target.value}); }})); }
            function button(label, click) { return h('button', {type: 'button', className: 'btn btn-light mr-2 mb-2', disabled: s.busy, onClick: click}, label); }
            return h(Page, Object.assign({}, this.props, {title: '账号安全'}), h('div', {className: 'block block-rounded'}, h('div', {className: 'block-content block-content-full'},
                h('p', {className: 'text-muted'}, '当前角色：' + window.adminSecurity.role_label),
                s.error && h('div', {className: 'alert alert-danger', role: 'alert'}, s.error), s.notice && h('div', {className: 'alert alert-info', role: 'status'}, s.notice),
                h('section', {style: {maxWidth: 520}}, h('h4', null, '修改密码'),
                    h('form', {onSubmit: function(event) { event.preventDefault(); if (!s.current || s.next.length < 8 || s.next !== s.confirm) { self.setState({error: '请填写当前密码，并确认两次新密码一致且不少于 8 位'}); return; }
                        self.act('/security/account/password', {old_password: s.current, new_password: s.next}, function() { localStorage.removeItem('authorization'); location.reload(); }); }},
                        field('current', '当前密码', 'password'), field('next', '新密码', 'password'), field('confirm', '确认新密码', 'password'), h('button', {className: 'btn btn-primary mb-4', disabled: s.busy}, '保存并重新登录')),
                    h('h4', null, '二步验证'), h('p', null, s.status.enabled ? '已启用' : '未启用'),
                    !s.status.enabled && button('开始绑定验证器', function() { self.act('/2fa/setup', {}, function(result) { self.setState({setup: result, codes: []}); }); }),
                    s.setup && h('div', {className: 'border rounded p-3 mb-3'},
                        s.setup.qr_code && h('img', {src: s.setup.qr_code, alt: '验证器二维码', style: {width: 220, height: 220, maxWidth: '100%'}}),
                        h('p', null, '手动密钥：', h('code', {style: {overflowWrap: 'anywhere'}}, s.setup.manual_key)),
                        field('code', '验证器动态码'), button('确认绑定', function() { self.act('/2fa/confirm', {code: s.code}, function(result) { self.setState({setup: null, codes: result.recovery_codes || [], code: ''}); }); })),
                    s.status.enabled && h('div', null, field('code', '验证器动态码'), field('recovery', '恢复码（与动态码二选一）'),
                        h('p', {className: 'text-muted'}, '以下操作需要上方填写的当前密码。'),
                        button('重新生成恢复码', function() { self.act('/2fa/recovery-codes/regenerate', {current_password: s.current, code: s.code, recovery_code: s.recovery}, function(result) { self.setState({codes: result.recovery_codes || [], code: '', recovery: ''}); }); }),
                        button('关闭二步验证', function() { if (confirm('确认关闭当前账号的二步验证？')) self.act('/2fa/disable', {current_password: s.current, code: s.code, recovery_code: s.recovery}, function() { self.setState({codes: [], code: '', recovery: '', notice: '已关闭二步验证'}); }); })),
                    s.codes.length > 0 && h('div', {className: 'alert alert-warning'}, h('strong', null, '恢复码只显示一次，请立即安全保存。'), h('pre', {className: 'mt-2'}, s.codes.join('\n')), button('已保存，重新登录', function() { localStorage.removeItem('authorization'); location.reload(); }))),
                h('h4', {className: 'mt-4'}, '登录会话'),
                h('div', {className: 'table-responsive'}, h('table', {className: 'table'}, h('thead', null, h('tr', null, ['登录时间', 'IP', '设备', '操作'].map(function(label) { return h('th', {key: label}, label); }))),
                    h('tbody', null, Object.keys(s.sessions).map(function(id) { var session = s.sessions[id]; return h('tr', {key: id}, h('td', null, new Date(session.login_at * 1000).toLocaleString()), h('td', null, session.ip), h('td', {style: {maxWidth: 380, overflowWrap: 'anywhere'}}, session.ua),
                        h('td', null, button('撤销', function() { self.act('/security/account/sessions/remove', {session_id: id}); }))); })))))));
        }
    }
    t.default = Account;
}
