function(e, t, n) {
    n.r(t);
    var React = n('q1tI'), h = React.createElement, Page = n('Bl7J').a;
    class Audit extends React.Component {
        constructor(props) { super(props); this.state = {rows: [], total: 0, page: 1, actor: '', event: '', result: '', requestId: '', detail: null, busy: false, error: '', notice: ''}; }
        componentDidMount() { this.load(); }
        query() { var s = this.state; return new URLSearchParams(Object.fromEntries(Object.entries({actor_id: s.actor, event: s.event, result: s.result, request_id: s.requestId}).filter(function(pair) { return pair[1] !== ''; }))).toString(); }
        async load() {
            this.setState({busy: true, error: ''});
            try {
                var response = await fetch('/api/v1/' + window.settings.secure_path + '/security/audit?page=' + this.state.page + '&' + this.query(), {cache: 'no-store', headers: {authorization: localStorage.getItem('authorization')}});
                var result = await response.json(); if (!response.ok) throw new Error(result.message || '加载失败');
                this.setState({rows: result.data, total: result.total});
            } catch (error) { this.setState({error: error.message}); } finally { this.setState({busy: false}); }
        }
        async verify() {
            this.setState({busy: true, error: '', notice: ''});
            try { var result = await window.adminRequest('/security/audit/verify', {method: 'POST'}); this.setState({notice: result.valid ? '完整性校验通过，共 ' + result.checked + ' 条。校验锚点：' + result.hash : '完整性校验失败，请保留现场并检查第 ' + (result.failed_at || result.checked + 1) + ' 条附近的记录。'}); }
            catch (error) { this.setState({error: error.message}); } finally { this.setState({busy: false}); }
        }
        async export() {
            this.setState({busy: true, error: ''});
            try {
                var response = await fetch('/api/v1/' + window.settings.secure_path + '/security/audit/export?' + this.query(), {method: 'POST', headers: {authorization: localStorage.getItem('authorization'), 'Content-Type': 'application/json'}, body: JSON.stringify({after: 0, limit: 10000})});
                if (!response.ok) { var error = await response.json(); throw new Error(error.message || '导出失败'); }
                var url = URL.createObjectURL(await response.blob()), link = document.createElement('a'); link.href = url; link.download = 'security-audit-' + Date.now() + '.jsonl'; link.click(); setTimeout(function() { URL.revokeObjectURL(url); }, 1000);
                this.setState({notice: '已导出符合条件的前 10000 条记录；更多记录可使用服务端归档命令导出。'});
            } catch (error) { this.setState({error: error.message}); } finally { this.setState({busy: false}); }
        }
        render() {
            var s = this.state, self = this;
            return h(Page, Object.assign({}, this.props, {title: '安全审计'}), h('div', {className: 'block block-rounded'}, h('div', {className: 'block-content block-content-full'},
                h('p', {className: 'text-muted'}, '原始记录只追加，不提供修改和删除。查询、导出及校验操作同样留痕。'),
                s.error && h('div', {role: 'alert', className: 'alert alert-danger'}, s.error), s.notice && h('div', {role: 'status', className: 'alert alert-info', style: {overflowWrap: 'anywhere'}}, s.notice),
                h('form', {className: 'd-flex flex-wrap mb-3', onSubmit: function(event) { event.preventDefault(); self.setState({page: 1}, function() { self.load(); }); }},
                    [['actor', '管理员 ID'], ['event', '事件名称'], ['requestId', '请求编号']].map(function(field) { return h('input', {key: field[0], className: 'form-control mr-2 mb-2', style: {maxWidth: 230}, placeholder: field[1], 'aria-label': field[1], value: s[field[0]], onChange: function(e) { self.setState({[field[0]]: e.target.value}); }}); }),
                    h('select', {className: 'form-control mr-2 mb-2', style: {maxWidth: 130}, 'aria-label': '操作结果', value: s.result, onChange: function(e) { self.setState({result: e.target.value}); }},
                        [['', '全部结果'], ['success', '成功'], ['failure', '失败'], ['denied', '拒绝'], ['pending', '执行意图']].map(function(pair) { return h('option', {key: pair[0], value: pair[0]}, pair[1]); })),
                    h('button', {className: 'btn btn-primary mr-2 mb-2', disabled: s.busy}, '查询'),
                    h('button', {type: 'button', className: 'btn btn-light mr-2 mb-2', disabled: s.busy, onClick: function() { self.verify(); }}, '完整性校验'),
                    h('button', {type: 'button', className: 'btn btn-light mb-2', disabled: s.busy, onClick: function() { self.export(); }}, '导出')),
                h('div', {className: 'table-responsive'}, h('table', {className: 'table'}, h('thead', null, h('tr', null, ['序号', '时间', '管理员 / 角色', '事件', '结果', '详情'].map(function(label) { return h('th', {key: label}, label); }))),
                    h('tbody', null, s.rows.map(function(row) { return h('tr', {key: row.id}, h('td', null, row.id), h('td', null, new Date(row.created_at * 1000).toLocaleString()), h('td', null, (row.actor_id || '未认证 / 系统') + ' / ' + (row.role || '—')), h('td', null, row.event), h('td', null, row.result),
                        h('td', null, h('button', {className: 'btn btn-sm btn-light', onClick: function() { self.setState({detail: row}); }}, '查看'))); })))),
                h('button', {className: 'btn btn-light', disabled: s.page <= 1 || s.busy, onClick: function() { self.setState({page: s.page - 1}, function() { self.load(); }); }}, '上一页'), h('span', {className: 'mx-3'}, '第 ' + s.page + ' 页 / 共 ' + s.total + ' 条'),
                h('button', {className: 'btn btn-light', disabled: s.page * 25 >= s.total || s.busy, onClick: function() { self.setState({page: s.page + 1}, function() { self.load(); }); }}, '下一页'),
                s.detail && h('section', {className: 'border rounded p-3 mt-3'}, h('button', {className: 'btn btn-light mb-2', onClick: function() { self.setState({detail: null}); }}, '收起详情'),
                    h('pre', {style: {whiteSpace: 'pre-wrap', overflowWrap: 'anywhere'}}, JSON.stringify(Object.assign({}, s.detail, {payload: JSON.parse(s.detail.payload)}), null, 2))))));
        }
    }
    t.default = Audit;
}
