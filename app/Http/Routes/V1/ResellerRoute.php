<?php

namespace App\Http\Routes\V1;

use Illuminate\Contracts\Routing\Registrar;

class ResellerRoute
{
    public function map(Registrar $router)
    {
        $router->group(['prefix' => 'reseller'], function ($router) {
            $router->post('/auth/register', 'V1\\Reseller\\AuthController@register');
            $router->post('/auth/login', 'V1\\Reseller\\AuthController@login');
            $router->post('/auth/logout', 'V1\\Reseller\\AuthController@logout');

            $router->group(['middleware' => 'reseller'], function ($router) {
                $router->get('/me', 'V1\\Reseller\\Controller@me');
                $router->get('/plan-template', 'V1\\Reseller\\Controller@templates');
                $router->get('/plans', 'V1\\Reseller\\Controller@plans');
                $router->post('/plans', 'V1\\Reseller\\Controller@savePlan');
                $router->get('/payments', 'V1\\Reseller\\Controller@payments');
                $router->post('/payments/form', 'V1\\Reseller\\Controller@paymentForm');
                $router->post('/payments', 'V1\\Reseller\\Controller@savePayment');
                $router->post('/store', 'V1\\Reseller\\Controller@updateStore');
                $router->get('/customers', 'V1\\Reseller\\Controller@customers');
                $router->get('/orders', 'V1\\Reseller\\Controller@orders');
            });
        });
    }
}
