<?php

namespace Tests\Unit;

use App\Utils\Helper;
use Tests\TestCase;

class HelperPinnedPeerCertTest extends TestCase
{
    private const PIN = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

    public function testPinnedCertificateIsIncludedInSharedUriFormats(): void
    {
        $server = $this->server([
            'tls' => 1,
            'tls_settings' => [
                'server_name' => 'example.com',
                'pinned_peer_cert_sha256' => self::PIN,
            ],
        ]);

        $vmess = json_decode(base64_decode(substr(trim(Helper::buildVmessUri('uuid', $server)), 8)), true);
        $vless = parse_url(trim(Helper::buildVlessUri('uuid', $server)), PHP_URL_QUERY);
        $trojan = parse_url(trim(Helper::buildTrojanUri('password', $server)), PHP_URL_QUERY);
        $hysteria2 = parse_url(trim(Helper::buildHysteria2Uri('password', $server)), PHP_URL_QUERY);
        $tuic = parse_url(trim(Helper::buildTuicUri('password', $server)), PHP_URL_QUERY);
        $anytls = parse_url(trim(Helper::buildAnytlsUri('password', $server)), PHP_URL_QUERY);

        $this->assertSame(self::PIN, $vmess['pcs']);
        $this->assertSame(self::PIN, $this->query($vless)['pcs']);
        $this->assertSame(self::PIN, $this->query($trojan)['pcs']);
        $this->assertSame(self::PIN, $this->query($hysteria2)['pcs']);
        $this->assertSame(self::PIN, $this->query($tuic)['pcs']);
        $this->assertSame(self::PIN, $this->query($anytls)['pcs']);
    }

    public function testEmptyPinIsNotAddedToSubscriptions(): void
    {
        $server = $this->server([
            'tls' => 1,
            'tls_settings' => ['server_name' => 'example.com'],
        ]);

        $vmess = json_decode(base64_decode(substr(trim(Helper::buildVmessUri('uuid', $server)), 8)), true);
        $vless = $this->query(parse_url(trim(Helper::buildVlessUri('uuid', $server)), PHP_URL_QUERY));
        $trojan = $this->query(parse_url(trim(Helper::buildTrojanUri('password', $server)), PHP_URL_QUERY));
        $hysteria2 = $this->query(parse_url(trim(Helper::buildHysteria2Uri('password', $server)), PHP_URL_QUERY));
        $tuic = $this->query(parse_url(trim(Helper::buildTuicUri('password', $server)), PHP_URL_QUERY));
        $anytls = $this->query(parse_url(trim(Helper::buildAnytlsUri('password', $server)), PHP_URL_QUERY));

        $this->assertArrayNotHasKey('pcs', $vmess);
        $this->assertArrayNotHasKey('pcs', $vless);
        $this->assertArrayNotHasKey('pcs', $trojan);
        $this->assertArrayNotHasKey('pcs', $hysteria2);
        $this->assertArrayNotHasKey('pcs', $tuic);
        $this->assertArrayNotHasKey('pcs', $anytls);
    }

    public function testCamelCaseTlsSettingIsAcceptedForLegacyServerShape(): void
    {
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

    public function testNonTlsVlessDoesNotIncludeCertificatePin(): void
    {
        $server = $this->server([
            'tls' => 0,
            'tls_settings' => ['pinned_peer_cert_sha256' => self::PIN],
        ]);

        $vless = $this->query(parse_url(trim(Helper::buildVlessUri('uuid', $server)), PHP_URL_QUERY));

        $this->assertArrayNotHasKey('pcs', $vless);
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

    private function query(?string $query): array
    {
        parse_str((string)$query, $params);
        return $params;
    }
}
