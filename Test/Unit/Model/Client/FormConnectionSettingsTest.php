<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseSearch\Test\Unit\Model\Client;

use DmLab\TypesenseCore\Api\ConnectionSettingsInterface;
use DmLab\TypesenseCore\Exception\ConfigurationException;
use DmLab\TypesenseCore\Model\Config\Node;
use DmLab\TypesenseSearch\Model\Client\FormConnectionSettings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class FormConnectionSettingsTest extends TestCase
{
    /**
     * @param array<string,mixed> $options
     */
    private function settings(array $options): FormConnectionSettings
    {
        $saved = $this->createStub(ConnectionSettingsInterface::class);
        $saved->method('getApiKey')->willReturn('saved-key');
        $saved->method('getNodes')->willReturn([new Node('typesense', 8108, 'https')]);
        $saved->method('getConnectionTimeout')->willReturn(5);
        $saved->method('getOperationTimeout')->willReturn(300);
        $saved->method('getRetryCount')->willReturn(2);
        $saved->method('getHealthCacheTtl')->willReturn(30);

        return new FormConnectionSettings($saved, $options);
    }

    public function testImplementsContract(): void
    {
        self::assertInstanceOf(ConnectionSettingsInterface::class, $this->settings([]));
    }

    public function testNodeBuiltFromForm(): void
    {
        $nodes = $this->settings(['hostname' => ' typesense ', 'port' => '8108', 'protocol' => 'HTTPS'])->getNodes();

        self::assertCount(1, $nodes);
        self::assertSame('typesense', $nodes[0]->getHost());
        self::assertSame(8108, $nodes[0]->getPort());
        self::assertSame('https', $nodes[0]->getProtocol());
    }

    public function testProtocolDefaultsToHttp(): void
    {
        $nodes = $this->settings(['hostname' => 'typesense', 'port' => 8108])->getNodes();

        self::assertSame('http', $nodes[0]->getProtocol());
    }

    /**
     * @return array<string,array{array<string,mixed>}>
     */
    public static function invalidNodeOptions(): array
    {
        return [
            'missing host' => [['port' => '8108']],
            'blank host' => [['hostname' => '  ', 'port' => '8108']],
            'missing port' => [['hostname' => 'typesense']],
            'non-numeric port' => [['hostname' => 'typesense', 'port' => 'abc']],
            'port out of range' => [['hostname' => 'typesense', 'port' => '70000']],
            'unsupported protocol' => [['hostname' => 'typesense', 'port' => '8108', 'protocol' => 'ftp']],
        ];
    }

    /**
     * @param array<string,mixed> $options
     */
    #[DataProvider('invalidNodeOptions')]
    public function testInvalidNodeThrows(array $options): void
    {
        $this->expectException(ConfigurationException::class);

        $this->settings($options)->getNodes();
    }

    public function testPostedKeyWins(): void
    {
        self::assertSame('posted-key', $this->settings(['apiKey' => 'posted-key'])->getApiKey());
    }

    /**
     * @return array<string,array{array<string,mixed>}>
     */
    public static function fallbackKeyOptions(): array
    {
        $node = ['hostname' => 'TypeSense', 'port' => '8108', 'protocol' => 'https'];

        return [
            'obscured' => [$node + ['apiKey' => '******']],
            'empty' => [$node + ['apiKey' => '']],
            'absent' => [$node],
        ];
    }

    /**
     * @param array<string,mixed> $options
     */
    #[DataProvider('fallbackKeyOptions')]
    public function testObscuredOrEmptyKeyFallsBackToSavedForSavedNode(array $options): void
    {
        self::assertSame('saved-key', $this->settings($options)->getApiKey());
    }

    /**
     * @return array<string,array{array<string,mixed>}>
     */
    public static function otherNodeOptions(): array
    {
        return [
            'other host' => [['hostname' => 'attacker.example', 'port' => '8108', 'protocol' => 'https']],
            'other port' => [['hostname' => 'typesense', 'port' => '9108', 'protocol' => 'https']],
            'other protocol' => [['hostname' => 'typesense', 'port' => '8108', 'protocol' => 'http']],
        ];
    }

    /**
     * @param array<string,mixed> $options
     */
    #[DataProvider('otherNodeOptions')]
    public function testSavedKeyNeverSentToAnotherNode(array $options): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Enter the API key');

        $this->settings($options + ['apiKey' => '******'])->getApiKey();
    }

    public function testUnconfiguredSavedNodesRequirePostedKey(): void
    {
        $saved = $this->createStub(ConnectionSettingsInterface::class);
        $saved->method('getNodes')->willThrowException(new ConfigurationException('unset'));
        $saved->method('getApiKey')->willReturn('saved-key');

        $this->expectExceptionMessage('Enter the API key');

        (new FormConnectionSettings($saved, ['hostname' => 'typesense', 'port' => '8108']))->getApiKey();
    }

    public function testSavedKeyErrorPropagates(): void
    {
        $saved = $this->createStub(ConnectionSettingsInterface::class);
        $saved->method('getNodes')->willReturn([new Node('typesense', 8108)]);
        $saved->method('getApiKey')->willThrowException(new ConfigurationException('unset'));

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('unset');

        (new FormConnectionSettings($saved, ['hostname' => 'typesense', 'port' => '8108', 'apiKey' => '***']))
            ->getApiKey();
    }

    public function testPostedTimeoutWins(): void
    {
        self::assertSame(9, $this->settings(['timeout' => '9'])->getConnectionTimeout());
    }

    public function testInvalidTimeoutFallsBackToSaved(): void
    {
        self::assertSame(5, $this->settings(['timeout' => '0'])->getConnectionTimeout());
        self::assertSame(5, $this->settings(['timeout' => 'x'])->getConnectionTimeout());
        self::assertSame(5, $this->settings([])->getConnectionTimeout());
    }

    public function testOtherTuningFromSaved(): void
    {
        $settings = $this->settings(['hostname' => 'typesense', 'port' => '8108']);

        self::assertSame(300, $settings->getOperationTimeout());
        self::assertSame(2, $settings->getRetryCount());
        self::assertSame(30, $settings->getHealthCacheTtl());
    }
}
