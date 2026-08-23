<?php

namespace App\Services;

use App\Models\DailyCheckin;
use App\Models\GameRoom;
use App\Models\Subscription;
use App\Models\TelegramSubscriptionBinding;
use App\Models\TrafficRewardLog;
use App\Models\UserGameSetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use RuntimeException;

class TrafficRewardService
{
    public const GB = 1073741824;
    public const MIN_GB = 1;
    public const MAX_GB = 10;
    public const MAX_GAME_GB = 1000;

    public static function normalizeRewardGb($value, int $fallback = 1): int
    {
        $value = filter_var($value, FILTER_VALIDATE_INT);
        if ($value === false) $value = $fallback;
        return min(self::MAX_GB, max(self::MIN_GB, (int)$value));
    }

    public static function splitTrafficChange(int $bytes): array
    {
        return [
            'increase_bytes' => max(0, $bytes),
            'deducted_bytes' => max(0, -$bytes),
        ];
    }

    private static function normalizeGameGb($value, int $fallback = 1): int
    {
        $value = filter_var($value, FILTER_VALIDATE_INT);
        if ($value === false) $value = $fallback;
        return min(self::MAX_GAME_GB, max(self::MIN_GB, (int)$value));
    }

    private static function normalizeProbability($value, int $fallback = 100): int
    {
        $value = filter_var($value, FILTER_VALIDATE_INT);
        if ($value === false) $value = $fallback;
        return min(100, max(0, (int)$value));
    }

    private static function normalizeMultiplier($value, string $fallback = '1.00'): string
    {
        if (!is_numeric($value)) $value = $fallback;
        $value = (float)$value;
        if ($value < 1 || $value > 1000) $value = (float)$fallback;
        return number_format($value, 2, '.', '');
    }

    /**
     * Rules are deliberately kept separate from a user's wager settings.  The
     * Telegram adapter can call these methods after it has proved that the
     * bound subscription belongs to an administrator.
     */
    public function gameRulesForAdministrator(User $actor, ?int $subscriptionId = null): array
    {
        $this->assertGameRuleAdministrator($actor, $subscriptionId);
        return $this->gameRules();
    }

    public function saveGameRuleForAdministrator(User $actor, string $game, $probability, $multiplier, ?int $subscriptionId = null, $enabled = null): array
    {
        $this->assertGameRuleAdministrator($actor, $subscriptionId);
        if (!in_array($game, ['dice', 'slots', 'poker'], true)) {
            throw new RuntimeException('不支持的游戏项目');
        }

        $validatedProbability = filter_var($probability, FILTER_VALIDATE_INT);
        if ($validatedProbability === false || $validatedProbability < 0 || $validatedProbability > 100 || !is_numeric($multiplier) || (float)$multiplier < 1 || (float)$multiplier > 1000) {
            throw new RuntimeException('游戏规则参数无效');
        }
        $probability = (int)$validatedProbability;
        $multiplier = self::normalizeMultiplier($multiplier);
        $config = (array)config('v2board', []);
        $config['reward_' . $game . '_win_probability'] = $probability;
        $config['reward_' . $game . '_payout_multiplier'] = $multiplier;
        if ($enabled !== null) {
            if (!in_array((string)$enabled, ['0', '1'], true) && !is_bool($enabled)) {
                throw new RuntimeException('游戏启用状态无效');
            }
            $config['reward_' . $game . '_enable'] = (int)(bool)$enabled;
        }

        if (!File::put(base_path('config/v2board.php'), "<?php\n return " . var_export($config, true) . " ;", LOCK_EX)) {
            throw new RuntimeException('保存游戏规则失败，请检查 config 目录写入权限');
        }
        $runtimeConfig = [
            'v2board.reward_' . $game . '_win_probability' => $probability,
            'v2board.reward_' . $game . '_payout_multiplier' => $multiplier,
        ];
        if ($enabled !== null) $runtimeConfig['v2board.reward_' . $game . '_enable'] = $config['reward_' . $game . '_enable'];
        config($runtimeConfig);
        try {
            Artisan::call('config:cache');
        } catch (\Throwable $exception) {
            throw new RuntimeException('游戏规则已写入，但配置缓存刷新失败：' . $exception->getMessage());
        }
        return $this->gameRules();
    }

    public function gameRules(): array
    {
        $rules = [];
        foreach (['dice', 'slots', 'poker'] as $game) {
            $rules[$game] = $this->gameRule($game);
        }
        return $rules;
    }

    public function gameRulesForTelegramAdministrator($telegramUserId, $chatId = null): array
    {
        $context = $this->requiredTelegramAdministrator($telegramUserId, $chatId);
        return $this->gameRulesForAdministrator($context['user'], $context['subscription_id']);
    }

    public function saveGameRuleForTelegramAdministrator($telegramUserId, $chatId, string $game, $probability, $multiplier, $enabled = null): array
    {
        $context = $this->requiredTelegramAdministrator($telegramUserId, $chatId);
        return $this->saveGameRuleForAdministrator($context['user'], $game, $probability, $multiplier, $context['subscription_id'], $enabled);
    }

    public function userForTelegram($telegramUserId, $chatId = null): ?User
    {
        try {
            if (Schema::hasTable('v2_telegram_subscription_binding')) {
                $query = TelegramSubscriptionBinding::where('telegram_user_id', (string)$telegramUserId)->where('status', 'active');
                if ($chatId !== null) $query->where('chat_id', (string)$chatId);
                $binding = $query->orderByDesc('updated_at')->first();
                if ($binding) return User::find($binding->user_id);
            }
        } catch (\Throwable) {
            // table query failed, fall through to legacy telegram_id lookup
        }
        return User::where('telegram_id', (string)$telegramUserId)->first();
    }

    /**
     * Resolve the binding afresh for every Telegram callback. Callers must pass
     * the returned subscription_id to checkin/play methods; do not cache this
     * result as an authorization decision.
     */
    public function telegramBindingContext($telegramUserId, $chatId = null): ?array
    {
        $query = TelegramSubscriptionBinding::where('telegram_user_id', (string)$telegramUserId)
            ->where('status', 'active');
        if ($chatId !== null) $query->where('chat_id', (string)$chatId);
        $binding = $query->orderByDesc('updated_at')->first();
        if (!$binding) return null;

        $subscription = Subscription::find($binding->subscription_id);
        if (!$subscription || (int)$subscription->user_id !== (int)$binding->user_id || $subscription->status !== 'active' || ($subscription->expired_at !== null && $subscription->expired_at <= time())) return null;
        $user = User::find($subscription->user_id);
        if (!$user) return null;
        return [
            'user' => $user,
            'subscription_id' => (int)$subscription->id,
            'is_admin' => (int)$user->is_admin === 1,
        ];
    }

    public function checkinStatus(User $user, ?int $subscriptionId = null): array
    {
        try {
            $subscription = $this->activeSubscription($user, $subscriptionId);
        } catch (RuntimeException) {
            return ['checked_in' => false, 'reward_gb' => null, 'streak_days' => 0, 'expires_at' => null];
        }
        $date = date('Y-m-d');
        $row = DailyCheckin::where('user_id', $user->id)->where('subscription_id', $subscription->id)->where('checkin_date', $date)->first();
        return ['checked_in' => (bool)$row, 'reward_gb' => $row ? (int)round($row->reward_bytes / self::GB) : null, 'streak_days' => $row ? (int)$row->streak_days : 0, 'expires_at' => $this->expiresAt($subscription)];
    }

    public function gameSettings(User $user): array
    {
        $settings = UserGameSetting::where('user_id', $user->id)->pluck('bet_gb', 'game');
        return [
            'dice_bet_gb' => self::normalizeGameGb($settings['dice'] ?? 1),
            'slots_bet_gb' => self::normalizeGameGb($settings['slots'] ?? 1),
            'poker_bet_gb' => self::normalizeGameGb($settings['poker'] ?? 1),
        ];
    }

    public function saveGameSettings(User $user, array $values): array
    {
        return DB::transaction(function () use ($user, $values) {
            $user = User::where('id', $user->id)->lockForUpdate()->firstOrFail();
            foreach (['dice', 'slots', 'poker'] as $game) {
                UserGameSetting::updateOrCreate(
                    ['user_id' => $user->id, 'game' => $game],
                    ['bet_gb' => self::normalizeGameGb($values[$game . '_bet_gb'] ?? 1), 'updated_at' => time()]
                );
            }
            return $this->gameSettings($user);
        });
    }

    public function saveGameWager(User $user, string $game, $betGb, ?int $subscriptionId = null): array
    {
        if (!in_array($game, ['dice', 'slots', 'poker'], true)) throw new RuntimeException('不支持的游戏项目');
        $betGb = filter_var($betGb, FILTER_VALIDATE_INT);
        if ($betGb === false || $betGb < self::MIN_GB || $betGb > self::MAX_GAME_GB) {
            throw new RuntimeException('赌注流量必须为 1-1000 GB');
        }
        return DB::transaction(function () use ($user, $game, $betGb, $subscriptionId) {
            $user = User::where('id', $user->id)->lockForUpdate()->firstOrFail();
            if ($subscriptionId !== null) $this->activeSubscription($user, $subscriptionId);
            UserGameSetting::updateOrCreate(
                ['user_id' => $user->id, 'game' => $game],
                ['bet_gb' => $betGb, 'updated_at' => time()]
            );
            return $this->gameSettings($user);
        });
    }

    public function checkin(User $user, string $source = 'web', ?int $subscriptionId = null): array
    {
        return DB::transaction(function () use ($user, $source, $subscriptionId) {
            if ((int)config('v2board.reward_enable', 1) !== 1) throw new RuntimeException('奖励功能已关闭');
            $user = User::where('id', $user->id)->lockForUpdate()->firstOrFail();
            $subscription = $this->activeSubscription($user, $subscriptionId);
            $date = date('Y-m-d');
            $uniqueKey = 'checkin:' . $subscription->id . ':' . $date;
            if (TrafficRewardLog::where('unique_key', $uniqueKey)->exists()) {
                throw new RuntimeException('今日已经签到');
            }
            $rewardGb = random_int(self::MIN_GB, self::MAX_GB);
            $rewardBytes = $rewardGb * self::GB;
            $checkin = DailyCheckin::create([
                'user_id' => $user->id,
                'subscription_id' => $subscription->id,
                'checkin_date' => $date,
                'reward_bytes' => $rewardBytes,
                'streak_days' => $this->streakDays($user->id, $subscription->id, $date),
                'created_at' => time(),
                'updated_at' => time(),
            ]);
            $this->grant($user, $subscription, $rewardBytes, 'checkin', $source, $uniqueKey, [
                'event' => 'checkin',
                'reward_gb' => $rewardGb,
                'reward_bytes' => $rewardBytes,
                'net_bytes' => $rewardBytes,
                'checkin_id' => $checkin->id,
            ]);
            return ['reward_gb' => $rewardGb, 'reward_bytes' => $rewardBytes, 'expires_at' => $this->expiresAt($subscription)];
        });
    }

    public function playDice(User $user, string $source = 'web', ?string $requestId = null, ?int $subscriptionId = null): array
    {
        return $this->playSingle($user, 'dice', $source, function () {
            return random_int(1, 6);
        }, $requestId, $subscriptionId);
    }

    public function playSlots(User $user, string $source = 'web', ?string $requestId = null, ?int $subscriptionId = null): array
    {
        return $this->playSingle($user, 'slots', $source, function () {
            $rate = min(10000, max(1, (int)config('v2board.reward_slots_jackpot_rate', 100)));
            if (random_int(1, 10000) <= $rate) {
                $symbol = random_int(1, 7);
                return [$symbol, $symbol, $symbol];
            }
            $a = random_int(1, 7); $b = random_int(1, 7);
            $c = $a === $b ? (($a % 7) + 1) : random_int(1, 7);
            return [$a, $b, $c];
        }, $requestId, $subscriptionId);
    }

    private function playSingle(User $user, string $game, string $source, callable $resultFactory, ?string $requestId = null, ?int $subscriptionId = null): array
    {
        return DB::transaction(function () use ($user, $game, $source, $resultFactory, $requestId, $subscriptionId) {
            if ((int)config('v2board.reward_enable', 1) !== 1) throw new RuntimeException('奖励功能已关闭');
            $user = User::where('id', $user->id)->lockForUpdate()->firstOrFail();
            $subscription = $this->activeSubscription($user, $subscriptionId);
            $this->assertGameEnabled($game);
            $day = date('Y-m-d');
            $requestId = trim((string)$requestId);
            if ($requestId !== '' && !preg_match('/^[A-Za-z0-9._:-]{8,100}$/', $requestId)) throw new RuntimeException('请求标识格式无效');
            $key = 'game:' . $game . ':' . $subscription->id . ':' . $day . ':' . ($requestId !== '' ? $requestId : bin2hex(random_bytes(8)));
            $existing = TrafficRewardLog::where('unique_key', $key)->first();
            if ($existing) {
                $metadata = (array)$existing->metadata;
                return [
                    'game' => $game,
                    'result' => (array)($metadata['result'] ?? []),
                    'won' => (bool)($metadata['won'] ?? false),
                    'reward_gb' => (int)($metadata['payout_gb'] ?? $metadata['gb'] ?? 0),
                    'reward_bytes' => (int)$existing->reward_bytes,
                    'bet_gb' => (int)($metadata['bet_gb'] ?? self::MIN_GB),
                    'payout_gb' => (float)($metadata['payout_gb'] ?? 0),
                    'net_bytes' => (int)($metadata['net_bytes'] ?? $existing->reward_bytes),
                    'win_probability' => (int)($metadata['win_probability'] ?? $this->gameRule($game)['win_probability']),
                    'payout_multiplier' => (string)($metadata['payout_multiplier'] ?? $this->gameRule($game)['payout_multiplier']),
                    'expires_at' => $this->expiresAt($subscription),
                ];
            }
            $this->assertGameDailyLimit($user->id, $game, $day);
            $rule = $this->gameRule($game);
            $result = $resultFactory($rule);
            $settlement = $this->gameSettlement($game, $result, $user);
            $netBytes = $this->settleWager($user, $subscription, $settlement['bet_gb'], $settlement['payout_gb'], 'game', $source, $key, ['game' => $game, 'result' => $result, 'won' => $settlement['won'], 'win_probability' => $settlement['win_probability'], 'payout_multiplier' => $settlement['payout_multiplier']]);
            return ['game' => $game, 'result' => $result, 'won' => $settlement['won'], 'payout_gb' => $settlement['payout_gb'], 'net_bytes' => $netBytes, 'reward_gb' => $settlement['payout_gb'], 'reward_bytes' => $netBytes, 'bet_gb' => $settlement['bet_gb'], 'win_probability' => $settlement['win_probability'], 'payout_multiplier' => $settlement['payout_multiplier'], 'expires_at' => $this->expiresAt($subscription)];
        });
    }

    private function grant(User $user, Subscription $subscription, int $bytes, string $type, string $source, string $uniqueKey, array $metadata, int $maxGb = self::MAX_GB): void
    {
        if ($bytes < self::MIN_GB * self::GB || $bytes > $maxGb * self::GB) throw new RuntimeException('奖励流量超出范围');
        $subscription->transfer_enable = function_exists('bcadd') ? (int)bcadd((string)$subscription->transfer_enable, (string)$bytes) : (int)((float)$subscription->transfer_enable + $bytes);
        $subscription->save();
        if ($subscription->is_primary) {
            $user->transfer_enable = $subscription->transfer_enable;
            $user->save();
        }
        TrafficRewardLog::create([
            'user_id' => $user->id, 'subscription_id' => $subscription->id, 'source' => $type,
            'entrypoint' => $source, 'reward_bytes' => $bytes, 'unique_key' => $uniqueKey,
            'metadata' => array_merge(['event' => $type, 'entrypoint' => $source, 'net_bytes' => $bytes], $metadata), 'created_at' => time(), 'updated_at' => time(),
        ]);
    }

    private function activePrimary(User $user): Subscription
    {
        $subscription = (new SubscriptionService())->primary($user);
        if (!$subscription || $subscription->status !== 'active' || ($subscription->expired_at !== null && $subscription->expired_at <= time())) throw new RuntimeException('没有可用的有效订阅');
        return Subscription::where('id', $subscription->id)->lockForUpdate()->firstOrFail();
    }

    private function activeSubscription(User $user, ?int $subscriptionId = null): Subscription
    {
        if ($subscriptionId === null) return $this->activePrimary($user);
        $subscription = Subscription::where('id', $subscriptionId)
            ->where('user_id', $user->id)
            ->lockForUpdate()
            ->first();
        if (!$subscription || $subscription->status !== 'active' || ($subscription->expired_at !== null && $subscription->expired_at <= time())) {
            throw new RuntimeException('没有可用的有效订阅');
        }
        return $subscription;
    }

    private function streakDays(int $userId, int $subscriptionId, string $date): int
    {
        $previous = DailyCheckin::where('user_id', $userId)->where('subscription_id', $subscriptionId)->orderByDesc('checkin_date')->first();
        if (!$previous || $previous->checkin_date !== date('Y-m-d', strtotime($date . ' -1 day'))) return 1;
        return min(365, (int)$previous->streak_days + 1);
    }

    private function gameSettlement(string $game, $result, ?User $user = null): array
    {
        $bet = $user ? $this->userGameBetGb($user, $game) : self::MIN_GB;
        $rule = $this->gameRule($game);
        $matched = $game === 'dice'
            ? (int)$result === min(6, max(1, (int)config('v2board.reward_dice_win_face', 6)))
            : ($game === 'poker' ? (bool)$result : is_array($result) && count(array_unique($result)) === 1);
        $won = $matched && $this->chanceHit($rule['win_probability']);
        $payoutGb = $won ? $bet * (float)$rule['payout_multiplier'] : 0;
        return [
            'bet_gb' => $bet,
            'payout_gb' => $payoutGb,
            'won' => $won,
            'win_probability' => $rule['win_probability'],
            'payout_multiplier' => $rule['payout_multiplier'],
        ];
    }

    private function gameRule(string $game): array
    {
        $defaults = ['dice' => 10, 'slots' => 10, 'poker' => 5];
        if (!array_key_exists($game, $defaults)) throw new RuntimeException('不支持的游戏项目');
        $probabilityKey = 'reward_' . $game . '_win_probability';
        $legacyKey = 'reward_' . $game . '_odds';
        $probability = config()->has('v2board.' . $probabilityKey)
            ? config('v2board.' . $probabilityKey)
            : config('v2board.' . $legacyKey, $defaults[$game]);
        return [
            'enabled' => (int)config('v2board.reward_' . $game . '_enable', config('v2board.reward_enable', 1)) === 1,
            'win_probability' => self::normalizeProbability($probability, $defaults[$game]),
            'payout_multiplier' => self::normalizeMultiplier(config('v2board.reward_' . $game . '_payout_multiplier', '1.00')),
        ];
    }

    private function assertGameEnabled(string $game): void
    {
        if (!$this->gameRule($game)['enabled']) throw new RuntimeException('该游戏当前未启用');
    }

    private function chanceHit(int $probability): bool
    {
        return $probability >= 100 || ($probability > 0 && random_int(1, 100) <= $probability);
    }

    private function assertGameRuleAdministrator(User $actor, ?int $subscriptionId = null): void
    {
        $actor = User::findOrFail($actor->id);
        if ((int)$actor->is_admin !== 1) throw new RuntimeException('仅管理员订阅可管理游戏规则');
        if ($subscriptionId !== null) $this->activeSubscription($actor, $subscriptionId);
    }

    private function requiredTelegramAdministrator($telegramUserId, $chatId = null): array
    {
        $context = $this->telegramBindingContext($telegramUserId, $chatId);
        if (!$context || !$context['is_admin']) {
            throw new RuntimeException('绑定的订阅不具备管理员权限');
        }
        return $context;
    }

    private function userGameBetGb(User $user, string $game): int
    {
        $value = UserGameSetting::where('user_id', $user->id)->where('game', $game)->value('bet_gb');
        return self::normalizeGameGb($value ?? 1);
    }

    private function settleWager(User $user, Subscription $subscription, int $betGb, $payoutGb, string $type, string $source, string $uniqueKey, array $metadata): int
    {
        $betBytes = $betGb * self::GB;
        $payoutBytes = (int)round((float)$payoutGb * self::GB);
        $usedBytes = (int)$subscription->u + (int)$subscription->d;
        $availableBytes = (int)$subscription->transfer_enable - $usedBytes;
        if ($availableBytes < $betBytes) throw new RuntimeException('剩余流量不足，无法下注');
        // A winning wager grants the configured payout. Only a losing wager
        // deducts the stake, matching the user-facing game's stated semantics.
        $netBytes = $payoutBytes > 0 ? $payoutBytes : -$betBytes;
        $newTransferEnable = (int)$subscription->transfer_enable + $netBytes;
        if ($newTransferEnable < $usedBytes) throw new RuntimeException('剩余流量不足，无法完成结算');
        $subscription->transfer_enable = $newTransferEnable;
        $subscription->save();
        if ($subscription->is_primary) {
            $user->transfer_enable = $subscription->transfer_enable;
            $user->save();
        }
        TrafficRewardLog::create([
            'user_id' => $user->id, 'subscription_id' => $subscription->id, 'source' => $type,
            'entrypoint' => $source, 'reward_bytes' => $netBytes, 'unique_key' => $uniqueKey,
            'metadata' => array_merge(['event' => $type, 'entrypoint' => $source], $metadata, ['bet_gb' => $betGb, 'payout_gb' => (float)$payoutGb, 'bet_bytes' => $betBytes, 'payout_bytes' => $payoutBytes, 'net_bytes' => $netBytes]),
            'created_at' => time(), 'updated_at' => time(),
        ]);
        return $netBytes;
    }

    private function assertGameDailyLimit(int $userId, string $game, string $day): void
    {
        $limit = max(0, (int)config('v2board.reward_' . $game . '_daily_limit', 0));
        if ($limit === 0) return;
        $prefix = 'game:' . $game . ':';
        $count = TrafficRewardLog::where('user_id', $userId)
            ->where('source', 'game')
            ->where('unique_key', 'like', $prefix . '%')
            ->whereBetween('created_at', [strtotime($day), strtotime($day . ' +1 day') - 1])
            ->count();
        if ($count >= $limit) throw new RuntimeException('今日' . ($game === 'dice' ? '骰子' : '老虎机') . '次数已用完');
    }

    public function playPoker(User $user, string $chatId, string $action = 'join', string $source = 'telegram_group', ?int $subscriptionId = null): array
    {
        return DB::transaction(function () use ($user, $chatId, $action, $source, $subscriptionId) {
            if ((int)config('v2board.reward_enable', 1) !== 1 || (int)config('v2board.reward_group_enable', 0) !== 1) throw new RuntimeException('群组娱乐已关闭');
            $user = User::where('id', $user->id)->lockForUpdate()->firstOrFail();
            $subscription = $this->activeSubscription($user, $subscriptionId);
            $this->assertGameEnabled('poker');
            $room = GameRoom::where('chat_id', (string)$chatId)->where('game', 'poker')->where('status', 'open')->where(function ($q) { $q->whereNull('expires_at')->orWhere('expires_at', '>', time()); })->lockForUpdate()->first();
            if (!$room) {
                if ($action === 'start') throw new RuntimeException('没有可开始的开放牌局');
                $lockKey = 'poker:create:' . $chatId;
                $locked = \Illuminate\Support\Facades\Cache::lock($lockKey, 10)->get();
                if (!$locked) throw new RuntimeException('牌局创建中，请稍后重试');
                try {
                    $room = GameRoom::where('chat_id', (string)$chatId)->where('game', 'poker')->where('status', 'open')->where(function ($q) { $q->whereNull('expires_at')->orWhere('expires_at', '>', time()); })->lockForUpdate()->first();
                    if (!$room) {
                        $room = GameRoom::create(['chat_id' => (string)$chatId, 'game' => 'poker', 'status' => 'open', 'players' => [], 'expires_at' => time() + 1800, 'created_at' => time(), 'updated_at' => time()]);
                    }
                } finally {
                    \Illuminate\Support\Facades\Cache::lock($lockKey)->forceRelease();
                }
            }
            $players = (array)$room->players;
            $ids = array_map('intval', array_column($players, 'user_id'));
            if (!in_array((int)$user->id, $ids, true)) {
                if (count($players) >= 6) throw new RuntimeException('牌局人数已满');
                $players[] = ['user_id' => (int)$user->id, 'subscription_id' => (int)$subscription->id];
                $room->players = $players; $room->save();
            }
            if ($action !== 'start') return ['status' => 'open', 'room_id' => $room->id, 'players' => count($players)];
            if (count($players) < 2) throw new RuntimeException('至少需要两名玩家才能开始');
            $hands = []; $winner = null; $winnerScore = -1;
            foreach ($players as $player) {
                $cards = [random_int(1, 13), random_int(1, 13), random_int(1, 13)];
                sort($cards); $score = count(array_unique($cards)) === 1 ? 100 + $cards[0] : (count(array_unique($cards)) === 2 ? 50 + max($cards) : max($cards));
                $hands[$player['user_id']] = ['cards' => $cards, 'score' => $score];
                if ($score >= $winnerScore) { $winnerScore = $score; $winner = (int)$player['user_id']; }
            }
            $playerIds = array_map('intval', array_column($players, 'user_id'));
            $playerUsers = User::whereIn('id', $playerIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $playerSubscriptions = [];
            foreach ($playerIds as $playerId) {
                if (!$playerUsers->has($playerId)) throw new RuntimeException('牌局中存在无效玩家');
                $player = collect($players)->firstWhere('user_id', $playerId);
                $playerSubscriptions[$playerId] = $this->activeSubscription($playerUsers->get($playerId), (int)($player['subscription_id'] ?? 0));
                $this->assertPokerDailyLimit($playerId, date('Y-m-d'));
            }
            $winnerSettlement = null;
            $winnerNetBytes = 0;
            foreach ($playerIds as $playerId) {
                $settlement = $this->gameSettlement('poker', $playerId === $winner, $playerUsers->get($playerId));
                $key = 'poker:' . $room->id . ':' . $playerId . ':' . bin2hex(random_bytes(8));
                $netBytes = $this->settleWager($playerUsers->get($playerId), $playerSubscriptions[$playerId], $settlement['bet_gb'], $settlement['payout_gb'], 'game', $source, $key, ['game' => 'poker', 'room_id' => $room->id, 'hands' => $hands, 'winner_user_id' => $winner, 'won' => $settlement['won'], 'win_probability' => $settlement['win_probability'], 'payout_multiplier' => $settlement['payout_multiplier']]);
                if ($playerId === $winner) {
                    $winnerSettlement = $settlement;
                    $winnerNetBytes = $netBytes;
                }
            }
            $room->status = 'settled'; $room->result = ['winner' => $winner, 'hands' => $hands]; $room->save();
            return ['status' => 'settled', 'room_id' => $room->id, 'winner_user_id' => $winner, 'won' => $winnerSettlement['won'], 'payout_gb' => $winnerSettlement['payout_gb'], 'net_bytes' => $winnerNetBytes, 'reward_gb' => $winnerSettlement['payout_gb'], 'reward_bytes' => $winnerNetBytes, 'bet_gb' => $winnerSettlement['bet_gb'], 'win_probability' => $winnerSettlement['win_probability'], 'payout_multiplier' => $winnerSettlement['payout_multiplier']];
        });
    }

    private function assertPokerDailyLimit(int $userId, string $day): void
    {
        $limit = max(0, (int)config('v2board.reward_poker_daily_limit', 0));
        if ($limit === 0) return;
        $count = TrafficRewardLog::where('user_id', $userId)
            ->where('source', 'game')
            ->where('unique_key', 'like', 'poker:%')
            ->whereBetween('created_at', [strtotime($day), strtotime($day . ' +1 day') - 1])
            ->count();
        if ($count >= $limit) throw new RuntimeException('今日炸金花次数已用完');
    }

    private function expiresAt(Subscription $subscription): ?int
    {
        return $subscription->expired_at ? (int)$subscription->expired_at : null;
    }
}
