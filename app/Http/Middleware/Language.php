<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Support\Facades\App;

class Language
{
    /**
     * 客户端语言标签（统一小写）→ 站内翻译目录。表外一律不认：locale 会被
     * Laravel 拼进 resources/lang/{locale}.json 路径，放行任意原始串等于放行
     * 任意 .json 文件读取。全部 8 个主题 UI 语种都有真目录
     * （resources/lang/<code>.json，键序与 en-US.json 一致），新增语种时
     * 先落目录文件再加表项。
     */
    private const CATALOG = [
        'zh-cn' => 'zh-CN',
        'zh-sg' => 'zh-CN',
        'zh'    => 'zh-CN',
        'zh-tw' => 'zh-TW',
        'zh-hk' => 'zh-TW',
        'en-us' => 'en-US',
        'en'    => 'en-US',
        'ja-jp' => 'ja-JP',
        'ja'    => 'ja-JP',
        'ko-kr' => 'ko-KR',
        'ko'    => 'ko-KR',
        'vi-vn' => 'vi-VN',
        'vi'    => 'vi-VN',
        'ru-ru' => 'ru-RU',
        'ru'    => 'ru-RU',
        'fa-ir' => 'fa-IR',
        'fa'    => 'fa-IR',
    ];

    /**
     * 启动期默认 locale。不能每次现读 config('app.locale')：App::setLocale()
     * 本身就是 config()->set('app.locale', ...)，长驻 worker 上现读拿到的是
     * 上一个请求写入的值，兜底会跟着漂移。首个请求经过本中间件时 config
     * 还是启动快照，捕获进 static 即为真·默认值。
     */
    private static $defaultLocale;

    public function handle($request, Closure $next)
    {
        if (self::$defaultLocale === null) {
            self::$defaultLocale = (string)config('app.locale', 'zh-CN');
        }
        // webman worker 长驻，这里必须无条件 setLocale：只在带头时设置的话，
        // 无头请求会继承同 worker 上一个请求残留的 locale（跨请求语言泄漏）。
        App::setLocale($this->resolve($request) ?? self::$defaultLocale);
        return $next($request);
    }

    private function resolve($request): ?string
    {
        // default 主题（umi）发 Content-Language，signature/ez 发 Accept-Language；
        // Content-Language 优先，保持 default 主题的既有行为不变。
        $locale = $this->match((string)$request->header('content-language'));
        if ($locale !== null) {
            return $locale;
        }
        // Accept-Language 形如 "en-US,en;q=0.9"：按逗号取候选，先中先得。
        // 信任浏览器的降序排列（主流浏览器皆如此），不做全量 q 值排序；
        // 但 q=0 是 RFC 9110 的「明确拒绝」，必须跳过。
        foreach (explode(',', (string)$request->header('accept-language')) as $candidate) {
            $parts = explode(';', $candidate);
            $rejected = false;
            for ($i = 1; $i < count($parts); $i++) {
                if (preg_match('/^\s*q\s*=\s*0(?:\.0{1,3})?\s*$/i', $parts[$i])) {
                    $rejected = true;
                    break;
                }
            }
            if ($rejected) {
                continue;
            }
            $locale = $this->match($parts[0]);
            if ($locale !== null) {
                return $locale;
            }
        }
        return null;
    }

    /**
     * 站点默认 locale（启动快照）。给「不该跟随请求 locale」的场景用——
     * 例如发给收件人的邮件文案（触发者的语言≠收件人的语言）。
     */
    public static function defaultLocale(): string
    {
        return self::$defaultLocale ?? (string)config('app.locale', 'zh-CN');
    }

    private function match(string $tag): ?string
    {
        $tag = strtolower(trim($tag));
        if ($tag === '' || strlen($tag) > 20) {
            return null;
        }
        if (isset(self::CATALOG[$tag])) {
            return self::CATALOG[$tag];
        }
        // en-GB / zh-Hans-CN 这类未列出的地区变体退到主语言段再查一次。
        return self::CATALOG[explode('-', $tag)[0]] ?? null;
    }
}
