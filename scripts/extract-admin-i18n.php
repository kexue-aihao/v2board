<?php
/**
 * 管理端编译产物中文字面量抽取器（只读，与 patch-admin-reseller.php 同族）。
 *
 * 扫 public/assets/admin/{umi.js,components.async.js,vendors.async.js} 的
 * 引号字符串字面量（上游 \uXXXX 转义与 fork 补丁的裸 UTF-8 一并覆盖），
 * 解码后凡含 CJK 的去重输出，附出现次数。用途：
 *   1) 首次生成 i18n.js 字典骨架；
 *   2) 日后 bundle 打补丁/换版后重跑 diff，找出新增待翻串。
 *
 * 用法：php scripts/extract-admin-i18n.php [输出.json]
 *   不带参数输出到 stdout；带参数写 JSON 文件（键=中文原文，值=""，占位待翻，
 *   另附 _meta 频次表）。已存在的输出文件不会被读取合并——纯抽取，无状态。
 */

$files = [
    'umi.js',
    'components.async.js',
    'vendors.async.js',
];
$base = __DIR__ . '/../public/assets/admin/';

// 码点 → UTF-8（零扩展依赖，本地/生产 PHP 都不要求 mbstring）。
function cp_utf8(int $cp): string
{
    if ($cp < 0x80) return chr($cp);
    if ($cp < 0x800) return chr(0xC0 | $cp >> 6) . chr(0x80 | $cp & 0x3F);
    if ($cp < 0x10000) return chr(0xE0 | $cp >> 12) . chr(0x80 | ($cp >> 6) & 0x3F) . chr(0x80 | $cp & 0x3F);
    return chr(0xF0 | $cp >> 18) . chr(0x80 | ($cp >> 12) & 0x3F) . chr(0x80 | ($cp >> 6) & 0x3F) . chr(0x80 | $cp & 0x3F);
}

// JS 字符串转义解码（够用面：\uXXXX 含代理对、\xHH、常见单字符转义）。
function js_decode(string $s): string
{
    // 代理对优先成对解码，散单 \uXXXX 其次
    $s = preg_replace_callback('/\\\\u(d[89ab][0-9a-fA-F]{2})\\\\u(d[c-fC-F][0-9a-fA-F]{2})|\\\\u([0-9a-fA-F]{4})/i', function ($m) {
        if (isset($m[3]) && $m[3] !== '') {
            return cp_utf8(hexdec($m[3]));
        }
        return cp_utf8(0x10000 + ((hexdec($m[1]) - 0xD800) << 10) + (hexdec($m[2]) - 0xDC00));
    }, $s);
    $s = preg_replace_callback('/\\\\x([0-9a-fA-F]{2})/', function ($m) {
        return chr(hexdec($m[1]));
    }, $s);
    return strtr($s, [
        '\\n' => "\n", '\\t' => "\t", '\\r' => "\r",
        '\\"' => '"', "\\'" => "'", '\\`' => '`',
        '\\/' => '/', '\\\\' => '\\',
    ]);
}

$out = [];      // 原文 => ['n' => 次数, 'files' => [文件 => 次数]]
foreach ($files as $name) {
    $path = $base . $name;
    if (!is_file($path)) {
        fwrite(STDERR, "跳过（不存在）：$name\n");
        continue;
    }
    $src = file_get_contents($path);
    // 双引号/单引号字面量（已核实：三份 bundle 与 fork 补丁源码均无含中文的
    // 反引号模板串，扫反引号只会把跨串区间误吞并，故不扫）；
    // 所有格量词避免 5MB 文件上的回溯灾难
    preg_match_all('/"(?:[^"\\\\]++|\\\\.)*+"|\'(?:[^\'\\\\]++|\\\\.)*+\'/', $src, $m);
    foreach ($m[0] as $lit) {
        // 先便宜筛：转义 CJK（䀀-鿿 粗放区间）或裸 CJK 任一命中才解码
        if (!preg_match('/\\\\u[4-9][0-9a-f]{3}/i', $lit)
            && !preg_match('/[\x{4e00}-\x{9fff}]/u', $lit)) {
            continue;
        }
        $text = js_decode(substr($lit, 1, -1));
        if (!preg_match('/[\x{4e00}-\x{9fff}]/u', $text)) {
            continue; // 只收含汉字的；纯全角标点/假名不收
        }
        if (!isset($out[$text])) {
            $out[$text] = ['n' => 0, 'files' => []];
        }
        $out[$text]['n']++;
        $out[$text]['files'][$name] = ($out[$text]['files'][$name] ?? 0) + 1;
    }

    // 恢复趟：主趟的引号配对会被压缩代码里含引号的正则字面量带偏——失步后整段
    // 代码被吞成一个巨串，其内部的真字符串全部漏抽（实测 umi.js 漏 ~360 个）。
    // 补救：对每个 CJK 命中区向两侧找最近的未转义引号，把引号对内内容解码并入。
    // 误报无害：产出只是人工复核的待翻骨架，永远匹配不上 DOM 的串不会生效。
    $unescaped = function (int $i) use ($src): bool {
        $bs = 0;
        for ($j = $i - 1; $j >= 0 && $src[$j] === '\\'; $j--) $bs++;
        return $bs % 2 === 0;
    };
    preg_match_all('/(?:\\\\u[4-9][0-9a-fA-F]{3})+|[\x{4e00}-\x{9fff}]+/u', $src, $runs, PREG_OFFSET_CAPTURE);
    $len = strlen($src);
    $recovered = 0;
    foreach ($runs[0] as [$run, $off]) {
        $q = null;
        $start = -1;
        for ($i = $off - 1; $i >= 0 && $i > $off - 4096; $i--) {
            $ch = $src[$i];
            if (($ch === '"' || $ch === "'") && $unescaped($i)) { $q = $ch; $start = $i; break; }
        }
        if ($q === null) continue;
        $endPos = -1;
        $from = $off + strlen($run);
        for ($i = $from; $i < $len && $i < $from + 4096; $i++) {
            if ($src[$i] === $q && $unescaped($i)) { $endPos = $i; break; }
        }
        if ($endPos < 0) continue;
        $text = js_decode(substr($src, $start + 1, $endPos - $start - 1));
        if (strlen($text) > 600 || !preg_match('/[\x{4e00}-\x{9fff}]/u', $text)) continue;
        if (!isset($out[$text])) {
            $out[$text] = ['n' => 1, 'files' => [$name . '+recovery' => 1]];
            $recovered++;
        }
    }
    fwrite(STDERR, sprintf("%s：累计 %d 个去重中文串（恢复趟 +%d）\n", $name, count($out), $recovered));
}

uasort($out, fn($a, $b) => $b['n'] <=> $a['n']);

$target = $argv[1] ?? null;
if ($target) {
    $skeleton = ['_meta' => ['generated_from' => $files, 'distinct' => count($out), 'freq' => array_map(fn($v) => $v['n'], $out)]];
    foreach ($out as $text => $_) {
        $skeleton[$text] = '';
    }
    file_put_contents($target, json_encode($skeleton, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
    fwrite(STDERR, "已写 {$target}（" . count($out) . " 串）\n");
} else {
    foreach ($out as $text => $info) {
        echo $info['n'] . "\t" . str_replace("\n", '\\n', $text) . "\n";
    }
}
