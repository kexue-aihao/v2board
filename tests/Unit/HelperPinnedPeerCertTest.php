<?php

namespace Tests\Unit;

use App\Utils\Helper;
use Tests\TestCase;

class HelperPinnedPeerCertTest extends TestCase
{
    private const PIN = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

    protected function tearDown(): void
    {
        Helper::setIncludeXrayPcs(false);
        parent::tearDown();
    }

    /**
     * V2rayN / V2rayNG 的协议处理器会打开这个开关，只有它们能收到证书指纹。
     * hysteria 系列的查询参数名是 pinSHA256，其余是 pcs。
     */
    public function testPinnedCertificateIsIncludedInV2rayShareLinks(): void
    {
        Helper::setIncludeXrayPcs(true);
        $server = $this->serverWithPin();

        $vmess = json_decode(base64_decode(substr(trim(Helper::buildVmessUri('uuid', $server)), 8)), true);

        $this->assertSame(self::PIN, $vmess['pcs']);
        $this->assertSame(self::PIN, $this->query(Helper::buildVlessUri('uuid', $server))['pcs']);
        $this->assertSame(self::PIN, $this->query(Helper::buildTrojanUri('password', $server))['pcs']);
        $this->assertSame(self::PIN, $this->query(Helper::buildTuicUri('password', $server))['pcs']);
        $this->assertSame(self::PIN, $this->query(Helper::buildAnytlsUri('password', $server))['pcs']);
        $this->assertSame(self::PIN, $this->query(Helper::buildHysteria2Uri('password', $server))['pinSHA256']);
    }

    /**
     * 其余客户端（Clash / sing-box / Shadowrocket 等）不允许收到指纹：它们不认
     * pcs，收到会让整份订阅解析失败。开关默认关闭，只有上面两个处理器会打开。
     */
    public function testPinnedCertificateIsNotEmittedForOtherClients(): void
    {
        Helper::setIncludeXrayPcs(false);
        $server = $this->serverWithPin();

        $vmess = json_decode(base64_decode(substr(trim(Helper::buildVmessUri('uuid', $server)), 8)), true);

        $this->assertArrayNotHasKey('pcs', $vmess);
        $this->assertArrayNotHasKey('vcn', $vmess);
        $this->assertArrayNotHasKey('pcs', $this->query(Helper::buildVlessUri('uuid', $server)));
        $this->assertArrayNotHasKey('pcs', $this->query(Helper::buildTrojanUri('password', $server)));
        $this->assertArrayNotHasKey('pcs', $this->query(Helper::buildTuicUri('password', $server)));
        $this->assertArrayNotHasKey('pcs', $this->query(Helper::buildAnytlsUri('password', $server)));
        $this->assertArrayNotHasKey('pinSHA256', $this->query(Helper::buildHysteria2Uri('password', $server)));
    }

    public function testEmptyPinIsNotAddedToSubscriptions(): void
    {
        Helper::setIncludeXrayPcs(true);
        $server = $this->server([
            'tls' => 1,
            'tls_settings' => ['server_name' => 'example.com'],
        ]);

        $vmess = json_decode(base64_decode(substr(trim(Helper::buildVmessUri('uuid', $server)), 8)), true);

        $this->assertArrayNotHasKey('pcs', $vmess);
        $this->assertArrayNotHasKey('pcs', $this->query(Helper::buildVlessUri('uuid', $server)));
        $this->assertArrayNotHasKey('pcs', $this->query(Helper::buildTrojanUri('password', $server)));
        $this->assertArrayNotHasKey('pcs', $this->query(Helper::buildTuicUri('password', $server)));
        $this->assertArrayNotHasKey('pcs', $this->query(Helper::buildAnytlsUri('password', $server)));
        $this->assertArrayNotHasKey('pinSHA256', $this->query(Helper::buildHysteria2Uri('password', $server)));
    }

    /**
     * 节点 API 与订阅服务里同时存在 snake_case 与驼峰两种载荷形状，指纹要都能取到。
     */
    public function testCamelCaseTlsSettingIsAcceptedForLegacyServerShape(): void
    {
        Helper::setIncludeXrayPcs(true);
        $server = $this->server([
            'tls' => 1,
            'tlsSettings' => [
                'serverName' => 'example.com',
                'pinnedPeerCertSha256' => self::PIN,
            ],
        ]);

        $vmess = json_decode(base64_decode(substr(trim(Helper::buildVmessUri('uuid', $server)), 8)), true);

        $this->assertSame(self::PIN, $vmess['pcs']);
    }

    private function serverWithPin(): array
    {
        return $this->server([
            'tls' => 1,
            'tls_settings' => [
                'server_name' => 'example.com',
                'pinned_peer_cert_sha256' => self::PIN,
            ],
        ]);
    }

    private function server(array $overrides = []): array
    {
        return array_replace_recursive([
            'name' => 'Test node',
            'host' => 'example.com',
            'port' => 443,
            'network' => 'tcp',
            'tls' => 1,
            'flow' => '',
            'tls_settings' => [],
            'server_name' => 'example.com',
            'insecure' => 0,
            'allow_insecure' => 0,
            'disable_sni' => 0,
            'zero_rtt_handshake' => 0,
            'udp_relay_mode' => 'native',
            'congestion_control' => 'cubic',
            'version' => 2,
            'network_settings' => [],
        ], $overrides);
    }

    private function query(string $uri): array
    {
        parse_str((string) parse_url(trim($uri), PHP_URL_QUERY), $params);
        return $params;
    }
}
