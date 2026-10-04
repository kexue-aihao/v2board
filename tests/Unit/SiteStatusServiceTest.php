<?php

namespace Tests\Unit;

use App\Http\Middleware\SiteStatus;
use App\Services\SiteStatusService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class SiteStatusServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array']);
        Cache::forget(SiteStatusService::CACHE_KEY);
    }

    protected function tearDown(): void
    {
        Cache::forget(SiteStatusService::CACHE_KEY);
        parent::tearDown();
    }

    public function testRuntimeStatusOverridesTheLongLivedConfigSnapshot(): void
    {
        config(['v2board.site_status' => 'maintenance']);
        Cache::forever(SiteStatusService::CACHE_KEY, [
            'site_status' => 'normal',
            'site_status_title' => '',
            'site_status_message' => '',
            'site_status_recovery_at' => null,
        ]);

        $request = Request::create('/api/v1/store/plan/fetch', 'GET');
        $response = (new SiteStatus())->handle($request, function () {
            return response('ok');
        });

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('ok', $response->getContent());
    }

    public function testMaintenanceModeIsAppliedImmediatelyAcrossRequests(): void
    {
        config(['v2board.site_status' => 'normal']);
        SiteStatusService::sync([
            'site_status' => 'maintenance',
            'site_status_title' => 'Planned maintenance',
            'site_status_message' => 'Please retry shortly',
            'site_status_recovery_at' => 123,
        ]);

        $request = Request::create('/api/v1/store/plan/fetch', 'GET');
        $response = (new SiteStatus())->handle($request, function () {
            return response('should not run');
        });

        $this->assertSame(503, $response->getStatusCode());
        $this->assertSame('SITE_MAINTENANCE', $response->getData(true)['code']);
        $this->assertSame('maintenance', $response->getData(true)['data']['site_status']);
    }

    public function testOnlySiteStatusChangesDoNotRequireAWebmanReload(): void
    {
        $before = [
            'site_status' => 'maintenance',
            'site_status_title' => '',
            'site_status_message' => '',
            'site_status_recovery_at' => null,
            'app_name' => 'V2Board',
        ];
        $after = [
            'site_status' => 'normal',
            'site_status_title' => null,
            'site_status_message' => null,
            'site_status_recovery_at' => null,
            'app_name' => 'V2Board',
        ];
        $this->assertTrue(SiteStatusService::onlyStatusChanges($before, $after));
        $after['app_name'] = 'Changed';
        $this->assertFalse(SiteStatusService::onlyStatusChanges($before, $after));
    }
}
