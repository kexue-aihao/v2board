function(e, t, n) {
    n.r(t);
    var React = n('q1tI'), h = React.createElement, Page = n('Bl7J').a;
    class Administrators extends React.Component {
        constructor(props) { super(props); this.state = {rows: [], total: 0, page: 1, roles: {}, userId: '', role: 'operations', email: '', error: '', busy: false}; }
        componentDidMount() { this.load(); }
        async load() {
            this.setState({busy: true, error: ''});
            try {
                var response = await fetch('/api/v1/' + window.settings.secure_path + '/security/administrators?page=' + this.state.page + '&email=' + encodeURIComponent(this.state.email), {cache: 'no-store', headers: {authorization: localStorage.getItem('authorization')}});
                var result = await response.json(); if (!response.ok) throw new Error(result.message || '加载失败');
                this.setState({rows: result.data, total: result.total, roles: result.roles});
            } catch (error) { this.setState({error: error.message}); } finally { this.setState({busy: false}); }
        }
        async save(event) {
            event.preventDefault();
            if (!confirm('确认调整用户 ID ' + this.state.userId + ' 的管理员权限？该账号的现有登录会话将失效。')) return;
            this.setState({busy: true, error: ''});
            try {
                await window.adminRequest('/security/administrators/role', {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({user_id: Number(this.state.userId), role: this.state.role || null})});
                this.setState({userId: ''}); await this.load();
            } catch (error) { this.setState({error: error.message}); } finally { this.setState({busy: false}); }
        }
        render() {
            var s = this.state, self = this;
            return h(Page, Object.assign({}, this.props, {title: '管理员权限'}), h('div', {className: 'block block-rounded'}, h('div', {className: 'block-content block-content-full'},
                h('p', {className: 'text-muted'}, 'ID 1 是唯一超级管理员。请选择已有用户并分配一个角色；撤销权限后该账号仍可作为普通用户使用。'),
                s.error && h('div', {role: 'alert', className: 'alert alert-danger'}, s.error),
                h('form', {className: 'd-flex flex-wrap mb-4', onSubmit: function(event) { self.save(event); }},
                    h('input', {className: 'form-control mr-2 mb-2', style: {maxWidth: 200}, type: 'number', min: 2, required: true, placeholder: '已有用户 ID', 'aria-label': '用户 ID', value: s.userId, onChange: function(e) { self.setState({userId: e.target.value}); }}),
                    h('select', {className: 'form-control mr-2 mb-2', style: {maxWidth: 230}, 'aria-label': '管理员角色', value: s.role, onChange: function(e) { self.setState({role: e.target.value}); }},
                        Object.keys(s.roles).map(function(key) { return h('option', {key: key, value: key}, s.roles[key]); }), h('option', {value: ''}, '撤销后台权限')),
                    h('button', {className: 'btn btn-primary mb-2', disabled: s.busy}, '保存角色')),
                h('form', {className: 'd-flex mb-3', onSubmit: function(event) { event.preventDefault(); self.setState({page: 1}, function() { self.load(); }); }},
                    h('input', {className: 'form-control mr-2', style: {maxWidth: 320}, placeholder: '搜索管理员邮箱', 'aria-label': '搜索管理员邮箱', value: s.email, onChange: function(e) { self.setState({email: e.target.value}); }}), h('button', {className: 'btn btn-light', disabled: s.busy}, '搜索')),
                h('div', {className: 'table-responsive'}, h('table', {className: 'table'}, h('thead', null, h('tr', null, ['ID', '邮箱', '角色', '状态', '操作'].map(function(label) { return h('th', {key: label}, label); }))),
                    h('tbody', null, s.rows.map(function(row) { return h('tr', {key: row.id}, h('td', null, row.id), h('td', null, row.email), h('td', null, row.role === 'super' ? '超级管理员' : s.roles[row.role] || '待分配'), h('td', null, row.banned ? '已停用' : row.role ? '正常' : '后台访问已暂停'),
                        h('td', null, row.protected ? '创始账号 · 受保护' : h('button', {className: 'btn btn-sm btn-light', onClick: function() { self.setState({userId: String(row.id), role: row.role || 'operations'}); }}, '调整角色'))); })))),
                h('button', {className: 'btn btn-light', disabled: s.page <= 1 || s.busy, onClick: function() { self.setState({page: s.page - 1}, function() { self.load(); }); }}, '上一页'),
                h('span', {className: 'mx-3'}, '第 ' + s.page + ' 页 / 共 ' + s.total + ' 条'),
                h('button', {className: 'btn btn-light', disabled: s.page * 25 >= s.total || s.busy, onClick: function() { self.setState({page: s.page + 1}, function() { self.load(); }); }}, '下一页'))));
        }
    }
    t.default = Administrators;
}
