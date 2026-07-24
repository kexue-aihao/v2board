<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\TwoFactorService;
use App\Utils\Helper;
use Illuminate\Http\Request;

class TwoFactorController extends Controller
{
    private function user(Request $request)
    {
        $user = User::find($request->user['id']);
        if (!$user) abort(500, __('The user does not exist'));
        return $user;
    }

    public function status(Request $request)
    {
        return response(['data' => (new TwoFactorService())->status($this->user($request))]);
    }

    public function setup(Request $request)
    {
        return response(['data' => (new TwoFactorService())->beginSetup($this->user($request))]);
    }

    public function confirm(Request $request)
    {
        $code = $request->input('code');
        $codes = (new TwoFactorService())->confirmSetup($this->user($request), $code, $request);
        return response(['data' => ['enabled' => true, 'recovery_codes' => $codes]]);
    }

    public function disable(Request $request)
    {
        $user = $this->user($request);
        $this->verifyPassword($user, $request->input('current_password'));
        (new TwoFactorService())->disable($user, $request->input('code'), $request->input('recovery_code'), $request);
        return response(['data' => true]);
    }

    public function regenerateRecoveryCodes(Request $request)
    {
        $user = $this->user($request);
        $this->verifyPassword($user, $request->input('current_password'));
        $codes = (new TwoFactorService())->regenerateRecoveryCodes($user, $request->input('code'), $request->input('recovery_code'), $request);
        return response(['data' => ['recovery_codes' => $codes]]);
    }

    private function verifyPassword(User $user, $password)
    {
        if (!is_string($password) || !Helper::multiPasswordVerify($user->password_algo, $user->password_salt, $password, $user->password)) abort(500, '当前密码不正确');
    }
}
