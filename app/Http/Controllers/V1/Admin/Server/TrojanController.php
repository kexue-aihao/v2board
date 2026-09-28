<?php

namespace App\Http\Controllers\V1\Admin\Server;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ServerTrojanSave;
use App\Http\Requests\Admin\ServerTrojanUpdate;
use App\Models\ServerTrojan;
use App\Services\ServerIdService;
use App\Services\ServerService;
use Illuminate\Http\Request;

class TrojanController extends Controller
{
    public function save(ServerTrojanSave $request)
    {
        $params = $request->validated();
        $nodeId = isset($params['node_id']) ? (int)$params['node_id'] : null;
        unset($params['node_id']);

        if ($request->input('id')) {
            $server = ServerTrojan::find($request->input('id'));
            if (!$server) {
                abort(500, __('服务器不存在'));
            }
            if ($nodeId !== null && $nodeId !== (int)$server->id) {
                ServerIdService::assertIdAvailable('trojan', $nodeId);
            }
            try {
                $server->update($params);
                if ($nodeId !== null && $nodeId !== (int)$server->id) {
                    ServerIdService::changeId('trojan', $server, $nodeId);
                }
            } catch (\Exception $e) {
                abort(500, __('保存失败'));
            }
            return response([
                'data' => true
            ]);
        }

        // 新增：填了 node_id 就用指定 ID 落库，留空走自增。
        $server = $nodeId !== null
            ? ServerIdService::createWithId('trojan', $params, $nodeId)
            : ServerTrojan::create($params);
        if (!$server) {
            abort(500, __('创建失败'));
        }

        return response([
            'data' => true
        ]);
    }

    public function drop(Request $request)
    {
        if ($request->input('id')) {
            $server = ServerTrojan::find($request->input('id'));
            if (!$server) {
                abort(500, __('节点ID不存在'));
            }
        }
        return response([
            'data' => $server->delete()
        ]);
    }

    public function update(ServerTrojanUpdate $request)
    {
        $params = $request->only([
            'show',
        ]);

        $server = ServerTrojan::find($request->input('id'));

        if (!$server) {
            abort(500, __('该服务器不存在'));
        }
        try {
            $server->update($params);
        } catch (\Exception $e) {
            abort(500, __('保存失败'));
        }

        return response([
            'data' => true
        ]);
    }

    public function copy(Request $request)
    {
        $server = ServerTrojan::find($request->input('id'));
        $server->show = 0;
        if (!$server) {
            abort(500, __('服务器不存在'));
        }
        if (!ServerTrojan::create($server->toArray())) {
            abort(500, __('复制失败'));
        }

        return response([
            'data' => true
        ]);
    }
}
