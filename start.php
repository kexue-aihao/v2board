<?php

/**
 * Laravel - A PHP Framework For Web Artisans
 *
 * @package  Laravel
 * @author   Taylor Otwell <taylor@laravel.com>
 */

define('LARAVEL_START', microtime(true));

/*
|--------------------------------------------------------------------------
| Register The Auto Loader
|--------------------------------------------------------------------------
|
| Composer provides a convenient, automatically generated class loader for
| our application. We just need to utilize it! We'll simply require it
| into the script here so that we don't have to worry about manual
| loading any of our classes later on. It feels great to relax.
|
*/

require __DIR__.'/vendor/autoload.php';

/*
|--------------------------------------------------------------------------
| Turn On The Lights
|--------------------------------------------------------------------------
|
| We need to illuminate PHP development, so let us turn on the lights.
| This bootstraps the framework and gets it ready for use, then it
| will load up this application so that we can run it and send
| the responses back to the browser and delight our users.
|
*/

$app = require_once __DIR__.'/bootstrap/app.php';

/*
|--------------------------------------------------------------------------
| Run The Application
|--------------------------------------------------------------------------
|
| Once we have the application, we can handle the incoming request
| through the kernel, and send the associated response back to
| the client's browser allowing them to enjoy the creative
| and wonderful application we have prepared for them.
|
*/

global $kernel;

$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);

function run()
{
    global $kernel;

    ob_start();

    try {
        $response = $kernel->handle(
            $request = Illuminate\Http\Request::capture()
        );

        $response->send();

        $kernel->terminate($request, $response);

        return ob_get_clean();
    } finally {
        // Webman/AdapterMan 是常驻进程：PDO 连接跨请求复用。若某请求在开着 DB 事务时抛异常
        // （例如 abort() 落在 DB::beginTransaction() 与 DB::commit() 之间，Laravel 不会自动回滚
        // 手动开启的事务），事务会残留到下一个请求 —— 后续 beginTransaction 变成嵌套 savepoint、
        // commit 不真正提交，资金写入的持久性与隔离性都不再可靠。这里在每个请求收尾兜底回滚所有
        // 未提交事务；正常请求 transactionLevel 恒为 0，是零成本空操作。整段自带 try/catch，
        // 兜底逻辑本身绝不影响already-sent的响应。
        try {
            foreach (app('db')->getConnections() as $connection) {
                while ($connection->transactionLevel() > 0) {
                    $connection->rollBack();
                }
            }
        } catch (\Throwable $e) {
        }
    }
}
