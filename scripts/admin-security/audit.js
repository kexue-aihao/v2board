function(e, t, n) {
    n.r(t);
    var React = n('q1tI'), h = React.createElement, Page = n('Bl7J').a;
    var stages = {begin: '开始操作', changes: '字段变更', batch: '批量明细', effect: '实际执行效果', queued: '任务已提交', finish: '执行结果', denied: '权限拒绝'};
    var states = {success: '成功', failure: '失败', denied: '拒绝', pending: '结果待确认', partial: '部分完成', no_change: '无变化', queued: '任务已提交'};
    var resultLabels = {matched_count: '匹配数量', updated_count: '修改数量', changed_count: '变化数量', created_count: '新增数量', deleted_count: '删除数量', skipped_count: '跳过数量', unchanged_count: '无变化数量', copied_count: '复制数量', count: '数量', total: '总数', returned: '返回数量', requested_count: '请求数量', state: '结果', reason: '原因', exception_type: '异常类型', ok: '业务成功', available: '功能可用', dry_run: '试运行', source_id: '订阅源编号', skipped: '解析跳过数量'};
    Object.assign(resultLabels, {submitted_count: '提交数量', queued_count: '提交任务数量', exported_count: '导出数量', checked_count: '校验数量', truncated: '达到导出上限', first_record: '首条记录序号', last_record: '末条记录序号'});
    function value(v) { if (v === null || v === undefined) return '—'; return typeof v === 'object' ? JSON.stringify(v) : (typeof v === 'boolean' ? (v ? '是' : '否') : String(v)); }
    function table(headers, rows) {
        return h('div', {className: 'table-responsive'}, h('table', {className: 'table table-sm'}, h('thead', null, h('tr', null, headers.map(function(label) { return h('th', {key: label}, label); }))),
            h('tbody', null, rows.map(function(row, i) { return h('tr', {key: i}, row.map(function(cell, j) { return h('td', {key: j, style: {whiteSpace: 'pre-wrap', overflowWrap: 'anywhere'}}, cell); })); }))));
    }
    class Audit extends React.Component {
        constructor(props) {
            super(props);
            this.state = {rows: [], total: 0, page: 1, actor: '', event: '', result: '', state: '', requestId: '', module: '', action: '', object: '', field: '', from: '', to: '', view: 'operations', detail: null, records: [], after: 0, more: false, detailBusy: false, busy: false, error: '', notice: ''};
            this.detailVersion = 0;
            Object.assign(this.state, {batchId: '', jobRef: '', detailScope: 'request'});
        }
        componentDidMount() { this.load(); }
        componentWillUnmount() { this.detailVersion++; }
        query(exporting) {
            var s = this.state;
            var filters = {actor_id: s.actor, keyword: s.event, result: s.result, state: s.state, request_id: s.requestId, module: s.module, action: s.action, object: s.object, field: s.field, view: exporting ? 'records' : s.view,
                batch_id: s.batchId, job_ref: s.jobRef, from: s.from ? Math.floor(new Date(s.from).getTime() / 1000) : '', to: s.to ? Math.floor(new Date(s.to).getTime() / 1000) : ''};
            return new URLSearchParams(Object.fromEntries(Object.entries(filters).filter(function(pair) { return pair[1] !== ''; }))).toString();
        }
        async get(path, options) {
            var response = await fetch('/api/v1/' + window.settings.secure_path + path, Object.assign({cache: 'no-store', headers: {authorization: localStorage.getItem('authorization')}}, options));
            var result = await response.json();
            if (!response.ok) throw new Error(result.message || '加载失败');
            return result;
        }
        async load() {
            this.setState({busy: true, error: ''});
            try {
                var result = await this.get('/security/audit?page=' + this.state.page + '&' + this.query());
                this.setState({rows: result.data, total: result.total});
            } catch (error) { this.setState({error: error.message}); } finally { this.setState({busy: false}); }
        }
        async showDetail(row, more, scope) {
            var version = more ? this.detailVersion : ++this.detailVersion;
            var after = more ? this.state.after : 0;
            scope = scope || (more ? this.state.detailScope : 'request');
            if (!more) this.setState({detail: row, records: [], after: 0, more: false, detailScope: scope});
            this.setState({detailBusy: true, error: ''});
            try {
                var result = await this.get('/security/audit/detail?id=' + row.id + '&after=' + after + '&limit=100&scope=' + scope);
                if (version !== this.detailVersion) return;
                this.setState(function(s) { return {records: more ? s.records.concat(result.data) : result.data, after: result.next_after, more: result.has_more}; });
            } catch (error) { if (version === this.detailVersion) this.setState({error: error.message}); }
            finally { if (version === this.detailVersion) this.setState({detailBusy: false}); }
        }
        async verify() {
            this.setState({busy: true, error: '', notice: ''});
            try { var result = await window.adminRequest('/security/audit/verify', {method: 'POST'}); this.setState({notice: result.valid ? '完整性校验通过，共 ' + result.checked + ' 条。校验锚点：' + result.hash : '完整性校验失败，请保留现场并检查第 ' + (result.failed_at || result.checked + 1) + ' 条附近的记录。'}); }
            catch (error) { this.setState({error: error.message}); } finally { this.setState({busy: false}); }
        }
        async export() {
            this.setState({busy: true, error: ''});
            try {
                var response = await fetch('/api/v1/' + window.settings.secure_path + '/security/audit/export?' + this.query(true), {method: 'POST', headers: {authorization: localStorage.getItem('authorization'), 'Content-Type': 'application/json'}, body: JSON.stringify({after: 0, limit: 10000})});
                if (!response.ok) { var error = await response.json(); throw new Error(error.message || '导出失败'); }
                var url = URL.createObjectURL(await response.blob()), link = document.createElement('a'); link.href = url; link.download = 'security-audit-' + Date.now() + '.jsonl'; link.click(); setTimeout(function() { URL.revokeObjectURL(url); }, 1000);
                this.setState({notice: '已导出符合条件的前 10000 条原始记录，包含中文业务详情；更多记录可使用服务端归档命令导出。'});
            } catch (error) { this.setState({error: error.message}); } finally { this.setState({busy: false}); }
        }
        renderItem(item, i) {
            return h('details', {key: i, className: 'border rounded p-2 mb-2', open: i === 0},
                h('summary', null, item.operation_label + ' · ' + item.object.label + (item.reason ? ' · ' + item.reason : '')),
                table(['字段', '操作前', '操作后'], (item.fields || []).map(function(field) { return [field.label, field.before_label, field.after_label]; })));
        }
        renderDetail() {
            var s = this.state, self = this;
            if (!s.detail) return null;
            var b = s.detail.business;
            return h('section', {className: 'border rounded p-3 mt-3', 'aria-label': '操作详情'},
                h('button', {className: 'btn btn-light mb-2', onClick: function() { self.detailVersion++; self.setState({detail: null, records: [], detailBusy: false}); }}, '收起详情'),
                h('h5', null, s.detail.description),
                h('p', null, '关联请求：' + s.detail.request_id + ' · ' + (b ? b.module_label + ' · ' + b.channel : '历史记录，部分详情可能未保留')),
                b && b.actor && h('p', null, '操作者：' + (b.actor.email || b.actor.id || '系统 / 未认证') + ' · ' + (s.detail.role_label || '—')),
                b && b.source && h('p', null, '复制来源：' + b.source.label),
                b && b.batch_id && h('p', null, '批次：' + b.batch_id, ' ', h('button', {className: 'btn btn-sm btn-light', disabled: s.detailBusy, onClick: function() { self.showDetail(s.detail, false, s.detailScope === 'batch' ? 'request' : 'batch'); }}, s.detailScope === 'batch' ? '仅看本次请求' : '查看同批次操作')),
                b && b.criteria && b.criteria.length > 0 && table(['操作条件', '内容'], b.criteria.map(function(c) { return [c.label, (c.condition ? c.condition + ' ' : '') + value(c.value)]; })),
                s.records.map(function(record) {
                    var business = record.business;
                    if (!business) return h('details', {key: record.id, className: 'mb-2'}, h('summary', null, '#' + record.id + ' · ' + record.description + ' · 历史记录'), h('p', null, '本条未保存结构化业务详情。'), self.raw(record));
                    var items = ['changes', 'batch'].includes(business.stage) ? business.items || [] : [];
                    return h('article', {key: record.id, className: 'mb-3'},
                        h('h6', null, '#' + record.id + ' · ' + new Date(record.created_at * 1000).toLocaleString() + ' · ' + (stages[business.stage] || business.stage) + ' · ' + record.result_label),
                        business.job && table(['任务信息', '内容'], [['任务', business.job.name], ['任务编号', business.job.ref || business.job.id], ['执行次数', business.job.attempt]].concat(Object.entries(business.job.metadata || {}).map(function(pair) { return [({email: '收件地址', subject: '邮件主题', template_name: '邮件模板', body_length: '正文长度', telegram_id: 'Telegram 接收对象', topic_id: '话题编号', trade_no: '订单号'})[pair[0]] || pair[0], value(pair[1])]; }))),
                        business.stage === 'finish' && business.result && table(['执行结果', '内容'], Object.entries(business.result).filter(function(pair) { return pair[1] !== null; }).map(function(pair) { return [resultLabels[pair[0]] || pair[0], states[pair[1]] || value(pair[1])]; })),
                        items.map(function(item, i) { return self.renderItem(item, i); }),
                        business.effects_total > (business.effects || []).length && h('p', null, '本条展示部分关联影响，全部 ' + business.effects_total + ' 项请查看关联的实际执行效果记录。'),
                        (business.effects || []).map(function(effect, i) { return h('div', {key: i, className: 'border rounded p-2 mb-2'}, h('p', null, effect.label + ' · ' + (states[effect.state] || effect.state)),
                            table(['影响内容', '结果'], Object.entries(effect.values || {}).filter(function(pair) { return pair[0] !== 'changes'; }).map(function(pair) { return [pair[0], value(pair[1])]; })),
                            (effect.values.changes || []).map(function(item, j) { return self.renderItem(item, j); })); }),
                        self.raw(record));
                }),
                s.detailBusy && h('p', {role: 'status'}, '正在加载操作明细…'),
                s.more && h('button', {className: 'btn btn-light', disabled: s.detailBusy, onClick: function() { self.showDetail(s.detail, true); }}, '加载更多关联明细'));
        }
        raw(record) {
            var payload; try { payload = JSON.parse(record.payload); } catch (error) { payload = record.payload; }
            return h('details', {className: 'mb-2'}, h('summary', null, '查看原始审计证据'), h('pre', {style: {whiteSpace: 'pre-wrap', overflowWrap: 'anywhere'}}, JSON.stringify(Object.assign({}, record, {payload: payload}), null, 2)));
        }
        render() {
            var s = this.state, self = this;
            function select(key, label, options) {
                return h('select', {className: 'form-control mr-2 mb-2', style: {maxWidth: 180}, 'aria-label': label, value: s[key], onChange: function(e) { self.setState({[key]: e.target.value}); }}, options.map(function(pair) { return h('option', {key: pair[0], value: pair[0]}, pair[1]); }));
            }
            return h(Page, Object.assign({}, this.props, {title: '安全审计'}), h('div', {className: 'block block-rounded'}, h('div', {className: 'block-content block-content-full'},
                h('p', {className: 'text-muted'}, '按实际后台功能记录对象、字段前后值和执行结果。密码及密钥只记录是否变化，正文保留长度与摘要。原始记录只追加。'),
                s.error && h('div', {role: 'alert', className: 'alert alert-danger'}, s.error), s.notice && h('div', {role: 'status', className: 'alert alert-info', style: {overflowWrap: 'anywhere'}}, s.notice),
                h('form', {className: 'd-flex flex-wrap mb-3', onSubmit: function(event) { event.preventDefault(); self.setState({page: 1}, function() { self.load(); }); }},
                    [['actor', '管理员 ID'], ['event', '中文操作内容'], ['action', '动作代码'], ['object', '对象编号或订单号'], ['field', '字段名称（如 email）'], ['requestId', '请求编号'], ['batchId', '批次编号'], ['jobRef', '任务编号']].map(function(field) { return h('input', {key: field[0], className: 'form-control mr-2 mb-2', style: {maxWidth: 200}, placeholder: field[1], 'aria-label': field[1], value: s[field[0]], onChange: function(e) { self.setState({[field[0]]: e.target.value}); }}); }),
                    select('module', '业务模块', [['', '全部模块'], ['users', '用户管理'], ['orders', '订单管理'], ['nodes', '节点管理'], ['rates', '动态倍率'], ['settings', '系统设置'], ['payments', '支付设置'], ['themes', '主题设置'], ['plans', '套餐'], ['coupons', '优惠券'], ['giftcards', '礼品卡'], ['notices', '公告'], ['knowledge', '知识库'], ['tickets', '工单'], ['rewards', '签到与娱乐'], ['external', '外部订阅源'], ['risk', '风控'], ['resellers', '倒卖商'], ['statistics', '统计'], ['system', '系统运行信息'], ['security', '账号与安全审计']]),
                    select('state', '操作结果', [['', '全部结果']].concat(Object.entries(states))),
                    select('view', '显示方式', [['operations', '按后台操作显示'], ['records', '全部原始记录']]),
                    [['from', '开始时间'], ['to', '结束时间']].map(function(field) { return h('label', {key: field[0], className: 'mr-2 mb-2'}, field[1], h('input', {type: 'datetime-local', className: 'form-control', value: s[field[0]], onChange: function(e) { self.setState({[field[0]]: e.target.value}); }})); }),
                    h('button', {className: 'btn btn-primary mr-2 mb-2', disabled: s.busy}, '查询'),
                    h('button', {type: 'button', className: 'btn btn-light mr-2 mb-2', disabled: s.busy, onClick: function() { self.verify(); }}, '完整性校验'),
                    h('button', {type: 'button', className: 'btn btn-light mb-2', disabled: s.busy, onClick: function() { self.export(); }}, '导出')),
                table(['序号', '时间', '管理员 / 角色', '操作内容', '结果', '详情'], s.rows.map(function(row) { return [row.id, new Date(row.created_at * 1000).toLocaleString(), (row.business && row.business.actor && row.business.actor.email || row.actor_id || '未认证 / 系统') + ' / ' + (row.role_label || '—'), row.description, row.result_label,
                    h('button', {className: 'btn btn-sm btn-light', onClick: function() { self.showDetail(row, false); }}, '查看')]; })),
                h('button', {className: 'btn btn-light', disabled: s.page <= 1 || s.busy, onClick: function() { self.setState({page: s.page - 1}, function() { self.load(); }); }}, '上一页'), h('span', {className: 'mx-3'}, '第 ' + s.page + ' 页 / 共 ' + s.total + ' 条'),
                h('button', {className: 'btn btn-light', disabled: s.page * 25 >= s.total || s.busy, onClick: function() { self.setState({page: s.page + 1}, function() { self.load(); }); }}, '下一页'), this.renderDetail())));
        }
    }
    t.default = Audit;
}
