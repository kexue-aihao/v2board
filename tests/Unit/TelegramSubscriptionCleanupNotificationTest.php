<?php

namespace Tests\Unit;

use App\Jobs\SendTelegramAdminOperationJob;
use App\Services\TelegramAdminOperationService;
use App\Services\TelegramService;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class TelegramSubscriptionCleanupNotificationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'v2board.telegram_admin_operation_enable' => 1,
            'v2board.telegram_bot_token' => 'test-token',
            'v2board.telegram_discuss_id' => '-100123456789',
            'v2board.telegram_admin_operation_topic_id' => 42,
            'v2board.app_name' => '测试站点',
        ]);
        Queue::fake();
    }

    public function testScanReportsTheCountAndDoesNotClaimUsersWereDeleted(): void
    {
        TelegramAdminOperationService::subscriptionCleanupScanned(7, ['id' => 9, 'email' => 'admin@example.test'], time());
        Queue::assertPushed(SendTelegramAdminOperationJob::class, 1);
        $values = $this->jobValues(Queue::pushed(SendTelegramAdminOperationJob::class)->first());
        $this->assertSame(-100123456789, $values['chatId']);
        $this->assertSame(42, $values['messageThreadId']);
        $this->assertSame('HTML', $values['parseMode']);
        $this->assertStringContainsString('符合条件：7 个账号', $values['text']);
        $this->assertStringContainsString('本次仅检测，未删除账号', $values['text']);
        $this->assertStringContainsString('admin@example.test', $values['text']);
        $this->assertStringNotContainsString('\\n', $values['text']);
    }

    public function testDeletionUsesAnEscapedMonospaceTableAndAccurateTotals(): void
    {
        $users = [
            $this->record(1, 'a<&>@example.test'),
            $this->record(2, "second\n@example.test", 'empty'),
            $this->record(3, 'third@example.test', 'empty_and_expired'),
        ];
        $users[0]['password'] = 'private-password';
        $users[0]['token'] = 'private-subscription-token';
        TelegramAdminOperationService::subscriptionCleanupDeleted($users, 5, 5, ['id' => 9, 'email' => '<admin>'], time());
        Queue::assertPushed(SendTelegramAdminOperationJob::class, 1);
        $values = $this->jobValues(Queue::pushed(SendTelegramAdminOperationJob::class)->first());
        $this->assertSame('HTML', $values['parseMode']);
        $this->assertStringContainsString('已删除：3；跳过：2；未完成：0', $values['text']);
        $this->assertStringContainsString('&lt;admin&gt;', $values['text']);
        $this->assertStringContainsString('a&lt;&amp;&gt;@example.test', $values['text']);
        $this->assertStringContainsString('second @example.test', $values['text']);
        $this->assertStringContainsString('<pre>', $values['text']);
        $this->assertStringContainsString('空订阅', $values['text']);
        $this->assertStringContainsString('空且过期', $values['text']);
        $this->assertStringContainsString('0.00', $values['text']);
        $this->assertStringNotContainsString('private-', $values['text']);
        $xml = new \DOMDocument();
        $this->assertTrue($xml->loadXML('<root>' . $values['text'] . '</root>'));
        $this->assertSame(1, $xml->getElementsByTagName('pre')->length);
        $this->assertSame(1, $xml->getElementsByTagName('b')->length);
        $lines = explode("\n", $xml->getElementsByTagName('pre')->item(0)->textContent);
        $widths = array_map(function ($line) { return mb_strwidth($line, 'UTF-8'); }, $lines);
        $this->assertCount(1, array_unique($widths), 'table rows must align including Chinese labels');
    }

    public function testLongListsAreSplitWithoutLosingAccountsOrBreakingHtml(): void
    {
        $users = [];
        for ($id = 1; $id <= 205; $id++) {
            $users[] = $this->record($id, 'user-' . $id . '-<>&-账户😀@example.test');
        }
        TelegramAdminOperationService::subscriptionCleanupDeleted($users, 207, 207, [], time());
        $jobs = Queue::pushed(SendTelegramAdminOperationJob::class)->all();
        $this->assertGreaterThan(1, count($jobs));
        $ids = [];
        foreach ($jobs as $index => $job) {
            $values = $this->jobValues($job);
            $this->assertLessThanOrEqual(3800, strlen(mb_convert_encoding($values['text'], 'UTF-16LE', 'UTF-8')) / 2);
            $this->assertStringContainsString('分页：' . ($index + 1) . '/' . count($jobs), $values['text']);
            $this->assertStringContainsString('已删除：205；跳过：2', $values['text']);
            $this->assertSame('send_telegram', $job->queue);
            $this->assertSame($index === 0 ? null : $index * 4, $job->delay);
            $xml = new \DOMDocument();
            $this->assertTrue($xml->loadXML('<root>' . $values['text'] . '</root>'));
            $lines = explode("\n", $xml->getElementsByTagName('pre')->item(0)->textContent);
            foreach (array_slice($lines, 1) as $line) {
                $ids[] = (int)explode('|', $line)[0];
            }
        }
        $this->assertSame(range(1, 205), $ids);
    }

    public function testLongEscapedFieldsStillFitTelegramLimits(): void
    {
        config(['v2board.app_name' => str_repeat('&', 80)]);
        $users = [];
        for ($id = 1; $id <= 8; $id++) {
            $users[] = $this->record($id, str_repeat('&😀', 80));
        }
        TelegramAdminOperationService::subscriptionCleanupDeleted($users, 8, 8, ['email' => str_repeat('&', 120)], time());
        foreach (Queue::pushed(SendTelegramAdminOperationJob::class) as $job) {
            $text = $this->jobValues($job)['text'];
            $this->assertLessThanOrEqual(3800, strlen(mb_convert_encoding($text, 'UTF-16LE', 'UTF-8')) / 2);
            $xml = new \DOMDocument();
            $this->assertTrue($xml->loadXML('<root>' . $text . '</root>'));
        }
    }

    public function testPrivateChatsAndInvalidGroupIdsAreNeverUsed(): void
    {
        foreach (['123456789', '0', '@groupname', 'https://t.me/groupname', ''] as $chatId) {
            config(['v2board.telegram_discuss_id' => $chatId]);
            TelegramAdminOperationService::subscriptionCleanupScanned(1, [], time());
            TelegramAdminOperationService::subscriptionCleanupDeleted([$this->record(1)], 1, 1, [], time());
        }
        Queue::assertNothingPushed();
    }

    public function testDisabledNotificationsOrMissingBotTokenDoNotQueueMessages(): void
    {
        config(['v2board.telegram_admin_operation_enable' => 0]);
        TelegramAdminOperationService::subscriptionCleanupScanned(1, [], time());
        TelegramAdminOperationService::subscriptionCleanupDeleted([$this->record(1)], 1, 1, [], time());
        config(['v2board.telegram_admin_operation_enable' => 1, 'v2board.telegram_bot_token' => '']);
        TelegramAdminOperationService::subscriptionCleanupScanned(1, [], time());
        TelegramAdminOperationService::subscriptionCleanupDeleted([$this->record(1)], 1, 1, [], time());
        Queue::assertNothingPushed();
    }

    public function testNothingDeletedAndPartialFailuresAreClearlyReported(): void
    {
        TelegramAdminOperationService::subscriptionCleanupDeleted([], 2, 2, [], time());
        $text = $this->jobValues(Queue::pushed(SendTelegramAdminOperationJob::class)->first())['text'];
        $this->assertStringContainsString('已删除：0；跳过：2；未完成：0', $text);
        $this->assertStringContainsString('本次没有实际删除账号', $text);
        Queue::fake();
        TelegramAdminOperationService::subscriptionCleanupDeleted([$this->record(1)], 3, 1, [], time(), true);
        $text = $this->jobValues(Queue::pushed(SendTelegramAdminOperationJob::class)->first())['text'];
        $this->assertStringContainsString('无效账号清理中断', $text);
        $this->assertStringContainsString('已删除：1；跳过：0；未完成：2', $text);
        $this->assertStringNotContainsString('无效账号清理完成', $text);
    }

    public function testJobPassesTheHtmlModeGroupAndTopicToTelegram(): void
    {
        $telegram = Mockery::mock(TelegramService::class);
        $telegram->shouldReceive('sendMessage')->once()
            ->with(-100123456789, '<pre>账号列表</pre>', 'HTML', null, 42)
            ->andReturn((object)['ok' => true]);
        $job = new SendTelegramAdminOperationJob(-100123456789, '<pre>账号列表</pre>', 42, 'HTML');
        $job->handle($telegram);
        $this->assertSame('send_telegram', $job->queue);
    }

    private function record(int $id, string $email = 'user@example.test', string $reason = 'expired'): array
    {
        return [
            'id' => $id, 'email' => $email, 'reason' => $reason,
            'expired_at' => $reason === 'empty' ? null : time() - 60,
            'balance' => 0, 'commission_balance' => 0,
        ];
    }

    private function jobValues(SendTelegramAdminOperationJob $job): array
    {
        $values = [];
        foreach (['chatId', 'text', 'messageThreadId', 'parseMode'] as $property) {
            $reflection = new \ReflectionProperty($job, $property);
            $reflection->setAccessible(true);
            $values[$property] = $reflection->getValue($job);
        }
        return $values;
    }
}
