<?php

namespace App\Services;

use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Telegram's reward adapter. This is intentionally separate from
 * TrafficRewardService: Telegram identity is authorized through a live
 * subscription binding. Legacy /bind users are bridged only after their
 * existing Telegram identity and active primary subscription are verified.
 */
class TelegramRewardService
{
    private const STATE_TTL = 900;

    private const BET_OPTIONS = [1, 5, 10, 50, 100, 500, 1000];
    private const DAILY_LIMIT_OPTIONS = [0, 1, 3, 5, 10, 20, 50, 100];
    private const PROBABILITY_OPTIONS = ['0.00', '0.01', '0.10', '0.50', '1.00', '5.00', '10.00', '25.00', '50.00', '75.00', '100.00'];
    private const MULTIPLIER_OPTIONS = ['1', '1.5', '2', '3', '5', '10'];

    private $telegram;
    private $rewards;

    public function __construct(?TelegramService $telegram = null, ?TrafficRewardService $rewards = null)
    {
        $this->telegram = $telegram ?: new TelegramService();
        $this->rewards = $rewards ?: new TrafficRewardService();
    }

    public function showMenu(int $chatId, $telegramUserId): void
    {
        $context = $this->boundContext($telegramUserId);
        $buttons = [
            [
                $this->button('每日签到', 'rw:c'),
                $this->button('骰子', 'rw:g:d'),
            ],
            [
                $this->button('老虎机', 'rw:g:s'),
                $this->button('炸金花', 'rw:g:p'),
            ],
        ];
        if ($context['is_admin']) {
            $buttons[] = [$this->button('管理员游戏规则', 'rw:a')];
        }
        $this->send($chatId, "娱乐中心\n请选择项目。所有结算均会写入流量明细。", $buttons);
    }

    public function showGame(int $chatId, $telegramUserId, string $game): void
    {
        $context = $this->boundContext($telegramUserId);
        $game = $this->game($game);
        $this->assertGameEnabled($game);
        $settings = $this->rewards->gameSettings($context['user']);
        $bet = (int)$settings[$game . '_bet_gb'];
        $this->send($chatId, sprintf("%s\n当前赌注：%d GB", $this->label($game), $bet), array_merge([
            [$this->button('开始', 'rw:go:' . $this->code($game)), $this->button('设置赌注', 'rw:b:' . $this->code($game))],
        ], [
            [$this->button('返回娱乐中心', 'rw:m')],
        ]));
    }

    public function showBets(int $chatId, $telegramUserId, string $game): void
    {
        $this->boundContext($telegramUserId);
        $game = $this->game($game);
        $code = $this->code($game);
        $rows = [];
        foreach (array_chunk(self::BET_OPTIONS, 4) as $chunk) {
            $row = [];
            foreach ($chunk as $bet) $row[] = $this->button($bet . ' GB', 'rw:v:' . $code . ':' . $bet);
            $rows[] = $row;
        }
        $rows[] = [$this->button('返回项目', 'rw:g:' . $code)];
        $this->send($chatId, $this->label($game) . "赌注设置\n请选择本项目每局赌注。", $rows);
    }

    public function checkin(int $chatId, $telegramUserId): void
    {
        $context = $this->boundContext($telegramUserId);
        $result = $this->rewards->checkin($context['user'], 'telegram', $context['subscription_id']);
        $this->send($chatId, sprintf(
            "签到成功\n增加流量：%s GB\n过期时间：%s",
            $this->number($result['reward_gb']),
            $result['expires_at'] ? date('Y-m-d H:i:s', $result['expires_at']) : '订阅到期'
        ), [[$this->button('返回娱乐中心', 'rw:m')]]);
    }

    public function play(int $chatId, $telegramUserId, string $game, string $requestId): void
    {
        $context = $this->boundContext($telegramUserId);
        $game = $this->game($game);
        $this->assertGameEnabled($game);
        if ($game === 'dice') {
            $result = $this->rewards->playDice($context['user'], 'telegram', $requestId, $context['subscription_id']);
            $headline = '骰子点数：' . (int)$result['result'];
        } elseif ($game === 'slots') {
            $result = $this->rewards->playSlots($context['user'], 'telegram', $requestId, $context['subscription_id']);
            $headline = '老虎机结果：' . implode(' | ', (array)$result['result']);
        } else {
            $result = $this->rewards->playPokerSolo($context['user'], 'telegram', $requestId, $context['subscription_id']);
            $headline = '炸金花结果：' . implode(' | ', (array)$result['result']);
        }
        $net = array_key_exists('net_bytes', $result) ? (int)$result['net_bytes'] : (int)($result['reward_bytes'] ?? 0);
        $this->send($chatId, sprintf(
            "%s\n%s\n赌注：%s GB\n赔付：%s GB\n净变化：%s%s GB",
            $headline,
            !empty($result['won']) ? '本局中奖' : '本局未中奖',
            $this->number($result['bet_gb'] ?? 0),
            $this->number($result['payout_gb'] ?? $result['reward_gb'] ?? 0),
            $net >= 0 ? '+' : '-',
            $this->number(abs($net) / TrafficRewardService::GB)
        ), [[$this->button('返回娱乐中心', 'rw:m')]]);
    }

    public function setBet(int $chatId, $telegramUserId, string $game, int $bet): void
    {
        if (!in_array($bet, self::BET_OPTIONS, true)) throw new RuntimeException('赌注选项无效');
        $context = $this->boundContext($telegramUserId);
        $game = $this->game($game);
        $this->rewards->saveGameWager($context['user'], $game, $bet, $context['subscription_id']);
        $this->send($chatId, sprintf("%s赌注已设置为 %d GB。", $this->label($game), $bet), [
            [$this->button('返回项目', 'rw:g:' . $this->code($game))],
        ]);
    }

    public function showAdminMenu(int $chatId, $telegramUserId): void
    {
        $this->administrator($telegramUserId);
        $this->send($chatId, "管理员游戏规则\n请选择需要调整的项目。", [
            [$this->button('骰子', 'rw:e:d'), $this->button('老虎机', 'rw:e:s'), $this->button('炸金花', 'rw:e:p')],
            [$this->button('返回娱乐中心', 'rw:m')],
        ]);
    }

    public function beginRuleEdit(int $chatId, $telegramUserId, string $game): void
    {
        $context = $this->administrator($telegramUserId);
        $game = $this->game($game);
        $rules = $this->rewards->gameRulesForAdministrator($context['user'], $context['subscription_id']);
        $token = $this->newState($telegramUserId, $context, $game, $rules[$game]);
        $this->showRuleEditor($chatId, $telegramUserId, $token);
    }

    public function updateRuleState(int $chatId, $telegramUserId, string $token, string $field, string $value): void
    {
        $state = $this->state($telegramUserId, $token, true);
        if ($field === 'p') {
            $value = is_numeric($value) ? number_format((float)$value, 2, '.', '') : '';
            if (!in_array($value, self::PROBABILITY_OPTIONS, true)) throw new RuntimeException('中奖概率选项无效');
            $state['probability'] = $value;
        } elseif ($field === 'x') {
            if (!in_array($value, self::MULTIPLIER_OPTIONS, true)) throw new RuntimeException('赔付倍率选项无效');
            $state['multiplier'] = $value;
        } elseif ($field === 'n') {
            if (!in_array($value, ['0', '1'], true)) throw new RuntimeException('游戏启用状态无效');
            $state['enabled'] = $value === '1';
        } elseif ($field === 'l') {
            $value = filter_var($value, FILTER_VALIDATE_INT);
            if (!in_array($value, self::DAILY_LIMIT_OPTIONS, true)) throw new RuntimeException('每日次数选项无效');
            $state['daily_limit'] = $value;
        } else {
            throw new RuntimeException('规则动作无效');
        }
        $this->putState($token, $state);
        $this->showRuleEditor($chatId, $telegramUserId, $token);
    }

    public function saveRule(int $chatId, $telegramUserId, string $token): void
    {
        $state = $this->state($telegramUserId, $token, true);
        $context = $this->administrator($telegramUserId);
        if ((int)$state['user_id'] !== (int)$context['user']->id
            || (int)$state['subscription_id'] !== (int)$context['subscription_id']) {
            throw new RuntimeException('绑定订阅已变更，请重新打开管理员规则');
        }
        $this->rewards->saveGameRuleForAdministrator(
            $context['user'],
            $state['game'],
            $state['probability'],
            $state['multiplier'],
            $context['subscription_id'],
            $state['enabled'],
            $state['daily_limit'] ?? 0
        );
        Cache::forget($this->stateKey($token));
        $this->send($chatId, sprintf(
            "%s规则已保存\n状态：%s\n每日次数：%s\n条件后中奖概率：%s%%\n赔付倍率：%sx",
            $this->label($state['game']),
            $state['enabled'] ? '已启用' : '已停用',
            $this->dailyLimit($state['daily_limit'] ?? 0),
            $state['probability'],
            $state['multiplier']
        ), [[$this->button('返回管理员规则', 'rw:a')]]);
    }

    public function handleCallback(array $callback): void
    {
        $queryId = (string)($callback['id'] ?? '');
        $chat = (array)($callback['message']['chat'] ?? []);
        $from = (array)($callback['from'] ?? []);
        $chatId = (int)($chat['id'] ?? 0);
        $telegramUserId = (string)($from['id'] ?? '');
        try {
            if (($chat['type'] ?? '') !== 'private' || $chatId <= 0 || $telegramUserId === '') {
                throw new RuntimeException('请在私聊中使用娱乐功能');
            }
        } catch (\Throwable $e) {
            $this->callbackFailure($queryId, $chatId, $e);
            return;
        }

        // Telegram acknowledgement improves button responsiveness, but a transient
        // acknowledgement failure must not discard an already delivered callback.
        try { $this->telegram->answerCallbackQuery($queryId); } catch (\Throwable $ignored) {}

        try {
            $this->dispatchCallback($chatId, $telegramUserId, (string)($callback['data'] ?? ''), (int)($callback['message']['message_id'] ?? 0));
        } catch (\Throwable $e) {
            $this->callbackFailure($queryId, $chatId, $e);
        }
    }

    private function callbackFailure(string $queryId, int $chatId, \Throwable $e): void
    {
        try { $this->telegram->answerCallbackQuery($queryId, '操作未完成', true); } catch (\Throwable $ignored) {}
        if ($chatId <= 0) return;
        try { $this->send($chatId, '操作失败：' . $this->safeMessage($e)); } catch (\Throwable $ignored) {}
    }

    private function dispatchCallback(int $chatId, string $telegramUserId, string $data, int $messageId): void
    {
        if ($data === 'rw:m') { $this->showMenu($chatId, $telegramUserId); return; }
        if ($data === 'rw:c') { $this->checkin($chatId, $telegramUserId); return; }
        if ($data === 'rw:a') { $this->showAdminMenu($chatId, $telegramUserId); return; }
        if (preg_match('/^rw:([gbe]):([dsp])$/', $data, $match)) {
            if ($match[1] === 'g') { $this->showGame($chatId, $telegramUserId, $match[2]); return; }
            if ($match[1] === 'b') { $this->showBets($chatId, $telegramUserId, $match[2]); return; }
            $this->beginRuleEdit($chatId, $telegramUserId, $match[2]);
            return;
        }
        if (preg_match('/^rw:v:([dsp]):(\d{1,4})$/', $data, $match)) {
            $this->setBet($chatId, $telegramUserId, $match[1], (int)$match[2]);
            return;
        }
        if (preg_match('/^rw:([pxnl]):([A-Za-z0-9_-]{8,16}):([0-9.]{1,6})$/', $data, $match)) {
            $this->updateRuleState($chatId, $telegramUserId, $match[2], $match[1], $match[3]);
            return;
        }
        if (preg_match('/^rw:s:([A-Za-z0-9_-]{8,16})$/', $data, $match)) {
            $this->saveRule($chatId, $telegramUserId, $match[1]);
            return;
        }
        if (preg_match('/^rw:go:([dsp])$/', $data, $match)) {
            $this->play($chatId, $telegramUserId, $match[1], 'telegram-callback-' . $messageId);
            return;
        }
        throw new RuntimeException('交互已失效，请重新打开娱乐中心');
    }

    private function showRuleEditor(int $chatId, string $telegramUserId, string $token): void
    {
        $state = $this->state($telegramUserId, $token, true);
        $probabilityButtons = [];
        foreach (self::PROBABILITY_OPTIONS as $option) {
            $probabilityButtons[] = $this->button($option . '%', 'rw:p:' . $token . ':' . $option);
        }
        $multiplierButtons = [];
        foreach (self::MULTIPLIER_OPTIONS as $option) {
            $multiplierButtons[] = $this->button($option . 'x', 'rw:x:' . $token . ':' . $option);
        }
        $limitButtons = [];
        foreach (self::DAILY_LIMIT_OPTIONS as $option) {
            $limitButtons[] = $this->button($option === 0 ? '不限' : $option . '次', 'rw:l:' . $token . ':' . $option);
        }
        $this->send($chatId, sprintf(
            "%s规则\n状态：%s\n每日次数：%s\n条件后中奖概率：%s%%\n赔付倍率：%sx\n骰子和老虎机需先满足触发条件；单人炸金花直接判定，群组炸金花需先胜出。",
            $this->label($state['game']), $state['enabled'] ? '已启用' : '已停用', $this->dailyLimit($state['daily_limit'] ?? 0), $state['probability'], $state['multiplier']
        ), [
            [$this->button($state['enabled'] ? '停用项目' : '启用项目', 'rw:n:' . $token . ':' . ($state['enabled'] ? '0' : '1'))],
            array_slice($limitButtons, 0, 4),
            array_slice($limitButtons, 4),
            array_slice($probabilityButtons, 0, 4),
            array_slice($probabilityButtons, 4, 4),
            array_slice($probabilityButtons, 8),
            $multiplierButtons,
            [$this->button('保存规则', 'rw:s:' . $token), $this->button('取消', 'rw:a')],
        ]);
    }

    private function boundContext($telegramUserId): array
    {
        $context = Schema::hasTable('v2_telegram_subscription_binding')
            ? $this->rewards->telegramBindingContext($telegramUserId)
            : null;
        if ($context && empty($context['user']->banned)) return $context;

        $legacyContext = $this->legacyBoundContext($telegramUserId);
        if ($legacyContext) return $legacyContext;

        throw new RuntimeException('请先在网站绑定有效订阅');
    }

    private function legacyBoundContext($telegramUserId): ?array
    {
        $telegramUserId = trim((string)$telegramUserId);
        if ($telegramUserId === '' || !ctype_digit($telegramUserId)) return null;

        $user = User::where('telegram_id', $telegramUserId)->first();
        if (!$user || $user->banned) return null;

        $subscription = (new SubscriptionService())->primary($user);
        if (!$subscription || !$this->activeSubscription($subscription)) return null;

        return [
            'user' => $user,
            'subscription_id' => (int)$subscription->id,
            'is_admin' => (int)$user->is_admin === 1,
        ];
    }

    private function administrator($telegramUserId): array
    {
        $context = $this->boundContext($telegramUserId);
        if (!$context['is_admin']) throw new RuntimeException('当前绑定订阅没有管理权限');
        return $context;
    }

    private function activeSubscription(Subscription $subscription): bool
    {
        return $subscription->status === 'active'
            && (!$subscription->expired_at || (int)$subscription->expired_at > time());
    }

    private function assertGameEnabled(string $game): void
    {
        $rules = $this->rewards->gameRules();
        if (empty($rules[$game]) || empty($rules[$game]['enabled'])) {
            throw new RuntimeException('该游戏当前未启用');
        }
    }

    private function newState($telegramUserId, array $context, string $game, array $rule): string
    {
        $token = rtrim(strtr(base64_encode(random_bytes(9)), '+/', '-_'), '=');
        $this->putState($token, [
            'telegram_user_id' => (string)$telegramUserId,
            'user_id' => (int)$context['user']->id,
            'subscription_id' => (int)$context['subscription_id'],
            'game' => $game,
            'enabled' => (bool)($rule['enabled'] ?? true),
            'daily_limit' => (int)($rule['daily_limit'] ?? 0),
            'probability' => (string)$rule['win_probability'],
            'multiplier' => (string)$rule['payout_multiplier'],
        ]);
        return $token;
    }

    private function state(string $telegramUserId, string $token, bool $administrator): array
    {
        $state = Cache::get($this->stateKey($token));
        if (!is_array($state) || !hash_equals((string)$state['telegram_user_id'], $telegramUserId)) {
            throw new RuntimeException('交互已失效，请重新打开管理员规则');
        }
        if ($administrator) {
            $context = $this->administrator($telegramUserId);
            if ((int)$state['user_id'] !== (int)$context['user']->id
                || (int)$state['subscription_id'] !== (int)$context['subscription_id']) {
                throw new RuntimeException('绑定订阅已变更，请重新打开管理员规则');
            }
        }
        return $state;
    }

    private function putState(string $token, array $state): void
    {
        Cache::put($this->stateKey($token), $state, self::STATE_TTL);
    }

    private function stateKey(string $token): string
    {
        return 'telegram_reward_state:' . $token;
    }

    private function game(string $code): string
    {
        $games = ['d' => 'dice', 's' => 'slots', 'p' => 'poker', 'dice' => 'dice', 'slots' => 'slots', 'poker' => 'poker'];
        if (!isset($games[$code])) throw new RuntimeException('不支持的游戏项目');
        return $games[$code];
    }

    private function code(string $game): string
    {
        return ['dice' => 'd', 'slots' => 's', 'poker' => 'p'][$game];
    }

    private function label(string $game): string
    {
        return ['dice' => '骰子', 'slots' => '老虎机', 'poker' => '炸金花'][$game];
    }

    private function button(string $text, string $callbackData): array
    {
        return ['text' => $text, 'callback_data' => $callbackData];
    }

    private function send(int $chatId, string $text, array $buttons = []): void
    {
        $this->telegram->sendMessage($chatId, $text, '', $buttons ? ['inline_keyboard' => $buttons] : null);
    }

    private function number($value): string
    {
        $value = (float)$value;
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }

    private function dailyLimit($limit): string
    {
        return (int)$limit === 0 ? '不限' : (int)$limit . ' 次';
    }

    private function safeMessage(\Throwable $e): string
    {
        return $e instanceof RuntimeException ? $e->getMessage() : '服务暂时不可用，请稍后重试';
    }
}
