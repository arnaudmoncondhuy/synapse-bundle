<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseBundle\Controller\Api;

use ArnaudMoncondhuy\SynapseBundle\Service\ChatService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Profiler\Profiler;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Cache\CacheInterface;
use Twig\Environment;

#[Route('/synapse/api')]
class ChatApiController extends AbstractController
{
    public function __construct(
        private ChatService $chatService,
        private Environment $twig,
        private CacheInterface $cache
    ) {
    }

    #[Route('/chat', name: 'synapse_api_chat', methods: ['POST'])]
    public function chat(Request $request, ?Profiler $profiler): StreamedResponse
    {
        // 1. Suppression de '_stateless' pour que l'historique de conversation fonctionne.

        // 2. On désactive le profiler pour ne pas casser le flux JSON
        if ($profiler) {
            $profiler->disable();
        }

        $data = json_decode($request->getContent(), true) ?? [];
        $message = $data['message'] ?? '';
        $options = $data['options'] ?? [];
        $options['debug'] = $data['debug'] ?? false;

        $response = new StreamedResponse(function () use ($message, $options, $request) {

            // 3. On ferme la session immédiatement au début du flux.
            if ($request->hasSession() && $request->getSession()->isStarted()) {
                $request->getSession()->save();
            }

            // Helper to send NDJSON event
            $sendEvent = function (string $type, mixed $payload): void {
                echo json_encode(['type' => $type, 'payload' => $payload], JSON_INVALID_UTF8_IGNORE) . "\n";
                flush();
            };

            if (empty($message) && !($options['reset_conversation'] ?? false)) {
                $sendEvent('error', 'Message is required.');
                return;
            }

            try {
                // Status update callback for streaming
                $onStatusUpdate = function (string $statusMessage, string $step) use ($sendEvent): void {
                    $sendEvent('status', ['message' => $statusMessage, 'step' => $step]);
                };

                // Execute chat
                $result = $this->chatService->ask($message, $options, $onStatusUpdate);

                // Server-Side Rendering of Debug View (to avoid needing a dedicated route)
                if (($options['debug'] ?? false) && isset($result['debug_id'])) {
                    $debugData = $this->cache->get("synapse_debug_{$result['debug_id']}", fn() => null);

                    if ($debugData) {
                        try {
                            $result['debug_html'] = $this->twig->render('@Synapse/debug/show.html.twig', [
                                'id' => $result['debug_id'],
                                'debug' => $debugData,
                            ]);
                        } catch (\Exception $e) {
                            $result['debug_error'] = 'Could not render debug view: ' . $e->getMessage();
                        }
                    }
                }

                // Send final result
                $sendEvent('result', $result);

            } catch (\Exception $e) {
                $sendEvent('error', $e->getMessage());
            }
        });

        $response->headers->set('Content-Type', 'application/x-ndjson');
        $response->headers->set('X-Accel-Buffering', 'no'); // Disable Nginx buffering
        $response->headers->set('Cache-Control', 'no-cache');

        return $response;
    }
}