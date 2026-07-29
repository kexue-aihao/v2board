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
                $router->get('/subscription', 'V1\\Store\\Controller@subscription');
                $router->get('/shared/subscription', 'V1\\Store\\Controller@sharedSubscription');
                $router->get('/shared/members', 'V1\\Store\\Controller@sharedMembers');
                $router->post('/shared/invitations', 'V1\\Store\\Controller@createSharedInvitation');
                $router->get('/shared/invitations', 'V1\\Store\\Controller@sharedInvitations');
                $router->post('/shared/invitations/{id}/revoke', 'V1\\Store\\Controller@revokeSharedInvitation');
                $router->post('/shared/invitations/accept', 'V1\\Store\\Controller@acceptSharedInvitation');
                $router->post('/shared/members/{id}/remove', 'V1\\Store\\Controller@removeSharedMember');
                $router->post('/shared/credential/rotate', 'V1\\Store\\Controller@rotateSharedCredential');
            });
        });
    }
}
