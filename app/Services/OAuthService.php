<?php

namespace App\Services;

use App\Utils\CacheKey;
use GuzzleHttp\Client;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

class OAuthService
{
    public const PROVIDERS = ['google', 'github', 'microsoft', 'telegram'];
    private const STATE_TTL = 600;
    private const TICKET_TTL = 300;
    private const TELEGRAM_AUTH_MAX_AGE = 86400;

    private $http;

    public function __construct()
    {
        $this->http = new Client([
            'timeout' => 10,
            'http_errors' => false,
            'allow_redirects' => false
        ]);
    }

    public function assertProvider(string $provider): string
    {
        $provider = strtolower(trim($provider));
        if (!in_array($provider, self::PROVIDERS, true)) {
            abort(404, 'Unsupported OAuth provider');
        }
        if (!(int)config('v2board.oauth_' . $provider . '_enable', 0)) {
            abort(503, 'This login provider is disabled');
        }
        if ($provider !== 'telegram') {
            foreach (['client_id', 'client_secret'] as $part) {
                if (trim((string)config('v2board.oauth_' . $provider . '_' . $part, '')) === '') {
                    abort(503, ucfirst($provider) . ' OAuth is not configured');
                }
            }
        } elseif (trim((string)config('v2board.telegram_bot_token', '')) === ''
            || trim((string)config('v2board.oauth_telegram_bot_username', '')) === '') {
            abort(503, 'Telegram login is not configured');
        }
        return $provider;
    }

    public function begin(string $provider, Request $request): string
    {
        $provider = $this->assertProvider($provider);
        $state = $this->randomToken();
        $payload = [
            'provider' => $provider,
            'state' => $state,
            'verifier' => $this->randomToken(48),
            'nonce' => $this->randomToken(),
            'ip' => $request->ip(),
            'ua_hash' => hash('sha256', (string)$request->userAgent()),
            'created_at' => time()
        ];
        Cache::put(CacheKey::get('OAUTH_STATE', $state), $payload, self::STATE_TTL);

        if ($provider === 'telegram') {
            return $this->frontendLoginUrl(['telegram_state' => $state]);
        }

        return $this->authorizationUrl($provider, $payload);
    }

    public function callback(string $provider, Request $request): string
    {
        $provider = $this->assertProvider($provider);
        if ($provider === 'telegram') {
            abort(422, 'Telegram login must be completed by the login widget');
        }
        $state = trim((string)$request->input('state'));
        if ($state === '') {
            return $this->frontendLoginUrl(['oauth_error' => 'authorization_cancelled']);
        }
        $statePayload = Cache::pull(CacheKey::get('OAUTH_STATE', $state));
        if (!is_array($statePayload) || ($statePayload['provider'] ?? '') !== $provider) {
            return $this->frontendLoginUrl(['oauth_error' => 'invalid_oauth_state']);
        }
        if (!empty($statePayload['ip']) && $statePayload['ip'] !== $request->ip()) {
            return $this->frontendLoginUrl(['oauth_error' => 'invalid_oauth_state']);
        }
        if ($request->input('error')) {
            return $this->frontendLoginUrl(['oauth_error' => 'authorization_cancelled']);
        }
        try {
            $profile = $this->fetchProfile($provider, (string)$request->input('code'), $statePayload);
            if (empty($profile['subject']) || !preg_match('/^[A-Za-z0-9:_-]{1,191}$/', (string)$profile['subject'])) {
                throw new RuntimeException('OAuth provider identity is invalid');
            }
            $ticket = $this->storeTicket($profile);
            return $this->frontendLoginUrl(['oauth_ticket' => $ticket]);
        } catch (\Throwable $e) {
            report($e);
            return $this->frontendLoginUrl(['oauth_error' => 'oauth_verification_failed']);
        }
    }

    public function consumeState(string $state, string $provider, Request $request): array
    {
        $state = trim($state);
        if ($state === '') {
            abort(422, 'OAuth state is required');
        }
        $payload = Cache::pull(CacheKey::get('OAUTH_STATE', $state));
        if (!is_array($payload) || ($payload['provider'] ?? '') !== $provider) {
            abort(422, 'OAuth state is invalid or expired');
        }
        if (!empty($payload['ip']) && $payload['ip'] !== $request->ip()) {
            abort(422, 'OAuth state origin changed');
        }
        return $payload;
    }

    public function storeTicket(array $profile): string
    {
        $ticket = $this->randomToken();
        Cache::put(CacheKey::get('OAUTH_TICKET', $ticket), [
            'profile' => $profile,
            'created_at' => time()
        ], self::TICKET_TTL);
        return $ticket;
    }

    public function ticket(string $ticket): ?array
    {
        $payload = Cache::get(CacheKey::get('OAUTH_TICKET', trim($ticket)));
        return is_array($payload) && is_array($payload['profile'] ?? null) ? $payload : null;
    }

    public function forgetTicket(string $ticket): void
    {
        Cache::forget(CacheKey::get('OAUTH_TICKET', trim($ticket)));
    }

    /**
     * Keep ticket completion and account linking single-use under concurrent requests.
     */
    public function withTicketLock(string $ticket, \Closure $callback)
    {
        $ticket = trim($ticket);
        if ($ticket === '') abort(422, 'OAuth ticket is required');

        $key = CacheKey::get('OAUTH_TICKET_LOCK', hash('sha256', $ticket));
        if (!Cache::add($key, 1, 15)) {
            abort(409, 'OAuth ticket is already being processed');
        }

        try {
            return $callback();
        } finally {
            Cache::forget($key);
        }
    }

    public function updateTicketProfile(string $ticket, array $profile): void
    {
        $key = CacheKey::get('OAUTH_TICKET', trim($ticket));
        $payload = Cache::get($key);
        if (!is_array($payload) || !is_array($payload['profile'] ?? null)) {
            abort(422, 'OAuth ticket is invalid or expired');
        }
        $payload['profile'] = $profile;
        Cache::put($key, $payload, self::TICKET_TTL);
    }

    public function telegramProfile(array $data, string $state, Request $request): array
    {
        $statePayload = $this->consumeState($state, 'telegram', $request);
        $id = (string)($data['id'] ?? '');
        $authDate = (int)($data['auth_date'] ?? 0);
        $hash = strtolower(trim((string)($data['hash'] ?? '')));
        if ($id === '' || !ctype_digit($id) || $authDate <= 0 || $hash === '') {
            abort(422, 'Telegram login data is invalid');
        }
        if (abs(time() - $authDate) > self::TELEGRAM_AUTH_MAX_AGE) {
            abort(422, 'Telegram login data has expired');
        }
        $check = [];
        foreach ($data as $key => $value) {
            if ($key === 'hash' || is_array($value) || is_object($value)) continue;
            $check[] = $key . '=' . $value;
        }
        sort($check, SORT_STRING);
        $secret = hash('sha256', (string)config('v2board.telegram_bot_token'), true);
        $expected = hash_hmac('sha256', implode("\n", $check), $secret);
        if (!hash_equals($expected, $hash)) {
            abort(422, 'Telegram login signature is invalid');
        }
        return [
            'provider' => 'telegram',
            'subject' => $id,
            'tenant' => '',
            'email' => null,
            'verified_email' => false,
            'username' => $this->normalizeTelegramUsername($data['username'] ?? null),
            'display_name' => trim((string)($data['first_name'] ?? '') . ' ' . (string)($data['last_name'] ?? '')),
            'state' => $statePayload['state'] ?? $state
        ];
    }

    public function normalizeTelegramUsername($username): string
    {
        return strtolower(ltrim(trim((string)$username), '@'));
    }

    /**
     * v2_user.email is required by the existing account model. Telegram users
     * do not provide an email, so keep a deterministic, non-deliverable value
     * for internal uniqueness only. Telegram identity remains the login key.
     */
    public function telegramAccountEmail(string $subject): string
    {
        $subject = trim($subject);
        if ($subject === '' || !ctype_digit($subject)) {
            abort(422, 'Telegram identity is invalid');
        }

        return 'telegram_' . hash('sha256', 'v2board:telegram:' . $subject) . '@telegram.invalid';
    }

    private function authorizationUrl(string $provider, array $state): string
    {
        $redirect = $this->redirectUri($provider);
        $challenge = $this->base64Url(hash('sha256', $state['verifier'], true));
        if ($provider === 'google') {
            return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
                'client_id' => config('v2board.oauth_google_client_id'),
                'redirect_uri' => $redirect,
                'response_type' => 'code',
                'scope' => 'openid email profile',
                'state' => $state['state'],
                'nonce' => $state['nonce'],
                'code_challenge' => $challenge,
                'code_challenge_method' => 'S256',
                'prompt' => 'select_account'
            ]);
        }
        if ($provider === 'github') {
            return 'https://github.com/login/oauth/authorize?' . http_build_query([
                'client_id' => config('v2board.oauth_github_client_id'),
                'redirect_uri' => $redirect,
                'scope' => 'read:user user:email',
                'state' => $state['state'],
                'code_challenge' => $challenge,
                'code_challenge_method' => 'S256',
                'allow_signup' => 'true'
            ]);
        }
        $tenant = trim((string)config('v2board.oauth_microsoft_tenant', 'common')) ?: 'common';
        return 'https://login.microsoftonline.com/' . rawurlencode($tenant) . '/oauth2/v2.0/authorize?' . http_build_query([
            'client_id' => config('v2board.oauth_microsoft_client_id'),
            'redirect_uri' => $redirect,
            'response_type' => 'code',
            'response_mode' => 'query',
            'scope' => 'openid profile email User.Read',
            'state' => $state['state'],
            'nonce' => $state['nonce'],
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
            'prompt' => 'select_account'
        ]);
    }

    private function fetchProfile(string $provider, string $code, array $state): array
    {
        if ($code === '') throw new RuntimeException('OAuth code is missing');
        $token = $this->exchangeCode($provider, $code, $state);
        if ($provider === 'github') {
            $user = $this->getJson('https://api.github.com/user', [
                'Authorization' => 'Bearer ' . $token['access_token'],
                'Accept' => 'application/vnd.github+json',
                'User-Agent' => 'V2Board-OAuth'
            ]);
            $emails = $this->getJson('https://api.github.com/user/emails', [
                'Authorization' => 'Bearer ' . $token['access_token'],
                'Accept' => 'application/vnd.github+json',
                'User-Agent' => 'V2Board-OAuth'
            ]);
            $email = null;
            foreach ((array)$emails as $item) {
                if (!empty($item['verified']) && (!empty($item['primary']) || !$email)) {
                    $email = strtolower(trim((string)($item['email'] ?? '')));
                    if (!empty($item['primary'])) break;
                }
            }
            return [
                'provider' => 'github',
                'subject' => (string)($user['id'] ?? ''),
                'tenant' => '',
                'email' => $email ?: null,
                'verified_email' => (bool)$email,
                'username' => (string)($user['login'] ?? ''),
                'display_name' => (string)($user['name'] ?? $user['login'] ?? '')
            ];
        }
        $claims = $this->verifyOidcToken($provider, (string)($token['id_token'] ?? ''), $state['nonce']);
        if ($provider === 'google') {
            return [
                'provider' => 'google',
                'subject' => (string)($claims['sub'] ?? ''),
                'tenant' => '',
                'email' => strtolower(trim((string)($claims['email'] ?? ''))) ?: null,
                'verified_email' => !empty($claims['email_verified']),
                'username' => (string)($claims['name'] ?? ''),
                'display_name' => (string)($claims['name'] ?? '')
            ];
        }
        $graph = $this->getJson('https://graph.microsoft.com/v1.0/me?$select=id,mail,userPrincipalName,displayName', [
            'Authorization' => 'Bearer ' . $token['access_token'],
            'Accept' => 'application/json'
        ]);
        $email = strtolower(trim((string)($graph['mail'] ?? $graph['userPrincipalName'] ?? $claims['email'] ?? '')));
        return [
            'provider' => 'microsoft',
            'subject' => (string)($claims['oid'] ?? $graph['id'] ?? $claims['sub'] ?? ''),
            'tenant' => (string)($claims['tid'] ?? ''),
            'email' => $email ?: null,
            'verified_email' => $email !== '',
            'username' => (string)($graph['userPrincipalName'] ?? $claims['preferred_username'] ?? ''),
            'display_name' => (string)($graph['displayName'] ?? '')
        ];
    }

    private function exchangeCode(string $provider, string $code, array $state): array
    {
        $params = [
            'code' => $code,
            'redirect_uri' => $this->redirectUri($provider),
            'grant_type' => 'authorization_code',
            'code_verifier' => $state['verifier']
        ];
        if ($provider === 'google') {
            $params['client_id'] = config('v2board.oauth_google_client_id');
            $params['client_secret'] = config('v2board.oauth_google_client_secret');
            return $this->postForm('https://oauth2.googleapis.com/token', $params);
        }
        if ($provider === 'github') {
            return $this->postForm('https://github.com/login/oauth/access_token', [
                'client_id' => config('v2board.oauth_github_client_id'),
                'client_secret' => config('v2board.oauth_github_client_secret'),
                'code' => $code,
                'redirect_uri' => $this->redirectUri($provider),
                'code_verifier' => $state['verifier']
            ], ['Accept' => 'application/json']);
        }
        $tenant = trim((string)config('v2board.oauth_microsoft_tenant', 'common')) ?: 'common';
        $params['client_id'] = config('v2board.oauth_microsoft_client_id');
        $params['client_secret'] = config('v2board.oauth_microsoft_client_secret');
        return $this->postForm('https://login.microsoftonline.com/' . rawurlencode($tenant) . '/oauth2/v2.0/token', $params);
    }

    private function verifyOidcToken(string $provider, string $token, string $nonce): array
    {
        if ($token === '') throw new RuntimeException('OIDC token is missing');
        $parts = explode('.', $token);
        if (count($parts) !== 3) throw new RuntimeException('OIDC token is invalid');
        $header = json_decode($this->base64UrlDecode($parts[0]), true);
        $claims = json_decode($this->base64UrlDecode($parts[1]), true);
        if (!is_array($header) || !is_array($claims) || ($header['alg'] ?? '') !== 'RS256') {
            throw new RuntimeException('OIDC token algorithm is invalid');
        }
        $clientId = (string)config('v2board.oauth_' . $provider . '_client_id');
        $audience = $claims['aud'] ?? null;
        $audiences = is_array($audience) ? $audience : [$audience];
        if ($clientId === '' || !in_array($clientId, array_map('strval', $audiences), true)) {
            throw new RuntimeException('OIDC token audience is invalid');
        }
        if ($provider === 'google' && isset($claims['azp']) && (string)$claims['azp'] !== $clientId) {
            throw new RuntimeException('OIDC authorized party is invalid');
        }
        $now = time();
        if (empty($claims['sub']) || ($claims['exp'] ?? 0) <= $now
            || ($claims['nbf'] ?? 0) > $now + 60
            || ($claims['iat'] ?? $now) > $now + 60) {
            throw new RuntimeException('OIDC token is expired');
        }
        if ($nonce === '' || !hash_equals($nonce, (string)($claims['nonce'] ?? ''))) {
            throw new RuntimeException('OIDC nonce is invalid');
        }
        $discovery = $this->oidcDiscovery($provider);
        $issuer = (string)($claims['iss'] ?? '');
        if ($provider === 'google') {
            if (!in_array($issuer, ['https://accounts.google.com', 'accounts.google.com'], true)) {
                throw new RuntimeException('OIDC issuer is invalid');
            }
        } else {
            $tenant = trim((string)config('v2board.oauth_microsoft_tenant', 'common')) ?: 'common';
            $tid = (string)($claims['tid'] ?? '');
            if ($tid === '') throw new RuntimeException('OIDC tenant is invalid');
            $expected = $tenant === 'common' || $tenant === 'organizations' || $tenant === 'consumers'
                ? 'https://login.microsoftonline.com/' . $tid . '/v2.0'
                : 'https://login.microsoftonline.com/' . $tenant . '/v2.0';
            if ($issuer !== $expected) throw new RuntimeException('OIDC issuer is invalid');
        }
        $kid = (string)($header['kid'] ?? '');
        foreach ((array)($discovery['keys'] ?? []) as $jwk) {
            if (($jwk['kid'] ?? '') !== $kid) continue;
            $pem = $this->jwkToPem($jwk);
            $signature = $this->base64UrlDecode($parts[2]);
            if ($pem && openssl_verify($parts[0] . '.' . $parts[1], $signature, $pem, OPENSSL_ALGO_SHA256) === 1) {
                return $claims;
            }
        }
        throw new RuntimeException('OIDC token signature is invalid');
    }

    private function oidcDiscovery(string $provider): array
    {
        if ($provider === 'google') {
            $url = 'https://accounts.google.com/.well-known/openid-configuration';
        } else {
            $tenant = trim((string)config('v2board.oauth_microsoft_tenant', 'common')) ?: 'common';
            $url = 'https://login.microsoftonline.com/' . rawurlencode($tenant) . '/v2.0/.well-known/openid-configuration';
        }
        $key = CacheKey::get('OAUTH_JWKS', md5($url));
        $discovery = Cache::get($key);
        if (!is_array($discovery)) {
            $discovery = $this->getJson($url);
            $jwksUri = (string)($discovery['jwks_uri'] ?? '');
            if ($jwksUri === '') throw new RuntimeException('OIDC key endpoint is missing');
            $discovery['keys'] = $this->getJson($jwksUri)['keys'] ?? [];
            Cache::put($key, $discovery, 3600);
        } elseif (empty($discovery['keys']) && !empty($discovery['jwks_uri'])) {
            $discovery['keys'] = $this->getJson((string)$discovery['jwks_uri'])['keys'] ?? [];
            Cache::put($key, $discovery, 3600);
        }
        return $discovery;
    }

    private function getJson(string $url, array $headers = []): array
    {
        $response = $this->http->get($url, ['headers' => $headers]);
        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            throw new RuntimeException('OAuth provider request failed');
        }
        $data = json_decode((string)$response->getBody(), true);
        if (!is_array($data)) throw new RuntimeException('OAuth provider response is invalid');
        return $data;
    }

    private function postForm(string $url, array $params, array $headers = []): array
    {
        $response = $this->http->post($url, [
            'form_params' => $params,
            'headers' => array_merge(['Accept' => 'application/json'], $headers)
        ]);
        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            throw new RuntimeException('OAuth token request failed');
        }
        $data = json_decode((string)$response->getBody(), true);
        if (!is_array($data) || empty($data['access_token'])) {
            throw new RuntimeException('OAuth token response is invalid');
        }
        return $data;
    }

    private function redirectUri(string $provider): string
    {
        $configured = trim((string)config('v2board.oauth_' . $provider . '_redirect_uri', ''));
        return $configured ?: url('/api/v1/passport/oauth/' . $provider . '/callback');
    }

    private function frontendLoginUrl(array $query): string
    {
        $base = rtrim((string)config('v2board.app_url', ''), '/');
        if ($base === '') $base = rtrim(url('/'), '/');
        return $base . '/#/login?' . http_build_query($query);
    }

    private function randomToken(int $bytes = 32): string
    {
        return $this->base64Url(random_bytes($bytes));
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): string
    {
        $padding = strlen($value) % 4;
        if ($padding) $value .= str_repeat('=', 4 - $padding);
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        return $decoded === false ? '' : $decoded;
    }

    private function jwkToPem(array $jwk): ?string
    {
        if (empty($jwk['n']) || empty($jwk['e'])) return null;
        $modulus = $this->derInteger($this->base64UrlDecode($jwk['n']));
        $exponent = $this->derInteger($this->base64UrlDecode($jwk['e']));
        $rsa = "\x30" . $this->derLength(strlen($modulus . $exponent)) . $modulus . $exponent;
        $bitString = "\x03" . $this->derLength(strlen($rsa) + 1) . "\x00" . $rsa;
        $sequence = "\x30" . $this->derLength(strlen($bitString) + 15)
            . "\x30\x0d\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00"
            . $bitString;
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($sequence), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    private function derInteger(string $value): string
    {
        if ($value === '' || (ord($value[0]) & 0x80)) $value = "\x00" . $value;
        return "\x02" . $this->derLength(strlen($value)) . $value;
    }

    private function derLength(int $length): string
    {
        if ($length < 128) return chr($length);
        $bytes = '';
        while ($length > 0) {
            $bytes = chr($length & 0xff) . $bytes;
            $length >>= 8;
        }
        return chr(0x80 | strlen($bytes)) . $bytes;
    }
}
