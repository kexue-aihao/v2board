<?php

namespace App\Console\Commands;

use App\Models\GameRoom;
use Illuminate\Console\Command;

class PruneRewardRooms extends Command
{
    protected $signature = 'reward:prune-rooms';
    protected $description = '关闭超时的娱乐房间';

    public function handle(): int
    {
        $count = GameRoom::where('status', 'open')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', time())
            ->update(['status' => 'expired', 'updated_at' => time()]);
        $this->info("已关闭 {$count} 个超时娱乐房间。");
        return self::SUCCESS;
    }
}
