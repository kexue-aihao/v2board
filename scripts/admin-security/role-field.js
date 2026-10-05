function(e, t, n) {
    n.r(t);
    var React = n('q1tI'), h = React.createElement, nextId = 0;
    t.default = function RoleField(props) {
        var user = props.user, protectedAccount = Number(user.id) === 1;
        var fieldId = React.useState(function() { return 'user-admin-role-' + ++nextId; })[0];
        var roles = protectedAccount ? [['super', '超级管理员']] : [
            ['', '普通用户'], ['operations', '运维管理员'], ['finance', '财务管理员'],
            ['support', '客服管理员'], ['marketing', '运营管理员'],
        ];
        return h('div', {className: 'form-group'},
            h('label', {htmlFor: fieldId}, '管理员身份'),
            h('select', {id: fieldId, className: 'form-control', 'aria-label': '管理员身份',
                value: protectedAccount ? 'super' : user.admin_role || '', disabled: protectedAccount,
                onChange: function(event) { props.onChange(event.target.value || null); }},
                roles.map(function(role) { return h('option', {key: role[0], value: role[0]}, role[1]); })));
    };
}
