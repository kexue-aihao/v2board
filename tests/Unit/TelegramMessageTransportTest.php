<?php

namespace Tests\Unit;

use App\Services\TelegramService;
use Mockery;
use Tests\TestCase;

class TelegramMessageTransportTest extends TestCase
{
    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testLongHtmlMessagesUsePostAndPreserveTheirGroupAndTopic(): void
    {
        $text = '<pre>' . str_repeat('account@example.test | 已过期 | 0.00 | 0.00' . "\n", 60) . '</pre>';
        $curl = Mockery::mock('overload:Curl\Curl');
        $curl->shouldReceive('setConnectTimeout')->once()->with(3);
        $curl->shouldReceive('setTimeout')->once()->with(8);
        $curl->shouldReceive('post')->once()->with('https://api.telegram.org/bottest-token/sendMessage', [
            'chat_id' => -100123456789,
            'text' => $text,
            'parse_mode' => 'HTML',
            'message_thread_id' => 42,
        ])->andSet('response', (object)['ok' => true, 'result' => (object)['message_id' => 7]]);
        $curl->shouldNotReceive('get');
        $curl->shouldReceive('close')->once();

        $response = (new TelegramService('test-token'))->sendMessage(-100123456789, $text, 'HTML', null, 42);
        $this->assertTrue($response->ok);
        $this->assertSame(7, $response->result->message_id);
    }
}
