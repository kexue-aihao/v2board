<?php
namespace App\Http\Routes\V1;

use Illuminate\Contracts\Routing\Registrar;

class GuestRoute
{
    public function map(Registrar $router)
    {
        $router->group([
            'prefix' => 'guest'
        ], function ($router) {
            // Telegram
            $router->post('/telegram/webhook', 'V1\\Guest\\TelegramController@webhook');
            // Payment
            $router->match(['get', 'post'], '/payment/notify/{method}/{uuid}', 'V1\\Guest\\PaymentController@notify');
            $router->get('/payment/paytaro-qr/{attemptNo}/{invoiceUuid}', 'V1\\Guest\\PaytaroQRController@page');
            $router->get('/payment/paytaro-qr/{attemptNo}/{invoiceUuid}/status', 'V1\\Guest\\PaytaroQRController@status');
            // Comm
            $router->get ('/comm/config', 'V1\\Guest\\CommController@config');
            $router->get ('/comm/arithmetic', 'V1\\Guest\\CommController@arithmetic');
            $router->post('/comm/arithmetic/verify', 'V1\\Guest\\CommController@verifyArithmetic');
            // Public plans for the signature landing page
            $router->get ('/plan/fetch', 'V1\\Guest\\PlanController@fetch');
        });
    }
}
