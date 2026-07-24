<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserTwoFactor;
use App\Utils\CacheKey;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use PragmaRX\Google2FA\Google2FA;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

class TwoFactorService
{
    const CHALLENGE_TTL = 300;
    const CODE_STEP = 30;

    private $google2fa;

    public function __construct()
    {
        $this->google2fa = new Google2FA();
        $this->google2fa->setWindow(1);
        $this->google2fa->setKeyRegeneration(self::CODE_STEP);
        $this->google2fa->setOneTimePasswordLength(6);
    }

    public function record(User $user)
    {
        return UserTwoFactor::firstOrCreate(['user_id' => $user->id]);
    }

    public function isEnabled($userId)
    {
        $record = UserTwoFactor::where('user_id', $userId)->first();
        return $record && (bool)$record->enabled && !empty($record->secret_encrypted);
    }

    public function requiresSetup(User $user)
    {
        return ($user->is_admin || $user->is_staff)
            && (int)config('v2board.admin_2fa_force_enable', 0) === 1
            && !$this->isEnabled($user->id);
    }

    public function requiresLoginChallenge(User $user)
    {
        return $this->isEnabled($user->id);
    }

    public function issueLoginResult(User $user, $request)
    {
        if ($this->requiresLoginChallenge($user)) {
            return $this->challengeResponse($user, $request, 'login');
        }
        if ($this->requiresSetup($user)) {
            return $this->challengeResponse($user, $request, 'setup');
        }
        return null;
    }

    public function challengeResponse(User $user, $request, $type)
    {
        $token = bin2hex(random_bytes(32));
        $cacheKey = CacheKey::get(
            $type === 'setup' ? 'TWO_FACTOR_SETUP' : 'TWO_FACTOR_CHALLENGE',
            hash('sha256', $token)
        );
        Cache::put($cacheKey, [
            'user_id' => $user->id,
            'type' => $type,
            'ip' => $request->ip()
        ], self::CHALLENGE_TTL);

        return [
            'two_factor_required' => $type === 'login',
            'two_factor_setup_required' => $type === 'setup',
            'challenge' => $token,
            'expires_in' => self::CHALLENGE_TTL,
            'recovery_allowed' => $type === 'login'
        ];
    }

    public function getChallenge($token, $type = 'login')
    {
        if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/', $token)) return null;
        $key = CacheKey::get(
            $type === 'setup' ? 'TWO_FACTOR_SETUP' : 'TWO_FACTOR_CHALLENGE',
            hash('sha256', $token)
        );
        return Cache::get($key);
    }

    public function forgetChallenge($token, $type = 'login')
    {
        $key = CacheKey::get(
            $type === 'setup' ? 'TWO_FACTOR_SETUP' : 'TWO_FACTOR_CHALLENGE',
            hash('sha256', $token)
        );
        Cache::forget($key);
    }

    public function verifyLogin($token, $code, $recoveryCode, $request)
    {
        $challenge = $this->getChallenge($token, 'login');
        if (!$challenge || empty($challenge['user_id'])) abort(500, '二步验证请求已过期，请重新登录');
        $user = User::find($challenge['user_id']);
        if (!$user || $user->banned || !$this->isEnabled($user->id)) abort(500, '二步验证不可用');

        $valid = DB::transaction(function () use ($user, $code, $recoveryCode, $request) {
            $record = UserTwoFactor::where('user_id', $user->id)->lockForUpdate()->first();
            if (!$record || !$record->enabled) return false;
            $valid = $this->validCode($record, $code) || $this->consumeRecoveryCode($record, $recoveryCode);
            if ($valid) $this->audit($user, 'login_verified', $request);
            return $valid;
        });
        if (!$valid) {
            $this->registerFailure($token, 'login');
            abort(500, '二步验证码不正确');
        }
        $this->forgetChallenge($token, 'login');
        return $user;
    }

    public function beginSetup(User $user)
    {
        $record = $this->record($user);
        if ($record->enabled) abort(500, '二步验证已经启用');
        $secret = $this->google2fa->generateSecretKey();
        $record->pending_secret_encrypted = Crypt::encryptString($secret);
        $record->save();

        $issuer = (string)config('v2board.app_name', 'V2Board');
        $uri = $this->google2fa->getQRCodeUrl($issuer, $user->email, $secret);
        $qrCode = null;
        try {
            if (class_exists(QROptions::class) && class_exists(QRCode::class)) {
                $options = new QROptions([
                    'outputType' => QRCode::OUTPUT_MARKUP_SVG,
                    'eccLevel' => QRCode::ECC_L
                ]);
                $svg = (new QRCode($options))->render($uri);
                if (is_string($svg) && $svg !== '') {
                    $qrCode = 'data:image/svg+xml;base64,' . base64_encode($svg);
                }
            }
        } catch (\Throwable $exception) {
            // Keep setup usable with the manual key when the optional QR renderer is unavailable.
        }

        return [
            'issuer' => $issuer,
            'account' => $user->email,
            'manual_key' => $secret,
            'otpauth_uri' => $uri,
            'qr_code' => $qrCode
        ];
    }

    public function confirmSetup(User $user, $code, $request, $setupToken = null)
    {
        $recoveryCodes = $this->generateRecoveryCodes();
        $hashes = [];
        foreach ($recoveryCodes as $recoveryCode) $hashes[] = password_hash($recoveryCode, PASSWORD_DEFAULT);
        DB::transaction(function () use ($user, $code, $request, $hashes) {
            $record = UserTwoFactor::where('user_id', $user->id)->lockForUpdate()->first();
            if (!$record || $record->enabled || empty($record->pending_secret_encrypted)) abort(500, '请先获取新的绑定二维码');
            $secret = Crypt::decryptString($record->pending_secret_encrypted);
            $matchedStep = $this->matchedStep($secret, $this->normalizeCode($code));
            if ($matchedStep === false) {
                $failureToken = $setupToken ?: 'setup-user-' . $user->id . '-' . (string)$request->ip();
                $this->registerFailure($failureToken, 'setup');
                abort(500, '二步验证码不正确');
            }
            $record->secret_encrypted = Crypt::encryptString($secret);
            $record->pending_secret_encrypted = null;
            $record->enabled = true;
            $record->confirmed_at = time();
            $record->recovery_codes = json_encode($hashes);
            $record->last_used_step = $matchedStep;
            $record->save();
            $this->audit($user, 'enabled', $request);
        });
        (new AuthService($user))->removeAllSession();
        return $recoveryCodes;
    }

    public function disable(User $user, $code, $recoveryCode, $request)
    {
        DB::transaction(function () use ($user, $code, $recoveryCode, $request) {
            $record = UserTwoFactor::where('user_id', $user->id)->lockForUpdate()->first();
            if (!$record || !$record->enabled) abort(500, '二步验证尚未启用');
            if (!$this->validCode($record, $code) && !$this->consumeRecoveryCode($record, $recoveryCode)) abort(500, '二步验证码不正确');
            $record->enabled = false;
            $record->secret_encrypted = null;
            $record->pending_secret_encrypted = null;
            $record->recovery_codes = null;
            $record->last_used_step = null;
            $record->save();
            $this->audit($user, 'disabled', $request);
        });
        (new AuthService($user))->removeAllSession();
    }

    public function regenerateRecoveryCodes(User $user, $code, $recoveryCode, $request)
    {
        $recoveryCodes = $this->generateRecoveryCodes();
        $hashes = [];
        foreach ($recoveryCodes as $recoveryCode) $hashes[] = password_hash($recoveryCode, PASSWORD_DEFAULT);
        DB::transaction(function () use ($user, $code, $recoveryCode, $request, $hashes) {
            $record = UserTwoFactor::where('user_id', $user->id)->lockForUpdate()->first();
            if (!$record || !$record->enabled) abort(500, '二步验证尚未启用');
            if (!$this->validCode($record, $code) && !$this->consumeRecoveryCode($record, $recoveryCode)) abort(500, '二步验证码不正确');
            $record->recovery_codes = json_encode($hashes);
            $record->save();
            $this->audit($user, 'recovery_codes_regenerated', $request);
        });
        (new AuthService($user))->removeAllSession();
        return $recoveryCodes;
    }

    public function status(User $user)
    {
        $record = $this->record($user);
        return [
            'enabled' => (bool)$record->enabled,
            'issuer' => (string)config('v2board.app_name', 'V2Board'),
            'account' => $user->email
        ];
    }

    public function emergencyDisable(User $user)
    {
        DB::transaction(function () use ($user) {
            $record = UserTwoFactor::where('user_id', $user->id)->lockForUpdate()->first();
            if (!$record) return;
            $record->enabled = false;
            $record->secret_encrypted = null;
            $record->pending_secret_encrypted = null;
            $record->recovery_codes = null;
            $record->last_used_step = null;
            $record->save();
            $ok = DB::table('v2_two_factor_audit')->insert([
                'user_id' => $user->id,
                'actor_user_id' => null,
                'action' => 'emergency_disabled',
                'ip' => null,
                'user_agent' => 'artisan',
                'metadata' => null,
                'created_at' => time()
            ]);
            if (!$ok) abort(500, '安全审计记录写入失败，操作未执行');
        });
        (new AuthService($user))->removeAllSession();
    }

    private function validCode(UserTwoFactor $record, $code)
    {
        if (!is_string($code) || !preg_match('/^\d{6}$/', $code) || empty($record->secret_encrypted)) return false;
        $secret = Crypt::decryptString($record->secret_encrypted);
        $step = $this->matchedStep($secret, $code);
        if ($step === false || ($record->last_used_step !== null && $step <= (int)$record->last_used_step)) return false;
        $record->last_used_step = $step;
        $record->save();
        return true;
    }

    private function matchedStep($secret, $code)
    {
        if (!is_string($secret) || !preg_match('/^\d{6}$/', (string)$code)) return false;
        $currentStep = (int)floor(time() / self::CODE_STEP);
        for ($offset = -1; $offset <= 1; $offset++) {
            $step = $currentStep + $offset;
            if ($step < 0) continue;
            if (hash_equals($this->otpAtStep($secret, $step), (string)$code)) return $step;
        }
        return false;
    }

    private function otpAtStep($secret, $step)
    {
        if (method_exists($this->google2fa, 'getCurrentOtp')) {
            return (string)$this->google2fa->getCurrentOtp($secret, $step * self::CODE_STEP);
        }

        // RFC 4226 HOTP calculation is the fallback for package versions without getCurrentOtp.
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $buffer = 0;
        $bits = 0;
        $key = '';
        foreach (str_split(rtrim(strtoupper($secret), '=')) as $character) {
            $value = strpos($alphabet, $character);
            if ($value === false) return '';
            $buffer = ($buffer << 5) | $value;
            $bits += 5;
            if ($bits >= 8) {
                $bits -= 8;
                $key .= chr(($buffer >> $bits) & 0xff);
            }
        }
        $counter = pack('N2', 0, (int)$step);
        $hash = hash_hmac('sha1', $counter, $key, true);
        $offset = ord($hash[19]) & 0x0f;
        $binary = ((ord($hash[$offset]) & 0x7f) << 24)
            | (ord($hash[$offset + 1]) << 16)
            | (ord($hash[$offset + 2]) << 8)
            | ord($hash[$offset + 3]);
        return str_pad((string)($binary % 1000000), 6, '0', STR_PAD_LEFT);
    }

    private function consumeRecoveryCode(UserTwoFactor $record, $code)
    {
        if (!is_string($code) || empty($record->recovery_codes)) return false;
        $code = strtoupper(trim($code));
        $codes = json_decode($record->recovery_codes, true);
        if (!is_array($codes)) return false;
        foreach ($codes as $index => $hash) {
            if (password_verify($code, $hash)) {
                unset($codes[$index]);
                $record->recovery_codes = json_encode(array_values($codes));
                $record->save();
                return true;
            }
        }
        return false;
    }

    private function generateRecoveryCodes()
    {
        $codes = [];
        for ($i = 0; $i < 8; $i++) {
            $raw = strtoupper(bin2hex(random_bytes(6)));
            $codes[] = substr($raw, 0, 4) . '-' . substr($raw, 4, 4) . '-' . substr($raw, 8, 4);
        }
        return $codes;
    }

    private function normalizeCode($code)
    {
        return is_string($code) ? preg_replace('/\s+/', '', $code) : '';
    }

    private function registerFailure($token, $type)
    {
        $key = CacheKey::get('TWO_FACTOR_FAILURE', hash('sha256', (string)$token));
        $count = (int)Cache::get($key, 0) + 1;
        if ($count >= 5) {
            $this->forgetChallenge($token, $type);
            Cache::forget($key);
            abort(429, '验证失败次数过多，请重新登录');
        }
        Cache::put($key, $count, self::CHALLENGE_TTL);
    }

    private function audit(User $user, $action, $request)
    {
        $ok = DB::table('v2_two_factor_audit')->insert([
            'user_id' => $user->id,
            'actor_user_id' => $user->id,
            'action' => $action,
            'ip' => substr((string)$request->ip(), 0, 64),
            'user_agent' => substr((string)$request->userAgent(), 0, 500),
            'metadata' => null,
            'created_at' => time()
        ]);
        if (!$ok) abort(500, '安全审计记录写入失败，操作未执行');
    }
}
