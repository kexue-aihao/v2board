<?php

namespace App\Http\Controllers\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\ExternalSubscriptionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 管理页「外部订阅源」的接口。
 *
 * 刷新刻意做成**一次一个源**：抓取是同步的、每个源最长 10 秒（Guzzle 超时），
 * 一次刷五个就可能顶到 PHP 的执行时限。页面上的「全部刷新」是前端逐个串行调用，
 * 每个源的结果当场显示，「卡住的是哪一个」一眼就能看到。
 */
class ExternalSourceController extends Controller
{
    public function fetch(Request $request)
    {
        $service = new ExternalSubscriptionService();
        $sourceId = (int) $request->input('source_id', 0);

        return response([
            'data' => [
                'sources' => $service->sources(),
                // 归属权限组用现成的节点分组，复用既有分发机制
                'groups' => $this->groups(),
                // 一次带一个源的节点预览，省得页面再发一次请求
                'nodes' => $sourceId > 0 ? $service->nodesForSource($sourceId) : []
            ]
        ]);
    }

    public function saveSource(Request $request)
    {
        $params = $request->validate([
            'id' => 'nullable|integer|min:1',
            'name' => 'nullable|string|max:64',
            'url' => 'required|string|max:512',
            'group_id' => 'required|integer|min:1',
            'enabled' => 'nullable|in:0,1',
            'remark' => 'nullable|string|max:255'
        ]);

        // 权限组必须是真实存在的分组，否则节点永远不会被下发（排查起来很费劲）
        if (!DB::table('v2_server_group')->where('id', (int) $params['group_id'])->exists()) {
            abort(422, __('选择的权限组不存在'));
        }

        $id = (new ExternalSubscriptionService())->saveSource(
            $params,
            isset($params['id']) ? (int) $params['id'] : null
        );

        return response(['data' => ['id' => $id]]);
    }

    public function dropSource(Request $request)
    {
        $params = $request->validate([
            'id' => 'required|integer|min:1'
        ]);

        return response([
            'data' => (new ExternalSubscriptionService())->deleteSource((int) $params['id'])
        ]);
    }

    /**
     * 抓取一个源。失败不删已有节点，只把错误写进源的 last_error —— 页面据此显示状态。
     */
    public function refreshSource(Request $request)
    {
        $params = $request->validate([
            'id' => 'required|integer|min:1',
            'dry_run' => 'nullable|in:0,1'
        ]);

        return response([
            'data' => (new ExternalSubscriptionService())->refresh(
                (int) $params['id'],
                (int) ($params['dry_run'] ?? 0) === 1
            )
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function groups(): array
    {
        if (!Schema::hasTable('v2_server_group')) {
            return [];
        }

        return array_map(function ($row) {
            return ['id' => (int) $row->id, 'name' => (string) $row->name];
        }, DB::table('v2_server_group')->orderBy('id')->get(['id', 'name'])->all());
    }
}
