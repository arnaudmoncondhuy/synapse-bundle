<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseBundle\Controller\Api;

use ArnaudMoncondhuy\SynapseBundle\Service\ChatService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Profiler\Profiler;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Contrôleur API principal pour le flux de conversation.
 *
 * Ce contrôleur expose le endpoint `/synapse/api/chat` qui gère les échanges
 * en temps réel avec le frontend via un flux NDJSON (Streamed Response).
 */
#[Route('/synapse/api')]
class ChatApiController extends AbstractController
{
    public function __construct(
        private ChatService $chatService,
    ) {
    }

    /**
     * Traite une nouvelle requête de chat et retourne un flux d'événements.
     *
     * IMPORTANT : Ce endpoint utilise 'Content-Type: application/x-ndjson' pour supporter
     * le streaming progressif des étapes (analyse, outils, réponse).
     *
     * Mécanismes clés :
     * 1. Désactivation du Symfony Profiler pour éviter la pollution du JSON.
     * 2. Clôture immédiate de la session (session_write_close) pour éviter le verrouillage (Session Blocking) pendant les longs appels API.
     *
     * @param Request       $request  la requête HTTP contenant le message JSON
     * @param Profiler|null $profiler le profiler Symfony (injecté si disponible)
     *
     * @return StreamedResponse une réponse HTTP dont le contenu est envoyé chunk par chunk
     */
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
        $options['debug'] = $data['debug'] ?? ($options['debug'] ?? false);

        if (!empty($data['api_key'])) {
            $options['api_key'] = (string) $data['api_key'];
        }
        if (!empty($data['model'])) {
            $options['model'] = (string) $data['model'];
        }

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
