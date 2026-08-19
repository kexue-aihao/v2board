<?php
namespace App\Http\Routes\V1;

use Illuminate\Contracts\Routing\Registrar;

class UserRoute
{
    public function map(Registrar $router)
    {
        $router->group([
            'prefix' => 'user',
            'middleware' => 'user'
        ], function ($router) {
            // User
            $router->get ('/unbindTelegram', 'V1\\User\\UserController@unbindTelegram');
            $router->get ('/resetSecurity', 'V1\\User\\UserController@resetSecurity');
            $router->get ('/info', 'V1\\User\\UserController@info');
            $router->post('/newPeriod', 'V1\\User\\UserController@newPeriod');
            $router->post('/redeemgiftcard', 'V1\\User\\UserController@redeemgiftcard');
            $router->post('/changePassword', 'V1\\User\\UserController@changePassword');
            $router->post('/resetPassword', 'V1\\User\\UserController@resetPassword');
            $router->post('/update', 'V1\\User\\UserController@update');
            $router->get ('/getSubscribe', 'V1\\User\\UserController@getSubscribe');
            $router->get ('/subscription/fetch', 'V1\\User\\SubscriptionController@fetch');
            $router->post('/subscription/set-primary', 'V1\\User\\SubscriptionController@setPrimary');
            $router->post('/subscription/revoke', 'V1\\User\\SubscriptionController@revoke');
            $router->get ('/getStat', 'V1\\User\\UserController@getStat');
            $router->get ('/checkLogin', 'V1\\User\\UserController@checkLogin');
            $router->get ('/2fa/status', 'V1\\User\\TwoFactorController@status');
            $router->post('/2fa/setup', 'V1\\User\\TwoFactorController@setup');
            $router->post('/2fa/confirm', 'V1\\User\\TwoFactorController@confirm');
            $router->post('/2fa/disable', 'V1\\User\\TwoFactorController@disable');
            $router->post('/2fa/recovery-codes/regenerate', 'V1\\User\\TwoFactorController@regenerateRecoveryCodes');
            $router->post('/transfer', 'V1\\User\\UserController@transfer');
            $router->post('/getQuickLoginUrl', 'V1\\User\\UserController@getQuickLoginUrl');
            $router->get ('/getActiveSession', 'V1\\User\\UserController@getActiveSession');
            $router->post('/removeActiveSession', 'V1\\User\\UserController@removeActiveSession');
            // Rewards and games
            $router->get ('/reward/checkin-status', 'V1\\User\\RewardController@checkinStatus');
            $router->get ('/reward/history', 'V1\\User\\RewardController@history');
            $router->post('/reward/checkin', 'V1\\User\\RewardController@checkin');
            $router->post('/game/dice/play', 'V1\\User\\RewardController@dice');
            $router->post('/game/slots/play', 'V1\\User\\RewardController@slots');
            $router->post('/game/poker/play', 'V1\\User\\RewardController@poker');
            // Order
            $router->post('/order/save', 'V1\\User\\OrderController@save');
            $router->post('/order/checkout', 'V1\\User\\OrderController@checkout');
            $router->get ('/order/check', 'V1\\User\\OrderController@check');
            $router->get ('/order/detail', 'V1\\User\\OrderController@detail');
            $router->get ('/order/fetch', 'V1\\User\\OrderController@fetch');
            $router->get ('/order/getPaymentMethod', 'V1\\User\\OrderController@getPaymentMethod');
            $router->post('/order/cancel', 'V1\\User\\OrderController@cancel');
            // Plan
            $router->get ('/plan/fetch', 'V1\\User\\PlanController@fetch');
            // Invite
            $router->get ('/invite/save', 'V1\\User\\InviteController@save');
            $router->get ('/invite/fetch', 'V1\\User\\InviteController@fetch');
            $router->get ('/invite/details', 'V1\\User\\InviteController@details');
            // Notice
            $router->get ('/notice/fetch', 'V1\\User\\NoticeController@fetch');
            // Ticket
            $router->post('/ticket/reply', 'V1\\User\\TicketController@reply');
            $router->post('/ticket/close', 'V1\\User\\TicketController@close');
            $router->post('/ticket/save', 'V1\\User\\TicketController@save');
            $router->get ('/ticket/fetch', 'V1\\User\\TicketController@fetch');
            $router->post('/ticket/withdraw', 'V1\\User\\TicketController@withdraw');
            // Server
            $router->get ('/server/fetch', 'V1\\User\\ServerController@fetch');
            // Coupon
            $router->post('/coupon/check', 'V1\\User\\CouponController@check');
            // Telegram
            $router->get ('/telegram/getBotInfo', 'V1\\User\\TelegramController@getBotInfo');
            $router->get ('/telegram/binding', 'V1\\User\\TelegramController@binding');
            $router->post('/telegram/binding/prepare', 'V1\\User\\TelegramController@prepareBinding');
            $router->post('/telegram/binding/revoke', 'V1\\User\\TelegramController@revokeBinding');
            $router->post('/oauth/link', 'V1\\Passport\\OAuthController@link');
            // Comm
            $router->get ('/comm/config', 'V1\\User\\CommController@config');
            $router->Post('/comm/getStripePublicKey', 'V1\\User\\CommController@getStripePublicKey');
            // Knowledge
            $router->get ('/knowledge/fetch', 'V1\\User\\KnowledgeController@fetch');
            $router->get ('/knowledge/getCategory', 'V1\\User\\KnowledgeController@getCategory');
            // Stat
            $router->get ('/stat/getTrafficLog', 'V1\\User\\StatController@getTrafficLog');
        });
    }
}
