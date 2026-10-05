<?php

namespace App\Services;

use Illuminate\Http\Request;

/** Business semantics are built before redaction, but only safe values survive. */
class SecurityAuditBusiness
{
    public static function definition(string $action, ?Request $request = null): array
    {
        preg_match('/(?:^|\\\\)(\w+)Controller@(\w+)$/', $action, $match);
        $controller = $match[1] ?? '';
        $method = $match[2] ?? '';
        $module = config('admin_audit.modules.' . $controller, ['other', '后台操作', '']);
        if (strpos($action, '\\Server\\') !== false && !in_array($controller, ['Manage', 'Group', 'Route'], true)) {
            $module = ['nodes', '节点管理', 'v2_server_' . strtolower($controller)];
        }
        if ($controller === 'User' && strpos($action, '\\User\\') !== false) $module = ['security', '自身账号', 'v2_user'];
        if ($controller === 'MasterSupervisor') $module = ['system', '系统运行信息', ''];
        $horizon = strpos($action, 'Laravel\\Horizon\\') !== false;
        if ($horizon) $module = ['system', '队列监控', ''];
        if ($controller === 'Reseller' && in_array($method, ['templates', 'saveTemplate'], true)) $module[2] = 'v2_reseller_plan_template';
        if ($controller === 'Ticket' && $method === 'reply') $module[2] = 'v2_ticket';
        if ($controller === 'Rate') {
            if (stripos($method, 'Policy') !== false) $module[2] = 'v2_rate_policy';
            if (stripos($method, 'Binding') !== false) $module[2] = 'v2_rate_node_policy';
        }
        $operation = 'execute';
        if ($request && ($request->boolean('dry_run') || ($method === 'subscriptionCleanup' && $request->input('action', 'scan') === 'scan'))
            || stripos($method, 'preview') === 0 || $method === 'inspectTlsFields') $operation = 'preview';
        elseif (in_array($method, ['save', 'generate', 'saveSource', 'saveRule', 'savePolicy', 'saveTemplate'], true)) $operation = $request && $request->input('id') ? 'update' : 'create';
        elseif (in_array($method, ['drop', 'dropSource', 'dropRule', 'dropPolicy', 'delUser', 'allDel', 'clearSubscribeAudit', 'deleteNodes'], true) || $method === 'subscriptionCleanup') $operation = 'delete';
        elseif (in_array($method, ['copy', 'copyNodes'], true)) $operation = 'copy';
        elseif (in_array($method, ['update', 'show', 'ban', 'release', 'block', 'handleRisk', 'sort'], true) || strpos($method, 'apply') === 0 || strpos($method, 'save') === 0 || strpos($method, 'reset') === 0 || strpos($method, 'set') === 0) $operation = 'update';
        elseif (stripos($method, 'export') !== false || $method === 'dumpCSV') $operation = 'export';
        elseif ($request && $request->isMethod('GET') || in_array($method, ['fetch', 'detail', 'getThemeConfig'], true)) $operation = 'read';
        if (in_array($controller, ['Config', 'Reward'], true) && $method === 'save') $operation = 'update';
        if (in_array($method, ['lookup', 'reveal', 'explain', 'getPaymentForm', 'getUserInfoById'], true)) $operation = 'read';
        $label = SecurityAuditDescription::action($action);
        if (in_array($method, ['save', 'generate', 'saveSource', 'saveRule', 'savePolicy', 'saveTemplate'], true)) {
            $label = preg_replace('/^(保存|批量生成)/u', $operation === 'create' ? '新增' : '修改', $label);
            if ($request && $request->input('generate_count')) $label = '批量生成' . $module[1];
        }
        if ($method === 'subscriptionCleanup') $label = $operation === 'preview' ? '扫描无有效订阅的用户' : '删除无有效订阅的用户';
        if ($controller === 'MasterSupervisor') $label = '查看队列主进程';
        if ($horizon) {
            $subjects = ['DashboardStats' => '运行统计', 'Workload' => '工作负载', 'MasterSupervisor' => '主进程',
                'FailedJobs' => '失败任务', 'PendingJobs' => '待处理任务', 'CompletedJobs' => '已完成任务', 'SilencedJobs' => '静默任务',
                'Monitoring' => '监控标签', 'JobMetrics' => '任务指标', 'QueueMetrics' => '队列指标', 'Batches' => '任务批次', 'Jobs' => '任务'];
            $verbs = ['index' => '查看', 'paginate' => '分页查看', 'show' => '查看详情', 'retry' => '重试', 'retryFailedJobs' => '重试失败任务', 'store' => '添加', 'destroy' => '删除'];
            $label = '队列监控：' . ($verbs[$method] ?? '处理') . ($subjects[$controller] ?? '运行信息');
            if ($controller === 'Retry') $label = '队列监控：重试失败任务';
        }
        return ['version' => 1, 'module' => $module[0], 'module_label' => $module[1], 'table' => $module[2],
            'action' => $action, 'action_label' => $label, 'operation' => $operation];
    }

    public static function object(string $table, $id, array $row = []): array
    {
        $protocol = strpos($table, 'v2_server_') === 0 ? substr($table, 10) : '';
        $tables = config('admin_audit.tables', []);
        if (isset($tables[$table])) $protocol = '';
        $typeLabel = $tables[$table] ?? ($protocol ? ucfirst($protocol) . ' 节点' : '关联数据');
        $identity = $row['trade_no'] ?? ($protocol ? $protocol . ':' . $id : $id);
        if ($table === 'v2_rate_node_policy') $identity = ($row['node_type'] ?? '') . ':' . ($row['node_id'] ?? $id);
        $name = $row['name'] ?? $row['email'] ?? $row['title'] ?? $row['subject'] ?? $row['store_name'] ?? '';
        return ['type' => $table, 'id' => $id, 'identity' => (string)$identity, 'protocol' => $protocol,
            'name' => self::text($name, 160), 'label' => $typeLabel . ($name !== '' ? '“' . self::text($name, 80) . '”' : '') . '（' . (string)$identity . '）'];
    }

    public static function change(string $table, $id, string $operation, ?array $before, ?array $after): array
    {
        if ($table === 'v2_rate_setting') {
            $key = ($after ?? $before)['setting_key'] ?? 'setting_value';
            if ($before !== null) { $before[$key] = $before['setting_value'] ?? null; unset($before['setting_value']); }
            if ($after !== null) { $after[$key] = $after['setting_value'] ?? null; unset($after['setting_value']); }
        }
        $row = $after ?? $before ?? [];
        $old = self::flatten($before ?? []);
        $new = self::flatten($after ?? []);
        $fields = [];
        foreach (array_unique(array_merge(array_keys($old), array_keys($new))) as $path) {
            $field = substr($path, strrpos('.' . $path, '.'));
            if (in_array($field, ['created_at', 'updated_at', 'admin_version', 'revision'], true)) continue;
            $a = $old[$path] ?? null; $b = $new[$path] ?? null;
            if ($before !== null && $after !== null && self::equal($a, $b)) continue;
            if ($before !== null && $after !== null && in_array($field, ['rate', 'multiplier', 'rate_multiplier', 'fallback_rate'], true) && is_numeric($a) && is_numeric($b) && (float)$a === (float)$b) continue;
            if ($before === null && $b === null || $after === null && $a === null) continue;
            $secret = self::secret($path);
            $body = in_array($field, ['message', 'content', 'body', 'store_description'], true);
            $label = config('admin_audit.secrets.' . $field) ?: (config('admin_audit.table_fields.' . $table . '.' . $field) ?: config('admin_audit.fields.' . $field));
            if (in_array($table, ['settings', 'theme'], true)) $label = config('admin_audit.settings.' . $field) ?: $label;
            if ($body) $label = ['message' => '回复正文', 'content' => '正文', 'body' => '正文', 'store_description' => '店铺说明'][$field];
            $safe = $secret || $body || $label !== null;
            $fields[] = ['field' => $path, 'label' => $label ?: ($secret ? '敏感配置' : '其他业务字段') . '（' . $path . '）',
                'sensitive' => $secret, 'before' => $secret ? null : ($body ? self::body($a) : ($safe ? self::value($table, $field, $a, $before ?? []) : '未保留值')),
                'after' => $secret ? null : ($body ? self::body($b) : ($safe ? self::value($table, $field, $b, $row) : '未保留值')),
                'before_label' => $secret ? ($before === null ? '未设置' : '已设置') : self::display($table, $field, $a, $before ?? [], $body, $safe),
                'after_label' => $secret ? ($after === null ? '已移除' : ($before === null ? '已设置' : '已修改')) : self::display($table, $field, $b, $row, $body, $safe)];
        }
        if ($operation === 'updated' && !$fields) $operation = 'unchanged';
        return ['object' => self::object($table, $id, $row), 'operation' => $operation,
            'operation_label' => ['created' => '新增', 'updated' => '修改', 'deleted' => '删除', 'unchanged' => '无变化'][$operation] ?? '处理',
            'fields' => $fields];
    }

    public static function secret(string $field): bool
    {
        if (in_array($field, ['password_limit_enable', 'password_limit_count', 'password_limit_expire', 'password_reset_required'], true)) return false;
        return (bool)preg_match('/password|passwd|secret|token|authorization|cookie|private|recovery|api[_-]?key|credential|manual_key|qr_code|certificate|cert_pem|uuid|(?:^|\.)key$|(?:^|\.)code$|auth_data|custom_html|custom_script|(?:^|\.)payload$/i', $field)
            || array_key_exists($field, config('admin_audit.secrets', []));
    }

    public static function snapshot(string $table, ?array $row): ?array
    {
        if ($row === null) return null;
        $out = [];
        foreach (self::flatten($row) as $path => $value) {
            $field = substr($path, strrpos('.' . $path, '.'));
            if (self::secret($path)) $out[$path] = '[redacted]';
            elseif (in_array($field, ['message', 'content', 'body', 'store_description'], true)) $out[$path] = self::body($value);
            elseif (config('admin_audit.fields.' . $field) || config('admin_audit.table_fields.' . $table . '.' . $field)
                || in_array($table, ['settings', 'theme'], true) && config('admin_audit.settings.' . $field)) $out[$path] = self::value($table, $field, $value, $row);
            else $out[$path] = '未保留值';
        }
        return $out;
    }

    public static function requestInput(Request $request, string $table): array
    {
        $input = self::snapshot($table, $request->except(['user', 'auth_data'])) ?? [];
        // Preserve the intended bulk scope without copying arbitrary nested
        // client data. Applied members are recorded independently in segments.
        if (is_array($request->input('nodes'))) {
            $input['nodes'] = [];
            foreach ($request->input('nodes') as $node) {
                if (!is_array($node)) continue;
                $input['nodes'][] = ['id' => isset($node['id']) && is_numeric($node['id']) ? (int)$node['id'] : null, 'type' => self::text($node['type'] ?? '', 30)];
            }
        }
        if (is_array($request->input('ids'))) $input['ids'] = array_map('intval', array_values(array_filter($request->input('ids'), 'is_numeric')));
        if ($request->has('filter')) $input['filter'] = self::criteria($request);
        return $input;
    }

    private static function flatten(array $row, string $prefix = ''): array
    {
        $out = [];
        foreach ($row as $key => $value) {
            $path = $prefix . $key;
            if (is_string($value) && isset($value[0]) && in_array($value[0], ['{', '['], true)) {
                $decoded = json_decode($value, true);
                if (is_array($decoded)) $value = $decoded;
            }
            if (is_array($value) && $value && array_keys($value) !== range(0, count($value) - 1) && !self::secret($path)) {
                $out += self::flatten($value, $path . '.');
            } else $out[$path] = $value;
        }
        return $out;
    }

    private static function equal($a, $b): bool
    {
        if ($a === $b) return true;
        if ($a === null || $b === null) return false;
        if (is_scalar($a) && is_scalar($b)) return (string)$a === (string)$b;
        if (is_array($a) && is_array($b)) {
            if (array_keys($a) !== range(0, count($a) - 1)) ksort($a);
            if (array_keys($b) !== range(0, count($b) - 1)) ksort($b);
            return json_encode($a) === json_encode($b);
        }
        return false;
    }

    private static function body($value): ?array
    {
        return $value === null ? null : ['length' => mb_strlen((string)$value), 'sha256' => hash('sha256', (string)$value), 'policy' => '正文不保留，仅记录长度与摘要'];
    }

    public static function text($value, int $length = 500): string
    {
        if (!is_scalar($value) && $value !== null) return '';
        $value = preg_replace('~(?:https?|trojan|vmess|vless|ss|tuic|hysteria2?|anytls)://[^\s<>"\']+~i', '[地址已脱敏]', (string)$value);
        $value = preg_replace('/((?:password|token|secret|api[_-]?key|authorization|uuid)\s*[=:]\s*)[^\s,;]+/i', '$1[已脱敏]', $value);
        return mb_substr($value, 0, $length);
    }

    private static function value(string $table, string $field, $value, array $row)
    {
        if ($value === null) return null;
        if (is_array($value)) return SecurityAuditService::redact($value);
        if (is_string($value) && (stripos($field, 'url') !== false || in_array($field, ['dest', 'host_header'], true))) {
            return self::url($value);
        }
        return is_string($value) ? self::text($value, 2000) : $value;
    }

    public static function url(string $value): string
    {
        $parts = parse_url($value);
        if (!$parts || !isset($parts['host']) || !in_array(strtolower($parts['scheme'] ?? 'https'), ['http', 'https'], true)) return '[地址已脱敏]';
        return ($parts['scheme'] ?? 'https') . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '') . (isset($parts['path']) || isset($parts['query']) || isset($parts['fragment']) ? '/[路径和参数已脱敏]' : '');
    }

    private static function display(string $table, string $field, $value, array $row, bool $body, bool $safe): string
    {
        if ($field === 'admin_role') return config('admin_security.roles.' . ($value ?: ''), '普通用户');
        if ($value === null) return in_array($field, ['expired_at', 'expires_at'], true) ? '长期有效 / 未设置' : '未设置';
        if ($body) return '正文 ' . mb_strlen((string)$value) . ' 字（不保留全文）';
        if (!$safe) return '已变化，未保留值';
        if ($field === 'transfer_enable') return $table === 'v2_plan' ? $value . ' GB' : self::number((float)$value / 1073741824) . ' GB';
        if (in_array($field, ['u', 'd'], true)) return self::number((float)$value / 1073741824) . ' GB';
        if (in_array($field, ['balance_before', 'balance_after', 'order_amount_minor', 'gateway_amount_minor'], true) || $table === 'v2_balance_log' && $field === 'amount') return number_format((float)$value / 100, 2, '.', '') . ' ' . ($row['gateway_currency'] ?? config('v2board.currency', 'CNY'));
        if (in_array($field, ['balance', 'commission_balance', 'actual_commission_balance', 'total_amount', 'order_amount', 'get_amount', 'handling_amount', 'discount_amount', 'surplus_amount', 'refund_amount', 'balance_amount', 'amount_snapshot', 'paid_amount_minor', 'handling_fee_fixed'], true) || substr($field, -6) === '_price') return number_format((float)$value / 100, 2, '.', '') . ' ' . config('v2board.currency', 'CNY');
        if ($field === 'value' && in_array($table, ['v2_coupon', 'v2_giftcard'], true)) {
            if ((int)($row['type'] ?? 0) === 1) return number_format((float)$value / 100, 2, '.', '') . ' ' . config('v2board.currency', 'CNY');
            return (string)$value . ($table === 'v2_coupon' ? '%' : ((int)($row['type'] ?? 0) === 3 ? ' GB' : ' 天'));
        }
        if (substr($field, -3) === '_at' && is_numeric($value)) return (int)$value > 0 ? \Carbon\Carbon::createFromTimestamp((int)$value, config('app.timezone', 'Asia/Shanghai'))->format('Y-m-d H:i:s T') : '未设置';
        if ($field === 'show') return (int)$value ? (strpos($table, 'v2_server_') === 0 || $table === 'v2_plan' ? '已上架' : '显示') : (strpos($table, 'v2_server_') === 0 || $table === 'v2_plan' ? '已下架' : '隐藏');
        if ($field === 'banned') return (int)$value ? '已停用' : '正常';
        if (in_array($field, ['is_admin', 'is_staff', 'enable', 'enabled', 'renew', 'tls', 'insecure', 'zero_rtt_handshake'], true) || substr($field, -7) === '_enable') return (int)$value ? '是 / 启用' : '否 / 停用';
        if ($field === 'status') {
            $maps = ['v2_order' => ['待支付', '开通中', '已取消', '已完成', '已折抵'], 'v2_ticket' => ['处理中', '已关闭']];
            if (isset($maps[$table][$value])) return $maps[$table][$value];
        }
        if ($field === 'commission_status') return [0 => '待确认', 1 => '发放中', 2 => '有效', 3 => '无效'][$value] ?? (string)$value;
        if ($field === 'commission_type') return [0 => '跟随系统设置', 1 => '每次购买', 2 => '仅首次购买'][$value] ?? (string)$value;
        if (in_array($field, ['discount', 'commission_rate', 'handling_fee_percent', 'risk_percent'], true)) return $value . '%';
        if ($field === 'period') return ['month_price' => '月付', 'quarter_price' => '季付', 'half_year_price' => '半年付', 'year_price' => '年付', 'two_year_price' => '两年付', 'three_year_price' => '三年付', 'onetime_price' => '一次性', 'reset_price' => '流量重置'][$value] ?? (string)$value;
        if ($field === 'type' && $table === 'v2_order') return [1 => '新购', 2 => '续费', 3 => '更换套餐', 4 => '流量重置'][$value] ?? (string)$value;
        if ($field === 'status' && $table === 'v2_payment_attempt') return ['initializing' => '初始化', 'pending' => '待支付', 'paid' => '已支付', 'failed' => '失败', 'invalidated' => '已作废'][$value] ?? (string)$value;
        if ($field === 'reply_status') return (int)$value ? '管理员已回复' : '等待管理员回复';
        $enums = ['pending' => '待审核', 'active' => '生效', 'rejected' => '已拒绝', 'suspended' => '已停用', 'released' => '已解除', 'ok' => '成功', 'error' => '失败', 'global' => '跟随全局', 'off' => '关闭', 'policy' => '指定策略', 'normal' => '正常', 'maintenance' => '维护', 'user' => '用户', 'subscription' => '订阅', 'account' => '账号', 'store' => '店铺'];
        if (is_string($value) && isset($enums[$value])) return $enums[$value];
        if ($field === 'speed_limit') return $value . ' Mbps';
        if ($field === 'device_limit') return $value . ' 台';
        $references = ['plan_id' => ['v2_plan', 'name'], 'base_plan_id' => ['v2_plan', 'name'], 'group_id' => ['v2_server_group', 'name'],
            'group_ids' => ['v2_server_group', 'name'], 'route_id' => ['v2_server_route', 'remarks'], 'route_ids' => ['v2_server_route', 'remarks'],
            'payment_id' => ['v2_payment', 'name'], 'policy_id' => ['v2_rate_policy', 'name']];
        if (isset($references[$field]) && (is_numeric($value) || is_array($value))) {
            [$referenceTable, $nameColumn] = $references[$field];
            // These names are snapshots at the time of the operation. Missing
            // legacy tables/rows still retain the actual submitted identity.
            if (\Illuminate\Support\Facades\Schema::hasTable($referenceTable) && \Illuminate\Support\Facades\Schema::hasColumn($referenceTable, $nameColumn)) {
                $ids = array_values(array_filter((array)$value, 'is_numeric'));
                $names = \Illuminate\Support\Facades\DB::table($referenceTable)->whereIn('id', $ids)->pluck($nameColumn, 'id');
                return implode('、', array_map(function ($id) use ($names) { return (isset($names[$id]) ? self::text($names[$id], 120) : '未找到对象') . '（' . $id . '）'; }, $ids));
            }
        }
        if (in_array($field, ['instant_mbps', 'sustained_mbps'], true)) return $value . ' Mbps';
        if (in_array($field, ['burst_exempt_minutes', 'stack_minutes'], true)) return $value . ' 分钟';
        if (in_array($field, ['start_minute', 'end_minute'], true)) return sprintf('%02d:%02d', intdiv((int)$value, 60), (int)$value % 60);
        $safeValue = self::value($table, $field, $value, $row);
        return is_array($safeValue) ? json_encode($safeValue, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : (string)$safeValue;
    }

    private static function number(float $value): string { return rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.'); }

    public static function envelope(Request $request, array $context, string $stage, string $state): array
    {
        $business = $context['business'] ?? self::definition($context['action'] ?? '', $request);
        $business['stage'] = $stage; $business['state'] = $state;
        $business['channel'] = $context['channel'] ?? '后台';
        $business['batch_id'] = $context['batch_id'] ?? null;
        $business['job_ref'] = $context['job_ref'] ?? null;
        $business['actor'] = array_intersect_key($context['actor'] ?? [], array_flip(['id', 'email', 'admin_role', 'admin_version']));
        $business['criteria'] = ($context['channel'] ?? '') === '异步任务' ? [] : self::criteria($request);
        $business['targets'] = $context['targets'] ?? [];
        if (($business['operation'] ?? '') === 'copy' && $request->input('id')) $business['source'] = self::object($business['table'], $request->input('id'));
        if (!$business['targets']) {
            foreach (['trade_no', 'id', 'user_id', 'subscription_id', 'source_id', 'name'] as $key) {
                $id = $request->input($key, self::routeValue($request, $key));
                if (is_scalar($id)) { $business['targets'][] = self::object($business['table'], $id); break; }
            }
        }
        $business['counts'] = ['created' => 0, 'updated' => 0, 'deleted' => 0, 'unchanged' => 0];
        foreach ($context['changes'] ?? [] as $change) {
            $item = $change['business'] ?? null;
            if ($item) $business['counts'][$item['operation']] = ($business['counts'][$item['operation']] ?? 0) + 1;
        }
        $business['effects'] = array_values(array_unique(array_merge($context['effects'] ?? [], $context['external_effects'] ?? []), SORT_REGULAR));
        $business['effects_total'] = count($business['effects']);
        $business['effects'] = array_slice($business['effects'], 0, 20);
        $business['result'] = $context['business_result'] ?? [];
        $business['detail_records'] = $context['detail_records'] ?? [];
        return $business;
    }

    public static function criteria(Request $request): array
    {
        $out = [];
        $keys = ['id', 'user_id', 'subscription_id', 'source_id', 'summary_id', 'trade_no', 'email', 'name', 'ip', 'scope', 'target', 'status', 'current', 'page', 'pageSize', 'page_size', 'sort', 'sort_type', 'from', 'to', 'start_at', 'end_at', 'keyword', 'dry_run', 'confirm', 'action', 'generate_count', 'subject', 'retention_days', 'module', 'field', 'object', 'request_id', 'batch_id', 'job_ref', 'state', 'after', 'limit'];
        foreach ($keys as $key) {
            if ($key === 'id' && $request->route() && strpos($request->route()->getActionName(), '@removeActiveSession') !== false) continue;
            if ($request->has($key) && is_scalar($request->input($key))) $out[] = ['field' => $key, 'label' => config('admin_audit.fields.' . $key, ['current' => '页码', 'page' => '页码', 'pageSize' => '每页数量', 'page_size' => '每页数量', 'from' => '起始时间', 'to' => '结束时间', 'keyword' => '查询关键词', 'dry_run' => '试运行', 'confirm' => '确认执行', 'generate_count' => '生成数量', 'target' => '处理对象'][$key] ?? '筛选条件'), 'value' => self::text($request->input($key))];
        }
        foreach ((array)$request->input('filter', []) as $filter) {
            if (!is_array($filter) || !isset($filter['key']) || self::secret((string)$filter['key'])) continue;
            if (!config('admin_audit.fields.' . $filter['key'])) continue;
            $out[] = ['field' => $filter['key'], 'label' => config('admin_audit.fields.' . $filter['key']), 'condition' => self::text($filter['condition'] ?? '='), 'value' => self::text($filter['value'] ?? '')];
        }
        foreach (['id' => '对象编号', 'tag' => '监控标签'] as $key => $label) {
            if (!$request->has($key) && is_scalar(self::routeValue($request, $key))) $out[] = ['field' => $key, 'label' => $label, 'value' => self::text(self::routeValue($request, $key))];
        }
        return $out;
    }

    private static function routeValue(Request $request, string $key)
    {
        // Synthetic requests and rejected routes can exist before binding.
        $route = $request->route();
        return $route ? ($route->parameters[$key] ?? null) : null;
    }

    public static function outcome($response, array $context): array
    {
        $body = is_object($response) && method_exists($response, 'getContent') ? json_decode($response->getContent(), true) : null;
        $data = is_array($body) ? ($body['data'] ?? null) : null;
        $result = $context['business_result'] ?? [];
        if (is_array($data)) {
            foreach (['matched_count', 'requested_count', 'changed_count', 'updated_count', 'skipped_count', 'unchanged_count', 'created_count', 'deleted_count', 'copied_count', 'count', 'total', 'returned', 'source_id', 'dry_run', 'ok', 'skipped', 'available'] as $key) if (isset($data[$key]) && is_scalar($data[$key])) $result[$key] = $data[$key];
        }
        if (is_array($body)) {
            foreach (['total', 'returned'] as $key) if (isset($body[$key]) && is_numeric($body[$key])) $result[$key] = $body[$key];
            if (is_array($data) && !isset($result['returned'])) $result['returned'] = isset($data['data']) && is_array($data['data']) ? count($data['data']) : (array_keys($data) === range(0, count($data) - 1) || !$data ? count($data) : null);
        }
        $status = is_object($response) && method_exists($response, 'getStatusCode') ? $response->getStatusCode() : 200;
        $state = $status === 403 ? 'denied' : ($status >= 400 || $data === false || isset($result['ok']) && !$result['ok'] ? 'failure' : 'success');
        if ($state === 'failure' && empty($result['reason'])) $result['reason'] = '业务返回失败，请按请求编号排查';
        if ($state === 'success' && ($result['skipped_count'] ?? 0) > 0) $state = 'partial';
        if ($state === 'success' && ($result['queued_count'] ?? 0) > 0 && substr($context['action'] ?? '', -9) === '@sendMail') $state = 'queued';
        return ['state' => $result['state'] ?? $state, 'result' => $result, 'body' => $body];
    }

    public static function describe(array $business): string
    {
        $description = $business['action_label'] ?? '后台操作';
        $targets = $business['targets'] ?? [];
        if ($targets) $description .= '：' . implode('、', array_column(array_slice($targets, 0, 2), 'label')) . (count($targets) > 2 ? '等' : '');
        $parts = [];
        foreach ($business['items'] ?? [] as $item) {
            foreach ($item['fields'] ?? [] as $field) {
                $parts[] = $field['label'] . ' ' . ($field['sensitive'] ? $field['after_label'] : $field['before_label'] . ' → ' . $field['after_label']);
                if (count($parts) >= 4) break 2;
            }
        }
        if ($parts) $description .= '；' . implode('，', $parts);
        $result = $business['result'] ?? [];
        foreach (['matched_count' => '匹配', 'updated_count' => '修改', 'created_count' => '新增', 'deleted_count' => '删除', 'skipped_count' => '跳过', 'unchanged_count' => '无变化', 'queued_count' => '任务提交', 'exported_count' => '导出', 'count' => '数量', 'total' => '总数', 'returned' => '返回'] as $key => $label) if (isset($result[$key])) $description .= '；' . $label . ' ' . $result[$key];
        if (($business['state'] ?? '') === 'no_change') $description .= '；未改变业务内容';
        if (($business['state'] ?? '') === 'failure') $description .= '；执行失败';
        if (($business['stage'] ?? '') === 'begin') $description = '开始：' . $description;
        if (($business['operation'] ?? '') === 'preview' && mb_strpos($description, '预览') === false && mb_strpos($description, '扫描') === false) $description = '预览：' . $description;
        return mb_substr($description, 0, 1000);
    }
}
