<?php

namespace Tests\Unit;

use App\Jobs\SendTelegramAdminOperationJob;
use App\Services\TelegramAdminOperationService;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class TelegramAdminOperationServiceTest extends TestCase
{
    public function testNodeNotificationUsesOnlySafeFieldsAndTopic(): void
    {
        config([
            'v2board.telegram_admin_operation_enable' => 1,
            'v2board.telegram_bot_token' => 'test-token',
            'v2board.telegram_discuss_id' => '-100123456789',
            'v2board.telegram_admin_operation_topic_id' => 42,
        ]);
        Queue::fake();

        $node = (object)[
            'show' => 1,
            'name' => "东京 node.example.com\n01",
            'rate' => '1.50',
            'host' => 'secret.example.com',
            'port' => 443,
            'password' => 'secret',
        ];

        TelegramAdminOperationService::nodeCreated($node, 'vless');

        Queue::assertPushed(SendTelegramAdminOperationJob::class, function ($job) {
            $values = $this->jobValues($job);
            return $values['chatId'] === -100123456789
                && $values['messageThreadId'] === 42
                && strpos($values['text'], '节点上架') !== false
                && strpos($values['text'], 'VLESS') !== false
                && strpos($values['text'], '1.5x') !== false
                && strpos($values['text'], 'node.example.com') === false
                && strpos($values['text'], 'secret') === false
                && strpos($values['text'], "\n01") === false;
        });
    }

    public function testUnchangedOrHiddenObjectsDoNotNotify(): void
    {
        config([
            'v2board.telegram_admin_operation_enable' => 1,
            'v2board.telegram_bot_token' => 'test-token',
            'v2board.telegram_discuss_id' => '-100123456789',
        ]);
        Queue::fake();

        $node = (object)['show' => 1, 'name' => 'node', 'rate' => 1];
        TelegramAdminOperationService::nodeVisibilityChanged($node, 'vmess', 1);
        TelegramAdminOperationService::nodeCreated((object)['show' => 0, 'name' => 'hidden', 'rate' => 1], 'vmess');

        Queue::assertNothingPushed();
    }

    public function testPublishedPlanDeletionUsesPlanNameOnly(): void
    {
        config([
            'v2board.telegram_admin_operation_enable' => 1,
            'v2board.telegram_bot_token' => 'test-token',
            'v2board.telegram_discuss_id' => '-100123456789',
        ]);
        Queue::fake();

        TelegramAdminOperationService::planDeleted((object)[
            'show' => 1,
            'name' => '月付基础版',
            'content' => 'internal details',
        ]);

        Queue::assertPushed(SendTelegramAdminOperationJob::class, function ($job) {
            $text = $this->jobValues($job)['text'];
            return strpos($text, '套餐下架') !== false
                && strpos($text, '月付基础版') !== false
                && strpos($text, 'internal details') === false;
        });
    }

    private function jobValues($job): array
    {
        $values = [];
        foreach (['chatId', 'text', 'messageThreadId'] as $property) {
            $reflection = new \ReflectionProperty($job, $property);
            $reflection->setAccessible(true);
            $values[$property] = $reflection->getValue($job);
        }
        return $values;
    }
}
