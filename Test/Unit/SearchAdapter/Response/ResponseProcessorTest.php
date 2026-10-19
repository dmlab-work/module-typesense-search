<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseSearch\Test\Unit\SearchAdapter\Response;

use DmLab\TypesenseSearch\SearchAdapter\Response\ResponseProcessor;
use DmLab\TypesenseSearch\SearchAdapter\Response\ResponseProcessorInterface;
use Magento\Framework\Search\RequestInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class ResponseProcessorTest extends TestCase
{
    private function tagging(string $tag): ResponseProcessorInterface
    {
        return new class ($tag) implements ResponseProcessorInterface {
            public function __construct(private readonly string $tag)
            {
            }

            public function process(array $response, array $params, RequestInterface $request): array
            {
                $response['trace'][] = $this->tag;

                return $response;
            }
        };
    }

    public function testEmptyChainIsIdentity(): void
    {
        $response = ['found' => 1, 'hits' => [['document' => ['id' => '1']]]];

        self::assertSame(
            $response,
            (new ResponseProcessor())->process($response, ['q' => '*'], $this->createMock(RequestInterface::class))
        );
    }

    public function testAppliesProcessorsInInjectedOrderWithParamsAndRequest(): void
    {
        $request = $this->createMock(RequestInterface::class);
        $seen = [];
        $spy = new class ($seen) implements ResponseProcessorInterface {
            public function __construct(private array &$seen)
            {
            }

            public function process(array $response, array $params, RequestInterface $request): array
            {
                $this->seen = [$response['trace'], $params, $request];

                return $response;
            }
        };

        $result = (new ResponseProcessor([$this->tagging('first'), $this->tagging('second'), $spy]))
            ->process(['trace' => []], ['q' => 'jacket'], $request);

        self::assertSame(['first', 'second'], $result['trace']);
        self::assertSame([['first', 'second'], ['q' => 'jacket'], $request], $seen);
    }

    public function testRejectsNonProcessorEntry(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ResponseProcessor([$this->tagging('ok'), new \stdClass()]);
    }
}
