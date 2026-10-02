<?php

namespace Tests\Feature;

use App\Protocols\ClashMeta;
use App\Protocols\Singbox\Singbox;
use Tests\TestCase;

/**
 * 外部过渡节点在 Clash 系与 sing-box 渲染器里的输出。
 *
 * 这是整个外部订阅功能里最容易出错的一段：凭据要从节点行自己的 `_external_creds` 取，
 * 而不是用户的 uuid；同时普通节点必须照旧用用户的 uuid。所以两侧都要断言 ——
 * 只断言「外部节点的凭据出现了」是不够的，还得断言「用户的 uuid 没有漏进外部节点」。
 *
 * 节点行按 ExternalSubscriptionService::serverRowsForGroups() 的形状手工构造，
 * 那里是字段映射的唯一来源（服务侧的映射由 ExternalSubscriptionTest 覆盖）。
 */
class ExternalNodeRenderTest extends TestCase
{
    private const USER_UUID = '11111111-2222-3333-4444-555555555555';
    private const PROVIDER_UUID = '99999999-8888-7777-6666-555555555555';
    private const PROVIDER_PASSWORD = 'provider-secret-password';

    private function user(): array
    {
        return [
            'uuid' => self::USER_UUID,
            'u' => 0,
            'd' => 0,
            'transfer_enable' => 107374182400,
            'expired_at' => time() + 86400,
            'email' => 'user@example.com'
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function normalNode(): array
    {
        return [
            'id' => 1,
            'type' => 'v2node',
            'protocol' => 'trojan',
            'name' => '自建-香港',
            'host' => 'self.example',
            'port' => 443,
            'rate' => '1',
            'show' => 1,
            'sort' => 0,
            'tls' => 1,
            'server_name' => 'self.example',
            'tls_settings' => ['server_name' => 'self.example'],
            'network' => 'tcp',
            'network_settings' => [],
            'last_check_at' => time(),
            'updated_at' => time()
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function externalNode(): array
    {
        return [
            'id' => -7,
            'type' => 'external',
            'protocol' => 'trojan',
            'name' => '【过渡】机场-日本',
            'host' => 'jp.example',
            'port' => 8443,
            'rate' => '1',
            'show' => 1,
            'sort' => 0,
            'last_check_at' => time(),
            'updated_at' => time(),
            '_external_uri' => 'trojan://' . self::PROVIDER_PASSWORD . '@jp.example:8443#JP',
            '_external_creds' => ['uuid' => self::PROVIDER_UUID, 'password' => self::PROVIDER_PASSWORD],
            // 字段映射的产物：builder 会读的这些
            'tls' => 1,
            'server_name' => 'jp.example',
            'tls_settings' => ['server_name' => 'jp.example'],
            'network' => 'tcp',
            'network_settings' => [],
            'networkSettings' => [],
            'cipher' => '',
            'encryption' => '',
            'encryption_settings' => [],
            'flow' => '',
            'obfs' => '',
            'obfs_password' => '',
            'congestion_control' => '',
            'udp_relay_mode' => '',
            'disable_sni' => 0,
            'zero_rtt_handshake' => 0,
            'up_mbps' => 0,
            'down_mbps' => 0,
            'version' => 1,
            'created_at' => time()
        ];
    }

    public function testClashFamilyUsesTheProvidersCredentialForExternalNodes(): void
    {
        $yaml = (new ClashMeta($this->user(), [$this->normalNode(), $this->externalNode()]))->handle();

        $this->assertStringContainsString('【过渡】机场-日本', $yaml);
        $this->assertStringContainsString(self::PROVIDER_PASSWORD, $yaml, '外部节点要用对方机场的密码');
        // 自建节点仍然用用户的 uuid
        $this->assertStringContainsString(self::USER_UUID, $yaml);
        // 用户的 uuid 只能出现在自建节点上：两个节点时它只该出现一次
        $this->assertSame(1, substr_count($yaml, self::USER_UUID), '用户的 uuid 不该漏进外部节点');
    }

    public function testSingboxUsesTheProvidersCredentialForExternalNodes(): void
    {
        $config = (new Singbox($this->user(), [$this->normalNode(), $this->externalNode()]))->handle();
        $json = is_string($config) ? $config : json_encode($config, JSON_UNESCAPED_UNICODE);

        $this->assertStringContainsString('【过渡】机场-日本', $json);
        $this->assertStringContainsString(self::PROVIDER_PASSWORD, $json);
        $this->assertStringContainsString(self::USER_UUID, $json);
        $this->assertSame(1, substr_count($json, self::USER_UUID), '用户的 uuid 不该漏进外部节点');
    }

    public function testExternalNodesWithoutCredentialsStayOutOfTheProxyList(): void
    {
        // 没给凭据（例如 ss 2022 那种拼不出正确密码的）：Clash 系应当照旧跳过它，
        // 而不是渲染出一个用用户自己 uuid 去连别人服务器的坏节点。
        $node = $this->externalNode();
        unset($node['_external_creds']);

        $yaml = (new ClashMeta($this->user(), [$node]))->handle();

        $this->assertStringNotContainsString('【过渡】机场-日本', $yaml);
        $this->assertStringNotContainsString(self::PROVIDER_PASSWORD, $yaml);
    }
}
