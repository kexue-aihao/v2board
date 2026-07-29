<?php
namespace App\Http\Routes\V1;

use Illuminate\Contracts\Routing\Registrar;

class PassportRoute
{
    public function map(Registrar $router)
    {
        // The public login endpoint is intentionally blocked while the site is
        // in maintenance mode. Keep the administrator entry point under the
        // secure path so the SiteStatus middleware can exempt operations work.
        $securePath = trim((string)config(
            'v2board.secure_path',
            config('v2board.frontend_admin_path', hash('crc32b', (string)config('app.key')))
        ), '/');
        if ($securePath !== '') {
            $router->group([
                'prefix' => $securePath
            ], function ($router) {
                $router->post('/passport/auth/login', 'V1\\Passport\\AuthController@adminLogin');
                $router->post('/passport/auth/verify2fa', 'V1\\Passport\\AuthController@adminVerify2fa');
                $router->post('/passport/auth/2fa/setup', 'V1\\Passport\\AuthController@adminSetup2fa');
                $router->post('/passport/auth/2fa/confirm', 'V1\\Passport\\AuthController@adminConfirmSetup2fa');
            });
        }

        $router->group([
            'prefix' => 'passport'
        ], function ($router) {
            // Auth
            $router->post('/auth/register', 'V1\\Passport\\AuthController@register');
            $router->post('/auth/login', 'V1\\Passport\\AuthController@login');
            $router->post('/auth/verify2fa', 'V1\\Passport\\AuthController@verify2fa');
            $router->post('/auth/2fa/setup', 'V1\\Passport\\AuthController@setup2fa');
            $router->post('/auth/2fa/confirm', 'V1\\Passport\\AuthController@confirmSetup2fa');
            $router->get ('/auth/token2Login', 'V1\\Passport\\AuthController@token2Login');
            $router->post('/auth/forget', 'V1\\Passport\\AuthController@forget');
            $router->post('/auth/getQuickLoginUrl', 'V1\\Passport\\AuthController@getQuickLoginUrl');
            // Comm
            $router->post('/comm/sendEmailVerify', 'V1\\Passport\\CommController@sendEmailVerify');
            $router->post('/comm/pv', 'V1\\Passport\\CommController@pv');
        });
    }
}
