<?php

namespace App\Http\Controllers\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\CommissionLog;
use App\Models\Order;
use App\Models\ServerHysteria;
use App\Models\ServerTuic;
use App\Models\ServerShadowsocks;
use App\Models\ServerTrojan;
use App\Models\ServerVmess;
use App\Models\ServerVless;
use App\Models\ServerAnytls;
use App\Models\ServerV2node;
use App\Models\Stat;
use App\Models\StatServer;
use App\Models\StatUser;
use App\Models\Ticket;
use App\Models\User;
use App\Services\StatisticalService;
use App\Services\TrafficRewardService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class StatController extends Controller
{
    public function getStat(Request $request)
    {
        return $this->getOverride($request);
    }

    public function getStatRecord(Request $request)
    {
        $type = (string)$request->input('type');
        if (!in_array($type, ['paid_total', 'commission_total', 'register_count'], true)) {
            abort(422, __('参数有误'));
        }

        $service = $this->historicalStatistics($request);
        return response(['data' => $service->getStatRecord($type) ?: []]);
    }

    public function getRanking(Request $request)
    {
        $type = (string)$request->input('type');
        if (!in_array($type, ['server_traffic_rank', 'user_consumption_rank', 'invite_rank'], true)) {
            abort(422, __('参数有误'));
        }

        $limit = max(1, min(100, (int)$request->input('limit', 20)));
        $service = $this->historicalStatistics($request);
        return response(['data' => $service->getRanking($type, $limit) ?: []]);
    }

    private function historicalStatistics(Request $request): StatisticalService
    {
        $startAt = (int)$request->input('start_at', strtotime('-30 days'));
        $endAt = (int)$request->input('end_at', time());
        if ($startAt <= 0 || $endAt <= $startAt || $endAt > time() + 60) {
            abort(422, __('参数有误'));
        }

        $service = new StatisticalService();
        $service->setStartAt($startAt);
        $service->setEndAt($endAt);
        return $service;
    }

    public function getOverride(Request $request)
    {
        $traffic = [
            'day_traffic_total' => 0,
            'day_traffic_upload' => 0,
            'day_traffic_download' => 0,
            'month_traffic_total' => 0,
            'month_traffic_upload' => 0,
            'month_traffic_download' => 0
        ];
        if (Schema::hasTable('v2_stat_user')) {
            $sumTraffic = function ($startAt) {
                return StatUser::where('record_type', 'd')
                    ->where('record_at', '>=', $startAt)
                    ->where('record_at', '<', time())
                    ->select([
                        DB::raw('COALESCE(SUM(u * server_rate), 0) AS upload'),
                        DB::raw('COALESCE(SUM(d * server_rate), 0) AS download'),
                        DB::raw('COALESCE(SUM((u + d) * server_rate), 0) AS total')
                    ])
                    ->first();
            };
            $dayTraffic = $sumTraffic(strtotime(date('Y-m-d')));
            $monthTraffic = $sumTraffic(strtotime(date('Y-m-1')));
            $traffic = [
                'day_traffic_total' => (float) $dayTraffic->total,
                'day_traffic_upload' => (float) $dayTraffic->upload,
                'day_traffic_download' => (float) $dayTraffic->download,
                'month_traffic_total' => (float) $monthTraffic->total,
                'month_traffic_upload' => (float) $monthTraffic->upload,
                'month_traffic_download' => (float) $monthTraffic->download
            ];
        }

        return [
            'data' => array_merge([
                'online_user' => User::where('t','>=', time() - 600)
                    ->count(),
                'month_income' => Order::where('created_at', '>=', strtotime(date('Y-m-1')))
                    ->where('created_at', '<', time())
                    ->whereNotIn('status', [0, 2])
                    ->sum('total_amount'),
                'month_register_total' => User::where('created_at', '>=', strtotime(date('Y-m-1')))
                    ->where('created_at', '<', time())
                    ->count(),
                'day_register_total' => User::where('created_at', '>=', strtotime(date('Y-m-d')))
                    ->where('created_at', '<', time())
                    ->count(),
                'ticket_pending_total' => Ticket::where('status', 0)
                    ->where('reply_status', 0)
                    ->count(),
                'commission_pending_total' => Order::where('commission_status', 0)
                    ->where('invite_user_id', '!=', NULL)
                    ->whereNotIn('status', [0, 2])
                    ->where('commission_balance', '>', 0)
                    ->count(),
                'day_income' => Order::where('created_at', '>=', strtotime(date('Y-m-d')))
                    ->where('created_at', '<', time())
                    ->whereNotIn('status', [0, 2])
                    ->sum('total_amount'),
                'last_month_income' => Order::where('created_at', '>=', strtotime('-1 month', strtotime(date('Y-m-1'))))
                    ->where('created_at', '<', strtotime(date('Y-m-1')))
                    ->whereNotIn('status', [0, 2])
                    ->sum('total_amount'),
                'commission_month_payout' => CommissionLog::where('created_at', '>=', strtotime(date('Y-m-1')))
                    ->where('created_at', '<', time())
                    ->sum('get_amount'),
                'commission_last_month_payout' => CommissionLog::where('created_at', '>=', strtotime('-1 month', strtotime(date('Y-m-1'))))
                    ->where('created_at', '<', strtotime(date('Y-m-1')))
                    ->sum('get_amount')
            ], $traffic)
        ];
    }

    public function getOrder(Request $request)
    {
        $statistics = Stat::where('record_type', 'd')
            ->limit(31)
            ->orderBy('record_at', 'DESC')
            ->get()
            ->toArray();
        $result = [];
        foreach ($statistics as $statistic) {
            $date = date('m-d', $statistic['record_at']);
            $result[] = [
                'type' => '注册人数',
                'date' => $date,
                'value' => $statistic['register_count']
            ];
            $result[] = [
                'type' => '收款金额',
                'date' => $date,
                'value' => $statistic['paid_total'] / 100
            ];
            $result[] = [
                'type' => '收款笔数',
                'date' => $date,
                'value' => $statistic['paid_count']
            ];
            $result[] = [
                'type' => '佣金金额(已发放)',
                'date' => $date,
                'value' => $statistic['commission_total'] / 100
            ];
            $result[] = [
                'type' => '佣金笔数(已发放)',
                'date' => $date,
                'value' => $statistic['commission_count']
            ];
        }
        $result = array_reverse($result);
        return [
            'data' => $result
        ];
    }

    public function getServerLastRank()
    {
        $servers = [
            'shadowsocks' => ServerShadowsocks::where('parent_id', null)->get()->toArray(),
            'v2ray' => ServerVmess::where('parent_id', null)->get()->toArray(),
            'trojan' => ServerTrojan::where('parent_id', null)->get()->toArray(),
            'vmess' => ServerVmess::where('parent_id', null)->get()->toArray(),
            'vless' => ServerVless::where('parent_id', null)->get()->toArray(),
            'tuic' => ServerTuic::where('parent_id', null)->get()->toArray(),
            'hysteria'=> ServerHysteria::where('parent_id', null)->get()->toArray(),
            'anytls' => ServerAnytls::where('parent_id', null)->get()->toArray(),
            'v2node' => ServerV2node::where('parent_id', null)->get()->toArray()
        ];
        $startAt = strtotime('-1 day', strtotime(date('Y-m-d')));
        $endAt = strtotime(date('Y-m-d'));
        $statistics = StatServer::select([
            'server_id',
            'server_type',
            'u',
            'd',
            DB::raw('(u+d) as total')
        ])
            ->where('record_at', '>=', $startAt)
            ->where('record_at', '<', $endAt)
            ->where('record_type', 'd')
            ->limit(15)
            ->orderBy('total', 'DESC')
            ->get()
            ->toArray();
        foreach ($statistics as $k => $v) {
            foreach ($servers[$v['server_type']] as $server) {
                if ($server['id'] === $v['server_id']) {
                    $statistics[$k]['server_name'] = $server['name'];
                }
            }
            $statistics[$k]['total'] = $statistics[$k]['total'] / 1073741824;
        }
        array_multisort(array_column($statistics, 'total'), SORT_DESC, $statistics);
        return [
            'data' => $statistics
        ];
    }

    public function getServerTodayRank()
    {
        $servers = [
            'shadowsocks' => ServerShadowsocks::where('parent_id', null)->get()->toArray(),
            'v2ray' => ServerVmess::where('parent_id', null)->get()->toArray(),
            'trojan' => ServerTrojan::where('parent_id', null)->get()->toArray(),
            'vmess' => ServerVmess::where('parent_id', null)->get()->toArray(),
            'vless' => ServerVless::where('parent_id', null)->get()->toArray(),
            'tuic' => ServerTuic::where('parent_id', null)->get()->toArray(),
            'hysteria'=> ServerHysteria::where('parent_id', null)->get()->toArray(),
            'anytls' => ServerAnytls::where('parent_id', null)->get()->toArray(),
            'v2node' => ServerV2node::where('parent_id', null)->get()->toArray()
        ];
        $startAt = strtotime(date('Y-m-d'));
        $endAt = time();
        $statistics = StatServer::select([
            'server_id',
            'server_type',
            'u',
            'd',
            DB::raw('(u+d) as total')
        ])
            ->where('record_at', '>=', $startAt)
            ->where('record_at', '<', $endAt)
            ->where('record_type', 'd')
            ->limit(15)
            ->orderBy('total', 'DESC')
            ->get()
            ->toArray();
        foreach ($statistics as $k => $v) {
            foreach ($servers[$v['server_type']] as $server) {
                if ($server['id'] === $v['server_id']) {
                    $statistics[$k]['server_name'] = $server['name'];
                }
            }
            $statistics[$k]['total'] = $statistics[$k]['total'] / 1073741824;
        }
        array_multisort(array_column($statistics, 'total'), SORT_DESC, $statistics);
        return [
            'data' => $statistics
        ];
    }

    public function getUserTodayRank()
    {
        $startAt = strtotime(date('Y-m-d'));
        $endAt = time();
        $statistics = StatUser::select([
            'user_id',
            'server_rate',
            'u',
            'd',
            DB::raw('(u+d) as total')
        ])
            ->where('record_at', '>=', $startAt)
            ->where('record_at', '<', $endAt)
            ->where('record_type', 'd')
            ->limit(30)
            ->orderBy('total', 'DESC')
            ->get()
            ->toArray();
        $data = [];
        $idIndexMap = [];
        foreach ($statistics as $k => $v) {
            $id = $statistics[$k]['user_id'];
            $user = User::where('id', $id)->first();
            $statistics[$k]['email'] = empty($user) ? "null" : $user['email'];
            $statistics[$k]['total'] = $statistics[$k]['total'] * $statistics[$k]['server_rate'] / 1073741824;
            if (isset($idIndexMap[$id])) {
                $index = $idIndexMap[$id];
                $data[$index]['total'] += $statistics[$k]['total'];
            } else {
                unset($statistics[$k]['server_rate']);
                $data[] = $statistics[$k];
                $idIndexMap[$id] = count($data) - 1;
            }
        }
        array_multisort(array_column($data, 'total'), SORT_DESC, $data);
        return [
            'data' => array_slice($data, 0, 15)
        ];
    }

    public function getUserLastRank()
    {
        $startAt = strtotime('-1 day', strtotime(date('Y-m-d')));
        $endAt = strtotime(date('Y-m-d'));
        $statistics = StatUser::select([
            'user_id',
            'server_rate',
            'u',
            'd',
            DB::raw('(u+d) as total')
        ])
            ->where('record_at', '>=', $startAt)
            ->where('record_at', '<', $endAt)
            ->where('record_type', 'd')
            ->limit(30)
            ->orderBy('total', 'DESC')
            ->get()
            ->toArray();
        $data = [];
        $idIndexMap = [];
        foreach ($statistics as $k => $v) {
            $id = $statistics[$k]['user_id'];
            $user = User::where('id', $id)->first();
            $statistics[$k]['email'] = empty($user) ? "null" : $user['email'];
            $statistics[$k]['total'] = $statistics[$k]['total'] * $statistics[$k]['server_rate'] / 1073741824;
            if (isset($idIndexMap[$id])) {

                $index = $idIndexMap[$id];
                $data[$index]['total'] += $statistics[$k]['total'];
            } else {
                unset($statistics[$k]['server_rate']);
                $data[] = $statistics[$k];
                $idIndexMap[$id] = count($data) - 1;
            }
        }
        array_multisort(array_column($data, 'total'), SORT_DESC, $data);
        return [
            'data' => array_slice($data, 0, 15)
        ];
    }

    public function getStatUser(Request $request)
    {
        $request->validate([
            'user_id' => 'required|integer'
        ]);
        $userId = (int)$request->input('user_id');
        $current = max(1, (int)$request->input('current', 1));
        $pageSize = max(10, min(100, (int)$request->input('pageSize', 10)));

        $traffic = DB::table('v2_stat_user')
            ->where('user_id', $userId)
            ->selectRaw("CONCAT('traffic:', id) AS record_key")
            ->addSelect([
                'record_at',
                'u',
                'd',
                'server_rate',
                DB::raw("'traffic' AS record_type"),
                DB::raw('NULL AS source'),
                DB::raw('NULL AS entrypoint'),
                DB::raw('0 AS reward_bytes'),
                DB::raw('NULL AS metadata'),
            ]);

        if (Schema::hasTable('v2_traffic_reward_log')) {
            $rewards = DB::table('v2_traffic_reward_log')
                ->where('user_id', $userId)
                ->selectRaw("CONCAT('reward:', id) AS record_key")
                ->addSelect([
                    DB::raw('created_at AS record_at'),
                    DB::raw('0 AS u'),
                    DB::raw('0 AS d'),
                    DB::raw('1 AS server_rate'),
                    DB::raw("'reward' AS record_type"),
                    'source',
                    'entrypoint',
                    'reward_bytes',
                    'metadata',
                ]);
            $traffic->unionAll($rewards);
        }

        $builder = DB::query()
            ->fromSub($traffic, 'traffic_records')
            ->orderByDesc('record_at')
            ->orderByDesc('record_key');
        $total = $builder->count();
        $records = $builder->forPage($current, $pageSize)->get()
            ->map(function ($row) {
                $row->increase_bytes = 0;
                $row->deducted_bytes = (int)round(((int)$row->u + (int)$row->d) * (float)$row->server_rate);
                $row->reward_label = '流量使用';
                $row->reward_detail = '节点流量按倍率扣除';

                if ($row->record_type !== 'reward') {
                    return $row;
                }

                $metadata = is_string($row->metadata)
                    ? (json_decode($row->metadata, true) ?: [])
                    : (array)$row->metadata;
                $change = TrafficRewardService::splitTrafficChange((int)$row->reward_bytes);
                $row->increase_bytes = $change['increase_bytes'];
                $row->deducted_bytes = $change['deducted_bytes'];
                $row->reward_label = $this->rewardLogLabel((string)$row->source, $metadata);
                $row->reward_detail = $this->rewardLogDetail((string)$row->source, $metadata, (int)$row->reward_bytes);
                $row->entrypoint = (string)($row->entrypoint ?: ($metadata['entrypoint'] ?? ''));
                $row->bet_gb = $metadata['bet_gb'] ?? null;
                $row->payout_gb = $metadata['payout_gb'] ?? ($metadata['reward_gb'] ?? null);
                $row->win_probability = $metadata['win_probability'] ?? null;
                $row->payout_multiplier = $metadata['payout_multiplier'] ?? null;
                $row->net_bytes = (int)($metadata['net_bytes'] ?? $row->reward_bytes);
                return $row;
            });
        return [
            'data' => $records,
            'total' => $total
        ];
    }

    private function rewardLogLabel(string $source, array $metadata): string
    {
        if ($source === 'checkin') {
            return '每日签到';
        }

        return [
            'dice' => '骰子娱乐',
            'slots' => '老虎机娱乐',
            'poker' => '炸金花娱乐',
        ][$metadata['game'] ?? ''] ?? '游戏娱乐';
    }

    private function rewardLogDetail(string $source, array $metadata, int $rewardBytes): string
    {
        if ($source === 'checkin') {
            $gb = $metadata['reward_gb'] ?? $metadata['gb'] ?? round($rewardBytes / TrafficRewardService::GB, 2);
            $entrypoint = $metadata['entrypoint'] ?? '';
            $netGb = round((int)($metadata['net_bytes'] ?? $rewardBytes) / TrafficRewardService::GB, 2);
            return "签到奖励 {$gb} GB；入口 {$entrypoint}；净变化 {$netGb} GB";
        }

        $result = $metadata['result'] ?? null;
        if (is_array($result)) {
            $result = implode(' | ', $result);
        } elseif ($result === null && isset($metadata['hands'])) {
            $result = '群组牌局';
        }
        $outcome = !empty($metadata['won']) ? '中奖' : '未中奖';
        $betGb = $metadata['bet_gb'] ?? 0;
        $payoutGb = $metadata['payout_gb'] ?? 0;
        $guessText = ($metadata['game'] ?? '') === 'dice' && array_key_exists('guess', $metadata)
            ? '猜测 ' . (int)$metadata['guess'] . ' 点；'
            : '';
        $resultText = $result === null || $result === '' ? '' : "结果 {$result}；";
        $entrypoint = $metadata['entrypoint'] ?? '';
        $probability = $metadata['win_probability'] ?? '-';
        $probabilityScope = $metadata['probability_scope'] ?? (in_array($metadata['game'] ?? '', ['dice', 'slots'], true) ? 'after_trigger' : 'per_round');
        $probabilityLabel = $probabilityScope === 'after_win' ? '胜出后概率' : ($probabilityScope === 'after_trigger' ? '触发后概率' : '单局概率');
        $multiplier = $metadata['payout_multiplier'] ?? '-';
        $netGb = round((int)($metadata['net_bytes'] ?? $rewardBytes) / TrafficRewardService::GB, 2);
        return "{$guessText}{$resultText}{$outcome}；入口 {$entrypoint}；押注 {$betGb} GB；{$probabilityLabel} {$probability}%；倍率 {$multiplier}；返还 {$payoutGb} GB；净变化 {$netGb} GB";
    }

}

