function(e, t, n) {
    n.r(t);
    var React = n('q1tI'), h = React.createElement, Page = n('Bl7J').a;
    class Support extends React.Component {
        constructor(props) {
            super(props);
            this.state = {rows: [], total: 0, page: 1, email: '', status: '', ticket: null, message: '', loading: false, error: ''};
        }
        componentDidMount() { this.load(); }
        componentDidUpdate(previous) { if (previous.location.pathname !== this.props.location.pathname) this.load(); }
        componentWillUnmount() { this.unmounted = true; }
        async api(path, body) {
            var response = await fetch('/api/v1/' + window.settings.secure_path + path, {cache: 'no-store',
                method: body ? 'POST' : 'GET', headers: {authorization: localStorage.getItem('authorization') || '', 'Content-Type': 'application/json'}, body: body ? JSON.stringify(body) : undefined});
            var data = await response.json();
            if (!response.ok) throw new Error(data.message || '请求失败');
            return data;
        }
        async load(id) {
            id = id || (this.props.match.params || {}).ticket_id;
            this.setState({loading: true, error: ''});
            try {
                var query = id ? '?id=' + encodeURIComponent(id) : '?current=' + this.state.page + '&pageSize=20'
                    + (this.state.email ? '&email=' + encodeURIComponent(this.state.email) : '')
                    + (this.state.status !== '' ? '&status=' + this.state.status : '');
                var result = await this.api('/ticket/fetch' + query);
                if (!this.unmounted) this.setState(id ? {ticket: result.data} : {rows: result.data, total: result.total, ticket: null});
            } catch (error) { if (!this.unmounted) this.setState({error: error.message}); }
            finally { if (!this.unmounted) this.setState({loading: false}); }
        }
        async reply(event) {
            event.preventDefault();
            if (this.state.loading || !this.state.message.trim()) return;
            this.setState({loading: true, error: ''});
            try {
                await this.api('/ticket/reply', {id: this.state.ticket.id, message: this.state.message});
                this.setState({message: ''}); await this.load(this.state.ticket.id);
            } catch (error) { this.setState({error: error.message, loading: false}); }
        }
        render() {
            var s = this.state, self = this;
            return h(Page, Object.assign({}, this.props, {title: '工单管理'}), h('div', {className: 'block block-rounded'}, h('div', {className: 'block-content block-content-full'},
                s.error && h('div', {role: 'alert', className: 'alert alert-danger'}, s.error),
                s.ticket ? h('div', null,
                    h('button', {className: 'btn btn-light mb-3', onClick: function() { location.hash = '/ticket'; }}, '返回列表'),
                    h('h4', null, '#' + s.ticket.id + ' ' + s.ticket.subject),
                    h('button', {className: 'btn btn-light mb-3', disabled: s.loading, onClick: function() { self.load(s.ticket.id); }}, '刷新消息'),
                    (s.ticket.message || []).map(function(message) { return h('div', {key: message.id, className: 'border rounded p-3 mb-2'},
                        h('small', {className: 'text-muted'}, (message.is_me ? '管理员' : '用户') + ' · ' + new Date(message.created_at * 1000).toLocaleString()),
                        h('p', {style: {whiteSpace: 'pre-wrap', overflowWrap: 'anywhere', marginBottom: 0}}, message.message)); }),
                    h('form', {onSubmit: function(event) { self.reply(event); }},
                        h('label', {htmlFor: 'support-reply'}, '回复内容'),
                        h('textarea', {id: 'support-reply', className: 'form-control my-2', rows: 5, value: s.message, required: true, onChange: function(event) { self.setState({message: event.target.value}); }}),
                        h('button', {className: 'btn btn-primary', disabled: s.loading || !s.message.trim()}, s.loading ? '正在提交…' : '回复工单')))
                : h('div', null,
                    h('form', {className: 'd-flex flex-wrap mb-3', onSubmit: function(event) { event.preventDefault(); self.setState({page: 1}, function() { self.load(); }); }},
                        h('input', {className: 'form-control mr-2 mb-2', style: {maxWidth: 280}, type: 'email', placeholder: '用户邮箱', 'aria-label': '用户邮箱', value: s.email, onChange: function(e) { self.setState({email: e.target.value}); }}),
                        h('select', {className: 'form-control mr-2 mb-2', style: {maxWidth: 140}, 'aria-label': '工单状态', value: s.status, onChange: function(e) { self.setState({status: e.target.value}); }}, h('option', {value: ''}, '全部状态'), h('option', {value: '0'}, '处理中'), h('option', {value: '1'}, '已结束')),
                        h('button', {className: 'btn btn-primary mb-2', disabled: s.loading}, '查询')),
                    h('div', {className: 'table-responsive'}, h('table', {className: 'table'},
                        h('thead', null, h('tr', null, ['ID', '主题', '状态', '操作'].map(function(label) { return h('th', {key: label}, label); }))),
                        h('tbody', null, s.rows.map(function(ticket) { return h('tr', {key: ticket.id}, h('td', null, ticket.id), h('td', null, ticket.subject), h('td', null, ticket.status ? '已结束' : '处理中'),
                            h('td', null, h('a', {href: '#/ticket/' + ticket.id}, '阅读与回复'))); })))),
                    !s.rows.length && h('p', {className: 'text-muted'}, s.loading ? '加载中…' : '没有符合条件的工单'),
                    h('div', {className: 'd-flex align-items-center'},
                        h('button', {className: 'btn btn-light', disabled: s.page <= 1 || s.loading, onClick: function() { self.setState({page: s.page - 1}, function() { self.load(); }); }}, '上一页'),
                        h('span', {className: 'mx-3'}, '第 ' + s.page + ' 页 / 共 ' + s.total + ' 条'),
                        h('button', {className: 'btn btn-light', disabled: s.page * 20 >= s.total || s.loading, onClick: function() { self.setState({page: s.page + 1}, function() { self.load(); }); }}, '下一页'))))));
        }
    }
    t.default = Support;
}
