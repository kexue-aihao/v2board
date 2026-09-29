<?php

namespace App\Http\Controllers\V1\Admin\Server;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ServerShadowsocksSave;
use App\Http\Requests\Admin\ServerShadowsocksUpdate;
use App\Models\ServerShadowsocks;
use App\Services\ServerIdService;
use App\Services\TelegramAdminOperationService;
use Illuminate\Http\Request;

class ShadowsocksController extends Controller
{
    public function save(ServerShadowsocksSave $request)
    {
        $params = $request->validated();
        $nodeId = isset($params['node_id']) ? (int)$params['node_id'] : null;
        unset($params['node_id']);

        if ($request->input('id')) {
            $server = ServerShadowsocks::find($request->input('id'));
            if (!$server) {
                abort(500, __('服务器不存在'));
            }
            if ($nodeId !== null && $nodeId !== (int)$server->id) {
                ServerIdService::assertIdAvailable('shadowsocks', $nodeId);
            }
            $previousShow = (int)$server->show;
            try {
                $saved = $server->update($params);
                if ($nodeId !== null && $nodeId !== (int)$server->id) {
                    ServerIdService::changeId('shadowsocks', $server, $nodeId);
                }
            } catch (\Exception $e) {
                abort(500, __('保存失败'));
            }
            if ($saved) {
                TelegramAdminOperationService::nodeVisibilityChanged($server, 'shadowsocks', $previousShow);
            }
            return response([
                'data' => true
            ]);
        }

        // 新增：填了 node_id 就用指定 ID 落库，留空走自增。
        $server = $nodeId !== null
            ? ServerIdService::createWithId('shadowsocks', $params, $nodeId)
            : ServerShadowsocks::create($params);
        if (!$server) {
            abort(500, __('创建失败'));
        }
        TelegramAdminOperationService::nodeCreated($server, 'shadowsocks');

        return response([
            'data' => true
        ]);
    }

    public function drop(Request $request)
    {
        if ($request->input('id')) {
            $server = ServerShadowsocks::find($request->input('id'));
            if (!$server) {
                abort(500, __('节点ID不存在'));
            }
        }
        $wasPublished = (int)$server->show === 1;
        $deleted = $server->delete();
        if ($deleted && $wasPublished) {
            TelegramAdminOperationService::nodeDeleted($server, 'shadowsocks');
        }
        return response(['data' => $deleted]);
    }

    public function update(ServerShadowsocksUpdate $request)
    {
        $params = $request->only([
            'show',
        ]);

        $server = ServerShadowsocks::find($request->input('id'));

        if (!$server) {
            abort(500, __('该服务器不存在'));
        }
        $previousShow = (int)$server->show;
        try {
            $saved = $server->update($params);
        } catch (\Exception $e) {
            abort(500, __('保存失败'));
        }
        if ($saved) {
            TelegramAdminOperationService::nodeVisibilityChanged($server, 'shadowsocks', $previousShow);
        }

        return response([
            'data' => true
        ]);
    }

    public function copy(Request $request)
    {
        $server = ServerShadowsocks::find($request->input('id'));
        $server->show = 0;
        if (!$server) {
            abort(500, __('服务器不存在'));
        }
        if (!ServerShadowsocks::create($server->toArray())) {
            abort(500, __('复制失败'));
        }

        return response([
            'data' => true
        ]);
    }
}
