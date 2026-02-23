<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseBundle\Tests\Functional\Api;

use ArnaudMoncondhuy\SynapseBundle\Core\Chat\ChatService;
use ArnaudMoncondhuy\SynapseBundle\Core\Controller\Api\ChatApiController;
use ArnaudMoncondhuy\SynapseBundle\Core\Formatter\MessageFormatter;
use ArnaudMoncondhuy\SynapseBundle\Core\Manager\ConversationManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ChatApiControllerTest extends TestCase
{
    private ChatApiController $controller;

    protected function setUp(): void
    {
        $chatService = $this->createMock(ChatService::class);
        $conversationManager = $this->createMock(ConversationManager::class);
        $messageFormatter = $this->createMock(MessageFormatter::class);

        $this->controller = new ChatApiController(
            $chatService,
            $conversationManager,
            $messageFormatter
        );
    }

    public function testChatEndpointReturnsStreamedResponse(): void
    {
        $request = Request::create(
            '/synapse/api/chat',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['message' => 'test'])
        );

        $response = $this->controller->chat($request, null);

        $this->assertInstanceOf(StreamedResponse::class, $response);
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertStringContainsString('application/x-ndjson', $response->headers->get('Content-Type') ?? '');
    }

    public function testChatEndpointSetsCorrectHeaders(): void
    {
        $request = Request::create(
            '/synapse/api/chat',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['message' => 'test'])
        );

        $response = $this->controller->chat($request, null);

        $this->assertEquals('application/x-ndjson', $response->headers->get('Content-Type'));
        $this->assertEquals('no', $response->headers->get('X-Accel-Buffering'));
        $this->assertStringContainsString('no-cache', $response->headers->get('Cache-Control') ?? '');
        $this->assertEquals('disabled', $response->headers->get('X-Debug-Token'));
    }
}
