<?php

namespace App\Http\Controllers\V1\Reseller;

use App\Http\Controllers\Controller;
use App\Models\ResellerAccount;
use App\Services\PasswordPolicyService;
use App\Services\ResellerAuthService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
            'store_slug' => ['required', 'regex:/^[a-z0-9][a-z0-9-]{2,31}$/'],
            'store_name' => 'required|string|max:128',
        ]);
        $data['email'] = strtolower(trim($data['email']));
        $data['store_slug'] = strtolower(trim($data['store_slug']));
        $data['store_name'] = trim($data['store_name']);
        if (ResellerAccount::where('email', $data['email'])->exists()) {
            abort(422, 'Email already exists');
        }
        if (ResellerAccount::where('store_slug', $data['store_slug'])->exists()) {
            abort(422, 'Store slug already exists');
        }

        // Use the same 64-character cryptographically random password policy as user accounts.
        // The plaintext is returned only in this response so the applicant can save it.
        $password = PasswordPolicyService::generate();

        DB::transaction(function () use ($data, $password) {
            $account = new ResellerAccount();
            $account->email = $data['email'];
            $account->password = password_hash($password, PASSWORD_DEFAULT);
            $account->store_slug = $data['store_slug'];
            $account->store_name = $data['store_name'];
            $account->status = 'pending';
            $account->reseller_status = 'pending';
            $account->store_status = 'pending';
            $account->save();
        });

        return response(['data' => [
            'status' => 'pending',
            'message' => 'Registration submitted and awaits administrator approval',
            'password' => $password,
            'password_length' => PasswordPolicyService::LENGTH,
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
        switch ($account->accountStatus()) {
            case 'pending':
                abort(403, 'Reseller account awaits administrator approval');
            case 'rejected':
                abort(403, 'Reseller account was rejected: ' . ($account->reseller_review_reason ?: 'please contact the administrator'));
            case 'suspended':
                abort(403, 'Reseller account is suspended: ' . ($account->reseller_review_reason ?: 'please contact the administrator'));
        }
        if (!$account->isAccountActive()) {
            abort(403, 'Reseller account is unavailable');
        }

        $account->last_login_at = time();
        $account->last_login_ip = $request->ip();
        $account->save();
        return response(['data' => (new ResellerAuthService())->generate($account, $request)]);
    }

    public function logout(Request $request)
    {
        $authorization = trim((string)$request->input('auth_data', ''));
        if ($authorization === '') {
            $authorization = trim((string)$request->header('authorization', ''));
        }
        if ($authorization) {
            (new ResellerAuthService())->forget($authorization);
        }
        return response(['data' => true]);
    }
}
