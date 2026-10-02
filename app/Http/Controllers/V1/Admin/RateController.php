<?php

namespace App\Http\Controllers\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\DynamicRateService;
use App\Services\ServerIdService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 管理页「动态倍率」的接口。
 *
 * 页面就三块：时段规则、峰值参数、实时叠加状态。取数做成一个 fetch 一次拿全 ——
 * 三块互相关联（规则改了要重看状态、参数改了要解释影响），分三个请求反而要处理
 * 各块不一致的中间态。
 */
class RateController extends Controller
{
    private const RULE_LIMIT = 200;

    public function fetch(Request $request)
    {
        $service = new DynamicRateService();

        return response([
            'data' => [
                'rules' => $service->rules(),
                'settings' => $service->settings(),
                'states' => $service->listStates([
                    'only_stacked' => (int) $request->input('only_stacked', 1) === 1,
                    'keyword' => (string) $request->input('keyword', ''),
                    'page' => max(1, (int) $request->input('page', 1)),
                    'limit' => max(1, min(200, (int) $request->input('limit', 50)))
                ]),
                // 作用域选「某个节点」时要能选节点，顺带给出前 200 个（节点总数就这个量级）
                'nodes' => $this->nodeOptions()
            ]
        ]);
    }

    public function saveRule(Request $request)
    {
        $params = $request->validate([
            'id' => 'nullable|integer|min:1',
            'scope' => 'required|in:global,node',
            'node_type' => 'nullable|string|max:24',
            'node_id' => 'nullable|integer|min:0',
            'weekdays' => 'nullable|array|max:7',
            'weekdays.*' => 'integer|between:1,7',
            'start_minute' => 'required|integer|between:0,1440',
            'end_minute' => 'required|integer|between:0,1440',
            'multiplier' => 'required|numeric|min:0|max:999.999',
            'enabled' => 'nullable|in:0,1',
            'remark' => 'nullable|string|max:255'
        ]);

        if ($params['scope'] === 'node') {
            $type = (string) ($params['node_type'] ?? '');
            if (!isset(ServerIdService::TYPES[$type]) || (int) ($params['node_id'] ?? 0) < 1) {
                abort(422, __('选择节点作用域时必须指定具体的节点'));
            }
        }

        $id = (new DynamicRateService())->saveRule($params, isset($params['id']) ? (int) $params['id'] : null);

        return response(['data' => ['id' => $id]]);
    }

    public function dropRule(Request $request)
    {
        $params = $request->validate([
            'id' => 'required|integer|min:1'
        ]);

        return response([
            'data' => (new DynamicRateService())->deleteRule((int) $params['id'])
        ]);
    }

    public function saveSettings(Request $request)
    {
        $params = $request->validate([
            'enabled' => 'required|in:0,1',
            'instant_mbps' => 'required|numeric|min:0|max:1000000',
            'sustained_mbps' => 'required|numeric|min:0|max:1000000',
            'burst_exempt_minutes' => 'required|integer|between:0,1440',
            'stack_minutes' => 'required|integer|between:1,1440',
            'stack_multiplier' => 'required|numeric|min:1|max:999.999',
            'decay_step' => 'required|integer|between:1,1440'
        ]);

        // 持续阈值高于瞬时阈值时，任何超过瞬时阈值的流量都同时超过持续阈值，
        // 突发豁免会把叠加彻底架空 —— 这不是「配置得奇怪」，是配错了。
        if ((float) $params['sustained_mbps'] > (float) $params['instant_mbps']) {
            abort(422, __('持续阈值不能高于瞬时阈值，否则突发豁免会让叠加永远不生效'));
        }

        return response([
            'data' => (new DynamicRateService())->saveSettings($params)
        ]);
    }

    /**
     * 「为什么他这一分钟按 1.5 倍计费」——页面上的「查看倍率构成」用。
     */
    public function explain(Request $request)
    {
        $params = $request->validate([
            'user_id' => 'required|integer|min:1'
        ]);

        return response([
            'data' => (new DynamicRateService())->explain((int) $params['user_id'])
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function nodeOptions(): array
    {
        $nodes = [];
        foreach (array_keys(ServerIdService::TYPES) as $type) {
            $table = 'v2_server_' . $type;
            if (!Schema::hasTable($table)) {
                continue;
            }
            foreach (DB::table($table)->orderBy('sort')->orderBy('id')->limit(self::RULE_LIMIT)->get(['id', 'name']) as $row) {
                $nodes[] = [
                    'type' => $type,
                    'id' => (int) $row->id,
                    'name' => (string) $row->name
                ];
            }
        }

        return $nodes;
    }
}
