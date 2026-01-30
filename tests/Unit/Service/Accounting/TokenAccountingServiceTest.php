<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseBundle\Tests\Unit\Service\Accounting;

use ArnaudMoncondhuy\SynapseBundle\Entity\TokenUsage;
use ArnaudMoncondhuy\SynapseBundle\Repository\TokenUsageRepository;
use ArnaudMoncondhuy\SynapseBundle\Service\Accounting\TokenAccountingService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class TokenAccountingServiceTest extends TestCase
{
    private EntityManagerInterface $entityManager;
    private TokenUsageRepository $repository;
    private TokenAccountingService $service;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->repository = $this->createMock(TokenUsageRepository::class);

        $this->service = new TokenAccountingService(
            $this->entityManager,
            $this->repository,
            true, // enabled
            [
                'gemini-2.5-flash' => ['input' => 0.30, 'output' => 2.50],
                'gemini-2.5-pro' => ['input' => 1.25, 'output' => 10.00],
            ]
        );
    }

    public function testLogUsage(): void
    {
        $this->entityManager
            ->expects($this->once())
            ->method('persist')
            ->with($this->isInstanceOf(TokenUsage::class));

        $this->entityManager
            ->expects($this->once())
            ->method('flush');

        $this->service->logUsage(
            module: 'chat',
            action: 'chat_turn',
            model: 'gemini-2.5-flash',
            promptTokens: 100,
            completionTokens: 50,
            thinkingTokens: 25
        );
    }

    public function testLogUsageCalculatesTotalTokens(): void
    {
        $capturedEntity = null;

        $this->entityManager
            ->expects($this->once())
            ->method('persist')
            ->willReturnCallback(function (TokenUsage $entity) use (&$capturedEntity) {
                $capturedEntity = $entity;
            });

        $this->service->logUsage(
            module: 'chat',
            action: 'chat_turn',
            model: 'gemini-2.5-flash',
            promptTokens: 100,
            completionTokens: 50,
            thinkingTokens: 25
        );

        $this->assertNotNull($capturedEntity);
        $this->assertEquals(175, $capturedEntity->getTotalTokens());
    }

    public function testLogFromGeminiResponse(): void
    {
        $response = [
            'usageMetadata' => [
                'promptTokenCount' => 120,
                'candidatesTokenCount' => 80,
                'totalTokenCount' => 200,
            ],
        ];

        $this->entityManager
            ->expects($this->once())
            ->method('persist')
            ->with($this->isInstanceOf(TokenUsage::class));

        $this->service->logFromGeminiResponse(
            response: $response,
            module: 'gmail',
            action: 'summarize',
            model: 'gemini-2.5-flash'
        );
    }

    public function testCalculateCostFlash(): void
    {
        $cost = $this->service->calculateCost(
            model: 'gemini-2.5-flash',
            promptTokens: 1000000,
            completionTokens: 1000000
        );

        // (1M * 0.30 + 1M * 2.50) / 1M = 2.80
        $this->assertEquals(2.80, $cost);
    }

    public function testCalculateCostPro(): void
    {
        $cost = $this->service->calculateCost(
            model: 'gemini-2.5-pro',
            promptTokens: 1000000,
            completionTokens: 1000000
        );

        // (1M * 1.25 + 1M * 10.00) / 1M = 11.25
        $this->assertEquals(11.25, $cost);
    }

    public function testCalculateCostUnknownModel(): void
    {
        $cost = $this->service->calculateCost(
            model: 'unknown-model',
            promptTokens: 1000000,
            completionTokens: 1000000
        );

        $this->assertEquals(0.0, $cost);
    }

    public function testCalculateCostWithThinkingTokens(): void
    {
        $cost = $this->service->calculateCost(
            model: 'gemini-2.5-flash',
            promptTokens: 500000,
            completionTokens: 500000,
            thinkingTokens: 250000
        );

        // Thinking tokens counted as completion tokens
        // (500k * 0.30 + 750k * 2.50) / 1M = 2.025
        $this->assertEquals(2.025, $cost);
    }

    public function testLogUsageWhenDisabled(): void
    {
        $disabledService = new TokenAccountingService(
            $this->entityManager,
            $this->repository,
            false, // disabled
            []
        );

        $this->entityManager
            ->expects($this->never())
            ->method('persist');

        $disabledService->logUsage(
            module: 'chat',
            action: 'test',
            model: 'gemini-2.5-flash',
            promptTokens: 100,
            completionTokens: 50
        );
    }
}
