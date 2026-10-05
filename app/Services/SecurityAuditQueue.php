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
            $metadata = [];
            $classes = [\App\Jobs\SendEmailJob::class, \App\Jobs\SendTelegramJob::class, \App\Jobs\SendTelegramAdminOperationJob::class, \App\Jobs\OrderHandleJob::class];
            // Laravel calls this hook before serialization, with the job object.
            // Read an explicit safe method; never deserialize a queue command.
            $command = $payload['data']['command'] ?? null;
            if (is_object($command) && in_array(get_class($command), $classes, true)) $metadata = SecurityAuditService::redact($command->auditMetadata());
            $origin = ['actor' => $context['actor'], 'request_id' => $context['request_id'], 'action' => $context['action'] ?? '',
                'business' => $context['business'] ?? SecurityAuditBusiness::definition($context['action'] ?? ''),
                'channel' => '异步任务', 'targets' => $context['targets'] ?? [], 'job_metadata' => $metadata,
                'job_ref' => $payload['uuid'] ?? null, 'batch_id' => $context['batch_id'] ?? $context['request_id']];
            $business = SecurityAuditBusiness::envelope(request(), $origin, 'queued', 'queued');
            $business['job'] = ['name' => $payload['displayName'] ?? null, 'ref' => $payload['uuid'] ?? null, 'metadata' => $metadata];
            SecurityAuditService::append('job.queued', 'pending', ['job' => $payload['displayName'] ?? null, 'queue' => $queue,
                'job_ref' => $payload['uuid'] ?? null, 'business' => $business]);
            $context = request()->attributes->get('security_audit_context');
            $context['business_result']['queued_count'] = ($context['business_result']['queued_count'] ?? 0) + 1;
            request()->attributes->set('security_audit_context', $context);
            return ['security_audit' => $origin];
        });
        Event::listen(JobProcessing::class, function ($event) {
            $origin = $event->job->payload()['security_audit'] ?? null;
            if (!$origin) return;
            $request = request();
            $stack = $request->attributes->get('security_audit_queue_stack', []);
            $stack[] = ['job' => spl_object_hash($event->job), 'context' => $request->attributes->get('security_audit_context')];
            $request->attributes->set('security_audit_queue_stack', $stack);
            $request->attributes->set('security_audit_context', $origin + ['changes' => [], 'statements' => [], 'effects' => [], 'business_result' => []]);
            $user = User::find($origin['actor']['id'] ?? 0);
            if (!$user || AdminAccessService::role($user) !== ($origin['actor']['admin_role'] ?? null)
                || (int)$user->admin_version !== (int)($origin['actor']['admin_version'] ?? 0)) {
                self::jobRecord($event, 'job.denied', 'denied', 'denied');
                throw new \Illuminate\Auth\Access\AuthorizationException('管理员权限已变更，异步任务停止执行');
            }
            self::jobRecord($event, 'job.begin', 'pending', 'begin');
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
            SecurityAuditService::replayExternalEffects($context);
            if (($context['business_result']['state'] ?? '') === 'failure') $result = 'failure';
            if (isset($event->exception) && $event->exception instanceof \Illuminate\Auth\Access\AuthorizationException) $result = 'denied';
            $context['detail_records'] = [];
            foreach (array_chunk($context['changes'] ?? [], 100) as $index => $changes) {
                $business = SecurityAuditBusiness::envelope($request, $context, 'changes', $result);
                $business['items'] = array_values(array_filter(array_column($changes, 'business')));
                $business['job'] = self::jobInfo($event, $context);
                $business['effects'] = []; $business['targets'] = [];
                $record = SecurityAuditService::append('job.changes', $result, ['job_id' => $event->job->getJobId(), 'batch' => $index, 'changes' => $changes, 'business' => $business]);
                $context['detail_records'][] = $record['id'];
            }
            SecurityAuditService::persistEffects($context);
            $business = SecurityAuditBusiness::envelope($request, $context, 'finish', $result);
            $business['items'] = SecurityAuditService::summaryItems($context);
            $business['job'] = self::jobInfo($event, $context);
            if (isset($event->exception)) $business['result']['reason'] = SecurityAuditService::failureReason($event->exception);
            SecurityAuditService::append('job.finish', $result, ['job' => $event->job->resolveName(), 'job_id' => $event->job->getJobId(),
                'change_count' => count($context['changes'] ?? []), 'statements' => $context['statements'] ?? [],
                'exception_type' => isset($event->exception) ? get_class($event->exception) : null, 'business' => $business]);
        } finally {
            if ($frame['context'] !== null) {
                $parent = $frame['context'];
                if (!empty($context['external_effects'])) $parent['external_effects'] = array_merge($parent['external_effects'] ?? [], $context['external_effects']);
                $request->attributes->set('security_audit_context', $parent);
            }
            else $request->attributes->remove('security_audit_context');
            array_pop($stack);
            if ($stack) $request->attributes->set('security_audit_queue_stack', $stack);
            else $request->attributes->remove('security_audit_queue_stack');
        }
    }

    private static function jobInfo($event, array $context): array
    {
        return ['name' => $event->job->resolveName(), 'id' => $event->job->getJobId(), 'ref' => $context['job_ref'] ?? $context['job_uuid'] ?? null,
            'attempt' => $event->job->attempts(), 'max_attempts' => $event->job->maxTries(), 'metadata' => $context['job_metadata'] ?? []];
    }

    private static function jobRecord($event, string $eventName, string $result, string $stage): void
    {
        $context = request()->attributes->get('security_audit_context', []);
        $business = SecurityAuditBusiness::envelope(request(), $context, $stage, $result);
        $business['job'] = self::jobInfo($event, $context);
        SecurityAuditService::append($eventName, $result, ['job' => $event->job->resolveName(), 'job_id' => $event->job->getJobId(), 'business' => $business]);
    }
}
