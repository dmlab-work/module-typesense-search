<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseSearch\Test\Unit\Setup;

use DmLab\TypesenseSearch\Setup\Validator;
use Magento\AdvancedSearch\Model\Client\ClientInterface;
use Magento\AdvancedSearch\Model\Client\ClientResolver;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;
use Magento\Search\Model\SearchEngine\ValidatorInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class ValidatorTest extends TestCase
{
    public function testImplementsValidatorInterface(): void
    {
        self::assertInstanceOf(
            ValidatorInterface::class,
            new Validator($this->createMock(ClientResolver::class))
        );
    }

    public function testNoErrorsWhenConnectionSucceeds(): void
    {
        $client = $this->createMock(ClientInterface::class);
        $client->method('testConnection')->willReturn(true);

        $resolver = $this->createMock(ClientResolver::class);
        $resolver->method('create')->willReturn($client);

        self::assertSame([], (new Validator($resolver))->validate());
    }

    public function testErrorWhenConnectionFails(): void
    {
        $client = $this->createMock(ClientInterface::class);
        $client->method('testConnection')->willReturn(false);

        $resolver = $this->createMock(ClientResolver::class);
        $resolver->method('create')->willReturn($client);

        $errors = (new Validator($resolver))->validate();

        self::assertCount(1, $errors);
        self::assertStringContainsString('Typesense', $errors[0]);
    }

    public function testErrorWhenResolverThrows(): void
    {
        $resolver = $this->createMock(ClientResolver::class);
        $resolver->method('create')->willThrowException(new \RuntimeException('boom'));

        $errors = (new Validator($resolver))->validate();

        self::assertCount(1, $errors);
        self::assertStringContainsString('boom', $errors[0]);
    }

    public function testConnectionFailureMessageSurfaced(): void
    {
        $client = $this->createMock(ClientInterface::class);
        $client->method('testConnection')->willThrowException(
            new LocalizedException(new Phrase('Typesense connection failed (%1): %2', ['unauthorized', 'bad key']))
        );

        $resolver = $this->createMock(ClientResolver::class);
        $resolver->method('create')->willReturn($client);

        self::assertSame(
            ['Could not validate a connection to Typesense. Typesense connection failed (unauthorized): bad key'],
            (new Validator($resolver))->validate()
        );
    }
}
