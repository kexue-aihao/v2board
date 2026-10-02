<?php

namespace App\Http\Controllers\V1\Admin\Server;

use App\Http\Controllers\Controller;
use App\Services\ServerBatchOperationService;
use App\Services\ServerService;
use App\Services\ServerHostReplacementService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ManageController extends Controller
{
    /** 可被批量操作选中的节点类型，与 ServerBatchOperationService::MODELS 一一对应。 */
    private const SELECTABLE_TYPES = 'shadowsocks,vmess,vless,trojan,tuic,hysteria,anytls,v2node';

    public function getNodes(Request $request)
    {
        $serverService = new ServerService();
        return response([
            'data' => $serverService->getAllServers()
        ])->header('Cache-Control', 'no-store, private');
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

    /**
     * 批量复制选中的节点。副本一律隐藏，可选为副本重新生成 REALITY 密钥。
     */
    public function copyNodes(Request $request)
    {
        $params = $this->validateSelection($request, true, [
            'regenerate_reality_keys' => 'nullable|boolean',
        ]);
        return response([
            'data' => (new ServerBatchOperationService())->copyNodes(
                $params['nodes'],
                (bool) ($params['regenerate_reality_keys'] ?? false)
            )
        ]);
    }

    public function previewTlsFields(Request $request)
    {
        $params = $this->validateTlsFields($request, false);
        return response([
            'data' => (new ServerBatchOperationService())->previewTlsFields(
                $params['nodes'],
                $params['server_name'] ?? null,
                $params['dest'] ?? null
            )
        ]);
    }

    public function applyTlsFields(Request $request)
    {
        $params = $this->validateTlsFields($request, true);
        return response([
            'data' => (new ServerBatchOperationService())->applyTlsFields(
                $params['nodes'],
                $params['server_name'] ?? null,
                $params['dest'] ?? null
            )
        ]);
    }

    /**
     * 批量删除选中的节点。
     *
     * 不级联删除子节点 —— 单个删除本来就不级联，批量操作更不该悄悄扩大删除范围。
     * 每个节点下还挂着几个子节点随结果返回，前端在确认框里提示。
     */
    public function deleteNodes(Request $request)
    {
        $params = $this->validateSelection($request, true);
        return response([
            'data' => (new ServerBatchOperationService())->deleteNodes($params['nodes'])
        ]);
    }

    public function previewProtocolSettings(Request $request)
    {
        $params = $this->validateProtocolSettings($request, false);
        return response([
            'data' => (new ServerBatchOperationService())->previewProtocolSettings(
                $params['nodes'],
                $params['network'] ?? null,
                $params['network_settings'] ?? null
            )
        ]);
    }

    public function applyProtocolSettings(Request $request)
    {
        $params = $this->validateProtocolSettings($request, true);
        return response([
            'data' => (new ServerBatchOperationService())->applyProtocolSettings(
                $params['nodes'],
                $params['network'] ?? null,
                $params['network_settings'] ?? null
            )
        ]);
    }

    /**
     * 传输协议与协议配置都可以缺省，缺省表示「这一项不动」（与批量填写 SNI 同一套语义）；
     * 两项都缺省没有意义，直接拒掉。
     */
    private function validateProtocolSettings(Request $request, bool $requireConfirmation): array
    {
        // 空串按「不动」处理；协议配置允许直接发 JSON 文本（编辑节点的表单是解析成对象
        // 再发的，两种都收），只有 {} 才表示「明确清空」。
        $request->merge(['network' => $this->blankToNull($request->input('network'))]);
        $this->normalizeNetworkSettings($request);

        $params = $this->validateSelection($request, $requireConfirmation, [
            'network' => 'nullable|in:' . implode(',', ServerBatchOperationService::NETWORKS),
            'network_settings' => 'nullable|array',
        ]);

        if (($params['network'] ?? null) === null && ($params['network_settings'] ?? null) === null) {
            abort(422, __('请至少选择传输协议或填写协议配置中的一项'));
        }

        return $params;
    }

    private function normalizeNetworkSettings(Request $request): void
    {
        $value = $request->input('network_settings');
        if (!is_string($value)) {
            return;
        }
        $value = trim($value);
        if ($value === '') {
            $request->merge(['network_settings' => null]);
            return;
        }
        $decoded = json_decode($value, true);
        if (!is_array($decoded)) {
            abort(422, __('协议配置不是合法的 JSON 对象'));
        }
        $request->merge(['network_settings' => $decoded]);
    }

    /**
     * @param array<string, string> $extraRules
     */
    private function validateSelection(Request $request, bool $requireConfirmation, array $extraRules = []): array
    {
        $rules = [
            'nodes' => 'required|array|min:1|max:' . ServerBatchOperationService::MAX_SELECTION,
            'nodes.*.type' => 'required|in:' . self::SELECTABLE_TYPES,
            'nodes.*.id' => 'required|integer|min:1',
        ] + $extraRules;
        if ($requireConfirmation) {
            $rules['confirm'] = 'required|accepted';
        }

        return $request->validate($rules);
    }

    private function validateTlsFields(Request $request, bool $requireConfirmation): array
    {
        // 空串要当成「这一项不动」而不是「写一个空值」，所以先把空白统一收敛成 null
        // 再交给 nullable 规则，否则空串会撞上下面的 no-whitespace 正则。
        $request->merge([
            'server_name' => $this->blankToNull($request->input('server_name')),
            'dest' => $this->blankToNull($request->input('dest')),
        ]);

        $params = $this->validateSelection($request, $requireConfirmation, [
            'server_name' => 'nullable|string|max:255|regex:/^[^\s]+$/u',
            'dest' => 'nullable|string|max:255|regex:/^[^\s]+$/u',
        ]);

        if (($params['server_name'] ?? null) === null && ($params['dest'] ?? null) === null) {
            abort(422, __('请至少填写 Server Name(SNI) 或 Server Address 中的一项'));
        }

        return $params;
    }

    private function blankToNull($value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
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
