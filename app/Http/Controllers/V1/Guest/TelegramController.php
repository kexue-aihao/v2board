<?php

namespace App\Http\Controllers\V1\Guest;

use App\Http\Controllers\Controller;
use App\Services\TelegramBindingService;
use App\Services\TelegramService;
use Illuminate\Http\Request;

class TelegramController extends Controller
{
    protected $msg;
    protected $commands = [];
    protected $telegramService;

    public function __construct(Request $request)
    {
        if ($request->input('access_token') !== md5(config('v2board.telegram_bot_token'))) {
            abort(401);
        }

        $this->telegramService = new TelegramService();
    }

    public function webhook(Request $request)
    {
        $data = $request->input();
        if ($this->handleBindingUpdate($data)) {
            return response(['data' => true]);
        }
        $this->formatMessage($data);
        $this->handle();
        return response(['data' => true]);
    }

    private function handleBindingUpdate(array $data): bool
    {
        $bindingService = new TelegramBindingService();
        if (isset($data['chat_join_request'])) {
            $request = $data['chat_join_request'];
            $chatId = (string)($request['chat']['id'] ?? '');
            $userId = (int)($request['from']['id'] ?? 0);
            if ($bindingService->enabled() && $bindingService->available()) {
                $approved = $bindingService->processJoinRequest($chatId, (array)($request['from'] ?? []));
                if ($approved) {
                    $this->telegramService->approveChatJoinRequest((int)$chatId, $userId);
                } else {
                    $this->telegramService->declineChatJoinRequest((int)$chatId, $userId);
                }
                return true;
            }
            $this->formatLegacyChatJoinRequest($data);
            return true;
        }
        if (isset($data['chat_member']) && $bindingService->enabled() && $bindingService->available()) {
            $bindingService->processChatMemberUpdate((array)$data['chat_member']);
            return true;
        }
        if (isset($data['message']['text']) && $bindingService->enabled() && $bindingService->available()) {
            $message = $data['message'];
            if (($message['chat']['type'] ?? '') !== 'private') return false;
            $parts = preg_split('/\s+/', trim((string)$message['text']), 2);
            if (($parts[0] ?? '') !== '/start' && strpos((string)($parts[0] ?? ''), '/start@') !== 0) {
                return false;
            }
            $argument = trim((string)($parts[1] ?? ''));
            if (strpos($argument, 'bind_') !== 0) return false;
            try {
                $result = $bindingService->completeFromBot(
                    substr($argument, 5),
                    $message['from']['id'] ?? '',
                    $message['from']['username'] ?? '',
                    $message['chat']['id'] ?? ''
                );
                $this->telegramService->sendMessage(
                    (int)$message['chat']['id'],
                    '绑定成功。请返回网站申请加入售后群。'
                );
            } catch (\Throwable $e) {
                report($e);
                $this->telegramService->sendMessage(
                    (int)$message['chat']['id'],
                    '绑定失败，请返回网站重新生成绑定链接后再试。'
                );
            }
            return true;
        }
        return false;
    }

    public function handle()
    {
        if (!$this->msg) return;
        $msg = $this->msg;
        $commandName = explode('@', $msg->command);

        // To reduce request, only commands contains @ will get the bot name
        if (count($commandName) == 2) {
            $botName = $this->getBotName();
            if ($commandName[1] === $botName){
                $msg->command = $commandName[0];
            }
        }

        try {
            foreach (glob(base_path('app//Plugins//Telegram//Commands') . '/*.php') as $file) {
                $command = basename($file, '.php');
                $class = '\\App\\Plugins\\Telegram\\Commands\\' . $command;
                if (!class_exists($class)) continue;
                $instance = new $class();
                if ($msg->message_type === 'message') {
                    if (!isset($instance->command)) continue;
                    if ($msg->command !== $instance->command) continue;
                    $instance->handle($msg);
                    return;
                }
                if ($msg->message_type === 'reply_message') {
                    if (!isset($instance->regex)) continue;
                    if (!preg_match($instance->regex, $msg->reply_text, $match)) continue;
                    $instance->handle($msg, $match);
                    return;
                }
            }
        } catch (\Exception $e) {
            $this->telegramService->sendMessage($msg->chat_id, $e->getMessage());
        }
    }

    public function getBotName()
    {
        $response = $this->telegramService->getMe();
        return $response->result->username;
    }

    private function formatMessage(array $data)
    {
        if (!isset($data['message'])) return;
        if (!isset($data['message']['text'])) return;
        $obj = new \StdClass();
        $text = explode(' ', $data['message']['text']);
        $obj->command = $text[0];
        $obj->args = array_slice($text, 1);
        $obj->chat_id = $data['message']['chat']['id'];
        $obj->message_id = $data['message']['message_id'];
        $obj->message_type = 'message';
        $obj->text = $data['message']['text'];
        $obj->is_private = $data['message']['chat']['type'] === 'private';
        if (isset($data['message']['reply_to_message']['text'])) {
            $obj->message_type = 'reply_message';
            $obj->reply_text = $data['message']['reply_to_message']['text'];
        }
        $this->msg = $obj;
    }

    private function formatLegacyChatJoinRequest(array $data)
    {
        if (!isset($data['chat_join_request'])) return;
        if (!isset($data['chat_join_request']['from']['id'])) return;
        if (!isset($data['chat_join_request']['chat']['id'])) return;
        $user = \App\Models\User::where('telegram_id', $data['chat_join_request']['from']['id'])
            ->first();
        if (!$user) {
            $this->telegramService->declineChatJoinRequest(
                $data['chat_join_request']['chat']['id'],
                $data['chat_join_request']['from']['id']
            );
            return;
        }
        $userService = new \App\Services\UserService();
        if (!$userService->isAvailable($user)) {
            $this->telegramService->declineChatJoinRequest(
                $data['chat_join_request']['chat']['id'],
                $data['chat_join_request']['from']['id']
            );
            return;
        }
        $userService = new \App\Services\UserService();
        $this->telegramService->approveChatJoinRequest(
            $data['chat_join_request']['chat']['id'],
            $data['chat_join_request']['from']['id']
        );
    }
}
