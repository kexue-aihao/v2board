<?php

namespace App\Http\Controllers\V1\Admin\Server;

use App\Http\Controllers\Controller;
use App\Models\ServerAnytls;
use App\Services\ServerIdService;
use App\Services\TelegramAdminOperationService;
use Illuminate\Http\Request;

class AnyTLSController extends Controller
{
    public function save(Request $request)
    {
        $params = $request->validate([
            'node_id' => 'nullable|integer|min:1',
            'show' => '',
            'name' => 'required',
            'group_id' => 'required|array',
            'route_id' => 'nullable|array',
            'parent_id' => 'nullable|integer',
            'host' => 'required',
            'port' => 'required',
            'server_port' => 'required',
            'tags' => 'nullable|array',
            'rate' => 'required|numeric',
            'server_name' => 'nullable',
            'insecure' => 'required|in:0,1',
            'pinned_peer_cert_sha256' => 'nullable|string|max:128',
            'padding_scheme' => 'nullable',
        ]);

        if (isset($params['padding_scheme'])) {
            $params['padding_scheme'] = json_decode($params['padding_scheme']);
        }

        $nodeId = isset($params['node_id']) ? (int)$params['node_id'] : null;
        unset($params['node_id']);

        if ($request->input('id')) {
            $server = ServerAnytls::find($request->input('id'));
            if (!$server) {
                abort(500, __('服务器不存在'));
            }
            if ($nodeId !== null && $nodeId !== (int)$server->id) {
                ServerIdService::assertIdAvailable('anytls', $nodeId);
            }
            $previousShow = (int)$server->show;
            try {
                $saved = $server->update($params);
                if ($nodeId !== null && $nodeId !== (int)$server->id) {
                    ServerIdService::changeId('anytls', $server, $nodeId);
                }
            } catch (\Exception $e) {
                abort(500, __('保存失败'));
            }
            if ($saved) {
                TelegramAdminOperationService::nodeVisibilityChanged($server, 'anytls', $previousShow);
            }
            return response([
                'data' => true
            ]);
        }

        // 新增：填了 node_id 就用指定 ID 落库，留空走自增。
        $server = $nodeId !== null
            ? ServerIdService::createWithId('anytls', $params, $nodeId)
            : ServerAnytls::create($params);
        if (!$server) {
            abort(500, __('创建失败'));
        }
        TelegramAdminOperationService::nodeCreated($server, 'anytls');

        return response([
            'data' => true
        ]);
    }

    public function drop(Request $request)
    {
        if ($request->input('id')) {
            $server = ServerAnytls::find($request->input('id'));
            if (!$server) {
                abort(500, __('节点ID不存在'));
            }
        }
        $wasPublished = (int)$server->show === 1;
        $deleted = $server->delete();
        if ($deleted && $wasPublished) {
            TelegramAdminOperationService::nodeDeleted($server, 'anytls');
        }
        return response(['data' => $deleted]);
    }

    public function update(Request $request)
    {
        $request->validate([
            'show' => 'in:0,1'
        ], [
            'show.in' => '显示状态格式不正确'
        ]);
        $params = $request->only([
            'show',
        ]);

        $server = ServerAnytls::find($request->input('id'));

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
            TelegramAdminOperationService::nodeVisibilityChanged($server, 'anytls', $previousShow);
        }

        return response([
            'data' => true
        ]);
    }

    public function copy(Request $request)
    {
        $server = ServerAnytls::find($request->input('id'));
        $server->show = 0;
        if (!$server) {
            abort(500, __('服务器不存在'));
        }
        if (!ServerAnytls::create($server->toArray())) {
            abort(500, __('复制失败'));
        }

        return response([
            'data' => true
        ]);
    }
}
