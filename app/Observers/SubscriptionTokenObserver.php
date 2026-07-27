<?php

namespace App\Observers;

use App\Models\Subscription;
use App\Services\SubscriptionTokenHistoryService;
use App\Utils\TokenRotationContext;
use Illuminate\Database\Eloquent\Model;

/**
 * 一个观察者服务 User 与 Subscription 两个模型，把每一次 token 签发与退役记进历史表。
 *
 * 为什么用观察者而不是在十个 token 写入点各加一行调用：明年新增的第十一个写入点不会
 * 知道要调。已逐个核实，只有 UserController@multiGenerate 的 User::insert() 绕过模型
 * 事件，那一处显式调 recordBulk()。
 */
class SubscriptionTokenObserver
{
    /** @var SubscriptionTokenHistoryService|null */
    private static $service;

    public function created(Model $model): void
    {
        $token = trim((string)$model->token);
        if ($token === '') {
            return;
        }
        [$userId, $subscriptionId] = $this->owner($model);
        if ($userId <= 0) {
            return;
        }
        $this->service()->noteIssued($token, $userId, $subscriptionId, TokenRotationContext::reason());
    }

    /**
     * 暂存旧值。这里的第一条语句必须是纯内存判断且不碰数据库：traffic:update 每分钟对
     * 成千上万行 User/Subscription 做 save()，观察者会被全部命中。
     *
     * 写在 updating 而不是直接在这里落库：若随后的 UPDATE 失败，就会记下一次没发生的轮换。
     */
    public function updating(Model $model): void
    {
        if (!$model->isDirty('token')) {
            return;
        }
        TokenRotationContext::stashOriginal($model, $model->getOriginal('token'));
    }

    /**
     * updated 只在 performUpdate 真的执行了 UPDATE 之后触发，所以到这里轮换已经落库。
     */
    public function updated(Model $model): void
    {
        if (!TokenRotationContext::hasOriginal($model)) {
            return;
        }
        $old = TokenRotationContext::takeOriginal($model);
        $token = trim((string)$model->token);
        [$userId, $subscriptionId] = $this->owner($model);
        if ($userId <= 0) {
            return;
        }
        $reason = TokenRotationContext::reason();
        $service = $this->service();
        if ($token !== '') {
            $service->noteIssued($token, $userId, $subscriptionId, $reason);
        }
        $old = trim((string)$old);
        if ($old !== '' && $old !== $token) {
            // 退役前先查活性：镜像、setPrimary 换主、reset:user 三种情况下旧值仍然活着。
            $service->noteRetiredIfDead($old, $reason);
        }
    }

    public function saved(Model $model): void
    {
        TokenRotationContext::forget($model);
    }

    /**
     * @return array{0: int, 1: ?int}
     */
    private function owner(Model $model): array
    {
        if ($model instanceof Subscription) {
            return [(int)$model->user_id, (int)$model->id];
        }
        return [(int)$model->id, null];
    }

    private function service(): SubscriptionTokenHistoryService
    {
        if (self::$service === null) {
            // 服务实例带记忆化的表探测，整个 worker 生命周期复用一个即可。
            self::$service = new SubscriptionTokenHistoryService();
        }
        return self::$service;
    }
}
