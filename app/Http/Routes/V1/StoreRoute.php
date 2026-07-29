<?php

namespace App\Http\Routes\V1;

use Illuminate\Contracts\Routing\Registrar;

class StoreRoute
{
    public function map(Registrar $router)
    {
        $router->group([
            'prefix' => 'store/{slug}',
            'middleware' => 'storefront',
        ], function ($router) {
            $router->get('/config', 'V1\\Store\\Controller@config');
            $router->get('/plans', 'V1\\Store\\Controller@plans');
            $router->get('/payments', 'V1\\Store\\Controller@payments');
            $router->post('/passport/register', 'V1\\Store\\Controller@register');
            $router->post('/passport/login', 'V1\\Store\\Controller@login');
            $router->post('/passport/verify2fa', 'V1\\Store\\Controller@verify2fa');
            $router->match(['get', 'post'], '/payment/notify/{payment_uuid}', 'V1\\Store\\Controller@notify');

            $router->group(['middleware' => 'user'], function ($router) {
                $router->post('/order/save', 'V1\\Store\\Controller@saveOrder');
                $router->post('/order/checkout', 'V1\\Store\\Controller@checkout');
                $router->get('/order/check', 'V1\\Store\\Controller@checkOrder');
                $router->get('/order/detail', 'V1\\Store\\Controller@detailOrder');
                $router->get('/order/fetch', 'V1\\Store\\Controller@fetchOrders');
                $router->post('/order/cancel', 'V1\\Store\\Controller@cancelOrder');
            });
        });
    }
}
