<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\TypesenseSearch\Test\Unit\Setup;

use MageDevGroup\TypesenseSearch\Setup\Validator;
use Magento\AdvancedSearch\Model\Client\ClientInterface;
use Magento\AdvancedSearch\Model\Client\ClientResolver;
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
}
