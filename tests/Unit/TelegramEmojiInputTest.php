<?php

namespace Tests\Unit;

use App\Http\Controllers\V1\Guest\TelegramController;
use Tests\TestCase;

class TelegramEmojiInputTest extends TestCase
{
    public function testDiceEmojiOpensTheDiceGame(): void
    {
        $message = $this->formatMessage([
            'message' => [
                'chat' => ['id' => 1001, 'type' => 'private'],
                'from' => ['id' => 2001],
                'message_id' => 1,
                'text' => '🎲',
            ],
        ]);

        $this->assertSame('/dice', $message->command);
        $this->assertSame([], $message->args);
    }

    public function testSlotsEmojiOpensTheSlotsGame(): void
    {
        $message = $this->formatMessage([
            'message' => [
                'chat' => ['id' => 1002, 'type' => 'private'],
                'from' => ['id' => 2002],
                'message_id' => 2,
                'text' => ' 🎰 ',
            ],
        ]);

        $this->assertSame('/slots', $message->command);
        $this->assertSame([], $message->args);
    }

    public function testNativeTelegramDiceEmojiIsAcceptedWithoutText(): void
    {
        $message = $this->formatMessage([
            'message' => [
                'chat' => ['id' => 1003, 'type' => 'private'],
                'from' => ['id' => 2003],
                'message_id' => 3,
                'dice' => ['emoji' => '🎰', 'value' => 32],
            ],
        ]);

        $this->assertSame('/slots', $message->command);
        $this->assertSame([], $message->args);
    }

    private function formatMessage(array $update)
    {
        $controller = new TelegramController();
        $method = (new \ReflectionClass($controller))->getMethod('formatMessage');
        $method->setAccessible(true);
        $method->invoke($controller, $update);

        $property = (new \ReflectionClass($controller))->getProperty('msg');
        $property->setAccessible(true);
        return $property->getValue($controller);
    }
}
