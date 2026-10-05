<?php

namespace App\Plugins\Telegram\Commands;

use App\Models\User;
use App\Plugins\Telegram\Telegram;
use App\Services\TicketService;

class ReplyTicket extends Telegram {
    public $regex = '/[#](.*)/';
    public $description = '快速工单回复';

    public function handle($message, $match = []) {
        if (!$message->is_private) return;
        $this->replayTicket($message, $match[1]);
    }


    private function replayTicket($msg, $ticketId)
    {
        $user = User::where('telegram_id', $msg->chat_id)->first();
        if (!$user) {
            abort(500, '用户不存在');
        }
        if (!$msg->text) return;
        $role = \App\Services\AdminAccessService::role($user);
        if (!$role || !\App\Services\AdminAccessService::allows($role, 'V1\\Admin\\TicketController@reply')) return;
        $ticketService = new TicketService();
        \App\Services\SecurityAuditService::run(request(), \App\Services\AdminAccessService::actor($user), function () use ($ticketService, $ticketId, $msg, $user) {
            $ticketService->replyByAdmin($ticketId, $msg->text, $user->id);
            return response(['data' => true]);
        }, ['action' => 'App\\Http\\Controllers\\V1\\Admin\\TicketController@reply', 'channel' => 'Telegram',
            'targets' => [\App\Services\SecurityAuditBusiness::object('v2_ticket', $ticketId)]]);
        $telegramService = $this->telegramService;
        $telegramService->sendMessage($msg->chat_id, "#`{$ticketId}` 的工单已回复成功", 'markdown');
        $telegramService->sendMessageWithAdmin("#`{$ticketId}` 的工单已由 {$user->email} 进行回复", true);
    }
}
