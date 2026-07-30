<?php

namespace App\Services;

use App\Jobs\KickTelegramBinding;
use App\Models\Subscription;
use App\Models\TelegramSubscriptionBinding;
use App\Models\User;
use App\Utils\CacheKey;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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
        $binding = DB::transaction(function () use ($payload, $telegramUserId, $username, &$previous) {
            $user = User::where('id', (int)$payload['user_id'])->lockForUpdate()->first();
            $subscription = Subscription::where('id', (int)$payload['subscription_id'])
                ->where('user_id', (int)$payload['user_id'])->lockForUpdate()->first();
            if (!$user || !$subscription || !$this->subscriptionIsActive($subscription)) {
                throw new RuntimeException('Subscription is no longer active');
            }
            if (!hash_equals((string)$payload['token_hash'], $this->tokenHash($subscription->token))) {
                throw new RuntimeException('Subscription link has changed; prepare a new binding');
            }
            $conflict = TelegramSubscriptionBinding::where('chat_id', $this->chatId())
                ->where('telegram_user_id', $telegramUserId)
                ->where('status', 'active')
                ->where('user_id', '!=', $user->id)
                ->lockForUpdate()->first();
            if ($conflict) throw new RuntimeException('Telegram account is already bound to another account');

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
        if (!$binding) return;
        $username = $this->normalizeUsername($member['user']['username'] ?? '');
        if ($username === '' || $username !== $this->normalizeUsername($binding->telegram_username)) {
            $this->invalidate($binding, 'telegram_username_changed');
        }
    }

    public function invalidateForSubscription(int $subscriptionId, string $reason): void
    {
        if (!$this->available()) return;
        TelegramSubscriptionBinding::where('subscription_id', $subscriptionId)
            ->where('status', 'active')->get()->each(function (TelegramSubscriptionBinding $binding) use ($reason) {
                $binding->status = 'invalid';
                $binding->invalid_reason = $reason;
                $binding->updated_at = time();
                $binding->save();
                KickTelegramBinding::dispatch((int)$binding->id, (string)$binding->telegram_user_id, (string)$binding->chat_id);
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
