<?php

namespace App\Http\Controllers\V1\Admin\Server;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ServerVmessSave;
use App\Http\Requests\Admin\ServerVmessUpdate;
use App\Models\ServerVmess;
use App\Services\ServerIdService;
use App\Services\TelegramAdminOperationService;
use Illuminate\Http\Request;

class VmessController extends Controller
{
    public function save(ServerVmessSave $request)
    {
        $params = $request->validated();

        $nodeId = isset($params['node_id']) ? (int)$params['node_id'] : null;
        unset($params['node_id']);

        if ($request->input('id')) {
            $server = ServerVmess::find($request->input('id'));
            if (!$server) {
                abort(500, __('服务器不存在'));
            }
            if ($nodeId !== null && $nodeId !== (int)$server->id) {
                ServerIdService::assertIdAvailable('vmess', $nodeId);
            }
            $previousShow = (int)$server->show;
            try {
                $saved = $server->update($params);
                if ($nodeId !== null && $nodeId !== (int)$server->id) {
                    ServerIdService::changeId('vmess', $server, $nodeId);
                }
            } catch (\Exception $e) {
                abort(500, __('保存失败'));
            }
            if ($saved) {
                TelegramAdminOperationService::nodeVisibilityChanged($server, 'vmess', $previousShow);
            }
            return response([
                'data' => true
            ]);
        }

        // 新增：填了 node_id 就用指定 ID 落库，留空走自增。
        $server = $nodeId !== null
            ? ServerIdService::createWithId('vmess', $params, $nodeId)
            : ServerVmess::create($params);
        if (!$server) {
            abort(500, __('创建失败'));
        }
        TelegramAdminOperationService::nodeCreated($server, 'vmess');

        return response([
            'data' => true
        ]);
    }

    public function drop(Request $request)
    {
        if ($request->input('id')) {
            $server = ServerVmess::find($request->input('id'));
            if (!$server) {
                abort(500, __('节点ID不存在'));
            }
        }
        $wasPublished = (int)$server->show === 1;
        $deleted = $server->delete();
        if ($deleted && $wasPublished) {
            TelegramAdminOperationService::nodeDeleted($server, 'vmess');
        }
        return response(['data' => $deleted]);
    }

    public function update(ServerVmessUpdate $request)
    {
        $params = $request->only([
            'show',
        ]);

        $server = ServerVmess::find($request->input('id'));

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
            TelegramAdminOperationService::nodeVisibilityChanged($server, 'vmess', $previousShow);
        }

        return response([
            'data' => true
        ]);
    }

    public function copy(Request $request)
    {
        $server = ServerVmess::find($request->input('id'));
        $server->show = 0;
        if (!$server) {
            abort(500, __('服务器不存在'));
        }
        if (!ServerVmess::create($server->toArray())) {
            abort(500, __('复制失败'));
        }

        return response([
            'data' => true
        ]);
    }
}
