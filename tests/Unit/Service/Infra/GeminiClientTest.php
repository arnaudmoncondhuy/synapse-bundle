<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseBundle\Tests\Unit\Service\Infra;

use ArnaudMoncondhuy\SynapseBundle\Service\Infra\GeminiClient;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class GeminiClientTest extends TestCase
{
    private $httpClient;
    private $geminiClient;
    private const API_KEY = 'TEST_API_KEY';

    protected function setUp(): void
    {
        $this->httpClient = $this->createMock(HttpClientInterface::class);
        $this->geminiClient = new GeminiClient($this->httpClient, 'gemini-test-model');
    }

    public function testGenerateContentBuildsCorrectPayload(): void
    {
        // Mock Response
        $mockResponse = $this->createMock(ResponseInterface::class);
        $mockResponse->method('toArray')->willReturn([
            'candidates' => [
                ['content' => ['parts' => [['text' => 'Hello']]]],
            ],
        ]);

        // Expectation: Request called with specific payload
        $this->httpClient->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                $this->stringContains('gemini-test-model'),
                $this->callback(function ($options) {
                    $json = $options['json'];

                    // Check structure
                    if ('You are a bot' !== $json['system_instruction']['parts'][0]['text']) {
                        return false;
                    }
                    if ('Hi' !== $json['contents'][0]['parts'][0]['text']) {
                        return false;
                    }

                    // Check API Key in Query Query
                    if (self::API_KEY !== $options['query']['key']) {
                        return false;
                    }

                    return true;
                })
            )
            ->willReturn($mockResponse);

        // Act
        $result = $this->geminiClient->generateContent(
            'You are a bot',
            [['role' => 'user', 'parts' => [['text' => 'Hi']]]],
            self::API_KEY
        );

        // Assert
        $this->assertEquals(['parts' => [['text' => 'Hello']]], $result);
    }

    public function testGenerateContentHidesApiKeyInException(): void
    {
        // Simulate Error containing API KEY
        $this->httpClient->method('request')
            ->willThrowException(new \Exception('Invalid key: '.self::API_KEY.' provided.'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('***API_KEY_HIDDEN***');

        // Act
        $this->geminiClient->generateContent('Sys', [], self::API_KEY);
    }
}
