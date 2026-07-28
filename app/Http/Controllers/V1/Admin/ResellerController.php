<?php

namespace App\Http\Controllers\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\ResellerAccount;
use App\Models\ResellerOrder;
use App\Models\ResellerPlanTemplate;
use App\Services\ResellerPaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;

class ResellerController extends Controller
{
    public function fetch()
    {
        $accounts = ResellerAccount::withCount(['customers', 'orders'])
            ->orderByDesc('id')->paginate(50);
        $accounts->getCollection()->each(function ($account) {
            $account->makeHidden(['password']);
        });
        return response(['data' => $accounts]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'id' => 'required|integer',
            'status' => 'required|in:pending,active,suspended',
            'store_slug' => ['nullable', 'regex:/^[a-z0-9][a-z0-9-]{2,31}$/'],
            'store_name' => 'nullable|string|max:128',
            'store_description' => 'nullable|string|max:10000',
        ]);
        $account = ResellerAccount::findOrFail($data['id']);
        if (isset($data['store_slug']) && ResellerAccount::where('store_slug', $data['store_slug'])->where('id', '!=', $account->id)->exists()) {
            abort(422, 'Store slug already exists');
        }
        $account->fill($data);
        $account->save();
        return response(['data' => $account->makeHidden(['password'])]);
    }

    public function templates()
    {
        $templates = ResellerPlanTemplate::with('plan')->orderBy('sort')->orderBy('id')->get();
        return response(['data' => $templates->map(function ($template) {
            return [
                'id' => (int)$template->id,
                'base_plan_id' => (int)$template->base_plan_id,
                'enabled' => (int)$template->enabled,
                'sort' => (int)$template->sort,
                'plan' => $template->plan ? ['id' => $template->plan->id, 'name' => $template->plan->name] : null,
            ];
        })->values()]);
    }

    public function saveTemplate(Request $request)
    {
        $data = $request->validate([
            'id' => 'nullable|integer',
            'base_plan_id' => 'required|integer',
            'enabled' => 'required|boolean',
            'sort' => 'nullable|integer',
        ]);
        if (!Plan::where('id', $data['base_plan_id'])->exists()) abort(422, 'Base plan does not exist');
        $template = !empty($data['id'])
            ? ResellerPlanTemplate::findOrFail($data['id'])
            : ResellerPlanTemplate::firstOrNew(['base_plan_id' => $data['base_plan_id']]);
        $template->base_plan_id = $data['base_plan_id'];
        $template->enabled = (int)$data['enabled'];
        $template->sort = (int)($data['sort'] ?? 0);
        $template->save();
        return response(['data' => $template->fresh('plan')]);
    }

    public function paymentDrivers()
    {
        return response(['data' => [
            'installed' => ResellerPaymentService::drivers(),
            'allowed' => array_values((array)config('v2board.reseller_allowed_payment_drivers', [])),
        ]]);
    }

    public function savePaymentDrivers(Request $request)
    {
        $data = $request->validate(['allowed' => 'nullable|array']);
        $installed = ResellerPaymentService::drivers();
        $allowed = array_values(array_unique(array_intersect($installed, (array)($data['allowed'] ?? []))));
        $config = config('v2board');
        $config['reseller_allowed_payment_drivers'] = $allowed;
        if (!File::put(base_path('config/v2board.php'), "<?php\n return " . var_export($config, true) . " ;")) {
            abort(500, 'Failed to save reseller payment drivers');
        }
        Artisan::call('config:cache');
        if (Cache::has('WEBMANPID')) {
            $pid = Cache::get('WEBMANPID');
            Cache::forget('WEBMANPID');
            $restarting = function_exists('posix_kill') ? (bool)posix_kill($pid, 15) : false;
            return response(['data' => ['allowed' => $allowed, 'restarting' => $restarting]]);
        }
        return response(['data' => ['allowed' => $allowed]]);
    }

    public function orders()
    {
        return response(['data' => ResellerOrder::with('reseller')->orderByDesc('id')->paginate(100)]);
    }
}
