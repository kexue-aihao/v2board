<?php

namespace App\Http\Controllers\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\ResellerAccount;
use App\Models\ResellerOrder;
use App\Models\ResellerPlanTemplate;
use App\Models\ResellerReviewLog;
use App\Services\ResellerPaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class ResellerController extends Controller
{
    public function summary()
    {
        return response(['data' => [
            'pending_resellers' => ResellerAccount::where(function ($query) {
                $query->where('reseller_status', 'pending')->orWhere(function ($fallback) {
                    $fallback->whereNull('reseller_status')->where('status', 'pending');
                });
            })->count(),
            'pending_stores' => ResellerAccount::where(function ($query) {
                $query->where('store_status', 'pending')->orWhere(function ($fallback) {
                    $fallback->whereNull('store_status')->where('status', 'pending');
                });
            })->count(),
            'active_stores' => ResellerAccount::where(function ($query) {
                $query->where('reseller_status', 'active')->orWhere(function ($fallback) {
                    $fallback->whereNull('reseller_status')->where('status', 'active');
                });
            })->where(function ($query) {
                $query->where('store_status', 'active')->orWhere(function ($fallback) {
                    $fallback->whereNull('store_status')->where('status', 'active');
                });
            })->count(),
            'suspended_resellers' => ResellerAccount::where(function ($query) {
                $query->where('reseller_status', 'suspended')->orWhere(function ($fallback) {
                    $fallback->whereNull('reseller_status')->where('status', 'suspended');
                });
            })->count(),
        ]]);
    }

    public function accounts(Request $request)
    {
        $query = ResellerAccount::withCount(['customers', 'orders'])
            ->orderByDesc('id');

        $this->applyAccountFilters($query, $request, 'reseller_status');

        $accounts = $query->paginate($this->pageSize($request), ['*'], 'page', $this->currentPage($request));
        $accounts->getCollection()->transform(function (ResellerAccount $account) {
            return $this->accountData($account);
        });

        return response(['data' => $accounts]);
    }

    public function stores(Request $request)
    {
        $query = ResellerAccount::withCount(['plans', 'payments'])
            ->orderByDesc('id');

        $this->applyAccountFilters($query, $request, 'store_status');

        $stores = $query->paginate($this->pageSize($request), ['*'], 'page', $this->currentPage($request));
        $stores->getCollection()->transform(function (ResellerAccount $account) {
            return $this->accountData($account) + [
                'plan_count' => (int)$account->plans_count,
                'payment_count' => (int)$account->payments_count,
            ];
        });

        return response(['data' => $stores]);
    }

    public function review(Request $request)
    {
        $data = $request->validate([
            'id' => 'required|integer',
            'target' => 'required|in:account,store',
            'status' => 'required|in:pending,active,rejected,suspended',
            'reason' => 'nullable|string|max:500',
        ]);
        if (in_array($data['status'], ['rejected', 'suspended'], true) && trim((string)($data['reason'] ?? '')) === '') {
            abort(422, 'Review reason is required');
        }

        $account = DB::transaction(function () use ($data, $request) {
            $account = ResellerAccount::where('id', $data['id'])->lockForUpdate()->first();
            if (!$account) abort(404, 'Reseller account does not exist');

            $field = $data['target'] === 'account' ? 'reseller_status' : 'store_status';
            $reasonField = $data['target'] === 'account' ? 'reseller_review_reason' : 'store_review_reason';
            $reviewerField = $data['target'] === 'account' ? 'reseller_reviewed_by' : 'store_reviewed_by';
            $reviewedAtField = $data['target'] === 'account' ? 'reseller_reviewed_at' : 'store_reviewed_at';
            $fromStatus = $account->{$field} ?: $account->status;

            $account->{$field} = $data['status'];
            $account->{$reasonField} = trim((string)($data['reason'] ?? '')) ?: null;
            $account->{$reviewerField} = (int)$request->user['id'];
            $account->{$reviewedAtField} = time();
            $account->status = $account->accountStatus();
            $account->save();

            ResellerReviewLog::create([
                'reseller_id' => $account->id,
                'target_type' => $data['target'],
                'from_status' => $fromStatus,
                'to_status' => $data['status'],
                'reason' => $account->{$reasonField},
                'operator_id' => (int)$request->user['id'],
                'created_at' => time(),
            ]);

            return $account->fresh();
        });

        return response(['data' => $this->accountData($account)]);
    }

    public function reviewLogs(Request $request)
    {
        $query = ResellerReviewLog::with('reseller:id,email,store_name,store_slug')
            ->orderByDesc('id');
        if ($request->input('reseller_id')) {
            $query->where('reseller_id', (int)$request->input('reseller_id'));
        }
        if ($request->input('target')) {
            $query->where('target_type', $request->input('target'));
        }

        return response(['data' => $query->paginate(
            $this->pageSize($request), ['*'], 'page', $this->currentPage($request)
        )]);
    }

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
        $account = DB::transaction(function () use ($data, $request) {
            $account = ResellerAccount::where('id', $data['id'])->lockForUpdate()->firstOrFail();
            if (isset($data['store_slug']) && ResellerAccount::where('store_slug', $data['store_slug'])->where('id', '!=', $account->id)->exists()) {
                abort(422, 'Store slug already exists');
            }

            $fromAccountStatus = $account->accountStatus();
            $fromStoreStatus = $account->storeStatus();
            $account->fill($data);
            $account->reseller_status = $data['status'];
            $account->store_status = $data['status'];
            $account->status = $data['status'];
            $account->reseller_reviewed_by = (int)$request->user['id'];
            $account->reseller_reviewed_at = time();
            $account->store_reviewed_by = (int)$request->user['id'];
            $account->store_reviewed_at = time();
            $account->save();

            foreach ([
                ['account', $fromAccountStatus],
                ['store', $fromStoreStatus],
            ] as [$target, $fromStatus]) {
                ResellerReviewLog::create([
                    'reseller_id' => $account->id,
                    'target_type' => $target,
                    'from_status' => $fromStatus,
                    'to_status' => $data['status'],
                    'operator_id' => (int)$request->user['id'],
                    'created_at' => time(),
                ]);
            }

            return $account;
        });
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

    public function orders(Request $request)
    {
        $query = ResellerOrder::with([
            'reseller:id,email,store_name,store_slug',
            'platformOrder:id,trade_no,status',
        ])->orderByDesc('id');
        $status = $request->input('status');
        if ($status !== null && $status !== '') {
            $query->whereHas('platformOrder', function ($orderQuery) use ($status) {
                $orderQuery->where('status', (int)$status);
            });
        }
        $keyword = trim((string)$request->input('keyword', ''));
        if ($keyword !== '') {
            $query->where(function ($orderQuery) use ($keyword) {
                $orderQuery->where('user_id', (int)$keyword)
                    ->orWhereHas('platformOrder', function ($platformQuery) use ($keyword) {
                        $platformQuery->where('trade_no', 'like', '%' . $keyword . '%');
                    })
                    ->orWhereHas('reseller', function ($resellerQuery) use ($keyword) {
                        $resellerQuery->where('email', 'like', '%' . $keyword . '%')
                            ->orWhere('store_name', 'like', '%' . $keyword . '%');
                    });
            });
        }

        $orders = $query->paginate($this->pageSize($request), ['*'], 'page', $this->currentPage($request));
        $orders->getCollection()->transform(function (ResellerOrder $order) {
            return [
                'trade_no' => optional($order->platformOrder)->trade_no,
                'status' => optional($order->platformOrder)->status,
                'amount' => (int)$order->amount_snapshot,
                'period' => $order->period,
                'user_id' => (int)$order->user_id,
                'reseller_email' => optional($order->reseller)->email,
                'store_name' => optional($order->reseller)->store_name,
                'store_slug' => optional($order->reseller)->store_slug,
                'created_at' => $order->created_at,
            ];
        });

        return response(['data' => $orders]);
    }

    private function applyAccountFilters($query, Request $request, string $statusField): void
    {
        $status = (string)$request->input('status', '');
        if ($status !== '') {
            $query->where(function ($statusQuery) use ($status, $statusField) {
                $statusQuery->where($statusField, $status)->orWhere(function ($fallback) use ($status) {
                    $fallback->whereNull('reseller_status')->whereNull('store_status')->where('status', $status);
                });
            });
        }

        $keyword = trim((string)$request->input('keyword', ''));
        if ($keyword !== '') {
            $query->where(function ($keywordQuery) use ($keyword) {
                $keywordQuery->where('email', 'like', '%' . $keyword . '%')
                    ->orWhere('store_name', 'like', '%' . $keyword . '%')
                    ->orWhere('store_slug', 'like', '%' . $keyword . '%');
            });
        }
    }

    private function accountData(ResellerAccount $account): array
    {
        return [
            'id' => (int)$account->id,
            'email' => $account->email,
            'store_slug' => $account->store_slug,
            'store_name' => $account->store_name,
            'store_description' => $account->store_description,
            'status' => $account->status,
            'reseller_status' => $account->accountStatus(),
            'store_status' => $account->storeStatus(),
            'reseller_review_reason' => $account->reseller_review_reason,
            'store_review_reason' => $account->store_review_reason,
            'reseller_reviewed_by' => $account->reseller_reviewed_by,
            'reseller_reviewed_at' => $account->reseller_reviewed_at,
            'store_reviewed_by' => $account->store_reviewed_by,
            'store_reviewed_at' => $account->store_reviewed_at,
            'customers_count' => (int)($account->customers_count ?? 0),
            'orders_count' => (int)($account->orders_count ?? 0),
            'created_at' => $account->created_at,
        ];
    }

    private function currentPage(Request $request): int
    {
        return max(1, (int)$request->input('current', $request->input('page', 1)));
    }

    private function pageSize(Request $request): int
    {
        return min(100, max(10, (int)$request->input('pageSize', 20)));
    }
}
