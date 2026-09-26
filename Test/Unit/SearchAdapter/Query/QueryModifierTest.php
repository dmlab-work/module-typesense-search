<?php
/**
 * Copyright © DMLab. All rights reserved.
 */
declare(strict_types=1);

namespace DmLab\TypesenseSearch\Test\Unit\SearchAdapter\Query;

use DmLab\TypesenseSearch\SearchAdapter\Query\QueryModifier;
use DmLab\TypesenseSearch\SearchAdapter\Query\QueryModifierInterface;
use Magento\Framework\Search\RequestInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class QueryModifierTest extends TestCase
{
    private function request(): RequestInterface
    {
        return $this->createMock(RequestInterface::class);
    }

    /**
     * A modifier that appends a tag to a `trace` list, so order is observable.
     */
    private function tagging(string $tag): QueryModifierInterface
    {
        return new class ($tag) implements QueryModifierInterface {
            public function __construct(private readonly string $tag)
            {
            }

            public function modify(array $query, RequestInterface $request): array
            {
                $query['trace'][] = $this->tag;

                return $query;
            }
        };
    }

    public function testEmptyChainIsNoOp(): void
    {
        $modifier = new QueryModifier();
        $payload = ['q' => '*', 'query_by' => 'name'];

        $this->assertSame($payload, $modifier->modify($payload, $this->request()));
    }

    public function testAppliesModifiersInInjectedOrder(): void
    {
        // di injects the array already sorted by sortOrder; assert we preserve that order.
        $modifier = new QueryModifier([
            $this->tagging('first'),
            $this->tagging('second'),
            $this->tagging('third'),
        ]);

        $result = $modifier->modify(['trace' => []], $this->request());

        $this->assertSame(['first', 'second', 'third'], $result['trace']);
    }

    public function testEachModifierReceivesPreviousResult(): void
    {
        $doubler = new class implements QueryModifierInterface {
            public function modify(array $query, RequestInterface $request): array
            {
                $query['n'] = ($query['n'] ?? 1) * 2;

                return $query;
            }
        };
        $adder = new class implements QueryModifierInterface {
            public function modify(array $query, RequestInterface $request): array
            {
                $query['n'] = ($query['n'] ?? 0) + 5;

                return $query;
            }
        };

        // (2 * 2) then + 5 = 9 — only possible if the adder sees the doubler's output.
        $result = (new QueryModifier([$doubler, $adder]))->modify(['n' => 2], $this->request());

        $this->assertSame(9, $result['n']);
    }

    public function testRequestIsPassedThroughToModifiers(): void
    {
        $request = $this->request();
        $captured = null;
        $spy = new class ($captured) implements QueryModifierInterface {
            public function __construct(private mixed &$captured)
            {
            }

            public function modify(array $query, RequestInterface $request): array
            {
                $this->captured = $request;

                return $query;
            }
        };

        (new QueryModifier([$spy]))->modify([], $request);

        $this->assertSame($request, $captured);
    }

    public function testRejectsNonModifierEntry(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new QueryModifier([$this->tagging('ok'), new \stdClass()]);
    }
}
