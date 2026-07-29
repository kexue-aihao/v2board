<?php
namespace App\Http\Routes\V1;

use Illuminate\Contracts\Routing\Registrar;

class AdminRoute
{
    public function map(Registrar $router)
    {
        $router->group([
            'prefix' => config('v2board.secure_path', config('v2board.frontend_admin_path', hash('crc32b', config('app.key')))),
            'middleware' => ['admin', 'log'],
        ], function ($router) {
            // Config
            $router->get ('/config/fetch', 'V1\\Admin\\ConfigController@fetch');
            $router->post('/config/save', 'V1\\Admin\\ConfigController@save');
            $router->get ('/config/getEmailTemplate', 'V1\\Admin\\ConfigController@getEmailTemplate');
            $router->get ('/config/getThemeTemplate', 'V1\\Admin\\ConfigController@getThemeTemplate');
            $router->post('/config/setTelegramWebhook', 'V1\\Admin\\ConfigController@setTelegramWebhook');
            $router->post('/config/testSendMail', 'V1\\Admin\\ConfigController@testSendMail');
            $router->get ('/2fa/status', 'V1\\User\\TwoFactorController@status');
            $router->post('/2fa/setup', 'V1\\User\\TwoFactorController@setup');
            $router->post('/2fa/confirm', 'V1\\User\\TwoFactorController@confirm');
            $router->post('/2fa/disable', 'V1\\User\\TwoFactorController@disable');
            $router->post('/2fa/recovery-codes/regenerate', 'V1\\User\\TwoFactorController@regenerateRecoveryCodes');
            // Plan
            $router->get ('/plan/fetch', 'V1\\Admin\\PlanController@fetch');
            $router->post('/plan/save', 'V1\\Admin\\PlanController@save');
            $router->post('/plan/drop', 'V1\\Admin\\PlanController@drop');
            $router->post('/plan/update', 'V1\\Admin\\PlanController@update');
            $router->post('/plan/sort', 'V1\\Admin\\PlanController@sort');
            // Server
            $router->get ('/server/group/fetch', 'V1\\Admin\\Server\\GroupController@fetch');
            $router->post('/server/group/save', 'V1\\Admin\\Server\\GroupController@save');
            $router->post('/server/group/drop', 'V1\\Admin\\Server\\GroupController@drop');
            $router->get ('/server/route/fetch', 'V1\\Admin\\Server\\RouteController@fetch');
            $router->post('/server/route/save', 'V1\\Admin\\Server\\RouteController@save');
            $router->post('/server/route/drop', 'V1\\Admin\\Server\\RouteController@drop');
            $router->get ('/server/manage/getNodes', 'V1\\Admin\\Server\\ManageController@getNodes');
            $router->post('/server/manage/sort', 'V1\\Admin\\Server\\ManageController@sort');
            $router->group([
                'prefix' => 'server/trojan'
            ], function ($router) {
                $router->post('save', 'V1\\Admin\\Server\\TrojanController@save');
                $router->post('drop', 'V1\\Admin\\Server\\TrojanController@drop');
                $router->post('update', 'V1\\Admin\\Server\\TrojanController@update');
                $router->post('copy', 'V1\\Admin\\Server\\TrojanController@copy');
            });
            $router->group([
                'prefix' => 'server/vmess'
            ], function ($router) {
                $router->post('save', 'V1\\Admin\\Server\\VmessController@save');
                $router->post('drop', 'V1\\Admin\\Server\\VmessController@drop');
                $router->post('update', 'V1\\Admin\\Server\\VmessController@update');
                $router->post('copy', 'V1\\Admin\\Server\\VmessController@copy');
            });
            $router->group([
                'prefix' => 'server/shadowsocks'
            ], function ($router) {
                $router->post('save', 'V1\\Admin\\Server\\ShadowsocksController@save');
                $router->post('drop', 'V1\\Admin\\Server\\ShadowsocksController@drop');
                $router->post('update', 'V1\\Admin\\Server\\ShadowsocksController@update');
                $router->post('copy', 'V1\\Admin\\Server\\ShadowsocksController@copy');
            });
            $router->group([
                'prefix' => 'server/tuic'
            ], function ($router) {
                $router->post('save', 'V1\\Admin\\Server\\TuicController@save');
                $router->post('drop', 'V1\\Admin\\Server\\TuicController@drop');
                $router->post('update', 'V1\\Admin\\Server\\TuicController@update');
                $router->post('copy', 'V1\\Admin\\Server\\TuicController@copy');
            });
            $router->group([
                'prefix' => 'server/hysteria'
            ], function ($router) {
                $router->post('save', 'V1\\Admin\\Server\\HysteriaController@save');
                $router->post('drop', 'V1\\Admin\\Server\\HysteriaController@drop');
                $router->post('update', 'V1\\Admin\\Server\\HysteriaController@update');
                $router->post('copy', 'V1\\Admin\\Server\\HysteriaController@copy');
            });
            $router->group([
                'prefix' => 'server/vless'
            ], function ($router) {
                $router->post('save', 'V1\\Admin\\Server\\VlessController@save');
                $router->post('drop', 'V1\\Admin\\Server\\VlessController@drop');
                $router->post('update', 'V1\\Admin\\Server\\VlessController@update');
                $router->post('copy', 'V1\\Admin\\Server\\VlessController@copy');
            });
            $router->group([
                'prefix' => 'server/anytls'
            ], function ($router) {
                $router->post('save', 'V1\\Admin\\Server\\AnyTLSController@save');
                $router->post('drop', 'V1\\Admin\\Server\\AnyTLSController@drop');
                $router->post('update', 'V1\\Admin\\Server\\AnyTLSController@update');
                $router->post('copy', 'V1\\Admin\\Server\\AnyTLSController@copy');
            });
            $router->group([
                'prefix' => 'server/v2node'
            ], function ($router) {
                $router->post('save', 'V1\\Admin\\Server\\V2nodeController@save');
                $router->post('drop', 'V1\\Admin\\Server\\V2nodeController@drop');
                $router->post('update', 'V1\\Admin\\Server\\V2nodeController@update');
                $router->post('copy', 'V1\\Admin\\Server\\V2nodeController@copy');
            });
            // Order
            $router->get ('/order/fetch', 'V1\\Admin\\OrderController@fetch');
            $router->post('/order/update', 'V1\\Admin\\OrderController@update');
            $router->post('/order/assign', 'V1\\Admin\\OrderController@assign');
            $router->post('/order/paid', 'V1\\Admin\\OrderController@paid');
            $router->post('/order/cancel', 'V1\\Admin\\OrderController@cancel');
            $router->post('/order/detail', 'V1\\Admin\\OrderController@detail');
            // User
            // These two routes are used by the compiled admin client immediately
            // after authentication. They must stay under the secure admin path so
            // maintenance mode does not block the administrator session refresh.
            $router->get ('/user/checkLogin', 'V1\\User\\UserController@checkLogin');
            $router->get ('/user/info', 'V1\\User\\UserController@info');
            $router->get ('/user/fetch', 'V1\\Admin\\UserController@fetch');
            $router->post('/user/update', 'V1\\Admin\\UserController@update');
            $router->get ('/user/getUserInfoById', 'V1\\Admin\\UserController@getUserInfoById');
            $router->post('/user/generate', 'V1\\Admin\\UserController@generate');
            $router->post('/user/dumpCSV', 'V1\\Admin\\UserController@dumpCSV');
            $router->post('/user/sendMail', 'V1\\Admin\\UserController@sendMail');
            $router->post('/user/ban', 'V1\\Admin\\UserController@ban');
            $router->post('/user/resetSecret', 'V1\\Admin\\UserController@resetSecret');
            $router->post('/user/resetPassword', 'V1\\Admin\\UserController@resetPassword');
            $router->post('/user/delUser', 'V1\\Admin\\UserController@delUser');
            $router->post('/user/allDel', 'V1\\Admin\\UserController@allDel');
            $router->post('/user/setInviteUser', 'V1\\Admin\\UserController@setInviteUser');
            $router->post('/user/subscription/set-primary', 'V1\\Admin\\UserController@setPrimarySubscription');
            $router->post('/user/subscription/revoke', 'V1\\Admin\\UserController@revokeSubscription');
            $router->get ('/user/subscribe-requests', 'V1\\Admin\\UserController@subscribeRequests');
            $router->get ('/user/risk', 'V1\\Admin\\UserController@subscriptionRisk');
            $router->post('/user/subscribe-audit/clear', 'V1\\Admin\\UserController@clearSubscribeAudit');
            // Risk
            $router->get ('/risk/rule/fetch', 'V1\\Admin\\RiskRuleController@fetch');
            $router->post('/risk/rule/save', 'V1\\Admin\\RiskRuleController@save');
            $router->post('/risk/rule/show', 'V1\\Admin\\RiskRuleController@show');
            $router->post('/risk/rule/sort', 'V1\\Admin\\RiskRuleController@sort');
            $router->post('/risk/rule/drop', 'V1\\Admin\\RiskRuleController@drop');
            $router->post('/risk/rule/recompute', 'V1\\Admin\\RiskRuleController@recompute');
            $router->get ('/risk/trace/fetch', 'V1\\Admin\\RiskTraceController@fetch');
            $router->get ('/risk/trace/history', 'V1\\Admin\\RiskTraceController@history');
            // lookup 与 reveal 用 POST 而非 GET：GET 会把 token 拼进 query string，落进
            // nginx 访问日志、浏览器历史与 Referer。详见控制器方法注释。
            $router->post('/risk/trace/token/lookup', 'V1\\Admin\\RiskTraceController@lookup');
            $router->post('/risk/trace/token/reveal', 'V1\\Admin\\RiskTraceController@reveal');
            // Stat
            $router->get ('/stat/getStat', 'V1\\Admin\\StatController@getStat');
            $router->get ('/stat/getOverride', 'V1\\Admin\\StatController@getOverride');
            $router->get ('/stat/getServerLastRank', 'V1\\Admin\\StatController@getServerLastRank');
            $router->get ('/stat/getServerTodayRank', 'V1\\Admin\\StatController@getServerTodayRank');
            $router->get ('/stat/getUserLastRank', 'V1\\Admin\\StatController@getUserLastRank');
            $router->get ('/stat/getUserTodayRank', 'V1\\Admin\\StatController@getUserTodayRank');
            $router->get ('/stat/getOrder', 'V1\\Admin\\StatController@getOrder');
            $router->get ('/stat/getStatUser', 'V1\\Admin\\StatController@getStatUser');
            $router->get ('/stat/getRanking', 'V1\\Admin\\StatController@getRanking');
            $router->get ('/stat/getStatRecord', 'V1\\Admin\\StatController@getStatRecord');
            // Notice
            $router->get ('/notice/fetch', 'V1\\Admin\\NoticeController@fetch');
            $router->post('/notice/save', 'V1\\Admin\\NoticeController@save');
            $router->post('/notice/update', 'V1\\Admin\\NoticeController@update');
            $router->post('/notice/drop', 'V1\\Admin\\NoticeController@drop');
            $router->post('/notice/show', 'V1\\Admin\\NoticeController@show');
            // Ticket
            $router->get ('/ticket/fetch', 'V1\\Admin\\TicketController@fetch');
            $router->post('/ticket/reply', 'V1\\Admin\\TicketController@reply');
            $router->post('/ticket/close', 'V1\\Admin\\TicketController@close');
            // Coupon
            $router->get ('/coupon/fetch', 'V1\\Admin\\CouponController@fetch');
            $router->post('/coupon/generate', 'V1\\Admin\\CouponController@generate');
            $router->post('/coupon/drop', 'V1\\Admin\\CouponController@drop');
            $router->post('/coupon/show', 'V1\\Admin\\CouponController@show');
            // Giftcard
            $router->get ('/giftcard/fetch', 'V1\\Admin\\GiftcardController@fetch');
            $router->post('/giftcard/generate', 'V1\\Admin\\GiftcardController@generate');
            $router->post('/giftcard/drop', 'V1\\Admin\\GiftcardController@drop');
            // Knowledge
            $router->get ('/knowledge/fetch', 'V1\\Admin\\KnowledgeController@fetch');
            $router->get ('/knowledge/getCategory', 'V1\\Admin\\KnowledgeController@getCategory');
            $router->post('/knowledge/save', 'V1\\Admin\\KnowledgeController@save');
            $router->post('/knowledge/show', 'V1\\Admin\\KnowledgeController@show');
            $router->post('/knowledge/drop', 'V1\\Admin\\KnowledgeController@drop');
            $router->post('/knowledge/sort', 'V1\\Admin\\KnowledgeController@sort');
            // Payment
            $router->get ('/payment/fetch', 'V1\\Admin\\PaymentController@fetch');
            $router->get ('/payment/getPaymentMethods', 'V1\\Admin\\PaymentController@getPaymentMethods');
            $router->post('/payment/getPaymentForm', 'V1\\Admin\\PaymentController@getPaymentForm');
            $router->post('/payment/save', 'V1\\Admin\\PaymentController@save');
            $router->post('/payment/drop', 'V1\\Admin\\PaymentController@drop');
            $router->post('/payment/show', 'V1\\Admin\\PaymentController@show');
            $router->post('/payment/sort', 'V1\\Admin\\PaymentController@sort');
            // System
            $router->get ('/system/getSystemStatus', 'V1\\Admin\\SystemController@getSystemStatus');
            $router->get ('/system/getQueueStats', 'V1\\Admin\\SystemController@getQueueStats');
            $router->get ('/system/getQueueWorkload', 'V1\\Admin\\SystemController@getQueueWorkload');
            $router->get ('/system/getQueueMasters', '\\Laravel\\Horizon\\Http\\Controllers\\MasterSupervisorController@index');
            $router->get ('/system/getSystemLog', 'V1\\Admin\\SystemController@getSystemLog');
            // Theme
            $router->get ('/theme/getThemes', 'V1\\Admin\\ThemeController@getThemes');
            $router->post('/theme/saveThemeConfig', 'V1\\Admin\\ThemeController@saveThemeConfig');
            $router->post('/theme/getThemeConfig', 'V1\\Admin\\ThemeController@getThemeConfig');
            // Reseller marketplace
            $router->get ('/reseller/summary', 'V1\\Admin\\ResellerController@summary');
            $router->get ('/reseller/accounts', 'V1\\Admin\\ResellerController@accounts');
            $router->post('/reseller/accounts/review', 'V1\\Admin\\ResellerController@review');
            $router->get ('/reseller/stores', 'V1\\Admin\\ResellerController@stores');
            $router->post('/reseller/stores/review', 'V1\\Admin\\ResellerController@review');
            $router->get ('/reseller/review-logs', 'V1\\Admin\\ResellerController@reviewLogs');
            $router->get ('/reseller/fetch', 'V1\\Admin\\ResellerController@fetch');
            $router->post('/reseller/update', 'V1\\Admin\\ResellerController@update');
            $router->get ('/reseller/template/fetch', 'V1\\Admin\\ResellerController@templates');
            $router->post('/reseller/template/save', 'V1\\Admin\\ResellerController@saveTemplate');
            // Hash-admin reseller module aliases. Keep the singular legacy routes above for compatibility.
            $router->get ('/reseller/templates', 'V1\\Admin\\ResellerController@templates');
            $router->post('/reseller/templates/save', 'V1\\Admin\\ResellerController@saveTemplate');
            $router->get ('/reseller/payment-drivers', 'V1\\Admin\\ResellerController@paymentDrivers');
            $router->post('/reseller/payment-drivers', 'V1\\Admin\\ResellerController@savePaymentDrivers');
            $router->get ('/reseller/orders', 'V1\\Admin\\ResellerController@orders');
        });
    }
}
