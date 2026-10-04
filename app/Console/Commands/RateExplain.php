<?php

namespace App\Console\Commands;

use App\Services\DynamicRateService;
use App\Services\RateRuleMatcher;
use Illuminate\Console\Command;

/**
 * 「为什么他这一分钟被按 3 倍计费」——把三个因子拆开打出来。
 *
 * 有效倍率 = 节点基础倍率 × 时段倍率 × 用户动态倍率，运维看到的往往只是最后那个
 * 倍数，所以这里把每个节点当前的实际算式列出来，省得靠猜。
 */
class RateExplain extends Command
{
    protected $signature = 'rate:explain {user_id : 用户 ID}
        {--node-user-id= : 指定该用户订阅的节点计费标识}
        {--at= : 按指定时间戳解释（默认当前），用于复核某个历史时段的规则命中}';

    protected $description = '拆解某个用户当前的计费倍率：节点倍率 × 时段倍率 × 动态倍率';

    public function handle(): int
    {
        $userId = (int) $this->argument('user_id');
        if ($userId < 1) {
            $this->error('user_id 必须是正整数。');

            return self::FAILURE;
        }

        $at = $this->option('at') ? (int) $this->option('at') : time();
        $data = (new DynamicRateService())->explain($userId, $at, $this->option('node-user-id') ? (int) $this->option('node-user-id') : null);
        $settings = $data['settings'];

        $this->line('时间：' . date('Y-m-d H:i:s', $at) . '（站点时区）');
        $this->line(sprintf(
            '全局策略：%s    瞬时阈值：%s Mbps    持续阈值：%s Mbps    豁免：%d 分钟    叠加门槛：%d 分钟    叠加倍率：%s',
            (int) $settings['enabled'] === 1 ? '开' : '关',
            $settings['instant_mbps'],
            $settings['sustained_mbps'],
            $settings['burst_exempt_minutes'],
            $settings['stack_minutes'],
            $settings['stack_multiplier']
        ));

        $state = $data['state'];
        if ($state === null) {
            $this->line(isset($data['node_user_id']) ? '当前计费标识：' . $data['node_user_id'] . '；各节点按绑定场景分别计算。' : '该用户还没有采样记录。');
        } else {
            $this->line(sprintf(
                '动态倍率：%s（状态 %s，持续计数 %d，突发计数 %d，上一轮速率 %.3f Mbps，采样于 %s）',
                $data['user_multiplier'],
                $state['state'],
                $state['high'],
                $state['burst'],
                (int) $state['rate_bps'] / 1000000,
                $state['sampled_at'] ? date('Y-m-d H:i:s', (int) $state['sampled_at']) : '—'
            ));
        }

        $matched = [];
        foreach ($data['rules_matched_now'] as $rule) {
            $matched[] = sprintf(
                '#%d %s%s %s-%s ×%s',
                (int) $rule['id'],
                $rule['scope'] === RateRuleMatcher::SCOPE_NODE ? '节点 ' . $rule['node_type'] . '#' . $rule['node_id'] : '全局',
                $rule['remark'] ? '（' . $rule['remark'] . '）' : '',
                $this->minuteText((int) $rule['start_minute']),
                $this->minuteText((int) $rule['end_minute']),
                (float) $rule['multiplier']
            );
        }
        $this->line('此刻命中的时段规则：' . ($matched ? implode('，', $matched) : '（无）'));

        $rows = [];
        foreach ($data['nodes'] as $node) {
            $rows[] = [
                $node['type'] . ' #' . $node['id'],
                $node['name'],
                $node['policy_name'] ?? '全局策略',
                $node['rate'],
                $node['band_multiplier'],
                $node['user_multiplier'] ?? $data['user_multiplier'],
                $node['effective']
            ];
        }
        if ($rows) {
            $this->table(
                ['节点', '名称', '场景策略', '基础倍率', '时段倍率', '动态倍率', '实际计费倍率'],
                $rows
            );
        } else {
            $this->line('没有可展示的节点。');
        }

        return self::SUCCESS;
    }

    private function minuteText(int $minute): string
    {
        return sprintf('%02d:%02d', intdiv($minute, 60) % 24, $minute % 60);
    }
}
