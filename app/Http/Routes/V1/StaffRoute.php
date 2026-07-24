<?php
namespace App\Http\Routes\V1;

use Illuminate\Contracts\Routing\Registrar;

class StaffRoute
{
    public function map(Registrar $router)
    {
        $router->group([
            'prefix' => 'staff',
            'middleware' => 'staff'
        ], function ($router) {
            // Two-factor protection for the current staff account.
            $router->get ('/2fa/status', 'V1\\User\\TwoFactorController@status');
            $router->post('/2fa/setup', 'V1\\User\\TwoFactorController@setup');
            $router->post('/2fa/confirm', 'V1\\User\\TwoFactorController@confirm');
            $router->post('/2fa/disable', 'V1\\User\\TwoFactorController@disable');
            $router->post('/2fa/recovery-codes/regenerate', 'V1\\User\\TwoFactorController@regenerateRecoveryCodes');
            // Ticket
            $router->get ('/ticket/fetch', 'V1\\Staff\\TicketController@fetch');
            $router->post('/ticket/reply', 'V1\\Staff\\TicketController@reply');
            $router->post('/ticket/close', 'V1\\Staff\\TicketController@close');
            // User
            $router->post('/user/update', 'V1\\Staff\\UserController@update');
            $router->get ('/user/getUserInfoById', 'V1\\Staff\\UserController@getUserInfoById');
            $router->post('/user/sendMail', 'V1\\Staff\\UserController@sendMail');
            $router->post('/user/ban', 'V1\\Staff\\UserController@ban');
            // Plan
            $router->get ('/plan/fetch', 'V1\\Staff\\PlanController@fetch');
            // Notice
            $router->get ('/notice/fetch', 'V1\\Admin\\NoticeController@fetch');
            $router->post('/notice/save', 'V1\\Admin\\NoticeController@save');
            $router->post('/notice/update', 'V1\\Admin\\NoticeController@update');
            $router->post('/notice/drop', 'V1\\Admin\\NoticeController@drop');
        });
    }
}
