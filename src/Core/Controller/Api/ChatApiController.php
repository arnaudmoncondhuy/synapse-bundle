<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseBundle\Core\Controller\Api;

use ArnaudMoncondhuy\SynapseBundle\Shared\Exception\LlmAuthenticationException;
use ArnaudMoncondhuy\SynapseBundle\Shared\Exception\LlmException;
use ArnaudMoncondhuy\SynapseBundle\Shared\Exception\LlmQuotaException;
use ArnaudMoncondhuy\SynapseBundle\Shared\Exception\LlmRateLimitException;
use ArnaudMoncondhuy\SynapseBundle\Shared\Exception\LlmServiceUnavailableException;
use ArnaudMoncondhuy\SynapseBundle\Contract\ConversationOwnerInterface;
use ArnaudMoncondhuy\SynapseBundle\Shared\Enum\MessageRole;
use ArnaudMoncondhuy\SynapseBundle\Core\Chat\ChatService;
use ArnaudMoncondhuy\SynapseBundle\Core\Formatter\MessageFormatter;
use ArnaudMoncondhuy\SynapseBundle\Core\Manager\ConversationManager;
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
        private ?ConversationManager $conversationManager = null,
        private ?MessageFormatter $messageFormatter = null,
    ) {}

    /**
     * Traite une nouvelle requête de chat et retourne un flux d'événements.
     *
     * IMPORTANT : Ce endpoint utilise 'Content-Type: application/x-ndjson' pour supporter
     * le streaming progressif des étapes (analyse, outils, réponse).
     *
     * Mécanismes clés :
     * 1. Désactivation du Symfony Profiler pour éviter la pollution du JSON.
     * 2. Clôture immédiate de la session (session_write_close) pour éviter le verrouillage (Session Blocking) si d'autres parties de l'application utilisent les sessions PHP.
     *
     * @param Request       $request  la requête HTTP contenant le message JSON
     * @param Profiler|null $profiler le profiler Symfony (injecté si disponible)
     *
     * @return StreamedResponse une réponse HTTP dont le contenu est envoyé chunk par chunk
     */
    #[Route('/chat', name: 'synapse_api_chat', methods: ['POST'])]
    public function chat(Request $request, ?Profiler $profiler): StreamedResponse
    {
        // 1. On désactive le profiler pour ne pas casser le flux JSON
        if ($profiler) {
            $profiler->disable();
        }

        $data = json_decode($request->getContent(), true) ?? [];
        $message = $data['message'] ?? '';
        $options = $data['options'] ?? [];
        $options['debug'] = $data['debug'] ?? ($options['debug'] ?? false);
        $conversationId = $data['conversation_id'] ?? null;
        $options['conversation_id'] = $conversationId;  // Pass to ChatService for debug logging

        // Load conversation if ID provided and persistence enabled
        $conversation = null;
        if ($conversationId && $this->conversationManager) {
            $user = $this->getUser();
            if ($user instanceof ConversationOwnerInterface) {
                $conversation = $this->conversationManager->getConversation($conversationId, $user);
                if ($conversation) {
                    $this->conversationManager->setCurrentConversation($conversation);
                }
            }
        }

        $response = new StreamedResponse(function () use ($message, $options, $conversation) {
            // CRITICAL: Disable ALL output buffering to prevent Symfony Debug Toolbar injection
            // The toolbar tries to inject HTML into buffered output, corrupting NDJSON stream
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            ob_implicit_flush(true);

            // Helper to send NDJSON event
            $sendEvent = function (string $type, mixed $payload): void {
                echo json_encode(['type' => $type, 'payload' => $payload], JSON_INVALID_UTF8_IGNORE | JSON_THROW_ON_ERROR) . "\n";
                // Force flush explicitly
                if (ob_get_length() > 0) {
                    ob_flush();
                }
                flush();
            };

            // Send padding to bypass browser/proxy buffering (approx 2KB)
            echo ":" . str_repeat(' ', 2048) . "\n";
            flush();

            if (empty($message) && !($options['reset_conversation'] ?? false)) {
                $sendEvent('error', 'Message is required.');

                return;
            }

            try {
                // Create or get conversation if persistence enabled
                if ($this->conversationManager && !$conversation && !empty($message)) {
                    $user = $this->getUser();
                    if ($user instanceof ConversationOwnerInterface) {
                        $conversation = $this->conversationManager->createConversation($user);
                        $this->conversationManager->setCurrentConversation($conversation);
                    }
                }

                // Load conversation history from database if persistence enabled (WITHOUT new message)
                if ($conversation && $this->conversationManager) {
                    $dbMessages = $this->conversationManager->getMessages($conversation);

                    // Convert DB messages to ChatService format using formatter (handles decryption)
                    if ($this->messageFormatter) {
                        $options['history'] = $this->messageFormatter->entitiesToApiFormat($dbMessages);
                    } else {
                        // Fallback (legacy risks sending encrypted content)
                        $options['history'] = $dbMessages;
                    }
                }

                // Status update callback for streaming
                $onStatusUpdate = function (string $statusMessage, string $step) use ($sendEvent): void {
                    $sendEvent('status', ['message' => $statusMessage, 'step' => $step]);
                };

                // Token streaming callback
                $onToken = function (string $token) use ($sendEvent): void {
                    $sendEvent('delta', ['text' => $token]);
                };

                // Execute chat (ChatService will handle adding the new user message to history)
                $result = $this->chatService->ask($message, $options, $onStatusUpdate, $onToken);

                // Save BOTH user message and assistant response to database after processing
                if ($conversation && $this->conversationManager) {
                    // Save user message
                    if (!empty($message)) {
                        $this->conversationManager->saveMessage($conversation, MessageRole::USER, $message);
                    }

                    // Save assistant message
                    if (!empty($result['answer'])) {
                        $usage = $result['usage'] ?? [];
                        $metadata = [
                            'prompt_tokens'     => $usage['prompt_tokens'] ?? 0,
                            'completion_tokens' => $usage['completion_tokens'] ?? 0,
                            'thinking_tokens'   => $usage['thinking_tokens'] ?? 0,
                            'safety_ratings'    => $result['safety'] ?? null,
                            'model'             => $result['model'] ?? null,
                            'metadata'          => ['debug_id' => $result['debug_id'] ?? null],
                        ];
                        $this->conversationManager->saveMessage($conversation, MessageRole::MODEL, $result['answer'], $metadata);
                    }
                }

                // Add conversation_id to result
                if ($conversation) {
                    $result['conversation_id'] = $conversation->getId();
                }

                // Send final result
                $sendEvent('result', $result);

                // Auto-generate title for new conversations (first exchange)
                if ($conversation && $this->conversationManager && !empty($message)) {
                    try {
                        $messages = $this->conversationManager->getMessages($conversation);

                        // Check if this is the first exchange (exactly 2 messages: 1 user + 1 model)
                        if (2 === count($messages)) {
                            $titlePrompt = "Génère un titre très court (max 6 mots) sans guillemets pour : '$message'";

                            // Generate title in stateless mode (don't pollute conversation history)
                            $titleResult = $this->chatService->ask($titlePrompt, ['stateless' => true, 'debug' => false]);

                            if (!empty($titleResult['answer'])) {
                                // Clean the result (remove quotes, etc.)
                                $rawTitle = $titleResult['answer'];
                                $newTitle = trim(str_replace(['"', 'Titre:', 'Title:'], '', $rawTitle));

                                if (!empty($newTitle)) {
                                    $this->conversationManager->updateTitle($conversation, $newTitle);

                                    // Send title update event to frontend
                                    $sendEvent('title', ['title' => $newTitle]);
                                }
                            }
                        }
                    } catch (\Throwable $e) {
                        // Silent fail: title generation is not critical
                        // Could log with a logger if available
                    }
                }
            } catch (\Throwable $e) {
                // Better error reporting for API failures
                $errorMessage = $e->getMessage();

                // Enrich error message for common failures
                if ($e instanceof LlmAuthenticationException) {
                    $errorMessage = "🔑 Erreur d'authentification : Les identifiants de l'IA sont incorrects ou expirés.";
                } elseif ($e instanceof LlmQuotaException) {
                    $errorMessage = "⚠️ Quota dépassé : La limite de consommation de l'IA a été atteinte.";
                } elseif ($e instanceof LlmRateLimitException) {
                    $errorMessage = "⏳ Trop de requêtes : Veuillez patienter un instant avant de réessayer.";
                } elseif ($e instanceof LlmServiceUnavailableException) {
                    $errorMessage = "🔧 Service indisponible : Le service IA est temporairement inaccessible.";
                } elseif ($e instanceof LlmException) {
                    $errorMessage = "🤖 Erreur IA : " . $e->getMessage();
                } elseif (str_contains($errorMessage, 'timeout') || str_contains($errorMessage, 'Timeout')) {
                    $errorMessage = "⏱️ Timeout : L'IA a mis trop de temps à répondre.";
                } else {
                    $errorMessage = "❌ Erreur système : Une erreur inattendue est survenue.";
                }

                $sendEvent('error', $errorMessage);
            }
        });

        $response->headers->set('Content-Type', 'application/x-ndjson');
        $response->headers->set('X-Accel-Buffering', 'no'); // Disable Nginx buffering
        $response->headers->set('Cache-Control', 'no-cache');
        $response->headers->set('X-Debug-Token', 'disabled'); // Prevent Symfony debug toolbar injection

        return $response;
    }
}
