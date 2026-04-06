<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseAdmin\Tests\Unit\Controller\Usage;

use ArnaudMoncondhuy\SynapseAdmin\Controller\Usage\AnalyticsController;
use ArnaudMoncondhuy\SynapseCore\Contract\PermissionCheckerInterface;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseLlmCallRepository;
use PHPUnit\Framework\TestCase;

class AnalyticsControllerTest extends TestCase
{
    public function testFillMissingDaysCompletesGaps(): void
    {
        $controller = new AnalyticsController(
            $this->createStub(SynapseLlmCallRepository::class),
            $this->createStub(PermissionCheckerInterface::class),
        );

        $method = new \ReflectionMethod($controller, 'fillMissingDays');

        $start = new \DateTimeImmutable('2025-01-01');
        $end = new \DateTimeImmutable('2025-01-03');

        $data = [
            '2025-01-02' => ['total_tokens' => 100, 'prompt_tokens' => 60, 'completion_tokens' => 40, 'thinking_tokens' => 0],
        ];

        $result = $method->invoke($controller, $data, $start, $end);

        $this->assertArrayHasKey('2025-01-01', $result);
        $this->assertArrayHasKey('2025-01-02', $result);
        $this->assertArrayHasKey('2025-01-03', $result);
        $this->assertSame(0, $result['2025-01-01']['total_tokens']);
        $this->assertSame(100, $result['2025-01-02']['total_tokens']);
        $this->assertSame(0, $result['2025-01-03']['total_tokens']);
    }

    public function testFillMissingDaysWithEmptyData(): void
    {
        $controller = new AnalyticsController(
            $this->createStub(SynapseLlmCallRepository::class),
            $this->createStub(PermissionCheckerInterface::class),
        );

        $method = new \ReflectionMethod($controller, 'fillMissingDays');

        $start = new \DateTimeImmutable('2025-03-01');
        $end = new \DateTimeImmutable('2025-03-01');

        $result = $method->invoke($controller, [], $start, $end);

        $this->assertCount(1, $result);
        $this->assertSame(0, $result['2025-03-01']['total_tokens']);
    }
}
