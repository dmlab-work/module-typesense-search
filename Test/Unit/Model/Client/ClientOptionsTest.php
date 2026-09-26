<?php
/**
 * Copyright © DMLab. All rights reserved.
 */
declare(strict_types=1);

namespace DmLab\TypesenseSearch\Test\Unit\Model\Client;

use DmLab\TypesenseCore\Api\ConnectionSettingsInterface;
use DmLab\TypesenseCore\Exception\ConfigurationException;
use DmLab\TypesenseCore\Model\Config\Node;
use DmLab\TypesenseIndexer\Api\EngineCode;
use DmLab\TypesenseSearch\Model\Client\ClientOptions;
use Magento\AdvancedSearch\Model\Client\ClientOptionsInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class ClientOptionsTest extends TestCase
{
    public function testImplementsClientOptionsInterface(): void
    {
        self::assertInstanceOf(
            ClientOptionsInterface::class,
            new ClientOptions($this->createMock(ConnectionSettingsInterface::class))
        );
    }

    public function testOptionsAreReadFromConnectionSettings(): void
    {
        $settings = $this->createMock(ConnectionSettingsInterface::class);
        $settings->method('getConnectionTimeout')->willReturn(7);
        $settings->method('getNodes')->willReturn(
            [new Node('ts-1', 8108, 'https'), new Node('ts-2', 8108, 'https')]
        );

        $options = (new ClientOptions($settings))->prepareClientOptions();

        self::assertSame(EngineCode::ENGINE, $options['engine']);
        self::assertSame('https', $options['protocol']);
        self::assertSame(7, $options['timeout']);
        self::assertSame(
            [['host' => 'ts-1', 'port' => 8108], ['host' => 'ts-2', 'port' => 8108]],
            $options['nodes']
        );
    }

    public function testOptionsContainNoElasticsearchPaths(): void
    {
        $settings = $this->createMock(ConnectionSettingsInterface::class);
        $settings->method('getConnectionTimeout')->willReturn(5);
        $settings->method('getNodes')->willReturn([new Node('localhost', 8108)]);

        $encoded = strtolower(json_encode((new ClientOptions($settings))->prepareClientOptions()));

        self::assertStringNotContainsString('elasticsearch', $encoded);
        self::assertStringNotContainsString('opensearch', $encoded);
        self::assertStringNotContainsString('hostname', $encoded);
        self::assertStringNotContainsString('index_prefix', $encoded);
    }

    public function testCallerOverridesWin(): void
    {
        $settings = $this->createMock(ConnectionSettingsInterface::class);
        $settings->method('getConnectionTimeout')->willReturn(5);
        $settings->method('getNodes')->willReturn([new Node('localhost', 8108)]);

        $options = (new ClientOptions($settings))->prepareClientOptions(['timeout' => 99, 'extra' => 'x']);

        self::assertSame(99, $options['timeout']);
        self::assertSame('x', $options['extra']);
    }

    public function testUnconfiguredSettingsDegradesGracefully(): void
    {
        $settings = $this->createMock(ConnectionSettingsInterface::class);
        $settings->method('getConnectionTimeout')->willReturn(5);
        $settings->method('getNodes')->willThrowException(new ConfigurationException('no nodes'));

        $options = (new ClientOptions($settings))->prepareClientOptions();

        self::assertSame('http', $options['protocol']);
        self::assertSame([], $options['nodes']);
    }
}
