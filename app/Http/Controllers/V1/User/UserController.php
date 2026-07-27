<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\UserChangePassword;
use App\Http\Requests\User\UserRedeemGiftCard;
use App\Http\Requests\User\UserTransfer;
use App\Http\Requests\User\UserUpdate;
use App\Models\Giftcard;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Ticket;
use App\Models\User;
use App\Services\AuthService;
use App\Services\OrderService;
use App\Services\PasswordPolicyService;
use App\Services\UserService;
use App\Services\SubscriptionService;
use App\Utils\CacheKey;
use App\Utils\Helper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class UserController extends Controller
{
    public function getActiveSession(Request $request)
    {
        $user = User::find($request->user['id']);
        if (!$user) {
            abort(500, __('The user does not exist'));
        }
        $authService = new AuthService($user);
        return response([
            'data' => $authService->getSessions()
        ]);
    }

    public function removeActiveSession(Request $request)
    {
        $user = User::find($request->user['id']);
        if (!$user) {
            abort(500, __('The user does not exist'));
        }
        $authService = new AuthService($user);
        return response([
            'data' => $authService->removeSession($request->input('session_id'))
        ]);
    }

    public function checkLogin(Request $request)
    {
        $data = [
            'is_login' => $request->user['id'] ? true : false
        ];
        if ($request->user['is_admin']) {
            $data['is_admin'] = true;
        }
        // 密码策略提醒的数据来源：这是前端每次启动唯一会调的接口。实时读库而不是复用
        // $request->user —— 那份载荷被 AuthService::decryptAuthData 按 JWT 缓存了 3600 秒，
        // 旗标翻转最多要一小时才生效。
        $data['password_reset_required'] = $this->passwordResetRequired($request);
        return response([
            'data' => $data
        ]);
    }

    /**
     * 一个「提醒」绝不能有能力把用户踢下线。
     *
     * checkLogin 抛异常 = 500，而前端路由守卫把 checkLogin 失败当作「未登录」直接 logout()，
     * 所以这里必须吞掉一切异常（例如 available() 记忆化之后列被 DROP 掉的竞态）。拿不到旗标
     * 的代价只是少提醒一次，代价方向正确。
     */
    private function passwordResetRequired(Request $request): bool
    {
        if (!PasswordPolicyService::available()) {
            return false;
        }
        try {
            $user = User::where('id', $request->user['id'])
                ->select(['id', 'is_admin', 'is_staff', 'password_reset_required'])
                ->first();
            return PasswordPolicyService::requiresReset($user);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('读取密码策略旗标失败', ['error' => $e->getMessage()]);
            return false;
        }
    }

    public function changePassword(UserChangePassword $request)
    {
        $user = User::find($request->user['id']);
        if (!$user) {
            abort(500, __('The user does not exist'));
        }
        if (!Helper::multiPasswordVerify(
            $user->password_algo,
            $user->password_salt,
            $request->input('old_password'),
            $user->password
        )) {
            abort(500, __('The old password is wrong'));
        }
        PasswordPolicyService::apply($user, $request->input('new_password'));
        if (!$user->save()) {
            abort(500, __('Save failed'));
        }
        // 自己敲的密码按策略不合规，重新开始提醒。这个接口的 UI 已经从三个主题里撤掉了，
        // 保留它只为兼容可能存在的第三方客户端。
        PasswordPolicyService::markRequired($user);
        $authService = new AuthService($user);
        $authService->removeAllSession();
        return response([
            'data' => true
        ]);
    }

    /**
     * 由系统生成一个新密码并落库，返回明文。
     *
     * 明文只在这一个响应里出现一次：不写日志、不发邮件、不入库。前端必须让用户先保存再关闭。
     * 仍然要求输入当前密码 —— 会话可能是别人捡到的设备，改密码是账号接管的最后一道门。
     */
    public function resetPassword(Request $request)
    {
        $request->validate(['current_password' => 'required|string']);

        $user = User::find($request->user['id']);
        if (!$user) {
            abort(500, __('The user does not exist'));
        }

        // 没有限流的话这个接口就是一台密码验证机：会话被偷之后可以用它离线爆破原密码。
        $limitKey = CacheKey::get('PASSWORD_RESET_ERROR_LIMIT', $user->id);
        $errorCount = (int)Cache::get($limitKey, 0);
        if ($errorCount >= 5) {
            abort(500, '密码错误次数过多，请 60 分钟后再试');
        }

        if (!Helper::multiPasswordVerify(
            $user->password_algo,
            $user->password_salt,
            $request->input('current_password'),
            $user->password
        )) {
            Cache::put($limitKey, $errorCount + 1, 3600);
            abort(500, __('The old password is wrong'));
        }
        Cache::forget($limitKey);

        $password = PasswordPolicyService::generate();
        PasswordPolicyService::apply($user, $password);
        if (!$user->save()) {
            abort(500, __('Save failed'));
        }
        // 顺序刻意排在 save() 之后：save 失败时旗标不能先翻，否则用户密码没变却不再被提醒。
        PasswordPolicyService::markSatisfied($user);
        // 密码变了，所有旧会话必须死。响应体已经生成，明文照样返回得出去。
        (new AuthService($user))->removeAllSession();

        return response([
            'data' => [
                'password' => $password,
                'length' => PasswordPolicyService::LENGTH
            ]
        ]);
    }

    public function newPeriod(Request $request) 
    {
        if (!config('v2board.allow_new_period', 0)) {
            abort(500, __('Renewal is not allowed'));
        }
        DB::beginTransaction();
        try {
            $user = User::find($request->user['id']);
            if (!$user) {
                abort(500, __('The user does not exist'));
            }
            if ($user->transfer_enable > $user->u + $user->d) {
                abort(500, __('You have not used up your traffic, you cannot renew your subscription'));
            }
            $userService = new UserService();
            $reset_day = $userService->getResetDay($user);
            if ($reset_day === null) {
                abort(500, __('You do not allow to renew the subscription'));
            }
            unset($user->plan);
            $reset_period = $userService->getResetPeriod($user);
            if ($reset_period === null) {
                abort(500, __('You do not allow to renew the subscription'));
            }
            switch ($reset_period) {
                case 1:
                    $reset_day = 30;
                    $reset_period = 30;
                    break;
                case 30:
                    break;
                case 12:
                    $reset_day = 365;
                    $reset_period = 365;
                    break;
                case 365:
                    break;
                default:
                    abort(500, __('Invalid reset period'));
            }
            if ($reset_day <= 0) {
                $reset_day = $reset_period;
            }
            if ($user->expired_at !== null && ($reset_period + 1) * 86400 < $user->expired_at - time()) {
                if (!$user->update(
                    [
                        'expired_at' => $user->expired_at - $reset_day * 86400,
                        'u' => 0,
                        'd' => 0
                    ]
                )) {
                    throw new \Exception(__('Save failed'));
                }
            } else {
                abort(500, __('You do not have enough time to renew your subscription'));
            }

            DB::commit();
            return response([
                'data' => true
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            abort(500, $e->getMessage());
        }
    }

    public function redeemgiftcard(UserRedeemGiftCard $request)
    {
        DB::beginTransaction();

        try {
            $user = User::find($request->user['id']);
            if (!$user) {
                abort(500, __('The user does not exist'));
            }
            $giftcard_input = $request->giftcard;
            $giftcard = Giftcard::where('code', $giftcard_input)->first();

            if (!$giftcard) {
                abort(500, __('The gift card does not exist'));
            }

            $currentTime = time();
            if ($giftcard->started_at && $currentTime < $giftcard->started_at) {
                abort(500, __('The gift card is not yet valid'));
            }

            if ($giftcard->ended_at && $currentTime > $giftcard->ended_at) {
                abort(500, __('The gift card has expired'));
            }

            if ($giftcard->limit_use !== null) {
                if (!is_numeric($giftcard->limit_use) || $giftcard->limit_use <= 0) {
                    abort(500, __('The gift card usage limit has been reached'));
                }
            }

            $usedUserIds = $giftcard->used_user_ids ? json_decode($giftcard->used_user_ids, true) : [];
            if (!is_array($usedUserIds)) {
                $usedUserIds = [];
            }

            if (in_array($user->id, $usedUserIds)) {
                abort(500, __('The gift card has already been used by this user'));
            }

            $usedUserIds[] = $user->id;
            $giftcard->used_user_ids = json_encode($usedUserIds);

            switch ($giftcard->type) {
                case 1:
                    $user->balance += $giftcard->value;
                    break;
                case 2:
                    if ($user->expired_at !== null) {
                        if ($user->expired_at <= $currentTime) {
                            $user->expired_at = $currentTime + $giftcard->value * 86400;
                        } else {
                            $user->expired_at += $giftcard->value * 86400;
                        }
                    } else {
                        abort(500, __('Not suitable gift card type'));
                    }
                    break;
                case 3:
                    $user->transfer_enable += $giftcard->value * 1073741824;
                    break;
                case 4:
                    $user->u = 0;
                    $user->d = 0;
                    break;
                case 5:
                    if ($user->plan_id == null || ($user->expired_at !== null && $user->expired_at < $currentTime)) {
                        $plan = Plan::where('id', $giftcard->plan_id)->first();
                        $user->plan_id = $plan->id;
                        $user->group_id = $plan->group_id;
                        $user->transfer_enable = $plan->transfer_enable * 1073741824;
                        $user->device_limit = $plan->device_limit;
                        $user->u = 0;
                        $user->d = 0;
                        if($giftcard->value == 0) {
                            $user->expired_at = null;
                        } else {
                            $user->expired_at = $currentTime + $giftcard->value * 86400;
                        }
                    } else {
                        abort(500, __('Not suitable gift card type'));
                    }
                    break;
                default:
                    abort(500, __('Unknown gift card type'));
            }

            if ($giftcard->limit_use !== null) {
                $giftcard->limit_use -= 1;
            }

            if (!$user->save() || !$giftcard->save()) {
                throw new \Exception(__('Save failed'));
            }

            DB::commit();

            return response([
                'data' => true,
                'type' => $giftcard->type,
                'value' => $giftcard->value
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            abort(500, $e->getMessage());
        }
    }

    public function info(Request $request)
    {
        $user = User::where('id', $request->user['id'])
            ->select([
                'email',
                'transfer_enable',
                'device_limit',
                'last_login_at',
                'created_at',
                'banned',
                'auto_renewal',
                'remind_expire',
                'remind_traffic',
                'expired_at',
                'balance',
                'commission_balance',
                'plan_id',
                'discount',
                'commission_rate',
                'telegram_id',
                'uuid'
            ])
            ->first();
        if (!$user) {
            abort(500, __('The user does not exist'));
        }
        $user['avatar_url'] = 'https://cravatar.cn/avatar/' . md5($user->email) . '?s=64&d=identicon';
        // 派生值而不是加进上面的 select()：列可能还没迁移，且 is_admin / is_staff 不在这个
        // 列表里，判定规则只能在 PasswordPolicyService 里算。
        $user['password_reset_required'] = $this->passwordResetRequired($request);
        return response([
            'data' => $user
        ]);
    }

    public function getStat(Request $request)
    {
        $stat = [
            Order::where('status', 0)
                ->where('user_id', $request->user['id'])
                ->count(),
            Ticket::where('status', 0)
                ->where('user_id', $request->user['id'])
                ->count(),
            User::where('invite_user_id', $request->user['id'])
                ->count()
        ];
        return response([
            'data' => $stat
        ]);
    }

    public function getSubscribe(Request $request)
    {
        $baseUser = User::find($request->user['id']);
        $subscriptionService = new SubscriptionService();
        if ($baseUser && $subscriptionService->available()) {
            $subscription = $subscriptionService->ensurePrimary($baseUser);
            if ($subscription) {
                $user = $subscriptionService->context($baseUser, $subscription);
                $user['email'] = $baseUser->email;
                $user['plan'] = Plan::find($subscription->plan_id);
                if (!$user['plan']) abort(500, __('Subscription plan does not exist'));
                $user['alive_ip'] = (Cache::get('ALIVE_IP_USER_' . $subscription->node_user_id)['alive_ip'] ?? 0);
                $user['subscribe_url'] = Helper::getSubscribeUrl($subscription->token, $subscription);
                $user['reset_day'] = (new UserService())->getResetDay($user);
                $user['allow_new_period'] = config('v2board.allow_new_period', 0);
                $user['multi_subscription_enable'] = (int)config('v2board.multi_subscription_enable', 0);
                return response(['data' => $user]);
            }
        }
        $user = User::where('id', $request->user['id'])
            ->select([
                'plan_id',
                'token',
                'expired_at',
                'u',
                'd',
                'transfer_enable',
                'device_limit',
                'email',
                'uuid'
            ])
            ->first();
        if (!$user) {
            abort(500, __('The user does not exist'));
        }
        if ($user->plan_id) {
            $user['plan'] = Plan::find($user->plan_id);
            if (!$user['plan']) {
                abort(500, __('Subscription plan does not exist'));
            }
        }

        //统计在线设备
        $countalive = 0;
        $ips_array = Cache::get('ALIVE_IP_USER_' . $request->user['id']);
        if ($ips_array) {
            $countalive = $ips_array['alive_ip'];
        }
        $user['alive_ip'] = $countalive;

        $user['subscribe_url'] = Helper::getSubscribeUrl($user['token']);

        $userService = new UserService();
        $user['reset_day'] = $userService->getResetDay($user);
        $user['allow_new_period'] = config('v2board.allow_new_period', 0);
        $user['multi_subscription_enable'] = (int)config('v2board.multi_subscription_enable', 0);
        return response([
            'data' => $user
        ]);
    }

    public function unbindTelegram(Request $request)
    {
        $user = User::find($request->user['id']);
        if (!$user) {
            abort(500, __('The user does not exist'));
        }
        if (!$user->update(['telegram_id' => null])) {
            abort(500, __('Unbind telegram failed'));
        }
        return response([
            'data' => true
        ]);
    }

    public function resetSecurity(Request $request)
    {
        $user = User::find($request->user['id']);
        if (!$user) {
            abort(500, __('The user does not exist'));
        }
        // 包 using() 只为给 token 历史标注原因；捕获由 Eloquent 观察者完成。
        return \App\Utils\TokenRotationContext::using('self_reset', function () use ($user) {
            $user->uuid = Helper::guid(true);
            $user->token = Helper::guid();
            if (!$user->save()) {
                abort(500, __('Reset failed'));
            }
            $subscriptionService = new SubscriptionService();
            $primary = $subscriptionService->primary($user);
            if ($primary) {
                $primary->uuid = $user->uuid;
                $primary->token = $user->token;
                $primary->save();
            }
            return response([
                'data' => Helper::getSubscribeUrl($user['token'])
            ]);
        });
    }

    public function update(UserUpdate $request)
    {
        $updateData = $request->only([
            'auto_renewal',
            'remind_expire',
            'remind_traffic'
        ]);

        $user = User::find($request->user['id']);
        if (!$user) {
            abort(500, __('The user does not exist'));
        }
        try {
            $user->update($updateData);
            $primary = (new SubscriptionService())->primary($user);
            if ($primary && array_key_exists('auto_renewal', $updateData)) {
                $primary->auto_renewal = $updateData['auto_renewal'];
                $primary->save();
            }
        } catch (\Exception $e) {
            abort(500, __('Save failed'));
        }

        return response([
            'data' => true
        ]);
    }

    public function transfer(UserTransfer $request)
    {
        $user = User::find($request->user['id']);
        if (!$user) {
            abort(500, __('The user does not exist'));
        }
        if ($request->input('transfer_amount') > $user->commission_balance) {
            abort(500, __('Insufficient commission balance'));
        }
        DB::beginTransaction();
        $order = new Order();
        $orderService = new OrderService($order);
        $order->user_id = $request->user['id'];
        $order->plan_id = 0;
        $order->period = 'deposit';
        $order->trade_no = Helper::generateOrderNo();
        $order->total_amount = $request->input('transfer_amount');

        $orderService->setOrderType($user);
        $orderService->setInvite($user);

        $user->commission_balance = $user->commission_balance - $request->input('transfer_amount');
        $user->balance = $user->balance + $request->input('transfer_amount');
        $order->status = 3;
        $order->total_amount = 0;
        $order->surplus_amount = $request->input('transfer_amount');
        $order->callback_no = '佣金划转 Commission transfer';
        if (!$order->save()||!$user->save()) {
            DB::rollback();
            abort(500, __('Transfer failed'));
        }

        DB::commit();

        return response([
            'data' => true
        ]);
    }

    public function getQuickLoginUrl(Request $request)
    {
        $user = User::find($request->user['id']);
        if (!$user) {
            abort(500, __('The user does not exist'));
        }

        $code = Helper::guid();
        $key = CacheKey::get('TEMP_TOKEN', $code);
        Cache::put($key, $user->id, 60);
        $redirect = '/#/login?verify=' . $code . '&redirect=' . ($request->input('redirect') ? $request->input('redirect') : 'dashboard');
        if (config('v2board.app_url')) {
            $url = config('v2board.app_url') . $redirect;
        } else {
            $url = url($redirect);
        }
        return response([
            'data' => $url
        ]);
    }
}
