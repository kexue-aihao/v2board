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
        $this->assertGroupAvailable($chatId);
        $context = $this->boundContext($telegramUserId);
        $buttons = [
            [
                $this->button('每日签到', 'rw:c'),
                $this->button('骰子', 'rw:g:d'),
            ],
            [
                $this->button('老虎机', 'rw:g:s'),
                $this->button($chatId < 0 ? '群组炸金花' : '炸金花', 'rw:g:p'),
            ],
        ];
        if ($context['is_admin']) {
            $buttons[] = [$this->button('管理员游戏规则', 'rw:a')];
        }
        $this->send($chatId, "娱乐中心\n签到规则：每日可签到一次，随机增加 1-10 GB。\n请选择项目查看游戏规则。所有结算均会写入流量明细。", $buttons);
    }

    public function showGame(int $chatId, $telegramUserId, string $game): void
    {
        $this->assertGroupAvailable($chatId);
        $context = $this->boundContext($telegramUserId);
        $game = $this->game($game);
        $this->assertGameEnabled($game);
        $settings = $this->rewards->gameSettings($context['user']);
        $bet = (int)$settings[$game . '_bet_gb'];
        $actionSuffix = $this->gameActionSuffix($chatId, $telegramUserId, $game);
        $buttons = $game === 'dice'
            ? [
                [$this->button('1', 'rw:dg:1' . $actionSuffix), $this->button('2', 'rw:dg:2' . $actionSuffix), $this->button('3', 'rw:dg:3' . $actionSuffix)],
                [$this->button('4', 'rw:dg:4' . $actionSuffix), $this->button('5', 'rw:dg:5' . $actionSuffix), $this->button('6', 'rw:dg:6' . $actionSuffix)],
                [$this->button('设置赌注', 'rw:b:d' . $actionSuffix)],
            ]
            : [[
                $this->button('开始', 'rw:go:' . $this->code($game) . $actionSuffix),
                $this->button('设置赌注', 'rw:b:' . $this->code($game) . $actionSuffix),
            ]];
        $this->send($chatId, sprintf("%s\n%s", $this->label($game), $this->gameRulesText($game, $bet)), array_merge($buttons, [
            [$this->button('返回娱乐中心', 'rw:m')],
        ]));
    }

    public function showBets(int $chatId, $telegramUserId, string $game): void
    {
        $this->assertGroupAvailable($chatId);
        $this->boundContext($telegramUserId);
        $game = $this->game($game);
        $code = $this->code($game);
        $actionSuffix = $this->gameActionSuffix($chatId, $telegramUserId, $game);
        $rows = [];
        foreach (array_chunk(self::BET_OPTIONS, 4) as $chunk) {
            $row = [];
            foreach ($chunk as $bet) $row[] = $this->button($bet . ' GB', 'rw:v:' . $code . ':' . $bet . $actionSuffix);
            $rows[] = $row;
        }
        $rows[] = [$this->button('返回项目', 'rw:g:' . $code)];
        $this->send($chatId, $this->label($game) . "赌注设置\n请选择本项目每局赌注。", $rows);
    }

    public function checkin(int $chatId, $telegramUserId): void
    {
        $this->assertGroupAvailable($chatId);
        $context = $this->boundContext($telegramUserId);
        $result = $this->rewards->checkin($context['user'], $this->entrypoint($chatId), $context['subscription_id']);
        $this->send($chatId, sprintf(
            "签到成功\n增加流量：%s GB\n过期时间：%s\n\n%s",
            $this->number($result['reward_gb']),
            $result['expires_at'] ? date('Y-m-d H:i:s', $result['expires_at']) : '订阅到期',
            $this->checkinRulesText()
        ), [[$this->button('返回娱乐中心', 'rw:m')]]);
    }

    public function play(int $chatId, $telegramUserId, string $game, string $requestId, ?int $diceGuess = null): void
    {
        $this->assertGroupAvailable($chatId);
        $context = $this->boundContext($telegramUserId);
        $game = $this->game($game);
        $this->assertGameEnabled($game);
        $entrypoint = $this->entrypoint($chatId);
        if ($game === 'dice') {
            if ($diceGuess === null) throw new RuntimeException('请选择猜测点数');
            $result = $this->rewards->playDice($context['user'], $entrypoint, $requestId, $context['subscription_id'], $diceGuess);
            $headline = '猜测点数：' . (int)($result['guess'] ?? $diceGuess) . "\n骰子点数：" . (int)$result['result'];
        } elseif ($game === 'slots') {
            $result = $this->rewards->playSlots($context['user'], $entrypoint, $requestId, $context['subscription_id']);
            $headline = '老虎机结果：' . implode(' | ', (array)$result['result']);
        } else {
            $result = $this->rewards->playPokerSolo($context['user'], $entrypoint, $requestId, $context['subscription_id']);
            $headline = '炸金花结果：' . implode(' | ', (array)$result['result']);
        }
        if (!empty($result['replayed'])) return;
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

    public function playDiceWithGuess(int $chatId, $telegramUserId, int $guess, ?string $requestId = null): void
    {
        if ($guess < 1 || $guess > 6) throw new RuntimeException('猜测点数必须为 1-6');
        $requestId = $requestId ?: 'telegram-command-' . $chatId . '-' . $telegramUserId . '-' . bin2hex(random_bytes(6));
        $this->play($chatId, $telegramUserId, 'dice', $requestId, $guess);
    }

    public function playGroupPoker(int $chatId, $telegramUserId, bool $start = false): void
    {
        if ($chatId === 0) throw new RuntimeException('群组标识无效');
        $this->assertGroupAvailable($chatId);

        $context = $this->boundContext($telegramUserId);
        $result = $this->rewards->playPoker(
            $context['user'],
            (string)$chatId,
            $start ? 'start' : 'join',
            'telegram_group',
            $context['subscription_id']
        );

        if (($result['status'] ?? '') === 'open') {
            $this->send($chatId, sprintf(
                "炸金花牌局已加入\n当前玩家：%d 人\n%s\n其他玩家发送 /poker 加入，任意已加入玩家发送 /poker start 开始。",
                (int)($result['players'] ?? 0),
                $this->gameRulesText('poker', null, true)
            ));
            return;
        }

        $net = (int)($result['net_bytes'] ?? 0);
        $this->send($chatId, sprintf(
            "炸金花牌局已结算\n%s\n获胜用户：%d\n%s\n获胜方赔付：%s GB\n获胜方净变化：%s%s GB",
            $this->gameRulesText('poker', null, true),
            (int)($result['winner_user_id'] ?? 0),
            !empty($result['won']) ? '获胜方中奖' : '获胜方未中奖',
            $this->number($result['payout_gb'] ?? 0),
            $net >= 0 ? '+' : '-',
            $this->number(abs($net) / TrafficRewardService::GB)
        ));
    }

    public function setBet(int $chatId, $telegramUserId, string $game, int $bet): void
    {
        if (!in_array($bet, self::BET_OPTIONS, true)) throw new RuntimeException('赌注选项无效');
        $this->assertGroupAvailable($chatId);
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
        try {
            $this->send($chatId, sprintf(
                "%s规则已保存\n状态：%s\n每日次数：%s\n条件后中奖概率：%s%%\n赔付倍率：%sx",
                $this->label($state['game']),
                $state['enabled'] ? '已启用' : '已停用',
                $this->dailyLimit($state['daily_limit'] ?? 0),
                $state['probability'],
                $state['multiplier']
            ), [[$this->button('返回管理员规则', 'rw:a')]]);
        } finally {
            // Send the confirmation before restarting Webman so the callback
            // request is not terminated before Telegram receives the result.
            $this->rewards->reloadWebman();
        }
    }

    public function handleCallback(array $callback): void
    {
        $queryId = (string)($callback['id'] ?? '');
        $chat = (array)($callback['message']['chat'] ?? []);
        $from = (array)($callback['from'] ?? []);
        $chatId = (int)($chat['id'] ?? 0);
        $telegramUserId = (string)($from['id'] ?? '');
        try {
            if (!in_array((string)($chat['type'] ?? ''), ['private', 'group', 'supergroup'], true)
                || $chatId === 0 || $telegramUserId === '') {
                throw new RuntimeException('请在私聊或群组中使用娱乐功能');
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
        if ($chatId === 0) return;
        try { $this->send($chatId, '操作失败：' . $this->safeMessage($e)); } catch (\Throwable $ignored) {}
    }

    private function dispatchCallback(int $chatId, string $telegramUserId, string $data, int $messageId): void
    {
        if ($data === 'rw:m') { $this->showMenu($chatId, $telegramUserId); return; }
        if ($data === 'rw:c') { $this->checkin($chatId, $telegramUserId); return; }
        if ($data === 'rw:a') { $this->showAdminMenu($chatId, $telegramUserId); return; }
        if (preg_match('/^rw:g:([dsp])$/', $data, $match)) {
            if ($chatId < 0 && $match[1] === 'p') {
                $this->playGroupPoker($chatId, $telegramUserId);
                return;
            }
            $this->showGame($chatId, $telegramUserId, $match[1]);
            return;
        }
        if (preg_match('/^rw:b:([dsp])(?::([A-Za-z0-9_-]{8,16}))?$/', $data, $match)) {
            $this->assertGameActionOwner($chatId, $telegramUserId, $match[1], $match[2] ?? '');
            $this->showBets($chatId, $telegramUserId, $match[1]);
            return;
        }
        if (preg_match('/^rw:e:([dsp])$/', $data, $match)) {
            $this->beginRuleEdit($chatId, $telegramUserId, $match[1]);
            return;
        }
        if (preg_match('/^rw:v:([dsp]):(\d{1,4})(?::([A-Za-z0-9_-]{8,16}))?$/', $data, $match)) {
            $this->assertGameActionOwner($chatId, $telegramUserId, $match[1], $match[3] ?? '');
            $this->setBet($chatId, $telegramUserId, $match[1], (int)$match[2]);
            return;
        }
        if (preg_match('/^rw:dg:([1-6])(?::([A-Za-z0-9_-]{8,16}))?$/', $data, $match)) {
            $this->assertGameActionOwner($chatId, $telegramUserId, 'd', $match[2] ?? '');
            $this->playDiceWithGuess(
                $chatId,
                $telegramUserId,
                (int)$match[1],
                'telegram-callback-' . $chatId . '-' . $messageId
            );
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
        if (preg_match('/^rw:go:([dsp])(?::([A-Za-z0-9_-]{8,16}))?$/', $data, $match)) {
            $this->assertGameActionOwner($chatId, $telegramUserId, $match[1], $match[2] ?? '');
            if ($match[1] === 'd') {
                $this->showGame($chatId, $telegramUserId, 'dice');
                return;
            }
            $this->play($chatId, $telegramUserId, $match[1], 'telegram-callback-' . $chatId . '-' . $messageId);
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

    private function assertGroupAvailable(int $chatId): void
    {
        if ($chatId < 0 && (int)config('v2board.reward_group_enable', 0) !== 1) {
            throw new RuntimeException('群组娱乐已关闭');
        }
    }

    private function entrypoint(int $chatId): string
    {
        return $chatId < 0 ? 'telegram_group' : 'telegram';
    }

    private function gameActionSuffix(int $chatId, $telegramUserId, string $game): string
    {
        if ($chatId >= 0) return '';

        $token = rtrim(strtr(base64_encode(random_bytes(9)), '+/', '-_'), '=');
        Cache::put($this->gameActionKey($token), [
            'telegram_user_id' => (string)$telegramUserId,
            'chat_id' => $chatId,
            'game' => $game,
        ], self::STATE_TTL);
        return ':' . $token;
    }

    private function assertGameActionOwner(int $chatId, string $telegramUserId, string $gameCode, string $token): void
    {
        if ($token === '') {
            if ($chatId < 0) throw new RuntimeException('该群组游戏页面已失效，请重新打开对应游戏');
            return;
        }

        $state = Cache::get($this->gameActionKey($token));
        if (!is_array($state)
            || !hash_equals((string)($state['telegram_user_id'] ?? ''), $telegramUserId)
            || (int)($state['chat_id'] ?? 0) !== $chatId
            || (string)($state['game'] ?? '') !== $this->game($gameCode)) {
            throw new RuntimeException('该游戏页面仅限创建者操作，请发送对应游戏命令打开自己的页面');
        }
    }

    private function gameActionKey(string $token): string
    {
        return 'telegram_reward_game_action:' . $token;
    }

    private function checkinRulesText(): string
    {
        return '签到规则：每日一次，随机增加 1-10 GB；流量发放至当前绑定的有效订阅。';
    }

    private function gameRulesText(string $game, ?int $betGb, bool $groupPoker = false): string
    {
        $rules = $this->rewards->gameRules();
        $rule = (array)($rules[$game] ?? []);
        $probability = (string)($rule['win_probability'] ?? '0.00');
        $multiplier = (string)($rule['payout_multiplier'] ?? '1.00');
        $dailyLimit = (int)($rule['daily_limit'] ?? 0);
        $betLine = $betGb === null
            ? '赌注：每位玩家按自身已设置的赌注结算。'
            : sprintf('当前赌注：%d GB；中奖赔付：%s GB（赌注 x %sx）。', $betGb, $this->number($betGb * (float)$multiplier), $multiplier);
        $limitLine = '每日次数：' . $this->dailyLimit($dailyLimit) . '。';

        if ($game === 'dice') {
            return sprintf(
                "游戏规则\n%s\n选择 1-6 猜测点数；实际骰子与猜测一致时，进入中奖概率判定。触发后中奖概率：%s%%，单局最终中奖概率约 %s%%。\n%s\n未中奖扣除当前赌注。",
                $betLine,
                $probability,
                $this->number((float)$probability / 6),
                $limitLine
            );
        }

        if ($game === 'slots') {
            $rate = min(10000, max(1, (int)config('v2board.reward_slots_jackpot_rate', 100)));
            return sprintf(
                "游戏规则\n%s\n触发条件：三个图案相同（出现概率：%s%%）；触发后中奖概率：%s%%，单局最终中奖概率约 %s%%。\n%s\n未中奖扣除当前赌注。",
                $betLine,
                $this->number($rate / 100),
                $probability,
                $this->number(($rate / 100) * (float)$probability / 100),
                $limitLine
            );
        }

        $qualification = $groupPoker
            ? '三张相同优先，其次为一对，随后比较最大点数；同分时最后加入牌局的玩家获胜。牌面最高者取得结算资格，随后按中奖概率判定。'
            : '单人模式的牌面仅作展示，单局直接按中奖概率判定。';
        return sprintf(
            "游戏规则\n%s\n%s 中奖概率：%s%%。\n%s\n未中奖扣除当前赌注。",
            $betLine,
            $qualification,
            $probability,
            $limitLine
        );
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
