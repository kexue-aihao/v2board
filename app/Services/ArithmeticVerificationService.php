<?php

namespace App\Services;

use App\Utils\CacheKey;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

class ArithmeticVerificationService
{
    public const TTL = 300;
    public const MAX_ATTEMPTS = 5;
    public const ISSUE_LIMIT = 30;
    public const ISSUE_DECAY = 600;

    public function issue(string $ip): array
    {
        $ip = $this->normalizeIp($ip);
        $rateKey = 'arithmetic_issue:' . hash('sha256', $ip);
        if (RateLimiter::tooManyAttempts($rateKey, self::ISSUE_LIMIT)) {
            throw new \RuntimeException('Arithmetic challenge rate limit exceeded');
        }
        RateLimiter::hit($rateKey, self::ISSUE_DECAY);

        $operator = random_int(0, 1) === 0 ? '+' : '-';
        $left = random_int(1, 9999);
        $right = random_int(1, 9999);
        if ($operator === '-' && $left < $right) {
            [$left, $right] = [$right, $left];
        }

        $challengeId = bin2hex(random_bytes(32));
        $key = CacheKey::get('ARITHMETIC_CHALLENGE', $challengeId);
        $payload = [
            'left' => $left,
            'right' => $right,
            'operator' => $operator,
            'answer' => $operator === '+' ? $left + $right : $left - $right,
            'attempts' => 0,
            'ip_hash' => hash('sha256', $ip),
            'expires_at' => time() + self::TTL,
        ];

        if (!Cache::put($key, $payload, self::TTL)) {
            throw new \RuntimeException('Unable to store arithmetic challenge');
        }

        return [
            'challenge_id' => $challengeId,
            'left' => $left,
            'operator' => $operator,
            'right' => $right,
            'expires_at' => $payload['expires_at'],
        ];
    }

    public function verify(string $challengeId, $answer, string $ip): array
    {
        if (!$this->isValidChallengeId($challengeId)) {
            return ['correct' => false, 'verified' => false];
        }

        return $this->withChallengeLock($challengeId, function () use ($challengeId, $answer, $ip) {
            $key = CacheKey::get('ARITHMETIC_CHALLENGE', $challengeId);
            $challenge = Cache::get($key);
            if (!is_array($challenge) || !$this->belongsToIp($challenge, $ip)) {
                return ['correct' => false, 'verified' => false];
            }

            $remaining = $this->remainingTtl($challenge);
            if ($remaining <= 0) {
                Cache::forget($key);
                return ['correct' => false, 'verified' => false];
            }

            $normalizedAnswer = $this->normalizeAnswer($answer);
            if ($normalizedAnswer !== null && $normalizedAnswer === (int)$challenge['answer']) {
                $challenge['verified'] = true;
                Cache::put($key, $challenge, $remaining);
                return ['correct' => true, 'verified' => true];
            }

            $this->recordFailedAttempt($key, $challenge, $remaining);

            return ['correct' => false, 'verified' => false];
        });
    }

    public function consume(string $challengeId, $answer, string $ip): bool
    {
        if (!$this->isValidChallengeId($challengeId)) {
            return false;
        }

        return $this->withChallengeLock($challengeId, function () use ($challengeId, $answer, $ip) {
            $key = CacheKey::get('ARITHMETIC_CHALLENGE', $challengeId);
            $challenge = Cache::get($key);
            if (!is_array($challenge) || !$this->belongsToIp($challenge, $ip)) {
                return false;
            }

            if ($this->remainingTtl($challenge) <= 0) {
                Cache::forget($key);
                return false;
            }

            $normalizedAnswer = $this->normalizeAnswer($answer);
            if ($normalizedAnswer === null || $normalizedAnswer !== (int)$challenge['answer']) {
                $this->recordFailedAttempt($key, $challenge, $this->remainingTtl($challenge));
                return false;
            }

            Cache::forget($key);
            return true;
        });
    }

    private function withChallengeLock(string $challengeId, \Closure $callback)
    {
        $lock = Cache::lock('arithmetic_challenge_lock:' . $challengeId, 10);
        return $lock->block(3, $callback);
    }

    private function belongsToIp(array $challenge, string $ip): bool
    {
        return isset($challenge['ip_hash'])
            && hash_equals((string)$challenge['ip_hash'], hash('sha256', $this->normalizeIp($ip)));
    }

    private function normalizeIp(string $ip): string
    {
        return trim($ip) !== '' ? trim($ip) : 'unknown';
    }

    private function normalizeAnswer($answer): ?int
    {
        if (is_int($answer)) {
            return $answer;
        }
        if (!is_string($answer) || !preg_match('/^\d+$/', trim($answer))) {
            return null;
        }
        return (int)trim($answer);
    }

    private function isValidChallengeId(string $challengeId): bool
    {
        return (bool)preg_match('/^[a-f0-9]{64}$/', $challengeId);
    }

    private function remainingTtl(array $challenge): int
    {
        return max(0, (int)($challenge['expires_at'] ?? 0) - time());
    }

    private function recordFailedAttempt(string $key, array $challenge, int $remaining): void
    {
        if ($remaining <= 0) {
            Cache::forget($key);
            return;
        }

        $challenge['attempts'] = (int)($challenge['attempts'] ?? 0) + 1;
        if ($challenge['attempts'] >= self::MAX_ATTEMPTS) {
            Cache::forget($key);
            return;
        }

        Cache::put($key, $challenge, $remaining);
    }
}
