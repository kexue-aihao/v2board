<?php

namespace App\Http\Controllers\V1\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class RewardController extends Controller
{
    public function fetch()
    {
        return response(['data' => $this->values()]);
    }

    public function save(Request $request)
    {
        $data = $request->validate([
            'reward_enable' => 'required|in:0,1',
            'reward_daily_game_limit' => 'required|integer|min:0|max:100',
            'reward_dice_six_gb' => 'required|integer|min:1|max:10',
            'reward_dice_win_face' => 'required|integer|min:1|max:6',
            'reward_slots_jackpot_rate' => 'required|integer|min:1|max:10000',
            'reward_slots_triple_gb' => 'required|integer|min:1|max:10',
            'reward_poker_winner_gb' => 'required|integer|min:1|max:10',
            'reward_group_enable' => 'required|in:0,1',
        ]);
        $config = config('v2board');
        foreach ($data as $key => $value) $config[$key] = is_numeric($value) ? (int)$value : $value;
        if (!File::put(base_path('config/v2board.php'), "<?php\n return " . var_export($config, true) . " ;", LOCK_EX)) {
            abort(500, '保存奖励配置失败，请检查 config 目录写入权限');
        }
        $cacheError = null;
        try {
            if (Artisan::call('config:cache') !== 0) {
                $cacheError = 'config:cache returned a non-zero exit code';
            }
        } catch (\Throwable $exception) {
            $cacheError = $exception->getMessage();
        }
        if ($cacheError !== null) {
            Log::error('Reward configuration cache failed after file write.', [
                'error' => $cacheError,
                'path' => base_path('config/v2board.php'),
            ]);
        }

        // PHP-FPM may not load ext-posix even when the Webman CLI does. Do not
        // turn a successful configuration write into a 500 solely because the
        // optional in-process reload signal is unavailable.
        $restarting = false;
        try {
            if (Cache::has('WEBMANPID')) {
                $pid = Cache::get('WEBMANPID');
                Cache::forget('WEBMANPID');
                $restarting = function_exists('posix_kill') && is_numeric($pid)
                    ? (bool) posix_kill((int) $pid, 15)
                    : false;
            }
        } catch (\Throwable $exception) {
            Log::warning('Reward configuration saved but Webman reload failed.', [
                'error' => $exception->getMessage(),
            ]);
        }
        return response(['data' => array_merge($this->values(), $data, [
            'restarting' => $restarting,
            'config_cache_warning' => $cacheError !== null,
        ])]);
    }

    private function values(): array
    {
        return [
            'reward_enable' => (int)config('v2board.reward_enable', 1),
            'reward_daily_game_limit' => (int)config('v2board.reward_daily_game_limit', 3),
            'reward_dice_six_gb' => (int)config('v2board.reward_dice_six_gb', 10),
            'reward_dice_win_face' => (int)config('v2board.reward_dice_win_face', 6),
            'reward_slots_jackpot_rate' => (int)config('v2board.reward_slots_jackpot_rate', 100),
            'reward_slots_triple_gb' => (int)config('v2board.reward_slots_triple_gb', 10),
            'reward_poker_winner_gb' => (int)config('v2board.reward_poker_winner_gb', 5),
            'reward_group_enable' => (int)config('v2board.reward_group_enable', 0),
        ];
    }
}
