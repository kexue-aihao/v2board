<?php

namespace App\Services;

use App\Jobs\KickTelegramBinding;
use App\Models\Subscription;
use App\Models\TelegramSubscriptionBinding;
use App\Models\User;
use App\Utils\CacheKey;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class TelegramBindingService
{
    public function enabled(): bool
    {
        return (int)config('v2board.telegram_subscription_binding_enable', 0) === 1
            && trim((string)config('v2board.telegram_discuss_id', '')) !== '';
    }

    public function available(): bool
    {
        return Schema::hasTable('v2_telegram_subscription_binding');
    }

    public function chatId(): string
    {
        return trim((string)config('v2board.telegram_discuss_id', ''));
    }

    public function prepare(User $user, Subscription $subscription): array
    {
        if (!$this->enabled()) abort(503, 'Telegram subscription binding is disabled');
        if (!$this->available()) abort(503, 'Telegram subscription binding is not ready');
        if ((int)$subscription->user_id !== (int)$user->id) abort(403, 'Subscription does not belong to the user');
        if (!$this->subscriptionIsActive($subscription)) abort(422, 'Subscription is not active');

        $nonce = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        Cache::put(CacheKey::get('TELEGRAM_BINDING', $nonce), [
            'user_id' => (int)$user->id,
            'subscription_id' => (int)$subscription->id,
            'token_hash' => $this->tokenHash($subscription->token),
            'chat_id' => $this->chatId(),
            'created_at' => time()
        ], 600);

        $username = trim((string)config('v2board.oauth_telegram_bot_username', ''));
        if ($username === '') {
            $response = (new TelegramService())->getMe();
            $username = (string)($response->result->username ?? '');
        }
        if ($username === '') abort(503, 'Telegram bot username is not configured');
        return [
            'bot_username' => ltrim($username, '@'),
            'binding_url' => 'https://t.me/' . ltrim($username, '@') . '?start=bind_' . $nonce,
            'expires_at' => time() + 600,
            'subscription_id' => (int)$subscription->id,
            'chat_id' => $this->chatId()
        ];
    }

    public function completeFromBot(string $nonce, $telegramUserId, $username, $chatId): array
    {
        if (!$this->enabled()) throw new RuntimeException('Telegram subscription binding is disabled');
        if (!$this->available()) throw new RuntimeException('Telegram subscription binding is not ready');
        $payload = Cache::pull(CacheKey::get('TELEGRAM_BINDING', trim($nonce)));
        if (!is_array($payload)) throw new RuntimeException('Binding link is invalid or expired');
        if ((string)$payload['chat_id'] !== $this->chatId()) throw new RuntimeException('Binding group is invalid');
        if (!ctype_digit((string)$chatId) || (int)$chatId <= 0) {
            throw new RuntimeException('Binding must be completed in a private chat');
        }
        $telegramUserId = (string)$telegramUserId;
        $username = $this->normalizeUsername($username);
        if ($telegramUserId === '' || !ctype_digit($telegramUserId) || $username === '') {
            throw new RuntimeException('Telegram UID and username are required');
        }

        $previous = null;
        $staleLinks = [];
        $binding = DB::transaction(function () use ($payload, $telegramUserId, $username, &$previous, &$staleLinks) {
            $staleLinks = [];
            $user = User::where('id', (int)$payload['user_id'])->lockForUpdate()->first();
            $subscription = Subscription::where('id', (int)$payload['subscription_id'])
                ->where('user_id', (int)$payload['user_id'])->lockForUpdate()->first();
            if (!$user || !$subscription || !$this->subscriptionIsActive($subscription)) {
                throw new RuntimeException('Subscription is no longer active');
            }
            if (!hash_equals((string)$payload['token_hash'], $this->tokenHash($subscription->token))) {
                throw new RuntimeException('Subscription link has changed; prepare a new binding');
            }
            // telegram_chat 唯一索引不分 status，所以这个 Telegram UID 至多还有
            // 一行属于别人。只有 active 且主人健在才是真冲突；其余 —— 解绑/失效
            // 留下的尸位行、后台删号留下的孤儿行（delUser 不级联本表）—— 只是
            // 占着索引，放任不管的话下面的 create/update 就是裸 1062，这个 UID
            // 从此对全站锁死。摘掉尸位行放行；原主的卡片会从「已失效」变成
            // 「未绑定」，效果等价（两种状态都得重新绑定）。不派踢人 Job：这个
            // UID 正是当前绑定者本人，踢了就是踢刚生效的新成员。
            $occupant = TelegramSubscriptionBinding::where('chat_id', $this->chatId())
                ->where('telegram_user_id', $telegramUserId)
                ->where('user_id', '!=', $user->id)
                ->lockForUpdate()->first();
            if ($occupant) {
                // 主人存在性探测不能加锁：锁别人的 v2_user 行会与对方自己的
                // completeFromBot（先锁自己的 user 再锁绑定行）互成反向加锁死锁。
                if ($occupant->status === 'active' && User::where('id', $occupant->user_id)->exists()) {
                    throw new RuntimeException('Telegram account is already bound to another account');
                }
                // 孤儿 active 行可能还揣着没过期的一次性入群链接；行删了链接在
                // Telegram 那边照样能用，捕下来到事务外回收。
                if (trim((string)$occupant->invite_link) !== ''
                    && (int)$occupant->invite_link_expires_at > time()) {
                    $staleLinks[] = ['chat_id' => (int)$occupant->chat_id, 'link' => (string)$occupant->invite_link];
                }
                $occupant->delete();
            }

            $existing = TelegramSubscriptionBinding::where('user_id', $user->id)
                ->where('chat_id', $this->chatId())
                ->lockForUpdate()->first();
            $attributes = [
                'subscription_id' => $subscription->id,
                'subscription_token_hash' => $this->tokenHash($subscription->token),
                'telegram_user_id' => $telegramUserId,
                'telegram_username' => $username,
                'chat_id' => $this->chatId(),
                'status' => 'active',
                'invalid_reason' => null,
                'bound_at' => time(),
                'last_checked_at' => time(),
                'updated_at' => time()
            ];
            if ($existing) {
                if ((string)$existing->telegram_user_id !== $telegramUserId
                    && (string)$existing->telegram_user_id !== '') {
                    $previous = [
                        'binding_id' => (int)$existing->id,
                        'telegram_user_id' => (string)$existing->telegram_user_id,
                        'chat_id' => (string)$existing->chat_id
                    ];
                }
                $existing->fill($attributes);
                $existing->save();
                return $existing;
            }
            return TelegramSubscriptionBinding::create(array_merge($attributes, [
                'user_id' => $user->id,
                'created_at' => time()
            ]));
        });
        // 摘尸位行时捕到的入群链接在事务外回收（Telegram HTTP 不进事务），
        // fail-open：回收失败只记日志，不影响已落库的新绑定。
        foreach ($staleLinks as $stale) {
            try {
                (new TelegramService())->revokeChatInviteLink($stale['chat_id'], $stale['link']);
            } catch (\Throwable $e) {
                Log::error('Failed to revoke a stale Telegram invite link', [
                    'chat_id' => $stale['chat_id'],
                    'error' => $e->getMessage()
                ]);
            }
        }
        if ($previous) {
            KickTelegramBinding::dispatch(
                $previous['binding_id'],
                $previous['telegram_user_id'],
                $previous['chat_id'],
                true
            );
        }
        return [
            'binding_id' => (int)$binding->id,
            'telegram_username' => $binding->telegram_username,
            'subscription_id' => (int)$binding->subscription_id,
            'chat_id' => $binding->chat_id
        ];
    }

    /**
     * 绑定完成后签发一次性入群链接：10 分钟有效、member_limit=1 用后即焚。
     * 链接存回绑定行，供 revoke/invalidate 时回收未用完的链接。
     * Telegram API 失败会向上抛（request() 是 abort(500)）—— webhook 调用方必须
     * 自己 try/catch，否则 500 会让 Telegram 反复重投同一条 update。
     */
    public function issueInviteLink(int $bindingId): string
    {
        $binding = TelegramSubscriptionBinding::find($bindingId);
        if (!$binding || $binding->status !== 'active') {
            throw new RuntimeException('Binding is not active');
        }
        // 10 分钟内重复绑定会复用同一行：先收回上一条未用完的链接，
        // 否则新旧两条会短暂同时有效。
        $this->recallInviteLink($binding);
        $expiresAt = time() + 600;
        $response = (new TelegramService())->createChatInviteLink((int)$binding->chat_id, $expiresAt, 1);
        $link = (string)($response->result->invite_link ?? '');
        if ($link === '') {
            throw new RuntimeException('Telegram did not return an invite link');
        }
        if ($this->inviteLinkColumnsAvailable()) {
            $binding->invite_link = $link;
            $binding->invite_link_expires_at = $expiresAt;
            $binding->updated_at = time();
            $binding->save();
        }
        return $link;
    }

    /**
     * 回收还在有效期内的一次性链接。fail-open：回收失败只记日志，绝不让
     * 解绑/作废本身失败（口径同 SubscriptionTokenHistoryService）。
     * 只改属性不 save() —— 两个调用方紧接着都会 save。
     */
    private function recallInviteLink(TelegramSubscriptionBinding $binding): void
    {
        if (!$this->inviteLinkColumnsAvailable()) return;
        $link = trim((string)$binding->invite_link);
        if ($link !== '' && (int)$binding->invite_link_expires_at > time()) {
            try {
                (new TelegramService())->revokeChatInviteLink((int)$binding->chat_id, $link);
            } catch (\Throwable $e) {
                Log::error('Failed to revoke a Telegram invite link', [
                    'binding_id' => (int)$binding->id,
                    'error' => $e->getMessage()
                ]);
            }
        }
        $binding->invite_link = null;
        $binding->invite_link_expires_at = null;
    }

    private function inviteLinkColumnsAvailable(): bool
    {
        static $available = null;
        if ($available === null) {
            $available = Schema::hasColumn('v2_telegram_subscription_binding', 'invite_link');
        }
        return $available;
    }

    public function forUser(User $user): ?TelegramSubscriptionBinding
    {
        if (!$this->enabled() || !$this->available()) return null;
        return TelegramSubscriptionBinding::where('user_id', $user->id)
            ->where('chat_id', $this->chatId())
            ->where('status', 'active')
            ->latest('id')->first();
    }

    public function latestForUser(User $user): ?TelegramSubscriptionBinding
    {
        if (!$this->available()) return null;
        return TelegramSubscriptionBinding::where('user_id', $user->id)
            ->where('chat_id', $this->chatId())->latest('id')->first();
    }

    public function revoke(User $user): bool
    {
        if (!$this->available()) return false;
        $binding = TelegramSubscriptionBinding::where('user_id', $user->id)
            ->where('chat_id', $this->chatId())
            ->where('status', 'active')->first();
        if (!$binding) return false;
        // 先收回没用完的一次性链接：绑定后拿到链接、不用、再解绑，那条链接在
        // 剩余窗口里依然能进群。
        $this->recallInviteLink($binding);
        $binding->status = 'revoked';
        $binding->invalid_reason = 'user_revoked';
        $binding->updated_at = time();
        $binding->save();
        KickTelegramBinding::dispatch((int)$binding->id, (string)$binding->telegram_user_id, (string)$binding->chat_id);
        return true;
    }

    public function processJoinRequest($chatId, array $from): bool
    {
        if (!$this->enabled() || !$this->available()) return false;
        $chatId = (string)$chatId;
        $uid = (string)($from['id'] ?? '');
        $username = $this->normalizeUsername($from['username'] ?? '');
        $binding = TelegramSubscriptionBinding::where('chat_id', $chatId)
            ->where('telegram_user_id', $uid)
            ->where('status', 'active')->first();
        $user = $binding ? User::find($binding->user_id) : null;
        if (!$binding || !$user || $user->banned || !$this->bindingMatches($binding, $username)) return false;
        return true;
    }

    public function processChatMemberUpdate(array $update): void
    {
        if (!$this->enabled() || !$this->available()) return;
        $chatId = (string)($update['chat']['id'] ?? '');
        $member = $update['new_chat_member'] ?? $update['old_chat_member'] ?? [];
        $telegramUserId = (string)($member['user']['id'] ?? '');
        if ($chatId === '' || $telegramUserId === '') return;
        $binding = TelegramSubscriptionBinding::where('chat_id', $chatId)
            ->where('telegram_user_id', $telegramUserId)->where('status', 'active')->first();
        if (!$binding) {
            // 没有有效绑定的人出现在群里（被管理员拉入、拿旧公共链接进入、或绑定
            // 已被作废后又回来）：一露面就清退，不必等任何巡检。
            // restricted 也算在群内 —— 被限制发言的成员仍然占着位置。
            if (in_array((string)($update['new_chat_member']['status'] ?? ''), ['member', 'restricted'], true)) {
                $this->enforceMember($chatId, (array)($member['user'] ?? []));
            }
            return;
        }
        $username = $this->normalizeUsername($member['user']['username'] ?? '');
        if ($username === '' || $username !== $this->normalizeUsername($binding->telegram_username)) {
            $this->invalidate($binding, 'telegram_username_changed');
        }
    }

    /**
     * 清退无有效绑定的群成员（ban+unban = 踢出、不封禁，随时可凭新链接回来）。
     *
     * Bot API 没有枚举群成员的接口，「定时扫全群」做不到；等价做法是事件驱动 ——
     * chat_member 事件盯住每一次加入，群消息兜住 bot 上任前就在群里的存量成员
     * （他们一发言即被校验）。覆盖面与轮询相同，时延从巡检间隔降到秒级。
     *
     * 三道保险：bot 一律豁免；管理员/群主豁免；管理员名单拉取失败时**绝不踢**
     * （fail-safe，避免 Telegram API 抖动时误踢群主）。已验证成员缓存 5 分钟，
     * 避免活跃群每条消息都打一次数据库。
     */
    public function enforceMember($chatId, array $user): void
    {
        if (!$this->enabled() || !$this->available()) return;
        $chatId = (string)$chatId;
        if ($chatId !== $this->chatId()) return;
        $uid = (string)($user['id'] ?? '');
        if ($uid === '' || !ctype_digit($uid)) return;
        if (!empty($user['is_bot'])) return;

        $verifiedKey = CacheKey::get('TELEGRAM_MEMBER_VERIFIED', $chatId . ':' . $uid);
        if (Cache::get($verifiedKey)) return;

        $admins = $this->groupAdminIds($chatId);
        if ($admins === null) return; // 名单拿不到，宁可放过不可错踢
        if (in_array($uid, $admins, true)) {
            Cache::put($verifiedKey, 1, 300);
            return;
        }

        $bound = TelegramSubscriptionBinding::where('chat_id', $chatId)
            ->where('telegram_user_id', $uid)
            ->where('status', 'active')->exists();
        if ($bound) {
            Cache::put($verifiedKey, 1, 300);
            return;
        }

        // forceSnapshot：按裸 uid/chatId 直接踢，不依赖绑定行（本来就没有）。
        KickTelegramBinding::dispatch(0, $uid, $chatId, true);
    }

    /**
     * @return array|null 数字 id 字符串数组；拉取失败返回 null（调用方必须按「未知」处理）
     */
    private function groupAdminIds(string $chatId): ?array
    {
        $key = CacheKey::get('TELEGRAM_GROUP_ADMINS', $chatId);
        $ids = Cache::get($key);
        if (is_array($ids)) return $ids;
        try {
            $response = (new TelegramService())->getChatAdministrators((int)$chatId);
            $ids = [];
            foreach ((array)($response->result ?? []) as $item) {
                $id = (string)($item->user->id ?? '');
                if ($id !== '') $ids[] = $id;
            }
            Cache::put($key, $ids, 300);
            return $ids;
        } catch (\Throwable $e) {
            report($e);
            return null;
        }
    }

    public function invalidateForSubscription(int $subscriptionId, string $reason): void
    {
        if (!$this->available()) return;
        TelegramSubscriptionBinding::where('subscription_id', $subscriptionId)
            ->where('status', 'active')->get()->each(function (TelegramSubscriptionBinding $binding) use ($reason) {
                // 与原内联逻辑逐项相同（invalid + reason + save + kick），
                // 走 invalidate() 才能一并回收未用完的入群链接。
                $this->invalidate($binding, $reason);
            });
    }

    public function invalidateForUser(int $userId, string $reason): void
    {
        if (!$this->available()) return;
        TelegramSubscriptionBinding::where('user_id', $userId)->where('status', 'active')
            ->get()->each(function (TelegramSubscriptionBinding $binding) use ($reason) {
                $this->invalidate($binding, $reason);
            });
    }

    public function invalidateAll(string $reason): void
    {
        if (!$this->available()) return;
        TelegramSubscriptionBinding::where('status', 'active')
            ->chunkById(100, function ($bindings) use ($reason) {
                foreach ($bindings as $binding) {
                    $this->invalidate($binding, $reason);
                }
            });
    }

    public function verifyOne(TelegramSubscriptionBinding $binding): void
    {
        if ($binding->status !== 'active') return;
        $subscription = Subscription::where('id', $binding->subscription_id)
            ->where('user_id', $binding->user_id)->first();
        if (!$subscription || !$this->subscriptionIsActive($subscription)
            || !hash_equals((string)$binding->subscription_token_hash, $this->tokenHash($subscription->token))) {
            $this->invalidate($binding, 'subscription_changed');
            return;
        }
        try {
            $member = (new TelegramService())->getChatMember($binding->chat_id, $binding->telegram_user_id);
            $currentId = (string)($member->result->user->id ?? '');
            $current = $this->normalizeUsername($member->result->user->username ?? '');
            if ($currentId !== (string)$binding->telegram_user_id || $current === '' || $current !== $this->normalizeUsername($binding->telegram_username)) {
                $this->invalidate($binding, 'telegram_username_changed');
                return;
            }
            $binding->last_checked_at = time();
            $binding->updated_at = time();
            $binding->save();
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public function invalidate(TelegramSubscriptionBinding $binding, string $reason): void
    {
        if ($binding->status !== 'active') return;
        $this->recallInviteLink($binding);
        $binding->status = 'invalid';
        $binding->invalid_reason = $reason;
        $binding->updated_at = time();
        $binding->save();
        KickTelegramBinding::dispatch((int)$binding->id, (string)$binding->telegram_user_id, (string)$binding->chat_id);
    }

    public function bindingMatches(TelegramSubscriptionBinding $binding, string $username): bool
    {
        if ($binding->chat_id !== $this->chatId()) return false;
        if ($username === '' || $this->normalizeUsername($binding->telegram_username) !== $username) return false;
        $subscription = Subscription::where('id', $binding->subscription_id)
            ->where('user_id', $binding->user_id)->first();
        return $subscription && $this->subscriptionIsActive($subscription)
            && hash_equals((string)$binding->subscription_token_hash, $this->tokenHash($subscription->token));
    }

    private function subscriptionIsActive(Subscription $subscription): bool
    {
        return $subscription->status === 'active'
            && (!$subscription->expired_at || (int)$subscription->expired_at >= time());
    }

    private function tokenHash($token): string
    {
        return hash('sha256', strtolower(trim((string)$token)));
    }

    private function normalizeUsername($username): string
    {
        return strtolower(ltrim(trim((string)$username), '@'));
    }
}
