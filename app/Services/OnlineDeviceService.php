<?php

namespace App\Services;

use App\Models\Subscription;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class OnlineDeviceService
{
    private const CACHE_KEY_PREFIX = 'ALIVE_IP_USER_';

    public function summariesForUsers(Collection $users): array
    {
        if ($users->isEmpty()) {
            return [];
        }

        $usersById = $users->keyBy(function ($user) {
            return (int)$user->id;
        });
        $subscriptionsByUser = collect();

        if (Schema::hasTable('v2_subscription')) {
            $subscriptionsByUser = Subscription::whereIn('user_id', $usersById->keys()->all())
                ->get(['user_id', 'node_user_id', 'device_limit', 'status', 'expired_at'])
                ->groupBy(function ($subscription) {
                    return (int)$subscription->user_id;
                });
        }

        $cacheKeys = [];
        $effectiveSubscriptions = [];
        foreach ($usersById as $userId => $user) {
            $subscriptions = $subscriptionsByUser->get($userId, collect());
            if ($subscriptions->isEmpty()) {
                $cacheKeys[] = $this->cacheKey($userId);
                continue;
            }

            $effectiveSubscriptions[$userId] = $subscriptions->filter(function ($subscription) {
                return $subscription->status === 'active'
                    && ($subscription->expired_at === null || (int)$subscription->expired_at >= time());
            })->values();

            foreach ($effectiveSubscriptions[$userId] as $subscription) {
                $cacheKeys[] = $this->cacheKey((int)$subscription->node_user_id);
            }
        }

        $cacheData = empty($cacheKeys) ? [] : Cache::many(array_values(array_unique($cacheKeys)));
        $deduplicateIps = (int)config('v2board.device_limit_mode', 0) === 1;
        $summaries = [];

        foreach ($usersById as $userId => $user) {
            if (!isset($effectiveSubscriptions[$userId])) {
                $summaries[$userId] = $this->summarizeCacheData(
                    [$cacheData[$this->cacheKey($userId)] ?? null],
                    $deduplicateIps,
                    $this->normalizeLegacyLimit($user->device_limit)
                );
                continue;
            }

            $subscriptions = $effectiveSubscriptions[$userId];
            if ($subscriptions->isEmpty()) {
                $summaries[$userId] = [
                    'alive_ip' => 0,
                    'ips' => '',
                    'device_limit' => $this->normalizeLegacyLimit($user->device_limit)
                ];
                continue;
            }

            $subscriptionCacheData = [];
            foreach ($subscriptions as $subscription) {
                $subscriptionCacheData[] = $cacheData[$this->cacheKey((int)$subscription->node_user_id)] ?? null;
            }

            $summaries[$userId] = $this->summarizeCacheData(
                $subscriptionCacheData,
                $deduplicateIps,
                $this->aggregateDeviceLimit($subscriptions)
            );
        }

        return $summaries;
    }

    private function summarizeCacheData(array $cacheData, bool $deduplicateIps, ?int $deviceLimit): array
    {
        $connections = [];
        $uniqueIps = [];

        foreach ($cacheData as $subscriptionData) {
            if (!is_array($subscriptionData)) {
                continue;
            }
            foreach ($subscriptionData as $nodeTypeId => $nodeData) {
                if ($nodeTypeId === 'alive_ip' || !is_array($nodeData) || !isset($nodeData['aliveips']) || !is_array($nodeData['aliveips'])) {
                    continue;
                }
                foreach ($nodeData['aliveips'] as $ipNodeId) {
                    if (!is_scalar($ipNodeId)) {
                        continue;
                    }
                    $ip = trim(explode('_', (string)$ipNodeId, 2)[0]);
                    if ($ip === '') {
                        continue;
                    }
                    $connections[] = $ip . '_' . $nodeTypeId;
                    $uniqueIps[$ip] = true;
                }
            }
        }

        return [
            'alive_ip' => $deduplicateIps ? count($uniqueIps) : count($connections),
            'ips' => implode(', ', $connections),
            'device_limit' => $deviceLimit
        ];
    }

    private function aggregateDeviceLimit(Collection $subscriptions): ?int
    {
        $total = 0;
        foreach ($subscriptions as $subscription) {
            if ($subscription->device_limit === null || (int)$subscription->device_limit <= 0) {
                return null;
            }
            $total += (int)$subscription->device_limit;
        }
        return $total;
    }

    private function normalizeLegacyLimit($deviceLimit): ?int
    {
        return $deviceLimit === null || (int)$deviceLimit <= 0 ? null : (int)$deviceLimit;
    }

    private function cacheKey(int $nodeUserId): string
    {
        return self::CACHE_KEY_PREFIX . $nodeUserId;
    }
}
