<?php

namespace App\Jobs;

use App\Models\TelegramSubscriptionBinding;
use App\Services\TelegramService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class KickTelegramBinding implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $timeout = 20;
    private $bindingId;
    private $telegramUserId;
    private $chatId;
    private $forceSnapshot;

    public function __construct(int $bindingId, string $telegramUserId = '', string $chatId = '', bool $forceSnapshot = false)
    {
        $this->bindingId = $bindingId;
        $this->telegramUserId = $telegramUserId;
        $this->chatId = $chatId;
        $this->forceSnapshot = $forceSnapshot;
        $this->onQueue('telegram');
    }

    public function handle()
    {
        $service = new TelegramService();
        if ($this->forceSnapshot) {
            $service->banChatMember($this->chatId, $this->telegramUserId);
            $service->unbanChatMember($this->chatId, $this->telegramUserId);
            return;
        }
        $binding = TelegramSubscriptionBinding::find($this->bindingId);
        if (!$binding) return;
        if ($binding->status === 'active'
            || ($this->telegramUserId !== '' && (string)$binding->telegram_user_id !== $this->telegramUserId)
            || ($this->chatId !== '' && (string)$binding->chat_id !== $this->chatId)) return;
        try {
            $service->banChatMember($binding->chat_id, $binding->telegram_user_id);
            $service->unbanChatMember($binding->chat_id, $binding->telegram_user_id);
        } catch (\Throwable $e) {
            report($e);
            throw $e;
        }
    }
}
