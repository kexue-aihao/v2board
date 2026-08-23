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
            'reward_dice_daily_limit' => 'required|integer|min:0|max:100',
            'reward_dice_enable' => 'nullable|in:0,1',
            'reward_dice_win_probability' => 'nullable|integer|min:0|max:100',
            'reward_dice_payout_multiplier' => 'nullable|numeric|min:1|max:1000',
            'reward_dice_odds' => 'nullable|integer|min:0|max:100',
            'reward_dice_win_face' => 'required|integer|min:1|max:6',
            'reward_slots_daily_limit' => 'required|integer|min:0|max:100',
            'reward_slots_enable' => 'nullable|in:0,1',
            'reward_slots_win_probability' => 'nullable|integer|min:0|max:100',
            'reward_slots_payout_multiplier' => 'nullable|numeric|min:1|max:1000',
            'reward_slots_odds' => 'nullable|integer|min:0|max:100',
            'reward_slots_jackpot_rate' => 'required|integer|min:1|max:10000',
            'reward_poker_daily_limit' => 'required|integer|min:0|max:100',
            'reward_poker_enable' => 'nullable|in:0,1',
            'reward_poker_win_probability' => 'nullable|integer|min:0|max:100',
            'reward_poker_payout_multiplier' => 'nullable|numeric|min:1|max:1000',
            'reward_poker_odds' => 'nullable|integer|min:0|max:100',
            'reward_group_enable' => 'required|in:0,1',
        ]);
        foreach (['dice', 'slots', 'poker'] as $game) {
            $probability = 'reward_' . $game . '_win_probability';
            $legacyOdds = 'reward_' . $game . '_odds';
            if (!array_key_exists($probability, $data) && array_key_exists($legacyOdds, $data)) {
                $data[$probability] = $data[$legacyOdds];
            }
            unset($data[$legacyOdds]);
            if (array_key_exists('reward_' . $game . '_payout_multiplier', $data)) {
                $data['reward_' . $game . '_payout_multiplier'] = number_format((float)$data['reward_' . $game . '_payout_multiplier'], 2, '.', '');
            }
        }
        $config = config('v2board');
        foreach ($data as $key => $value) {
            $config[$key] = str_ends_with($key, '_payout_multiplier')
                ? number_format((float)$value, 2, '.', '')
                : (is_numeric($value) ? (int)$value : $value);
        }
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
            'reward_dice_daily_limit' => (int)config('v2board.reward_dice_daily_limit', 0),
            'reward_dice_enable' => (int)config('v2board.reward_dice_enable', config('v2board.reward_enable', 1)),
            'reward_dice_win_probability' => (int)config('v2board.reward_dice_win_probability', config('v2board.reward_dice_odds', 10)),
            'reward_dice_payout_multiplier' => number_format((float)config('v2board.reward_dice_payout_multiplier', 1), 2, '.', ''),
            'reward_dice_win_face' => (int)config('v2board.reward_dice_win_face', 6),
            'reward_slots_daily_limit' => (int)config('v2board.reward_slots_daily_limit', 0),
            'reward_slots_enable' => (int)config('v2board.reward_slots_enable', config('v2board.reward_enable', 1)),
            'reward_slots_win_probability' => (int)config('v2board.reward_slots_win_probability', config('v2board.reward_slots_odds', 10)),
            'reward_slots_payout_multiplier' => number_format((float)config('v2board.reward_slots_payout_multiplier', 1), 2, '.', ''),
            'reward_slots_jackpot_rate' => (int)config('v2board.reward_slots_jackpot_rate', 100),
            'reward_poker_daily_limit' => (int)config('v2board.reward_poker_daily_limit', 0),
            'reward_poker_enable' => (int)config('v2board.reward_poker_enable', config('v2board.reward_enable', 1)),
            'reward_poker_win_probability' => (int)config('v2board.reward_poker_win_probability', config('v2board.reward_poker_odds', 5)),
            'reward_poker_payout_multiplier' => number_format((float)config('v2board.reward_poker_payout_multiplier', 1), 2, '.', ''),
            'reward_group_enable' => (int)config('v2board.reward_group_enable', 0),
        ];
    }
}
