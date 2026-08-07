<?php

namespace App\Services;

use App\Models\TelegramLoginLink;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class TelegramLoginLinkService
{
    public const TTL = 60;
    private const REISSUE_COOLDOWN = 15;

    public static function isLoginToken($token): bool
    {
        return is_string($token) && preg_match('/^[a-f0-9]{64}$/', $token) === 1;
    }

    public function issue(User $user, $telegramChatId = null, $redirect = 'dashboard', bool $requireHttps = false): string
    {
        if ($user->banned) {
            throw new RuntimeException('User is suspended');
        }

        $chatId = $this->normalizeChatId($telegramChatId);
        if ($chatId !== null && (string)$user->telegram_id !== $chatId) {
            throw new RuntimeException('Telegram binding is invalid');
        }

        $baseUrl = $this->baseUrl($requireHttps);
        $now = time();
        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);

        DB::transaction(function () use ($user, $chatId, $now, $tokenHash) {
            $link = TelegramLoginLink::where('user_id', $user->id)->lockForUpdate()->first();
            if ($link && (int)$link->created_at + self::REISSUE_COOLDOWN > $now) {
                throw new RuntimeException('Login link was issued recently');
            }

            $attributes = [
                'telegram_chat_id' => $chatId,
                'token_hash' => $tokenHash,
                'expires_at' => $now + self::TTL,
                'consumed_at' => null,
                'created_at' => $now,
                'updated_at' => $now
            ];

            if ($link) {
                $link->fill($attributes)->save();
                return;
            }

            TelegramLoginLink::create(array_merge($attributes, ['user_id' => $user->id]));
        });

        return $baseUrl . '/#/login?' . http_build_query([
            'verify' => $token,
            'redirect' => (string)$redirect
        ]);
    }

    public function consume($token): ?User
    {
        if (!self::isLoginToken($token)) {
            return null;
        }

        return DB::transaction(function () use ($token) {
            $link = TelegramLoginLink::where('token_hash', hash('sha256', $token))
                ->lockForUpdate()
                ->first();
            $now = time();

            if (!$link || $link->consumed_at || (int)$link->expires_at <= $now) {
                return null;
            }

            $user = User::where('id', $link->user_id)->lockForUpdate()->first();
            if (!$user || $user->banned
                || ($link->telegram_chat_id !== null && (string)$user->telegram_id !== (string)$link->telegram_chat_id)) {
                $link->consumed_at = $now;
                $link->updated_at = $now;
                $link->save();
                return null;
            }

            $link->consumed_at = $now;
            $link->updated_at = $now;
            $link->save();
            return $user;
        });
    }

    public function prune(): int
    {
        return TelegramLoginLink::where('expires_at', '<=', time())->delete();
    }

    private function normalizeChatId($chatId): ?string
    {
        if ($chatId === null || $chatId === '') {
            return null;
        }

        $chatId = (string)$chatId;
        if (preg_match('/^[1-9][0-9]{0,18}$/', $chatId) !== 1) {
            throw new RuntimeException('Telegram chat is invalid');
        }
        return $chatId;
    }

    private function baseUrl(bool $requireHttps): string
    {
        $baseUrl = trim((string)config('v2board.app_url', ''));
        if ($baseUrl === '') {
            $baseUrl = url('/');
        }

        $parts = parse_url($baseUrl);
        if (!is_array($parts) || empty($parts['host'])) {
            throw new RuntimeException('Site URL is invalid');
        }
        if ($requireHttps && strtolower((string)($parts['scheme'] ?? '')) !== 'https') {
            throw new RuntimeException('Telegram login requires HTTPS');
        }
        return rtrim($baseUrl, '/');
    }
}
