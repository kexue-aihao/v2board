<?php

namespace App\Services;

/**
 * 外部订阅内容的解析：一份订阅文本 → 节点数组。
 *
 * 纯函数，不碰网络也不碰数据库，单测可以直接喂样本。
 *
 * 只做「base64 或明文 URI 列表」这一种最常见的形态（机场的通用做法）。Clash YAML /
 * sing-box JSON 这类结构化订阅暂时记为不支持，由调用方把错误写进源的 last_error ——
 * 猜格式去解析只会把一份看不懂的配置变成一堆看起来正常的错节点。
 *
 * 每个节点都保留原始 URI（raw_uri）：v2ray 系客户端的订阅就是把这些链接原样拼回去，
 * 有它就不必为每种协议再写一遍「还原成 URI」的代码。
 */
class ExternalSubscriptionParser
{
    /** 单个源一次最多收这么多节点：源被换成一堆垃圾时不至于把站点拖垮。 */
    public const MAX_NODES = 500;

    private const SCHEME_PROTOCOLS = [
        'vmess' => 'vmess',
        'vless' => 'vless',
        'trojan' => 'trojan',
        'ss' => 'shadowsocks',
        'hysteria2' => 'hysteria2',
        'hy2' => 'hysteria2',
        'tuic' => 'tuic',
        'anytls' => 'anytls'
    ];

    /**
     * @return array{count: int, skipped: int, base64: bool, nodes: array<int, array<string, mixed>>}
     */
    public function parse(string $body): array
    {
        $decoded = $this->decode($body);
        $nodes = [];
        $skipped = 0;
        $seen = [];

        foreach (preg_split('/\r\n|\r|\n/', $decoded['text']) as $line) {
            $uri = trim($line);
            if ($uri === '') {
                continue;
            }
            $node = $this->parseUri($uri);
            if ($node === null) {
                $skipped++;
                continue;
            }
            // 同一份订阅里重复贴同一个节点是常态（多个入口域名指向同一台机器），
            // 原样收下来只会让用户的列表里出现一堆一模一样的条目。
            $fingerprint = md5($node['raw_uri']);
            if (isset($seen[$fingerprint])) {
                continue;
            }
            $seen[$fingerprint] = true;
            $nodes[] = $node;
            if (count($nodes) >= self::MAX_NODES) {
                break;
            }
        }

        return [
            'count' => count($nodes),
            'skipped' => $skipped,
            'base64' => $decoded['base64'],
            'nodes' => $nodes
        ];
    }

    /**
     * 订阅体通常是整份 base64。解出来必须像一份 URI 列表才认，否则按明文处理 ——
     * 有些源直接返回明文，硬解 base64 会得到一堆乱码再被当成「解析不出节点」。
     *
     * @return array{text: string, base64: bool}
     */
    private function decode(string $body): array
    {
        $trimmed = trim($body);
        if ($trimmed === '') {
            return ['text' => '', 'base64' => false];
        }
        $compact = preg_replace('/\s+/', '', $trimmed);
        if ($compact !== '' && preg_match('#^[A-Za-z0-9+/_=-]+$#', $compact)) {
            $decoded = base64_decode(strtr($compact, '-_', '+/'), true);
            if (is_string($decoded) && $decoded !== '' && strpos($decoded, '://') !== false) {
                return ['text' => $decoded, 'base64' => true];
            }
        }

        return ['text' => $trimmed, 'base64' => false];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function parseUri(string $uri)
    {
        $scheme = strtolower((string) parse_url($uri, PHP_URL_SCHEME));
        if (!isset(self::SCHEME_PROTOCOLS[$scheme])) {
            return null;
        }
        if ($scheme === 'vmess') {
            return $this->parseVmess($uri);
        }
        if ($scheme === 'ss') {
            return $this->parseShadowsocks($uri);
        }

        return $this->parseUrlStyle($uri, $scheme, self::SCHEME_PROTOCOLS[$scheme]);
    }

    /**
     * vmess://<base64(JSON)>：字段是一份 JSON，不是 URL 参数。
     *
     * @return array<string, mixed>|null
     */
    private function parseVmess(string $uri)
    {
        $payload = substr($uri, strlen('vmess://'));
        $json = base64_decode(strtr(trim($payload), '-_', '+/'), true);
        if (!is_string($json) || $json === '') {
            return null;
        }
        $config = json_decode($json, true);
        if (!is_array($config) || empty($config['add']) || empty($config['port']) || empty($config['id'])) {
            return null;
        }

        // vmess 的 tls 字段是 'tls' / '' / 偶尔的 'none'，别把 none 当成开了 TLS
        $tls = in_array(strtolower((string) ($config['tls'] ?? '')), ['tls', 'reality', 'xtls'], true) ? 1 : 0;
        $network = strtolower((string) ($config['net'] ?? 'tcp'));

        return $this->node([
            'name' => (string) ($config['ps'] ?? ''),
            'protocol' => 'vmess',
            'host' => (string) $config['add'],
            'port' => (int) $config['port'],
            'uuid' => (string) $config['id'],
            'cipher' => (string) ($config['scy'] ?? 'auto'),
            'network' => $network,
            'tls' => $tls,
            'sni' => (string) ($config['sni'] ?? ($config['host'] ?? '')),
            'path' => (string) ($config['path'] ?? ''),
            'host_header' => (string) ($config['host'] ?? ''),
            'extra' => ['aid' => (int) ($config['aid'] ?? 0), 'type' => (string) ($config['type'] ?? '')]
        ], $uri);
    }

    /**
     * ss:// 有两种写法：SIP002 的 base64(方法:密码)@主机:端口，以及整串 base64 的旧写法。
     *
     * @return array<string, mixed>|null
     */
    private function parseShadowsocks(string $uri)
    {
        $rest = substr($uri, strlen('ss://'));
        $fragment = '';
        if (strpos($rest, '#') !== false) {
            [$rest, $fragment] = explode('#', $rest, 2);
        }
        $query = '';
        if (strpos($rest, '?') !== false) {
            [$rest, $query] = explode('?', $rest, 2);
        }

        if (strpos($rest, '@') === false) {
            // 旧写法：整串是 base64(方法:密码@主机:端口)
            $decoded = base64_decode(strtr(trim($rest), '-_', '+/'), true);
            if (!is_string($decoded) || strpos($decoded, '@') === false) {
                return null;
            }
            $rest = $decoded;
        }

        $parts = explode('@', $rest);
        $credentials = base64_decode(strtr(array_shift($parts), '-_', '+/'), true);
        if (!is_string($credentials) || strpos($credentials, ':') === false) {
            return null;
        }
        [$cipher, $password] = explode(':', $credentials, 2);
        $endpoint = implode('@', $parts);
        [$host, $port] = $this->splitHostPort($endpoint);
        if ($host === '' || $port < 1) {
            return null;
        }

        parse_str($query, $params);

        return $this->node([
            'name' => rawurldecode($fragment),
            'protocol' => 'shadowsocks',
            'host' => $host,
            'port' => $port,
            'password' => $password,
            'cipher' => $cipher,
            'network' => 'tcp',
            'tls' => 0,
            'extra' => ['plugin' => (string) ($params['plugin'] ?? '')]
        ], $uri);
    }

    /**
     * vless / trojan / hysteria2 / tuic / anytls：标准 URL，参数在 query 里。
     *
     * @return array<string, mixed>|null
     */
    private function parseUrlStyle(string $uri, string $scheme, string $protocol)
    {
        $parts = parse_url($uri);
        if (!is_array($parts) || empty($parts['host'])) {
            return null;
        }
        $host = (string) $parts['host'];
        $port = isset($parts['port']) ? (int) $parts['port'] : 0;
        if ($port < 1) {
            return null;
        }
        parse_str((string) ($parts['query'] ?? ''), $params);

        $user = isset($parts['user']) ? rawurldecode((string) $parts['user']) : '';
        $pass = isset($parts['pass']) ? rawurldecode((string) $parts['pass']) : '';
        // trojan / hysteria2 / anytls 的 userinfo 就是密码本身；vless / tuic 是 uuid[:密码]
        $password = $pass !== '' ? $pass : ($scheme === 'vless' ? '' : $user);
        $uuid = $scheme === 'vless' || $scheme === 'tuic' ? $user : '';
        $security = strtolower((string) ($params['security'] ?? ''));

        return $this->node([
            'name' => rawurldecode((string) ($parts['fragment'] ?? '')),
            'protocol' => $protocol,
            'host' => $host,
            'port' => $port,
            'uuid' => $uuid,
            'password' => $password,
            'cipher' => '',
            'network' => strtolower((string) ($params['type'] ?? 'tcp')),
            // reality 也是「需要 TLS 才能连」的模式，对我们只关心「要不要按 TLS 处理」
            'tls' => in_array($security, ['tls', 'reality', 'xtls'], true) ? 1 : 0,
            'sni' => (string) ($params['sni'] ?? ($params['peer'] ?? '')),
            'path' => (string) ($params['path'] ?? ''),
            'host_header' => (string) ($params['host'] ?? ''),
            'service_name' => (string) ($params['serviceName'] ?? ''),
            'extra' => array_diff_key($params, array_flip(['security', 'type', 'sni', 'peer', 'path', 'host', 'serviceName']))
        ], $uri);
    }

    /**
     * @return array{0: string, 1: int}
     */
    private function splitHostPort(string $endpoint): array
    {
        $endpoint = trim($endpoint, "/ \t");
        if (strpos($endpoint, '[') === 0) {
            // IPv6 字面量：[::1]:443
            $closing = strpos($endpoint, ']');
            if ($closing === false) {
                return ['', 0];
            }

            return [substr($endpoint, 1, $closing - 1), (int) ltrim(substr($endpoint, $closing + 1), ':')];
        }
        $position = strrpos($endpoint, ':');
        if ($position === false) {
            return [$endpoint, 0];
        }

        return [substr($endpoint, 0, $position), (int) substr($endpoint, $position + 1)];
    }

    /**
     * 统一补齐字段并压掉超长值：外部数据一律按列宽截断，免得一条脏数据让整批入库失败。
     *
     * @param array<string, mixed> $node
     * @return array<string, mixed>
     */
    private function node(array $node, string $uri): array
    {
        $host = $this->text($node['host'] ?? '', 255);
        $port = (int) ($node['port'] ?? 0);
        $name = $this->text($node['name'] ?? '', 255);

        return [
            'name' => $name !== '' ? $name : $host . ':' . $port,
            'protocol' => (string) $node['protocol'],
            'host' => $host,
            'port' => $port,
            'uuid' => $this->text($node['uuid'] ?? '', 255),
            'password' => $this->text($node['password'] ?? '', 255),
            'cipher' => $this->text($node['cipher'] ?? '', 64),
            'network' => $this->text($node['network'] ?? '', 24),
            'tls' => (int) ($node['tls'] ?? 0) === 1 ? 1 : 0,
            'sni' => $this->text($node['sni'] ?? '', 255),
            'path' => $this->text($node['path'] ?? '', 500),
            'host_header' => $this->text($node['host_header'] ?? '', 255),
            'service_name' => $this->text($node['service_name'] ?? '', 255),
            'extra' => is_array($node['extra'] ?? null) ? $node['extra'] : [],
            'raw_uri' => $uri
        ];
    }

    private function text($value, int $max): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }

        return function_exists('mb_substr') ? mb_substr($value, 0, $max) : substr($value, 0, $max);
    }
}
