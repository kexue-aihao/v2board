<?php

namespace App\Http\Controllers\V1\Admin\Server;

use App\Http\Controllers\Controller;
use App\Services\ServerService;
use App\Services\ServerHostReplacementService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ManageController extends Controller
{
    public function getNodes(Request $request)
    {
        $serverService = new ServerService();
        return response([
            'data' => $serverService->getAllServers()
        ]);
    }

    public function sort(Request $request)
    {
        ini_set('post_max_size', '5m');
        $params = $request->only(
            'shadowsocks',
            'vmess',
            'vless',
            'trojan',
            'tuic',
            'hysteria',
            'anytls',
            'v2node'
        ) ?? [];
        if (empty($params)) {
            $params = [
                'shadowsocks' => $_POST['shadowsocks'] ?? null,
                'vmess'       => $_POST['vmess'] ?? null,
                'vless'       => $_POST['vless'] ?? null,
                'trojan'      => $_POST['trojan'] ?? null,
                'tuic'        => $_POST['tuic'] ?? null,
                'hysteria'    => $_POST['hysteria'] ?? null,
                'anytls'      => $_POST['anytls'] ?? null,
                'v2node'      => $_POST['v2node'] ?? null,
            ];
        }
        DB::beginTransaction();
        foreach ($params as $k => $v) {
            $model = 'App\\Models\\Server' . ucfirst($k);
            foreach($v as $id => $sort) {
                if (!$model::find($id)->update(['sort' => $sort])) {
                    DB::rollBack();
                    abort(500, __('保存失败'));
                }
            }
        }
        DB::commit();
        return response([
            'data' => true
        ]);
    }

    public function previewHostReplacement(Request $request)
    {
        $params = $this->validateHostReplacement($request, false);
        return response([
            'data' => (new ServerHostReplacementService())->preview(
                $params['mode'],
                $params['old_host'],
                $params['new_host']
            )
        ]);
    }

    public function replaceHost(Request $request)
    {
        $params = $this->validateHostReplacement($request, true);
        return response([
            'data' => (new ServerHostReplacementService())->replace(
                $params['mode'],
                $params['old_host'],
                $params['new_host']
            )
        ]);
    }

    private function validateHostReplacement(Request $request, bool $requireConfirmation): array
    {
        $rules = [
            'mode' => 'required|in:exact,contains',
            'old_host' => ['required', 'string', 'max:255', 'regex:/^[^\s]+$/u'],
            'new_host' => ['required', 'string', 'max:255', 'regex:/^[^\s]+$/u'],
        ];
        if ($requireConfirmation) {
            $rules['confirm'] = 'required|accepted';
        }

        $params = $request->validate($rules);
        $params['old_host'] = trim($params['old_host']);
        $params['new_host'] = trim($params['new_host']);
        if ($params['old_host'] === $params['new_host']) {
            abort(422, __('新旧节点域名不能相同'));
        }
        return $params;
    }
}
