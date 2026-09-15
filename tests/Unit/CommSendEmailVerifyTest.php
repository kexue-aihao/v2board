<?php

namespace Tests\Unit;

use App\Http\Controllers\V1\Passport\CommController;
use App\Http\Requests\Passport\CommSendEmailVerify;
use App\Models\InviteCode;
use App\Utils\CacheKey;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class CommSendEmailVerifyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'cache.default' => 'array',
            'v2board.recaptcha_enable' => 0,
            'v2board.email_whitelist_enable' => 0,
            'v2board.email_gmail_limit_enable' => 0,
        ]);
        DB::purge('sqlite');
        DB::reconnect('sqlite');

        Schema::create('v2_invite_code', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('user_id')->default(0);
            $table->char('code', 32);
            $table->boolean('status')->default(false);
            $table->integer('pv')->default(0);
            $table->integer('created_at')->nullable();
            $table->integer('updated_at')->nullable();
        });
        Schema::create('v2_user', function (Blueprint $table) {
            $table->increments('id');
            $table->string('email')->nullable();
        });
    }

    public function testRegistrationRequiresAnUnusedInviteBeforeSending(): void
    {
        config(['v2board.email_verify' => 1, 'v2board.invite_force' => 1]);
        Queue::fake();

        try {
            $this->send(['email' => 'new@example.com', 'isforget' => 0]);
            $this->fail('Expected a missing invitation code to prevent sending an email.');
        } catch (HttpException $exception) {
            $this->assertSame(500, $exception->getStatusCode());
        }

        Queue::assertNothingPushed();
        $this->assertNull(Cache::get(CacheKey::get('EMAIL_VERIFY_CODE', 'new@example.com')));
    }

    /**
     * @dataProvider invalidInviteProvider
     */
    public function testRegistrationDoesNotSendForAnInvalidOrUsedInvite(string $inviteCode, ?int $status): void
    {
        config(['v2board.email_verify' => 1, 'v2board.invite_force' => 1]);
        if ($status !== null) {
            DB::table('v2_invite_code')->insert([
                'user_id' => 0,
                'code' => $inviteCode,
                'status' => $status,
            ]);
        }
        Queue::fake();

        try {
            $this->send([
                'email' => 'new@example.com',
                'isforget' => 0,
                'invite_code' => $inviteCode,
            ]);
            $this->fail('Expected an invalid invitation code to prevent sending an email.');
        } catch (HttpException $exception) {
            $this->assertSame(500, $exception->getStatusCode());
        }

        Queue::assertNothingPushed();
        $this->assertNull(Cache::get(CacheKey::get('EMAIL_VERIFY_CODE', 'new@example.com')));
    }

    public function invalidInviteProvider(): array
    {
        return [
            'unknown invite' => ['unknown-code', null],
            'used invite' => ['used-code', 1],
        ];
    }

    public function testRegistrationWithAnUnusedInviteSendsWithoutConsumingIt(): void
    {
        config(['v2board.email_verify' => 1, 'v2board.invite_force' => 1]);
        DB::table('v2_invite_code')->insert(['user_id' => 0, 'code' => 'valid-code', 'status' => 0]);
        Queue::fake();

        $response = $this->send([
            'email' => 'new@example.com',
            'isForgetPassword' => false,
            'invite_code' => 'valid-code',
        ]);

        $this->assertTrue(json_decode($response->getContent(), true)['data']);
        Queue::assertPushed(\App\Jobs\SendEmailJob::class);
        $this->assertNotNull(Cache::get(CacheKey::get('EMAIL_VERIFY_CODE', 'new@example.com')));
        $this->assertSame(0, InviteCode::where('code', 'valid-code')->value('status'));
    }

    public function testInviteCheckIsSkippedWhenEmailVerificationIsDisabled(): void
    {
        config(['v2board.email_verify' => 0, 'v2board.invite_force' => 1]);
        Queue::fake();

        $response = $this->send(['email' => 'new@example.com', 'isforget' => 0]);

        $this->assertTrue(json_decode($response->getContent(), true)['data']);
        Queue::assertPushed(\App\Jobs\SendEmailJob::class);
    }

    public function testForgotPasswordDoesNotRequireAnInvite(): void
    {
        config(['v2board.email_verify' => 1, 'v2board.invite_force' => 1]);
        DB::table('v2_user')->insert(['email' => 'member@example.com']);
        Queue::fake();

        $response = $this->send([
            'email' => 'member@example.com',
            'isForgetPassword' => true,
        ]);

        $this->assertTrue(json_decode($response->getContent(), true)['data']);
        Queue::assertPushed(\App\Jobs\SendEmailJob::class);
    }

    private function send(array $data)
    {
        $request = Request::create('/passport/comm/sendEmailVerify', 'POST', $data);
        $request->setUserResolver(function () {
            return null;
        });
        $formRequest = CommSendEmailVerify::createFrom($request);
        $formRequest->setContainer($this->app)->setRedirector($this->app['redirect']);
        $formRequest->validateResolved();

        return app(CommController::class)->sendEmailVerify($formRequest);
    }
}
