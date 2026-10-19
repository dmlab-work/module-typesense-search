<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseSearch\Test\Unit\Model\Client;

use DmLab\TypesenseCore\Model\Client\ConnectionStatus;
use DmLab\TypesenseSearch\Model\Client\ConnectionVerifier;
use DmLab\TypesenseSearch\Model\Client\FormConnectionSettings;
use DmLab\TypesenseSearch\Model\Client\FormConnectionSettingsFactory;
use DmLab\TypesenseSearch\Model\Client\TypesenseClientAdapter;
use Magento\AdvancedSearch\Model\Client\ClientInterface;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TypesenseClientAdapterTest extends TestCase
{
    private function connectionStatus(string $state, string $message): ConnectionStatus
    {
        return match ($state) {
            ConnectionStatus::STATE_OK => ConnectionStatus::ok('30.2', $message),
            ConnectionStatus::STATE_UNAUTHORIZED => ConnectionStatus::unauthorized($message),
            default => ConnectionStatus::unreachable($message),
        };
    }

    public function testImplementsClientInterface(): void
    {
        $adapter = new TypesenseClientAdapter(
            $this->createStub(ConnectionVerifier::class),
            $this->createStub(FormConnectionSettingsFactory::class)
        );

        self::assertInstanceOf(ClientInterface::class, $adapter);
    }

    public function testFormOptionsVerifyFormSettings(): void
    {
        $options = ['engine' => 'typesense', 'hostname' => 'typesense', 'port' => '8108'];
        $formSettings = $this->createStub(FormConnectionSettings::class);

        $factory = $this->createMock(FormConnectionSettingsFactory::class);
        $factory->expects(self::once())->method('create')->with(['options' => $options])->willReturn($formSettings);

        $verifier = $this->createMock(ConnectionVerifier::class);
        $verifier->expects(self::once())->method('verify')->with($formSettings)
            ->willReturn($this->connectionStatus('ok', 'Connected to Typesense 30.2.'));

        self::assertTrue((new TypesenseClientAdapter($verifier, $factory, $options))->testConnection());
    }

    public function testWithoutHostnameVerifiesSavedSettings(): void
    {
        $factory = $this->createMock(FormConnectionSettingsFactory::class);
        $factory->expects(self::never())->method('create');

        $verifier = $this->createMock(ConnectionVerifier::class);
        $verifier->expects(self::once())->method('verify')->with(null)
            ->willReturn($this->connectionStatus('ok', 'Connected to Typesense 30.2.'));

        $adapter = new TypesenseClientAdapter($verifier, $factory, ['engine' => 'typesense', 'timeout' => 5]);

        self::assertTrue($adapter->testConnection());
    }

    /**
     * @return array<string,array{string,string}>
     */
    public static function failures(): array
    {
        return [
            'unreachable' => ['unreachable', 'No Typesense node answered: http://typesense:8108: refused'],
            'unauthorized' => ['unauthorized', 'Typesense rejected the API key (HTTP 401).'],
        ];
    }

    #[DataProvider('failures')]
    public function testFailureThrowsLocalizedExceptionWithMessage(string $state, string $message): void
    {
        $verifier = $this->createStub(ConnectionVerifier::class);
        $verifier->method('verify')->willReturn($this->connectionStatus($state, $message));

        $adapter = new TypesenseClientAdapter($verifier, $this->createStub(FormConnectionSettingsFactory::class));

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage(sprintf('Typesense connection failed (%s): %s', $state, $message));

        $adapter->testConnection();
    }
}
