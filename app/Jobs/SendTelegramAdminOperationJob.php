<?php

namespace App\Jobs;

use App\Services\TelegramService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendTelegramAdminOperationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $chatId;
    protected $text;
    protected $messageThreadId;
    protected $parseMode = '';

    public $tries = 3;
    public $timeout = 10;
    public $backoff = [5, 30];

    public function __construct(int $chatId, string $text, ?int $messageThreadId = null, string $parseMode = '')
    {
        $this->onQueue('send_telegram');
        $this->chatId = $chatId;
        $this->text = $text;
        $this->messageThreadId = $messageThreadId;
        $this->parseMode = $parseMode;
    }

    public function handle(TelegramService $telegram)
    {
        $telegram->sendMessage($this->chatId, $this->text, $this->parseMode, null, $this->messageThreadId);
        \App\Services\SecurityAuditService::effect('管理员 Telegram 操作通知发送完成', $this->auditMetadata(), 'success', true);
    }

    public function auditMetadata(): array
    {
        return ['telegram_id' => $this->chatId, 'topic_id' => $this->messageThreadId, 'body_length' => mb_strlen($this->text)];
    }
}
