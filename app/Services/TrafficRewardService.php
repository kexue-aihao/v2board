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
        return min(self::MAX_GB, max(self::MIN_GB, (int)$value));
    }

    private static function normalizeProbability($value, int $fallback = 100): int
    {
        $value = filter_var($value, FILTER_VALIDATE_INT);
        if ($value === false) $value = $fallback;
        return min(100, max(0, (int)$value));
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

    public function checkinStatus(User $user): array
    {
        try {
            $subscription = $this->activePrimary($user);
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

    public function checkin(User $user, string $source = 'web'): array
    {
        return DB::transaction(function () use ($user, $source) {
            if ((int)config('v2board.reward_enable', 1) !== 1) throw new RuntimeException('奖励功能已关闭');
            $user = User::where('id', $user->id)->lockForUpdate()->firstOrFail();
            $subscription = $this->activePrimary($user);
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
            $this->grant($user, $subscription, $rewardBytes, 'checkin', $source, $uniqueKey, ['gb' => $rewardGb, 'checkin_id' => $checkin->id]);
            return ['reward_gb' => $rewardGb, 'reward_bytes' => $rewardBytes, 'expires_at' => $this->expiresAt($subscription)];
        });
    }

    public function playDice(User $user, string $source = 'web', ?string $requestId = null): array
    {
        return $this->playSingle($user, 'dice', $source, function () { return random_int(1, 6); }, $requestId);
    }

    public function playSlots(User $user, string $source = 'web', ?string $requestId = null): array
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
        }, $requestId);
    }

    private function playSingle(User $user, string $game, string $source, callable $resultFactory, ?string $requestId = null): array
    {
        return DB::transaction(function () use ($user, $game, $source, $resultFactory, $requestId) {
            if ((int)config('v2board.reward_enable', 1) !== 1) throw new RuntimeException('奖励功能已关闭');
            $user = User::where('id', $user->id)->lockForUpdate()->firstOrFail();
            $subscription = $this->activePrimary($user);
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
                    'payout_gb' => (int)($metadata['payout_gb'] ?? $metadata['gb'] ?? 0),
                    'expires_at' => $this->expiresAt($subscription),
                ];
            }
            $this->assertGameDailyLimit($user->id, $game, $day);
            $result = $resultFactory();
            $settlement = $this->gameSettlement($game, $result, $user);
            $netBytes = $this->settleWager($user, $subscription, $settlement['bet_gb'], $settlement['payout_gb'], 'game', $source, $key, ['game' => $game, 'result' => $result, 'won' => $settlement['won']]);
            return ['game' => $game, 'result' => $result, 'won' => $settlement['won'], 'reward_gb' => $settlement['payout_gb'], 'reward_bytes' => $netBytes, 'bet_gb' => $settlement['bet_gb'], 'payout_gb' => $settlement['payout_gb'], 'expires_at' => $this->expiresAt($subscription)];
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
            'metadata' => $metadata, 'created_at' => time(), 'updated_at' => time(),
        ]);
    }

    private function activePrimary(User $user): Subscription
    {
        $subscription = (new SubscriptionService())->primary($user);
        if (!$subscription || $subscription->status !== 'active' || ($subscription->expired_at !== null && $subscription->expired_at <= time())) throw new RuntimeException('没有可用的有效订阅');
        return Subscription::where('id', $subscription->id)->lockForUpdate()->firstOrFail();
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
        $probability = self::normalizeProbability(config('v2board.reward_' . $game . '_odds', $game === 'poker' ? 5 : 10), $game === 'poker' ? 5 : 10);
        $matched = $game === 'dice'
            ? (int)$result === min(6, max(1, (int)config('v2board.reward_dice_win_face', 6)))
            : ($game === 'poker' ? (bool)$result : is_array($result) && count(array_unique($result)) === 1);
        $won = $matched && ($probability >= 100 || ($probability > 0 && random_int(1, 100) <= $probability));
        return ['bet_gb' => $bet, 'payout_gb' => $won ? min(self::MAX_GAME_GB, $bet * $probability) : 0, 'won' => $won];
    }

    private function userGameBetGb(User $user, string $game): int
    {
        $value = UserGameSetting::where('user_id', $user->id)->where('game', $game)->value('bet_gb');
        return self::normalizeGameGb($value ?? 1);
    }

    private function settleWager(User $user, Subscription $subscription, int $betGb, int $payoutGb, string $type, string $source, string $uniqueKey, array $metadata): int
    {
        $betBytes = $betGb * self::GB;
        $payoutBytes = $payoutGb * self::GB;
        $usedBytes = (int)$subscription->u + (int)$subscription->d;
        $availableBytes = (int)$subscription->transfer_enable - $usedBytes;
        if ($availableBytes < $betBytes) throw new RuntimeException('剩余流量不足，无法下注');
        $netBytes = $payoutBytes - $betBytes;
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
            'metadata' => array_merge($metadata, ['bet_gb' => $betGb, 'payout_gb' => $payoutGb, 'gb' => $payoutGb, 'bet_bytes' => $betBytes, 'payout_bytes' => $payoutBytes, 'net_bytes' => $netBytes]),
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

    public function playPoker(User $user, string $chatId, string $action = 'join', string $source = 'telegram_group'): array
    {
        return DB::transaction(function () use ($user, $chatId, $action, $source) {
            if ((int)config('v2board.reward_enable', 1) !== 1 || (int)config('v2board.reward_group_enable', 0) !== 1) throw new RuntimeException('群组娱乐已关闭');
            $user = User::where('id', $user->id)->lockForUpdate()->firstOrFail();
            $subscription = $this->activePrimary($user);
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
                $players[] = ['user_id' => (int)$user->id, 'subscription_id' => (int)$subscription->id, 'telegram_id' => (string)$chatId];
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
                $playerSubscriptions[$playerId] = $this->activePrimary($playerUsers->get($playerId));
                $this->assertPokerDailyLimit($playerId, date('Y-m-d'));
            }
            $winnerSettlement = null;
            $winnerNetBytes = 0;
            foreach ($playerIds as $playerId) {
                $settlement = $this->gameSettlement('poker', $playerId === $winner, $playerUsers->get($playerId));
                $key = 'poker:' . $room->id . ':' . $playerId . ':' . bin2hex(random_bytes(8));
                $netBytes = $this->settleWager($playerUsers->get($playerId), $playerSubscriptions[$playerId], $settlement['bet_gb'], $settlement['payout_gb'], 'game', $source, $key, ['game' => 'poker', 'room_id' => $room->id, 'hands' => $hands, 'winner_user_id' => $winner, 'won' => $settlement['won']]);
                if ($playerId === $winner) {
                    $winnerSettlement = $settlement;
                    $winnerNetBytes = $netBytes;
                }
            }
            $room->status = 'settled'; $room->result = ['winner' => $winner, 'hands' => $hands]; $room->save();
            return ['status' => 'settled', 'room_id' => $room->id, 'winner_user_id' => $winner, 'won' => $winnerSettlement['won'], 'reward_gb' => $winnerSettlement['payout_gb'], 'reward_bytes' => $winnerNetBytes, 'bet_gb' => $winnerSettlement['bet_gb'], 'payout_gb' => $winnerSettlement['payout_gb']];
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
