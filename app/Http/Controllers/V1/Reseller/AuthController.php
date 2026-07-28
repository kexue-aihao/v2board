<?php

namespace App\Http\Controllers\V1\Reseller;

use App\Http\Controllers\Controller;
use App\Models\ResellerAccount;
use App\Services\ResellerAuthService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        if (!(int)config('v2board.reseller_enable', 0)) {
            abort(503, 'Reseller service is disabled');
        }

        $data = $request->validate([
            'email' => 'required|email|max:128',
            'password' => 'required|string|min:8|max:72',
            'store_slug' => ['required', 'regex:/^[a-z0-9][a-z0-9-]{2,31}$/'],
            'store_name' => 'required|string|max:128',
        ]);
        if (ResellerAccount::where('email', $data['email'])->exists()) {
            abort(422, 'Email already exists');
        }
        if (ResellerAccount::where('store_slug', $data['store_slug'])->exists()) {
            abort(422, 'Store slug already exists');
        }

        $account = new ResellerAccount();
        $account->email = strtolower(trim($data['email']));
        $account->password = Hash::make($data['password']);
        $account->store_slug = $data['store_slug'];
        $account->store_name = $data['store_name'];
        $account->status = 'pending';
        $account->save();

        return response(['data' => [
            'status' => 'pending',
            'message' => 'Registration submitted and awaits administrator approval',
        ]]);
    }

    public function login(Request $request)
    {
        if (!(int)config('v2board.reseller_enable', 0)) {
            abort(503, 'Reseller service is disabled');
        }
        $data = $request->validate([
            'email' => 'required|email|max:128',
            'password' => 'required|string',
        ]);
        $account = ResellerAccount::where('email', strtolower(trim($data['email'])))->first();
        if (!$account || !Hash::check($data['password'], $account->password)) {
            abort(403, 'Incorrect email or password');
        }
        if ($account->status === 'pending') {
            abort(403, 'Reseller account awaits administrator approval');
        }
        if ($account->status !== 'active') {
            abort(403, 'Reseller account is unavailable');
        }

        $account->last_login_at = time();
        $account->last_login_ip = $request->ip();
        $account->save();
        return response(['data' => (new ResellerAuthService())->generate($account, $request)]);
    }

    public function logout(Request $request)
    {
        $authorization = $request->input('auth_data') ?? $request->header('authorization');
        if ($authorization) {
            (new ResellerAuthService())->forget($authorization);
        }
        return response(['data' => true]);
    }
}
