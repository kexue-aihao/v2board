<?php

namespace App\Console\Commands;

use App\Services\DynamicRateService;
use App\Services\RatePeakStateMachine;
use Illuminate\Console\Command;

/**
 * 把一串速率喂进峰值状态机，打印逐分钟判定结果。
 *
 * 调阈值时最怕「改完等真实流量验证」，一次晚高峰才有一轮反馈。这个命令让运维当场
 * 看到「连续 8 分钟 100Mbps 会不会被叠加」「4 分钟 20Mbps 会不会」——判定逻辑与线上
 * 完全同一份代码，只是样本由命令行给。
 */
class RateSimulate extends Command
{
    protected $signature = 'rate:simulate {mbps : 逗号分隔的每分钟 Mbps 序列，例如 100,100,20,20,20,20,20,20}
        {--param=* : 临时覆盖参数，可重复，例如 --param=instant_mbps=30 --param=stack_minutes=3}
        {--defaults : 忽略库里的当前参数，只用默认值 + --param}';

    protected $description = '把一串速率喂进峰值状态机，打印逐分钟倍率时间线';

    public function handle(): int
    {
        $sequence = $this->sequence();
        if (!$sequence) {
            $this->error('mbps 参数要写成逗号分隔的速率序列，例如 100,100,20,20,20。');

            return self::FAILURE;
        }

        $machine = new RatePeakStateMachine();
        $params = $this->params();
        $this->line(sprintf(
            '参数：瞬时阈值 %s Mbps，持续阈值 %s Mbps，豁免 %d 分钟，叠加门槛 %d 分钟，叠加倍率 %s，回落步长 %d',
            $params['instant_mbps'],
            $params['sustained_mbps'],
            $params['burst_exempt_minutes'],
            $params['stack_minutes'],
            $params['stack_multiplier'],
            $params['decay_step']
        ));

        $state = ['high' => 0, 'burst' => 0];
        $rows = [];
        $firstStackedAt = null;
        foreach ($sequence as $index => $mbps) {
            $next = $machine->advance($state, $mbps, $params);
            if ($next['multiplier'] > 1 && $firstStackedAt === null) {
                $firstStackedAt = $index + 1;
            }
            $rows[] = [
                $index + 1,
                $mbps,
                $next['state'],
                $next['high'],
                $next['burst'],
                // 模拟时总开关按「开」处理，否则每一行都是 1.0，看不出效果
                $next['multiplier']
            ];
            $state = $next;
        }

        $this->table(['第几分钟', '速率 Mbps', '判定', '持续计数', '突发计数', '该分钟倍率'], $rows);

        if ($firstStackedAt === null) {
            $this->info('这段流量全程不会被叠加（没有被判定为「长时间高带宽」）。');
        } else {
            $this->info(sprintf(
                '第 %d 分钟开始叠加 %s 倍，此后共 %d 分钟处于叠加状态。',
                $firstStackedAt,
                $params['stack_multiplier'],
                count(array_filter($rows, function (array $row) {
                    return $row[5] > 1;
                }))
            ));
        }

        return self::SUCCESS;
    }

    /**
     * @return array<int, float>
     */
    private function sequence(): array
    {
        $values = [];
        foreach (explode(',', (string) $this->argument('mbps')) as $item) {
            $item = trim($item);
            if ($item === '' || !is_numeric($item)) {
                continue;
            }
            $values[] = max(0.0, (float) $item);
        }

        return $values;
    }

    /**
     * @return array<string, mixed>
     */
    private function params(): array
    {
        $params = RatePeakStateMachine::DEFAULT_PARAMS;
        if (!$this->option('defaults')) {
            try {
                // 以线上当前参数为基准，调参时看到的就是「改这一项会怎样」
                $params = array_merge($params, (new DynamicRateService())->settings());
            } catch (\Throwable $e) {
                // 表还没建时退回默认值，模拟本身不需要数据库
            }
        }

        foreach ((array) $this->option('param') as $override) {
            $parts = explode('=', (string) $override, 2);
            if (count($parts) !== 2) {
                continue;
            }
            $key = trim($parts[0]);
            if (!array_key_exists($key, $params)) {
                $this->warn('忽略无法识别的参数：' . $key);
                continue;
            }
            $params[$key] = $parts[1];
        }
        // 模拟必须开着开关，否则倍率恒为 1
        $params['enabled'] = 1;

        return (new RatePeakStateMachine())->normalizeParams($params);
    }
}
