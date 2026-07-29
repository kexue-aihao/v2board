<?php

namespace App\Services;

use App\Models\ResellerAccount;
use App\Models\ResellerOrder;
use App\Models\ResellerSharedInvitation;
use App\Models\ResellerSharedSubscription;
use App\Models\ResellerSharedSubscriptionMember;
use App\Models\Subscription;
use App\Models\User;
use App\Utils\Helper;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ResellerSharedSubscriptionService
{
    private const INVITATION_TTL = 259200;

    public function available(): bool
    {
        return Schema::hasTable('v2_reseller_shared_subscription')
            && Schema::hasTable('v2_reseller_shared_subscription_member')
            && Schema::hasTable('v2_reseller_shared_invitation');
    }

    public function isSharedPlan($plan): bool
    {
        return (int)($plan->shared_member_limit ?? 1) > 1;
    }

    public function synchronizePaidOrder(ResellerOrder $mapping): ?ResellerSharedSubscription
    {
        if (!$this->available()) return null;

        return DB::transaction(function () use ($mapping) {
            $mapping = ResellerOrder::with(['platformOrder', 'plan'])
                ->where('id', $mapping->id)->lockForUpdate()->first();
            if (!$mapping || !$mapping->platformOrder || (int)$mapping->platformOrder->status !== 3) return null;
            if (!$mapping->plan || !$this->isSharedPlan($mapping->plan)) return null;

            $order = $mapping->platformOrder;
            if (!$order->subscription_id) return null;
            $subscription = Subscription::where('id', $order->subscription_id)->lockForUpdate()->first();
            if (!$subscription || (int)$subscription->user_id !== (int)$mapping->user_id) {
                throw new \RuntimeException('Shared subscription does not belong to the group owner');
            }

            $group = $mapping->shared_subscription_id
                ? ResellerSharedSubscription::where('id', $mapping->shared_subscription_id)->lockForUpdate()->first()
                : ResellerSharedSubscription::where('subscription_id', $subscription->id)->lockForUpdate()->first();

            if (!$group) {
                $now = time();
                $group = new ResellerSharedSubscription();
                $group->reseller_id = $mapping->reseller_id;
                $group->reseller_plan_id = $mapping->reseller_plan_id;
                $group->subscription_id = $subscription->id;
                $group->owner_user_id = $mapping->user_id;
                $group->member_limit = max(2, (int)$mapping->plan->shared_member_limit);
                $group->member_count = 1;
                $group->status = 'active';
                $group->created_order_id = $order->id;
                $group->last_order_id = $order->id;
                $group->created_at = $now;
                $group->updated_at = $now;
                $group->save();

                ResellerSharedSubscriptionMember::firstOrCreate(
                    ['shared_subscription_id' => $group->id, 'user_id' => $mapping->user_id],
                    [
                        'reseller_id' => $mapping->reseller_id,
                        'role' => 'owner',
                        'status' => 'active',
                        'joined_at' => $now,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]
                );
            } else {
                if ((int)$group->reseller_id !== (int)$mapping->reseller_id
                    || (int)$group->owner_user_id !== (int)$mapping->user_id
                    || (int)$group->subscription_id !== (int)$subscription->id) {
                    throw new \RuntimeException('Shared subscription mapping mismatch');
                }
                if ($group->status === 'suspended' || $group->status === 'dissolved') {
                    throw new \RuntimeException('Shared group is unavailable');
                }
                $group->last_order_id = $order->id;
                $group->status = 'active';
                $group->suspended_reason = null;
                $group->updated_at = time();
                $group->save();
            }

            if ((int)$mapping->shared_subscription_id !== (int)$group->id) {
                $mapping->shared_subscription_id = $group->id;
                $mapping->save();
            }
            return $group->fresh(['subscription.plan', 'plan']);
        });
    }

    public function groupForUser(User $user, ?ResellerAccount $store = null): ?ResellerSharedSubscription
    {
        if (!$this->available()) return null;
        $query = ResellerSharedSubscriptionMember::with(['group.subscription.plan', 'group.plan'])
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->orderByDesc('id');
        if ($store) $query->where('reseller_id', $store->id);

        foreach ($query->get() as $member) {
            $group = $member->group;
            if ($group && $this->groupAvailable($group)) return $group;
        }
        return null;
    }

    public function hasActiveMembership(User $user): bool
    {
        return $this->available() && ResellerSharedSubscriptionMember::where('user_id', $user->id)
            ->where('status', 'active')->exists();
    }

    public function suspendedGroupForUser(User $user): ?ResellerSharedSubscription
    {
        if (!$this->available()) return null;

        $memberships = ResellerSharedSubscriptionMember::with('group')
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->orderByDesc('id')
            ->get();
        foreach ($memberships as $membership) {
            if ($membership->group && in_array($membership->group->status, ['suspended', 'dissolved'], true)) {
                return $membership->group;
            }
        }
        return null;
    }

    public function ownerGroup(ResellerAccount $store, User $owner): ?ResellerSharedSubscription
    {
        if (!$this->available()) return null;
        $groups = ResellerSharedSubscription::with(['subscription.plan', 'plan'])
            ->where('reseller_id', $store->id)
            ->where('owner_user_id', $owner->id)
            ->whereIn('status', ['active', 'expired'])
            ->orderByDesc('id')->get();
        foreach ($groups as $group) {
            $this->markExpiredIfNeeded($group);
            if (in_array($group->status, ['active', 'expired'], true)) return $group->fresh(['subscription.plan', 'plan']);
        }
        return null;
    }

    public function groupForRenewal(ResellerAccount $store, User $owner, int $resellerPlanId): ?ResellerSharedSubscription
    {
        if (!$this->available()) return null;
        $group = ResellerSharedSubscription::where('reseller_id', $store->id)
            ->where('owner_user_id', $owner->id)
            ->where('reseller_plan_id', $resellerPlanId)
            ->whereIn('status', ['active', 'expired'])
            ->orderByDesc('id')->first();
        if ($group) $this->markExpiredIfNeeded($group);
        return $group;
    }

    public function payload(ResellerSharedSubscription $group, User $user): array
    {
        $group->loadMissing(['subscription.plan', 'plan']);
        $subscription = $group->subscription;
        $member = ResellerSharedSubscriptionMember::where('shared_subscription_id', $group->id)
            ->where('user_id', $user->id)->where('status', 'active')->first();
        if (!$subscription || !$member || !$this->groupAvailable($group)) {
            abort(404, 'Shared subscription is not available');
        }

        $used = max(0, (int)$subscription->u + (int)$subscription->d);
        $total = max(0, (int)$subscription->transfer_enable);
        return [
            'id' => (int)$group->id,
            'reseller_plan_id' => (int)$group->reseller_plan_id,
            'subscription_id' => (int)$subscription->id,
            'plan_name' => optional($group->plan)->name ?: optional($subscription->plan)->name,
            'status' => $group->status,
            'is_owner' => $member->role === 'owner',
            'member_limit' => (int)$group->member_limit,
            'member_count' => (int)$group->member_count,
            'remaining_members' => max(0, (int)$group->member_limit - (int)$group->member_count),
            'total' => $total,
            'used' => $used,
            'remaining' => max(0, $total - $used),
            'usage_percent' => $total > 0 ? min(100, (int)floor($used * 100 / $total)) : 0,
            'transfer_enable' => $total,
            'u' => (int)$subscription->u,
            'd' => (int)$subscription->d,
            'device_limit' => (int)$subscription->device_limit,
            'expired_at' => $subscription->expired_at,
            'subscribe_url' => Helper::getSubscribeUrl($subscription->token, $subscription),
            'traffic_log_available' => false,
            'shared_subscription' => true,
        ];
    }

    public function createInvitation(ResellerAccount $store, User $owner, string $email): array
    {
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) abort(422, 'Invitation email is invalid');

        return DB::transaction(function () use ($store, $owner, $email) {
            $group = $this->lockOwnerGroup($store, $owner);
            if (strtolower((string)$owner->email) === $email) abort(422, 'The group owner is already a member');
            if (!$this->groupAvailable($group)) abort(422, 'Shared group is unavailable');

            $pendingCount = ResellerSharedInvitation::where('shared_subscription_id', $group->id)
                ->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>', time())->count();
            if ((int)$group->member_count + $pendingCount >= (int)$group->member_limit) {
                abort(422, 'No shared member slots are available');
            }
            if (ResellerSharedInvitation::where('shared_subscription_id', $group->id)
                ->where('email', $email)->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>', time())->exists()) {
                abort(422, 'An active invitation already exists for this email');
            }

            $token = bin2hex(random_bytes(32));
            $now = time();
            $invitation = new ResellerSharedInvitation();
            $invitation->reseller_id = $store->id;
            $invitation->shared_subscription_id = $group->id;
            $invitation->email = $email;
            $invitation->token_hash = hash('sha256', $token);
            $invitation->created_by_user_id = $owner->id;
            $invitation->expires_at = $now + self::INVITATION_TTL;
            $invitation->created_at = $now;
            $invitation->updated_at = $now;
            $invitation->save();

            return [
                'id' => (int)$invitation->id,
                'email' => $email,
                'expires_at' => $invitation->expires_at,
                'invite_url' => url('/store/' . $store->store_slug) . '?shared_invite=' . $token,
            ];
        });
    }

    public function invitations(ResellerAccount $store, User $owner): array
    {
        $group = $this->ownerGroup($store, $owner);
        if (!$group) return [];
        return ResellerSharedInvitation::where('shared_subscription_id', $group->id)
            ->orderByDesc('id')->get()->map(function (ResellerSharedInvitation $invite) {
                return [
                    'id' => (int)$invite->id,
                    'email' => $invite->email,
                    'expires_at' => $invite->expires_at,
                    'accepted_at' => $invite->accepted_at,
                    'revoked_at' => $invite->revoked_at,
                    'status' => $invite->accepted_at ? 'accepted' : ($invite->revoked_at ? 'revoked' : ($invite->expires_at < time() ? 'expired' : 'pending')),
                ];
            })->values()->all();
    }

    public function revokeInvitation(ResellerAccount $store, User $owner, int $invitationId): void
    {
        DB::transaction(function () use ($store, $owner, $invitationId) {
            $group = $this->lockOwnerGroup($store, $owner);
            $invite = ResellerSharedInvitation::where('id', $invitationId)
                ->where('shared_subscription_id', $group->id)->lockForUpdate()->first();
            if (!$invite || $invite->accepted_at) abort(404, 'Invitation does not exist');
            $invite->revoked_at = time();
            $invite->updated_at = time();
            $invite->save();
        });
    }

    public function acceptInvitation(ResellerAccount $store, User $user, string $token): ResellerSharedSubscription
    {
        $hash = hash('sha256', trim($token));
        if (!preg_match('/^[a-f0-9]{64}$/', $hash)) abort(422, 'Invitation is invalid');

        return DB::transaction(function () use ($store, $user, $hash) {
            User::where('id', $user->id)->lockForUpdate()->firstOrFail();
            $invite = ResellerSharedInvitation::where('token_hash', $hash)->lockForUpdate()->first();
            if (!$invite || (int)$invite->reseller_id !== (int)$store->id || $invite->accepted_at || $invite->revoked_at || $invite->expires_at < time()) {
                abort(422, 'Invitation is invalid or expired');
            }
            if (strtolower((string)$invite->email) !== strtolower((string)$user->email)) {
                abort(403, 'Invitation email does not match the current account');
            }

            $group = ResellerSharedSubscription::where('id', $invite->shared_subscription_id)->lockForUpdate()->first();
            if (!$group || (int)$group->reseller_id !== (int)$store->id || !$this->groupAvailable($group)) {
                abort(422, 'Shared group is unavailable');
            }
            $this->ensureNoOtherActiveGroup($user, $group->id);
            if ((int)$group->member_count >= (int)$group->member_limit) abort(422, 'No shared member slots are available');

            $now = time();
            $member = ResellerSharedSubscriptionMember::where('shared_subscription_id', $group->id)
                ->where('user_id', $user->id)->lockForUpdate()->first();
            if ($member && $member->status === 'active') abort(422, 'You are already a member of this shared group');
            if (!$member) $member = new ResellerSharedSubscriptionMember();
            $member->reseller_id = $store->id;
            $member->shared_subscription_id = $group->id;
            $member->user_id = $user->id;
            $member->role = 'member';
            $member->status = 'active';
            $member->joined_at = $now;
            $member->removed_at = null;
            $member->removed_by_user_id = null;
            $member->remove_reason = null;
            $member->created_at = $member->created_at ?: $now;
            $member->updated_at = $now;
            $member->save();

            $group->member_count++;
            $group->updated_at = $now;
            $group->save();
            $invite->accepted_by_user_id = $user->id;
            $invite->accepted_at = $now;
            $invite->updated_at = $now;
            $invite->save();
            return $group->fresh(['subscription.plan', 'plan']);
        });
    }

    public function members(ResellerAccount $store, User $owner): array
    {
        $group = $this->ownerGroup($store, $owner);
        if (!$group) return [];
        return ResellerSharedSubscriptionMember::with('user')
            ->where('shared_subscription_id', $group->id)->orderBy('role')->orderBy('id')->get()
            ->map(function (ResellerSharedSubscriptionMember $member) {
                return [
                    'id' => (int)$member->id,
                    'email' => optional($member->user)->email,
                    'role' => $member->role,
                    'status' => $member->status,
                    'joined_at' => $member->joined_at,
                    'removed_at' => $member->removed_at,
                    'remove_reason' => $member->remove_reason,
                ];
            })->values()->all();
    }

    public function removeMember(ResellerAccount $store, User $owner, int $memberId, ?string $reason = null): void
    {
        DB::transaction(function () use ($store, $owner, $memberId, $reason) {
            $group = $this->lockOwnerGroup($store, $owner);
            $member = ResellerSharedSubscriptionMember::where('id', $memberId)
                ->where('shared_subscription_id', $group->id)->where('status', 'active')->lockForUpdate()->first();
            if (!$member) abort(404, 'Shared member does not exist');
            if ($member->role === 'owner') abort(422, 'The group owner cannot be removed');
            $this->removeLockedMember($group, $member, $owner->id, $reason ?: 'Removed by group owner');
        });
    }

    public function rotateOwnerCredential(ResellerAccount $store, User $owner): ResellerSharedSubscription
    {
        return DB::transaction(function () use ($store, $owner) {
            $group = $this->lockOwnerGroup($store, $owner);
            if (!$this->groupAvailable($group)) abort(422, 'Shared group is unavailable');
            $this->rotateLockedGroupCredential($group, 'reseller_shared_owner_rotate');
            return $group->fresh(['subscription.plan', 'plan']);
        });
    }

    public function suspendFromReseller(ResellerAccount $store, int $groupId, string $reason): void
    {
        DB::transaction(function () use ($store, $groupId, $reason) {
            $group = ResellerSharedSubscription::where('id', $groupId)->where('reseller_id', $store->id)->lockForUpdate()->first();
            if (!$group) abort(404, 'Shared group does not exist');
            if ($group->status === 'suspended') return;
            $this->rotateLockedGroupCredential($group, 'reseller_shared_suspend');
            $subscription = Subscription::where('id', $group->subscription_id)->lockForUpdate()->first();
            if ($subscription) {
                $subscription->status = 'suspended';
                $subscription->save();
            }
            $group->status = 'suspended';
            $group->suspended_reason = $reason;
            $group->updated_at = time();
            $group->save();
        });
    }

    public function forceRemoveFromReseller(ResellerAccount $store, int $groupId, int $memberId, string $reason): void
    {
        DB::transaction(function () use ($store, $groupId, $memberId, $reason) {
            $group = ResellerSharedSubscription::where('id', $groupId)->where('reseller_id', $store->id)->lockForUpdate()->first();
            if (!$group) abort(404, 'Shared group does not exist');
            $member = ResellerSharedSubscriptionMember::where('id', $memberId)
                ->where('shared_subscription_id', $group->id)->where('status', 'active')->lockForUpdate()->first();
            if (!$member || $member->role === 'owner') abort(422, 'Only invited members can be removed');
            $this->removeLockedMember($group, $member, null, $reason);
        });
    }

    public function auditGroups(ResellerAccount $store)
    {
        return ResellerSharedSubscription::with(['subscription.plan', 'plan', 'owner'])
            ->where('reseller_id', $store->id)->orderByDesc('id')->paginate(50);
    }

    public function auditMembers(ResellerAccount $store, int $groupId): array
    {
        $group = ResellerSharedSubscription::where('id', $groupId)
            ->where('reseller_id', $store->id)
            ->first();
        if (!$group) abort(404, 'Shared group does not exist');

        return ResellerSharedSubscriptionMember::with('user')
            ->where('reseller_id', $store->id)
            ->where('shared_subscription_id', $group->id)
            ->orderBy('role')
            ->orderBy('id')
            ->get()
            ->map(function (ResellerSharedSubscriptionMember $member) {
                return [
                    'id' => (int)$member->id,
                    'email' => optional($member->user)->email,
                    'role' => $member->role,
                    'status' => $member->status,
                    'joined_at' => $member->joined_at,
                    'removed_at' => $member->removed_at,
                    'remove_reason' => $member->remove_reason,
                ];
            })->values()->all();
    }

    private function lockOwnerGroup(ResellerAccount $store, User $owner): ResellerSharedSubscription
    {
        $group = ResellerSharedSubscription::where('reseller_id', $store->id)
            ->where('owner_user_id', $owner->id)->whereIn('status', ['active', 'expired'])
            ->orderByDesc('id')->lockForUpdate()->first();
        if (!$group) abort(404, 'Shared group does not exist');
        $this->markExpiredIfNeeded($group);
        return $group;
    }

    private function ensureNoOtherActiveGroup(User $user, int $exceptGroupId): void
    {
        $memberships = ResellerSharedSubscriptionMember::with('group.subscription')
            ->where('user_id', $user->id)->where('status', 'active')
            ->where('shared_subscription_id', '!=', $exceptGroupId)->get();
        foreach ($memberships as $membership) {
            if ($membership->group && $this->groupAvailable($membership->group)) {
                abort(422, 'An account can only join one active shared group');
            }
        }
    }

    private function removeLockedMember(ResellerSharedSubscription $group, ResellerSharedSubscriptionMember $member, ?int $operatorUserId, string $reason): void
    {
        $now = time();
        $member->status = 'removed';
        $member->removed_at = $now;
        $member->removed_by_user_id = $operatorUserId;
        $member->remove_reason = mb_substr($reason, 0, 500);
        $member->updated_at = $now;
        $member->save();
        $group->member_count = max(1, (int)$group->member_count - 1);
        $group->updated_at = $now;
        $group->save();
        $this->rotateLockedGroupCredential($group, 'reseller_shared_member_removed');
    }

    private function rotateLockedGroupCredential(ResellerSharedSubscription $group, string $reason): void
    {
        $subscription = Subscription::where('id', $group->subscription_id)->lockForUpdate()->first();
        if (!$subscription) throw new \RuntimeException('Shared subscription does not exist');
        (new SubscriptionService())->rotateCredential($subscription, $reason);
    }

    private function markExpiredIfNeeded(ResellerSharedSubscription $group): void
    {
        $subscription = $group->relationLoaded('subscription') ? $group->subscription : Subscription::find($group->subscription_id);
        if ($group->status === 'active' && (!$subscription || $subscription->status !== 'active' || ($subscription->expired_at && $subscription->expired_at < time()))) {
            $group->status = 'expired';
            $group->updated_at = time();
            $group->save();
        }
    }

    private function groupAvailable(ResellerSharedSubscription $group): bool
    {
        $this->markExpiredIfNeeded($group);
        if ($group->status !== 'active') return false;
        $subscription = $group->relationLoaded('subscription') ? $group->subscription : Subscription::find($group->subscription_id);
        return $subscription && $subscription->status === 'active'
            && (!$subscription->expired_at || $subscription->expired_at >= time());
    }
}
