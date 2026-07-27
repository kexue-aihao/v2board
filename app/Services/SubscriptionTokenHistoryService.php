<?php

namespace App\Services;

use App\Models\Subscription;
use App\Models\SubscriptionTokenHistory;
use App\Models\User;
use App\Utils\CacheKey;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * 订阅 token 历史。
 *
 * 存在的理由：resetSecret / resetSecurity 原地覆写 token，改之前不读旧值，全库没有任何
 * 地方留下旧 token，所以「哪怕 token 被重置也能溯源」只能靠这张表，且历史只能从部署那
 * 一刻起累积。
 *
 * retired_at 的语义契约：它是这个 token 字符串不再存在于任何活凭证列（v2_subscription
 * .token、v2_user.token）的时刻，**不等于「不再能通过验证」**。被 revoke 的订阅和已过期
 * 订阅的 token 仍留在列里因而不退役，却已经过不了 SubscriptionService::byToken 的过滤；
 * 反过来 show_subscribe_method=1 时 otpn_* 缓存让重置前的一次性链接还能成功一次。
 * 若改用「能否验证」当活性，retired_at 会随订阅过期/续费来回跳动，那是在时间上撒谎。
 *
 * 本类所有公开方法都不抛异常：写入失败只记 Log::error，由 token-history:reconcile 兜底。
 * 理由见实施计划 §1.4 —— noteIssued 会跑在付款订单的事务里，在那里抛异常会回滚订单；
 * 而两个重置路径没有事务，抛异常也撤不回已提交的轮换，只会产出「token 已换、告诉用户
 * 失败、且没有历史」这个最坏结果。
 */
class SubscriptionTokenHistoryService
{
    /** 机器码 => 中文标签。放类常量而不是 config/，v2board:update 会先跑 config:cache。 */
    public const REASONS = [
        'register' => '注册',
        'admin_generate' => '管理员生成账号',
        'admin_generate_bulk' => '管理员批量生成账号',
        'install_admin' => '安装时创建管理员',
        'subscription_new' => '新建订阅',
        'admin_reset' => '管理员重置安全信息',
        'self_reset' => '用户自行重置订阅地址',
        'cli_reset_all' => '命令行批量重置',
        'superseded' => '被新 token 取代',
        'seed' => '部署时补录（原因未知）',
        'reconciled' => '对账补录（原因未知）',
        'unknown' => '未知'
    ];

    private const PREFIX_LENGTH = 8;

    private $availability;

    public function available(): bool
    {
        if ($this->availability !== null) {
            return $this->availability;
        }
        try {
            return $this->availability = Schema::hasTable('v2_subscription_token_history');
        } catch (\Throwable $e) {
            Log::warning('Token 历史表探测失败，按未安装处理', ['error' => $e->getMessage()]);
            return $this->availability = false;
        }
    }

    public function hash(string $token): string
    {
        return hash('sha256', strtolower(trim($token)));
    }

    /** 统一的掩码格式：前 8 位 + 省略号。后几位需要解密才能取到，不值得为此再开一列。 */
    public function mask(?string $tokenOrPrefix): ?string
    {
        $value = (string)$tokenOrPrefix;
        if ($value === '') {
            return null;
        }
        return substr($value, 0, self::PREFIX_LENGTH) . '…';
    }

    /** 本表开始记录的时刻。UI 必须据此声明「此前的重置没有留下任何痕迹」。 */
    public function startedAt(): ?int
    {
        if (!$this->available()) {
            return null;
        }
        try {
            return Cache::remember(CacheKey::get('TOKEN_HISTORY_STARTED_AT', 'global'), 3600, function () {
                $min = SubscriptionTokenHistory::min('created_at');
                return $min === null ? null : (int)$min;
            });
        } catch (\Throwable $e) {
            Log::warning('读取 Token 历史起始时间失败', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * 这个 token 字符串是否仍存在于任何活凭证列。
     *
     * 退役判定必须先过这一关，而不是「有旧值就退役」。这一条规则同时解决三个陷阱：
     * syncUser 的镜像不会被当成两个 token；setPrimary 换主后旧主订阅的 token 仍在自己
     * 的行上因而不退役（一个用户可以合法地同时有多个活 token）；reset:user 只写
     * v2_user.token，订阅仍持旧值所以旧值不退役。
     */
    public function tokenIsLive(string $token): bool
    {
        $token = trim($token);
        if ($token === '') {
            return false;
        }
        try {
            if (Schema::hasTable('v2_subscription') && Subscription::where('token', $token)->exists()) {
                return true;
            }
            return User::where('token', $token)->exists();
        } catch (\Throwable $e) {
            // 探测失败时保守地当作「仍然活着」，宁可漏记一次退役，也不要错误地把一个
            // 仍在使用的 token 标成已停用。
            Log::error('Token 活性探测失败，保守视为仍在使用', ['error' => $e->getMessage()]);
            return true;
        }
    }

    /**
     * 观察到一次 token 变化。$oldToken 为 null 表示新建。
     */
    public function observe(string $newToken, ?string $oldToken, int $userId, ?int $subscriptionId): void
    {
        $newToken = trim($newToken);
        if ($newToken !== '') {
            $this->noteIssued($newToken, $userId, $subscriptionId);
        }
        $oldToken = trim((string)$oldToken);
        if ($oldToken !== '' && $oldToken !== $newToken) {
            $this->noteRetiredIfDead($oldToken);
        }
    }

    public function noteIssued(
        string $token,
        int $userId,
        ?int $subscriptionId,
        ?string $reason = null,
        ?string $actorType = null,
        ?int $actorUserId = null,
        ?int $issuedAt = null,
        bool $exact = true
    ): void {
        if (!$this->available()) {
            return;
        }
        $token = trim($token);
        if ($token === '' || $userId <= 0) {
            return;
        }

        try {
            $hash = $this->hash($token);
            $existing = SubscriptionTokenHistory::where('token_hash', $hash)
                ->first(['id', 'user_id', 'retired_at']);
            if ($existing && (int)$existing->user_id !== $userId) {
                // 不该发生：token 列各自带唯一索引，撞上只可能是 Helper::guid() 碰撞或逻辑
                // bug。记下来但不改写归属 —— 静默重新指派归属会让溯源结果彻底不可信。
                Log::error('Token 历史归属冲突，保留原有 user_id', [
                    'token_prefix' => substr($token, 0, self::PREFIX_LENGTH),
                    'stored_user_id' => (int)$existing->user_id,
                    'incoming_user_id' => $userId
                ]);
            }

            $actor = $this->resolveActor($actorType, $actorUserId);
            $now = time();
            DB::statement(
                "INSERT INTO `v2_subscription_token_history`
                    (`token_hash`,`token_prefix`,`token_encrypted`,`user_id`,`subscription_id`,
                     `issued_at`,`issued_at_exact`,`issued_reason`,`issued_actor_type`,
                     `issued_actor_user_id`,`created_at`,`updated_at`)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE
                    `subscription_id` = COALESCE(`subscription_id`, VALUES(`subscription_id`)),
                    `token_encrypted` = COALESCE(`token_encrypted`, VALUES(`token_encrypted`)),
                    `retired_at` = NULL,
                    `retired_at_exact` = NULL,
                    `retired_reason` = NULL,
                    `retired_actor_type` = NULL,
                    `retired_actor_user_id` = NULL,
                    `updated_at` = VALUES(`updated_at`)",
                [
                    $hash,
                    substr($token, 0, self::PREFIX_LENGTH),
                    $this->encrypt($token),
                    $userId,
                    $subscriptionId ?: null,
                    $issuedAt ?: $now,
                    $exact ? 1 : 0,
                    $this->normalizeReason($reason),
                    $actor['type'],
                    $actor['user_id'],
                    $now,
                    $now
                ]
            );
        } catch (\Throwable $e) {
            // 冲突时刻意只更新 subscription_id / token_encrypted 的补空、清退役标记和
            // updated_at，绝不覆写 user_id 与任何 issued_*：否则一条不精确的种子会被
            // 错误的「精确」当前时间盖掉。
            Log::error('写入 Token 签发记录失败', [
                'token_prefix' => substr($token, 0, self::PREFIX_LENGTH),
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
        }
    }

    public function noteRetiredIfDead(
        string $token,
        ?string $reason = null,
        ?string $actorType = null,
        ?int $actorUserId = null,
        bool $exact = true
    ): void {
        if (!$this->available()) {
            return;
        }
        $token = trim($token);
        if ($token === '' || $this->tokenIsLive($token)) {
            return;
        }

        try {
            $actor = $this->resolveActor($actorType, $actorUserId);
            $hash = $this->hash($token);
            // WHERE retired_at IS NULL：已有的退役时间绝不移动。
            $affected = SubscriptionTokenHistory::where('token_hash', $hash)
                ->whereNull('retired_at')
                ->update([
                    'retired_at' => time(),
                    'retired_at_exact' => $exact ? 1 : 0,
                    'retired_reason' => $this->normalizeReason($reason ?: 'superseded'),
                    'retired_actor_type' => $actor['type'],
                    'retired_actor_user_id' => $actor['user_id'],
                    'updated_at' => time()
                ]);
            if ($affected > 0) {
                return;
            }
            if (SubscriptionTokenHistory::where('token_hash', $hash)->exists()) {
                return;
            }
            // 完全没有这一行：这个 token 在建表与种子之间被创建又被换掉。补一条追溯记录，
            // 签发时间未知所以标为不精确。
            $this->insertRetroactive($token, $reason);
        } catch (\Throwable $e) {
            Log::error('写入 Token 退役记录失败', [
                'token_prefix' => substr($token, 0, self::PREFIX_LENGTH),
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * 批量记录。给 multiGenerate 用 —— 它走 User::insert()，绕过全部模型事件，是唯一
     * 需要显式调用的写入点。
     *
     * @param array $rows [['user_id'=>int,'subscription_id'=>?int,'token'=>string], ...]
     */
    public function recordBulk(array $rows, ?string $reason = null, ?string $actorType = null, ?int $actorUserId = null): int
    {
        if (!$this->available() || !count($rows)) {
            return 0;
        }

        try {
            $actor = $this->resolveActor($actorType, $actorUserId);
            $now = time();
            $normalizedReason = $this->normalizeReason($reason);
            $values = [];
            $bindings = [];
            $seen = [];
            foreach ($rows as $row) {
                $token = trim((string)($row['token'] ?? ''));
                $userId = (int)($row['user_id'] ?? 0);
                if ($token === '' || $userId <= 0) {
                    continue;
                }
                $hash = $this->hash($token);
                // 单条 INSERT 内部同一唯一键出现两次会自我冲突，先去重。
                if (isset($seen[$hash])) {
                    continue;
                }
                $seen[$hash] = true;
                $values[] = '(?,?,?,?,?,?,?,?,?,?,?,?)';
                array_push(
                    $bindings,
                    $hash,
                    substr($token, 0, self::PREFIX_LENGTH),
                    $this->encrypt($token),
                    $userId,
                    isset($row['subscription_id']) && $row['subscription_id'] ? (int)$row['subscription_id'] : null,
                    isset($row['issued_at']) && $row['issued_at'] ? (int)$row['issued_at'] : $now,
                    isset($row['exact']) && !$row['exact'] ? 0 : 1,
                    $normalizedReason,
                    $actor['type'],
                    $actor['user_id'],
                    $now,
                    $now
                );
            }
            if (!count($values)) {
                return 0;
            }

            DB::statement(
                "INSERT INTO `v2_subscription_token_history`
                    (`token_hash`,`token_prefix`,`token_encrypted`,`user_id`,`subscription_id`,
                     `issued_at`,`issued_at_exact`,`issued_reason`,`issued_actor_type`,
                     `issued_actor_user_id`,`created_at`,`updated_at`)
                 VALUES " . implode(',', $values) . "
                 ON DUPLICATE KEY UPDATE
                    `subscription_id` = COALESCE(`subscription_id`, VALUES(`subscription_id`)),
                    `token_encrypted` = COALESCE(`token_encrypted`, VALUES(`token_encrypted`)),
                    `updated_at` = VALUES(`updated_at`)",
                $bindings
            );
            return count($values);
        } catch (\Throwable $e) {
            Log::error('批量写入 Token 历史失败', ['count' => count($rows), 'error' => $e->getMessage()]);
            return 0;
        }
    }

    public function findByToken(string $token): ?SubscriptionTokenHistory
    {
        if (!$this->available()) {
            return null;
        }
        try {
            return SubscriptionTokenHistory::where('token_hash', $this->hash($token))->first();
        } catch (\Throwable $e) {
            Log::warning('按 token 反查历史失败', ['error' => $e->getMessage()]);
            return null;
        }
    }

    public function forUser(int $userId)
    {
        if (!$this->available()) {
            return collect();
        }
        try {
            return SubscriptionTokenHistory::where('user_id', $userId)
                ->orderByDesc('issued_at')
                ->get([
                    'id', 'token_prefix', 'user_id', 'subscription_id',
                    'issued_at', 'issued_at_exact', 'issued_reason', 'issued_actor_type',
                    'retired_at', 'retired_at_exact', 'retired_reason', 'retired_actor_type'
                ]);
        } catch (\Throwable $e) {
            Log::warning('读取用户 Token 历史失败', ['user_id' => $userId, 'error' => $e->getMessage()]);
            return collect();
        }
    }

    /**
     * 解密单条记录的原值。这是「看到该用户的历史 token 是多少」的唯一出口，调用方必须
     * 单独记审计日志。
     *
     * @return array{token: ?string, error: ?string}
     */
    public function reveal(int $id): array
    {
        if (!$this->available()) {
            return ['token' => null, 'error' => 'Token 历史表尚未安装'];
        }
        $record = SubscriptionTokenHistory::find($id);
        if (!$record) {
            return ['token' => null, 'error' => '记录不存在'];
        }
        if (!$record->token_encrypted) {
            return ['token' => null, 'error' => '该记录没有保存原值，只能看到前 8 位：' . $record->token_prefix];
        }
        try {
            return ['token' => Crypt::decryptString($record->token_encrypted), 'error' => null];
        } catch (\Throwable $e) {
            Log::warning('Token 解密失败', ['id' => $id, 'error' => $e->getMessage()]);
            return [
                'token' => null,
                'error' => '解密失败（APP_KEY 可能已变更），只能看到前 8 位：' . $record->token_prefix
            ];
        }
    }

    /**
     * 对账：让历史表与当前活凭证列一致。既是夜间兜底，也是建表时的种子 —— 只有一条
     * 代码路径。稳态下应当返回 inserted=0 retired=0，非零就是观察者漏写的证据。
     */
    public function reconcile(int $chunk = 2000, int $maxLive = 500000, bool $dryRun = false): array
    {
        $result = [
            'inserted' => 0, 'retired' => 0, 'orphaned' => 0,
            'live' => 0, 'skipped_retire' => false, 'dry_run' => $dryRun
        ];
        if (!$this->available()) {
            return $result;
        }
        $chunk = max(100, min(10000, $chunk));

        try {
            $live = [];
            if (Schema::hasTable('v2_subscription')) {
                $this->reconcileSource(
                    Subscription::query()->select(['id', 'user_id', 'token', 'created_at']),
                    true, $chunk, $dryRun, $result, $live
                );
            }
            $this->reconcileSource(
                User::query()->select(['id', 'token', 'created_at']),
                false, $chunk, $dryRun, $result, $live
            );
            $result['live'] = count($live);

            // 退役阶段需要把全部活 token 的哈希放进 PHP 集合：纯 SQL diff 不可行，
            // SHA2(s.token,256) = h.token_hash 用不上索引，是 O(N×M)。
            if ($result['live'] > $maxLive) {
                $result['skipped_retire'] = true;
                Log::warning('活 token 数超过上限，本轮对账跳过退役阶段', [
                    'live' => $result['live'], 'max_live' => $maxLive
                ]);
                return $result;
            }

            SubscriptionTokenHistory::whereNull('retired_at')
                ->orderBy('id')
                ->chunkById($chunk, function ($records) use (&$result, $live, $dryRun) {
                    $ids = [];
                    foreach ($records as $record) {
                        if (!isset($live[(string)$record->token_hash])) {
                            $ids[] = (int)$record->id;
                        }
                    }
                    if (!count($ids)) {
                        return;
                    }
                    $result['retired'] += count($ids);
                    if ($dryRun) {
                        return;
                    }
                    SubscriptionTokenHistory::whereIn('id', $ids)
                        ->whereNull('retired_at')
                        ->update([
                            'retired_at' => time(),
                            'retired_at_exact' => 0,
                            'retired_reason' => 'reconciled',
                            'retired_actor_type' => 'reconcile',
                            'updated_at' => time()
                        ]);
                }, 'id');
        } catch (\Throwable $e) {
            Log::error('Token 历史对账失败', ['error' => $e->getMessage()]);
        }

        return $result;
    }

    public function purgeUser(int $userId): int
    {
        if (!$this->available() || $userId <= 0) {
            return 0;
        }
        try {
            return (int)SubscriptionTokenHistory::where('user_id', $userId)->delete();
        } catch (\Throwable $e) {
            Log::error('清理用户 Token 历史失败', ['user_id' => $userId, 'error' => $e->getMessage()]);
            return 0;
        }
    }

    private function reconcileSource($query, bool $isSubscription, int $chunk, bool $dryRun, array &$result, array &$live): void
    {
        $query->orderBy('id')->chunkById($chunk, function ($rows) use ($isSubscription, $dryRun, &$result, &$live) {
            $pending = [];
            $hashes = [];
            $userIds = [];
            foreach ($rows as $row) {
                $token = trim((string)$row->token);
                if ($token === '') {
                    continue;
                }
                $userId = $isSubscription ? (int)$row->user_id : (int)$row->id;
                $hash = $this->hash($token);
                $live[$hash] = true;
                $hashes[] = $hash;
                $userIds[$userId] = true;
                $pending[$hash] = [
                    'user_id' => $userId,
                    'subscription_id' => $isSubscription ? (int)$row->id : null,
                    'token' => $token,
                    // created_at 是 token 的真实下界（token 不可能早于它所在的行），
                    // 比统一取部署时刻有用；但仍然不是真实签发时间，标为不精确。
                    'issued_at' => (int)$row->created_at,
                    'exact' => false
                ];
            }
            if (!count($pending)) {
                return;
            }

            // delUser/allDel 从不删 v2_subscription 行（既有缺陷），已删用户的订阅 token
            // 仍然活着。这些行不能补进历史，否则会永远指向一个不存在的 user_id。
            $existingUsers = User::whereIn('id', array_keys($userIds))->pluck('id')->all();
            $existingUsers = array_flip(array_map('intval', $existingUsers));
            foreach ($pending as $hash => $row) {
                if (!isset($existingUsers[$row['user_id']])) {
                    unset($pending[$hash]);
                    $result['orphaned']++;
                }
            }
            if (!count($pending)) {
                return;
            }

            $known = SubscriptionTokenHistory::whereIn('token_hash', array_keys($pending))
                ->pluck('token_hash')->all();
            foreach ($known as $hash) {
                unset($pending[(string)$hash]);
            }
            if (!count($pending)) {
                return;
            }

            $result['inserted'] += count($pending);
            if ($dryRun) {
                return;
            }
            $this->recordBulk(array_values($pending), 'reconciled', 'reconcile', null);
        }, 'id');
    }

    private function insertRetroactive(string $token, ?string $reason): void
    {
        $now = time();
        $actor = $this->resolveActor(null, null);
        DB::statement(
            "INSERT INTO `v2_subscription_token_history`
                (`token_hash`,`token_prefix`,`token_encrypted`,`user_id`,`subscription_id`,
                 `issued_at`,`issued_at_exact`,`issued_reason`,`issued_actor_type`,
                 `retired_at`,`retired_at_exact`,`retired_reason`,`retired_actor_type`,
                 `created_at`,`updated_at`)
             VALUES (?,?,?,?,?,?,0,'reconciled',?,?,0,?,?,?,?)
             ON DUPLICATE KEY UPDATE `updated_at` = VALUES(`updated_at`)",
            [
                $this->hash($token),
                substr($token, 0, self::PREFIX_LENGTH),
                $this->encrypt($token),
                0,
                null,
                $now,
                $actor['type'],
                $now,
                $this->normalizeReason($reason ?: 'superseded'),
                $actor['type'],
                $now,
                $now
            ]
        );
    }

    private function encrypt(string $token): ?string
    {
        try {
            return Crypt::encryptString($token);
        } catch (\Throwable $e) {
            // 加密不可用不该让整条记录丢掉 —— 反查靠哈希，原值只影响「显示」。
            Log::error('Token 加密失败，本条记录只保留哈希与前缀', ['error' => $e->getMessage()]);
            return null;
        }
    }

    private function normalizeReason(?string $reason): string
    {
        $reason = (string)$reason;
        return isset(self::REASONS[$reason]) ? $reason : 'unknown';
    }

    private function resolveActor(?string $actorType, ?int $actorUserId): array
    {
        if ($actorType !== null) {
            return ['type' => $actorType, 'user_id' => $actorUserId];
        }
        return \App\Utils\TokenRotationContext::actor();
    }
}
