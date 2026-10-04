<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobExceptionOccurred;

class SecurityAuditQueue
{
    public static function register(): void
    {
        if (app()->bound('security.audit.queue.registered')) return;
        app()->instance('security.audit.queue.registered', true);
        \Illuminate\Queue\Queue::createPayloadUsing(function ($connection, $queue, $payload) {
            if (!empty($payload['security_audit'])) return [];
            if (!app()->bound('request')) return [];
            $context = request()->attributes->get('security_audit_context');
            if (!$context || empty($context['actor'])) return [];
            $origin = ['actor' => $context['actor'], 'request_id' => $context['request_id']];
            SecurityAuditService::append('job.queued', 'pending', ['job' => $payload['displayName'] ?? null, 'queue' => $queue,
                'job_uuid' => $payload['uuid'] ?? null]);
            return ['security_audit' => $origin];
        });
        Event::listen(JobProcessing::class, function ($event) {
            $origin = $event->job->payload()['security_audit'] ?? null;
            if (!$origin) return;
            $request = request();
            $stack = $request->attributes->get('security_audit_queue_stack', []);
            $stack[] = ['job' => spl_object_hash($event->job), 'context' => $request->attributes->get('security_audit_context')];
            $request->attributes->set('security_audit_queue_stack', $stack);
            $request->attributes->set('security_audit_context', $origin + ['changes' => [], 'statements' => []]);
            $user = User::find($origin['actor']['id'] ?? 0);
            if (!$user || AdminAccessService::role($user) !== ($origin['actor']['admin_role'] ?? null)
                || (int)$user->admin_version !== (int)($origin['actor']['admin_version'] ?? 0)) {
                SecurityAuditService::append('job.denied', 'denied', ['job' => $event->job->resolveName(), 'job_id' => $event->job->getJobId()]);
                throw new \Illuminate\Auth\Access\AuthorizationException('管理员权限已变更，异步任务停止执行');
            }
            SecurityAuditService::append('job.begin', 'pending', ['job' => $event->job->resolveName(), 'job_id' => $event->job->getJobId()]);
        });
        Event::listen(JobProcessed::class, function ($event) { self::finish($event, 'success'); });
        Event::listen(JobExceptionOccurred::class, function ($event) { self::finish($event, 'failure'); });
    }

    private static function finish($event, string $result): void
    {
        if (empty($event->job->payload()['security_audit'])) return;
        $request = request();
        $stack = $request->attributes->get('security_audit_queue_stack', []);
        $frame = end($stack);
        // A failed completion listener can itself cause JobExceptionOccurred.
        // Never consume the parent's frame a second time in that case.
        if (!$frame || $frame['job'] !== spl_object_hash($event->job)) return;
        try {
            $context = $request->attributes->get('security_audit_context', []);
            foreach (array_chunk($context['changes'] ?? [], 100) as $index => $changes) {
                SecurityAuditService::append('job.changes', $result, ['job_id' => $event->job->getJobId(), 'batch' => $index, 'changes' => $changes]);
            }
            SecurityAuditService::append('job.finish', $result, ['job' => $event->job->resolveName(), 'job_id' => $event->job->getJobId(),
                'change_count' => count($context['changes'] ?? []), 'statements' => $context['statements'] ?? [],
                'exception_type' => isset($event->exception) ? get_class($event->exception) : null]);
        } finally {
            if ($frame['context'] !== null) $request->attributes->set('security_audit_context', $frame['context']);
            else $request->attributes->remove('security_audit_context');
            array_pop($stack);
            if ($stack) $request->attributes->set('security_audit_queue_stack', $stack);
            else $request->attributes->remove('security_audit_queue_stack');
        }
    }
}
