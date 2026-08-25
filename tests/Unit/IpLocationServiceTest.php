<?php

namespace Tests\Unit;

use App\Services\IpLocationService;
use MaxMind\Db\Reader;
use ReflectionMethod;
use Tests\TestCase;

class IpLocationServiceTest extends TestCase
{
    public function testCatalogContainsAllTwentyThreeReadableDatabases(): void
    {
        $service = new IpLocationService();
        $catalogFiles = $this->invoke($service, 'catalogFiles');

        $this->assertCount(23, $catalogFiles);
        foreach ($catalogFiles as $file) {
            $path = base_path('resources/ipdb/' . $file);
            $this->assertFileExists($path, $file);
            $this->assertGreaterThan(0, filesize($path), $file);

            $reader = new Reader($path);
            $metadata = $reader->metadata();
            $expectedVersion = strpos($file, 'ipv6') !== false ? 6 : 4;
            $this->assertSame($expectedVersion, (int)$metadata->ipVersion, $file);
            $this->assertGreaterThan(0, (int)$metadata->buildEpoch, $file);
        }
    }

    public function testIpv4ResolutionIncludesCatalogVersionAndMatchedSources(): void
    {
        $result = $this->lookupMmdb('183.197.159.172', 4);

        $this->assertSame('resolved', $result['status']);
        $this->assertSame('CN', $result['country_code']);
        $this->assertSame('河北', $result['province']);
        $this->assertSame('石家庄', $result['city']);
        $this->assertSame('residential', $result['connection_type']);
        $this->assertTrue($result['is_residential']);
        $this->assertSame(0.76, $result['geo_confidence']);
        $this->assertSame(50, $result['accuracy_radius']);
        $this->assertNotEmpty($result['catalog_version']);
        $this->assertContains('china_ipv4_high_prec_v2', $result['matched_sources']);
    }

    public function testIpv6ResolutionUsesIpv6Candidates(): void
    {
        $result = $this->lookupMmdb('2001:4860:4860::8888', 6);

        $this->assertSame('resolved', $result['status']);
        $this->assertNotEmpty($result['matched_sources']);
        foreach ($result['matched_sources'] as $source) {
            $this->assertStringContainsString('ipv6', $source);
        }
        $this->assertNotEmpty($result['catalog_version']);
    }

    public function testUnknownPublicAddressDoesNotBreakWhenNoDatabaseMatches(): void
    {
        $result = $this->lookupMmdb('192.0.2.1', 4);

        $this->assertSame('unknown', $result['status']);
        $this->assertSame([], $result['matched_sources']);
        $this->assertSame('no_matching_record', $result['lookup_error']);
    }

    private function lookupMmdb(string $ip, int $version): array
    {
        $service = new IpLocationService();
        return $this->invoke($service, 'lookupMmdb', [$ip, $version]);
    }

    private function invoke(object $object, string $method, array $arguments = [])
    {
        $reflection = new ReflectionMethod($object, $method);
        $reflection->setAccessible(true);
        return $reflection->invokeArgs($object, $arguments);
    }
}
