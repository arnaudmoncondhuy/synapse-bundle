<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseChat\Controller\Api;

use ArnaudMoncondhuy\SynapseCore\Agent\AgentResolver;
use ArnaudMoncondhuy\SynapseCore\Agent\Input;
use ArnaudMoncondhuy\SynapseCore\Agent\WorkflowDelegatingAgent;
use ArnaudMoncondhuy\SynapseCore\AgentRegistry;
use ArnaudMoncondhuy\SynapseCore\Chat\ChatIntentRouter;
use ArnaudMoncondhuy\SynapseCore\Contract\ConversationOwnerInterface;
use ArnaudMoncondhuy\SynapseCore\Contract\PermissionCheckerInterface;
use ArnaudMoncondhuy\SynapseCore\Engine\ChatService;
use ArnaudMoncondhuy\SynapseCore\Event\SynapseArchitectProposalEvent;
use ArnaudMoncondhuy\SynapseCore\Event\SynapseChunkReceivedEvent;
use ArnaudMoncondhuy\SynapseCore\Event\SynapseCodeExecutedEvent;
use ArnaudMoncondhuy\SynapseCore\Event\SynapseGoalFailedEvent;
use ArnaudMoncondhuy\SynapseCore\Event\SynapseGoalReachedEvent;
use ArnaudMoncondhuy\SynapseCore\Event\SynapseMemoryResultsEvent;
use ArnaudMoncondhuy\SynapseCore\Event\SynapseMultiTurnIterationEvent;
use ArnaudMoncondhuy\SynapseCore\Event\SynapsePlannerPlanProducedEvent;
use ArnaudMoncondhuy\SynapseCore\Event\SynapseRagResultsEvent;
use ArnaudMoncondhuy\SynapseCore\Event\SynapseStatusChangedEvent;
use ArnaudMoncondhuy\SynapseCore\Event\SynapseTokenStreamedEvent;
use ArnaudMoncondhuy\SynapseCore\Event\SynapseToolCallCompletedEvent;
use ArnaudMoncondhuy\SynapseCore\Event\SynapseToolCallStartedEvent;
use ArnaudMoncondhuy\SynapseCore\Event\SynapseUsageRecordedEvent;
use ArnaudMoncondhuy\SynapseCore\Event\SynapseWorkflowStepCompletedEvent;
use ArnaudMoncondhuy\SynapseCore\Event\SynapseWorkflowStepStartedEvent;
use ArnaudMoncondhuy\SynapseCore\Formatter\MessageFormatter;
use ArnaudMoncondhuy\SynapseCore\Manager\ConversationManager;
use ArnaudMoncondhuy\SynapseCore\Shared\Enum\MessageRole;
use ArnaudMoncondhuy\SynapseCore\Shared\Exception\LlmAuthenticationException;
use ArnaudMoncondhuy\SynapseCore\Shared\Exception\LlmException;
use ArnaudMoncondhuy\SynapseCore\Shared\Exception\LlmQuotaException;
use ArnaudMoncondhuy\SynapseCore\Shared\Exception\LlmRateLimitException;
use ArnaudMoncondhuy\SynapseCore\Shared\Exception\LlmServiceUnavailableException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Profiler\Profiler;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Contrôleur API principal pour le flux de conversation.
 *
 * Ce contrôleur expose le endpoint `%synapse.chat_api_prefix%/chat` qui gère les échanges
 * en temps réel avec le frontend via un flux NDJSON (Streamed Response).
 */
#[Route('%synapse.chat_api_prefix%')]
class ChatApiController extends AbstractController
{
    public function __construct(
        private readonly ChatService $chatService,
        private readonly EventDispatcherInterface $dispatcher,
        private readonly PermissionCheckerInterface $permissionChecker,
        private readonly AgentRegistry $agentRegistry,
        private readonly AgentResolver $agentResolver,
        private readonly ?ConversationManager $conversationManager = null,
        private readonly ?MessageFormatter $messageFormatter = null,
        private readonly ?CsrfTokenManagerInterface $csrfTokenManager = null,
        private readonly ?\ArnaudMoncondhuy\SynapseCore\Accounting\TokenCostEstimator $tokenCostEstimator = null,
        private readonly ?TranslatorInterface $translator = null,
        private readonly ?\ArnaudMoncondhuy\SynapseCore\Engine\ToolRegistry $toolRegistry = null,
        // Chantier I phase 2 : branchement architecte. Tous optionnels pour
        // ne pas casser les setups qui n'ont pas le preset architecte configuré.
        private readonly ?ChatIntentRouter $chatIntentRouter = null,
        private readonly ?\ArnaudMoncondhuy\SynapseCore\Governance\Architect\ArchitectAgent $architectAgent = null,
        private readonly ?\ArnaudMoncondhuy\SynapseCore\Governance\Architect\ArchitectProposalProcessor $architectProposalProcessor = null,
        private readonly ?UrlGeneratorInterface $urlGenerator = null,
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
     * 2. Clôture immédiate de la session (session_write_close) pour éviter le verrouillage (Session Blocking) si d'autres parties de l'application utilisent les sessions PHP.
     *
     * @param Request $request la requête HTTP contenant le message JSON
     * @param Profiler|null $profiler le profiler Symfony (injecté si disponible)
     *
     * @return StreamedResponse une réponse HTTP dont le contenu est envoyé chunk par chunk
     */
    #[Route('/chat', name: 'synapse_api_chat', methods: ['POST'])]
    public function chat(Request $request, ?Profiler $profiler): StreamedResponse
    {
        // CSRF Check (désactivable via synapse.security.api_csrf_enabled: false)
        if ($this->getParameter('synapse.security.api_csrf_enabled') && $this->csrfTokenManager) {
            $token = $request->headers->get('X-CSRF-Token') ?? $request->request->get('_csrf_token');
            $token = (string) $token;
            if ('' === $token) {
                $msg = $this->translator ? $this->translator->trans('synapse.chat.api.error.csrf_missing', [], 'synapse_chat') : 'Jeton CSRF manquant.';
                throw $this->createAccessDeniedException($msg);
            }
            if (!$this->isCsrfTokenValid('synapse_api', $token)) {
                $msg = $this->translator ? $this->translator->trans('synapse.chat.api.error.csrf_invalid', [], 'synapse_chat') : 'Jeton CSRF invalide ou expiré.';
                throw $this->createAccessDeniedException($msg);
            }
        }

        // Permission check: Can start/continue chat?
        if (!$this->permissionChecker->canCreateConversation()) {
            $msg = $this->translator ? $this->translator->trans('synapse.chat.api.error.permission_denied', [], 'synapse_chat') : 'Not allowed to start a conversation.';
            throw $this->createAccessDeniedException($msg);
        }

        // 1. On désactive le profiler pour ne pas casser le flux JSON
        if ($profiler) {
            $profiler->disable();
        }

        $rawJson = $request->getContent();
        $decoded = json_decode(is_string($rawJson) ? $rawJson : '{}', true);
        $data = is_array($decoded) ? $decoded : [];

        $messageRaw = $data['message'] ?? '';
        $message = is_string($messageRaw) ? $messageRaw : '';

        // Pièces jointes (images, PDF, etc.) — accepte 'attachments' (nouveau) ou 'images' (rétrocompat)
        $attachmentsRaw = $data['attachments'] ?? $data['images'] ?? [];
        $attachments = is_array($attachmentsRaw)
            ? array_values(array_filter($attachmentsRaw, fn ($i) => is_array($i) && isset($i['data'], $i['mime_type']) && is_string($i['data']) && is_string($i['mime_type'])))
            : [];

        $optionsRaw = $data['options'] ?? [];
        $options = is_array($optionsRaw) ? $optionsRaw : [];

        $debugRaw = $data['debug'] ?? ($options['debug'] ?? false);
        $options['debug'] = (bool) $debugRaw;

        $conversationIdRaw = $data['conversation_id'] ?? null;
        $conversationId = is_string($conversationIdRaw) ? $conversationIdRaw : null;
        $options['conversation_id'] = $conversationId;  // Pass to ChatService for debug logging

        // Load conversation if ID provided and persistence enabled
        $conversation = null;
        if ($conversationId && $this->conversationManager) {
            $user = $this->getUser();
            if ($user instanceof ConversationOwnerInterface) {
                $conversation = $this->conversationManager->getConversation($conversationId, $user);
                if ($conversation) {
                    if (!$this->permissionChecker->canView($conversation)) {
                        $msg = $this->translator ? $this->translator->trans('synapse.chat.api.error.conversation_access_denied', [], 'synapse_chat') : 'Access Denied to this conversation.';
                        throw $this->createAccessDeniedException($msg);
                    }
                    $this->conversationManager->setCurrentConversation($conversation);
                }
            }
        }

        $response = new StreamedResponse(function () use ($message, $options, $conversation, $conversationId, $attachments) {
            // CRITICAL: Disable ALL output buffering to prevent Symfony Debug Toolbar injection
            // The toolbar tries to inject HTML into buffered output, corrupting NDJSON stream
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            ob_implicit_flush(true);

            // Helper to send NDJSON event
            $sendEvent = function (string $type, mixed $payload): void {
                echo json_encode(['type' => $type, 'payload' => $payload], JSON_INVALID_UTF8_IGNORE | JSON_THROW_ON_ERROR)."\n";
                // Force flush explicitly
                if (ob_get_length() > 0) {
                    ob_flush();
                }
                flush();
            };

            // Send padding to bypass browser/proxy buffering (approx 2KB)
            echo ':'.str_repeat(' ', 2048)."\n";
            flush();

            $isReset = isset($options['reset_conversation']) && true === $options['reset_conversation'];
            if (empty($message) && empty($attachments) && !$isReset) {
                $msg = $this->translator ? $this->translator->trans('synapse.chat.api.error.message_required', [], 'synapse_chat') : 'SynapseMessage is required.';
                $sendEvent('error', $msg);

                return;
            }

            try {
                // Create or get conversation if persistence enabled
                if ($this->conversationManager && !$conversation && (!empty($message) || !empty($attachments))) {
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
                        $trailingAttachments = $this->messageFormatter->getAndClearTrailingAttachments();
                        if (!empty($trailingAttachments)) {
                            $options['_trailing_generated_attachments'] = $trailingAttachments;
                        }
                    } else {
                        // Fallback (legacy risks sending encrypted content)
                        $history = [];
                        foreach ($dbMessages as $dbMsg) {
                            $history[] = [
                                'role' => $dbMsg->getRole(),
                                'content' => $dbMsg->getContent(),
                            ];
                        }
                        $options['history'] = $history;
                    }
                }

                // Temporary event listeners for NDJSON streaming (scoped to this request)
                $statusListener = function (SynapseStatusChangedEvent $e) use ($sendEvent): void {
                    $sendEvent('status', ['message' => $e->message, 'step' => $e->step]);
                };
                // $isWorkflowMode est positionné plus bas, mais on a besoin d'une ref
                // mutable ici pour que le listener puisse la lire.
                $workflowModeRef = new \stdClass();
                $workflowModeRef->active = false;
                $tokenListener = function (SynapseTokenStreamedEvent $e) use ($sendEvent, $workflowModeRef): void {
                    // En mode workflow, ne PAS streamer les tokens des sous-agents dans le chat.
                    // Les résultats de chaque step sont affichés dans la sidebar via workflow_step events.
                    if ($workflowModeRef->active) {
                        return;
                    }
                    $sendEvent('delta', ['text' => $e->token]);
                };
                $toolListener = function (SynapseToolCallCompletedEvent $e) use ($sendEvent, $conversation): void {
                    $isProposeToRemember = 'propose_to_remember' === $e->getToolName() || str_ends_with($e->getToolName(), 'propose_to_remember');
                    if ($isProposeToRemember && \is_array($e->getResult()) && ($e->getResult()['__synapse_action'] ?? '') === 'memory_proposal') {
                        $sendEvent('tool_executed', [
                            'tool' => 'propose_to_remember',
                            'proposal' => $e->getResult(),
                            'conversation_id' => $conversation?->getId(),
                        ]);
                    }
                    // Transparency sidebar: tool completed
                    $resultRaw = $e->getResult();
                    $resultPreview = \is_string($resultRaw) ? mb_substr($resultRaw, 0, 200) : mb_substr((string) json_encode($resultRaw, \JSON_UNESCAPED_UNICODE), 0, 200);
                    $sendEvent('tool_completed', [
                        'toolName' => $e->getToolName(),
                        'resultPreview' => $resultPreview,
                    ]);
                };
                // Transparency: thinking tokens
                $thinkingListener = function (SynapseChunkReceivedEvent $e) use ($sendEvent, $workflowModeRef): void {
                    if ($workflowModeRef->active) {
                        return;
                    }
                    $thinking = $e->getThinking();
                    if (null !== $thinking && '' !== $thinking) {
                        $sendEvent('thinking_delta', ['text' => $thinking]);
                    }
                };
                // Transparency: tool call started
                $toolStartedListener = function (SynapseToolCallStartedEvent $e) use ($sendEvent): void {
                    $sendEvent('tool_started', [
                        'toolName' => $e->toolName,
                        'toolLabel' => $e->toolLabel,
                        'arguments' => $e->arguments,
                        'toolCallId' => $e->toolCallId,
                        'turn' => $e->turn,
                    ]);
                };
                // Transparency: multi-turn iteration
                $turnIterationListener = function (SynapseMultiTurnIterationEvent $e) use ($sendEvent): void {
                    $tools = array_map(function (array $t): array {
                        $t['label'] = $this->toolRegistry?->getLabel($t['name']) ?? $t['name'];

                        return $t;
                    }, $e->toolCallsSummary);
                    $sendEvent('turn_iteration', [
                        'turn' => $e->turn,
                        'maxTurns' => $e->maxTurns,
                        'tools' => $tools,
                        'usage' => $e->usage,
                    ]);
                };
                // Transparency: RAG results
                $ragResultsListener = function (SynapseRagResultsEvent $e) use ($sendEvent): void {
                    $sendEvent('rag_context', [
                        'results' => $e->results,
                        'totalInjected' => $e->totalInjected,
                        'tokenEstimate' => $e->tokenEstimate,
                    ]);
                };
                // Transparency: memory recalled
                $memoryResultsListener = function (SynapseMemoryResultsEvent $e) use ($sendEvent): void {
                    $sendEvent('memory_recalled', [
                        'memories' => $e->memories,
                        'totalRecalled' => $e->totalRecalled,
                    ]);
                };
                // Transparency: usage/cost update
                $usageListener = function (SynapseUsageRecordedEvent $e) use ($sendEvent): void {
                    $sendEvent('usage_update', [
                        'model' => $e->getModel(),
                        'promptTokens' => $e->getPromptTokens(),
                        'completionTokens' => $e->getCompletionTokens(),
                        'thinkingTokens' => $e->getThinkingTokens(),
                        'imageTokens' => $e->getImageCompletionTokens(),
                        'cost' => $e->getCostInReferenceCurrency(),
                    ]);
                };
                $workflowStepStartedListener = function (SynapseWorkflowStepStartedEvent $e) use ($sendEvent): void {
                    $sendEvent('workflow_step_started', [
                        'workflowRunId' => $e->workflowRunId,
                        'stepIndex' => $e->stepIndex,
                        'stepName' => $e->stepName,
                        'agentName' => $e->agentName,
                        'totalSteps' => $e->totalSteps,
                    ]);
                };
                $workflowStepListener = function (SynapseWorkflowStepCompletedEvent $e) use ($sendEvent): void {
                    $sendEvent('workflow_step', [
                        'workflowRunId' => $e->workflowRunId,
                        'stepIndex' => $e->stepIndex,
                        'stepName' => $e->stepName,
                        'agentName' => $e->agentName,
                        'answer' => $e->answer,
                        'totalSteps' => $e->totalSteps,
                        'usage' => $e->usage,
                    ]);
                };
                // Chantier D + Principe 8 : quand un PlannerAgent produit un
                // plan, l'envoyer en live au front pour afficher la section
                // `plan` dans la transparency sidebar. Le payload est déjà
                // formaté pour le renderer JS via toArray().
                $plannerPlanListener = function (SynapsePlannerPlanProducedEvent $e) use ($sendEvent): void {
                    $sendEvent('planner_plan', $e->toArray());
                };
                // Chantier I phase 2 + Principe 8 : quand l'ArchitectAgent
                // produit une proposition (agent ou workflow éphémère), la
                // push au front pour afficher la section `proposals` avec
                // les 3 boutons HITL (Inspecter / Promouvoir / Rejeter).
                $architectProposalListener = function (SynapseArchitectProposalEvent $e) use ($sendEvent): void {
                    $sendEvent('architect_proposal', $e->toArray());
                };
                // Chantier E phase 3 + Principe 8 : quand CodeExecuteTool
                // exécute du code via le sandbox, remonter au front une
                // carte dédiée avec le source Python + stdout + return_value
                // pour la section `code` de la transparency sidebar.
                $codeExecutedListener = function (SynapseCodeExecutedEvent $e) use ($sendEvent): void {
                    $sendEvent('code_executed', $e->toArray());
                };
                // Chantier D finition + Principe 8 : events de cycle de vie
                // du goal d'un PlannerAgent. Permettent à la sidebar d'afficher
                // le goal courant + son issue (atteint / échoué) avec raison.
                $goalReachedListener = function (SynapseGoalReachedEvent $e) use ($sendEvent): void {
                    $sendEvent('goal_reached', $e->toArray());
                };
                $goalFailedListener = function (SynapseGoalFailedEvent $e) use ($sendEvent): void {
                    $sendEvent('goal_failed', $e->toArray());
                };
                $this->dispatcher->addListener(SynapseStatusChangedEvent::class, $statusListener);
                $this->dispatcher->addListener(SynapseTokenStreamedEvent::class, $tokenListener);
                $this->dispatcher->addListener(SynapseToolCallCompletedEvent::class, $toolListener);
                $this->dispatcher->addListener(SynapseChunkReceivedEvent::class, $thinkingListener);
                $this->dispatcher->addListener(SynapseToolCallStartedEvent::class, $toolStartedListener);
                $this->dispatcher->addListener(SynapseMultiTurnIterationEvent::class, $turnIterationListener);
                $this->dispatcher->addListener(SynapseRagResultsEvent::class, $ragResultsListener);
                $this->dispatcher->addListener(SynapseMemoryResultsEvent::class, $memoryResultsListener);
                $this->dispatcher->addListener(SynapseUsageRecordedEvent::class, $usageListener);
                $this->dispatcher->addListener(SynapseWorkflowStepStartedEvent::class, $workflowStepStartedListener);
                $this->dispatcher->addListener(SynapseWorkflowStepCompletedEvent::class, $workflowStepListener);
                $this->dispatcher->addListener(SynapsePlannerPlanProducedEvent::class, $plannerPlanListener);
                $this->dispatcher->addListener(SynapseArchitectProposalEvent::class, $architectProposalListener);
                $this->dispatcher->addListener(SynapseCodeExecutedEvent::class, $codeExecutedListener);
                $this->dispatcher->addListener(SynapseGoalReachedEvent::class, $goalReachedListener);
                $this->dispatcher->addListener(SynapseGoalFailedEvent::class, $goalFailedListener);

                // Pass user_id for spending limit checks
                $user = $this->getUser();
                if ($user instanceof ConversationOwnerInterface) {
                    $options['user_id'] = (string) $user->getId();
                }

                // Estimate cost for spending limit check (before LLM call)
                if (null !== $this->tokenCostEstimator) {
                    $historyRaw = $options['history'] ?? [];
                    /** @var array<int, array{role: string, content?: string|null}> $estimateContents */
                    $estimateContents = is_array($historyRaw) ? $historyRaw : [];

                    if ('' !== $message) {
                        $estimateContents[] = ['role' => 'user', 'content' => $message];
                    }
                    if (!empty($estimateContents)) {
                        $estimate = $this->tokenCostEstimator->estimateCost($estimateContents);
                        $options['estimated_cost_reference'] = (float) $estimate['cost_reference'];
                    }
                }

                // Build typed options array for ChatService::ask
                /** @var array{tone?: string, history?: array<int, array<string, mixed>>, stateless?: bool, debug?: bool, preset?: \ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseModelPreset, conversation_id?: string, user_id?: string, estimated_cost_reference?: float, streaming?: bool, reset_conversation?: bool, module?: string, action?: string} $typedOptions */
                $typedOptions = [
                    // ChatService est le point unique de token accounting : il va créer la ligne
                    // SynapseLlmCall avec ces valeurs (module/action alignés sur Analytics).
                    // Pour un modèle image-only, ChatService basculera automatiquement l'action
                    // vers `image_generation`. Pour un modèle mixte (texte+image), l'action reste
                    // `chat_turn` et les imageCompletionTokens sont facturés via pricing_output_image.
                    'module' => 'chat',
                    'action' => 'chat_turn',
                ];
                if (isset($options['tone']) && is_string($options['tone'])) {
                    $typedOptions['tone'] = $options['tone'];
                }
                if (isset($options['agent']) && is_string($options['agent']) && '' !== $options['agent']) {
                    $typedOptions['agent'] = $options['agent'];
                }
                if (isset($options['history']) && is_array($options['history'])) {
                    /** @var array<int, array<string, mixed>> $history */
                    $history = $options['history'];
                    $typedOptions['history'] = $history;
                }
                if (isset($options['stateless'])) {
                    $typedOptions['stateless'] = (bool) $options['stateless'];
                }
                $typedOptions['debug'] = (bool) $options['debug'];
                if (null !== $conversationId) {
                    $typedOptions['conversation_id'] = $conversationId;
                }
                if (isset($options['user_id']) && is_string($options['user_id'])) {
                    $typedOptions['user_id'] = $options['user_id'];
                }
                if (isset($options['estimated_cost_reference']) && is_numeric($options['estimated_cost_reference'])) {
                    $typedOptions['estimated_cost_reference'] = (float) $options['estimated_cost_reference'];
                }
                if (isset($options['streaming'])) {
                    $typedOptions['streaming'] = (bool) $options['streaming'];
                }
                if (isset($options['reset_conversation'])) {
                    $typedOptions['reset_conversation'] = (bool) $options['reset_conversation'];
                }
                if (isset($options['_trailing_generated_attachments']) && is_array($options['_trailing_generated_attachments'])) {
                    $typedOptions['_trailing_generated_attachments'] = $options['_trailing_generated_attachments'];
                }

                // ── Chantier I phase 2 : Architect intent routing ──
                // Si le message est une demande de création d'agent/workflow
                // (détectée par regex via ChatIntentRouter), on court-circuite
                // ChatService pour appeler l'ArchitectAgent. La proposition
                // devient une entité éphémère via ArchitectProposalProcessor
                // et est poussée au front via SynapseArchitectProposalEvent
                // pour affichage dans la sidebar. Le chat renvoie un message
                // court confirmant la création — le vrai UX est dans la sidebar.
                $architectHandled = false;
                $architectResult = null;
                if (
                    null !== $this->chatIntentRouter
                    && null !== $this->architectAgent
                    && null !== $this->architectProposalProcessor
                    && null !== $this->urlGenerator
                    && '' !== $message
                ) {
                    $intent = $this->chatIntentRouter->route($message);
                    if (null !== $intent) {
                        try {
                            $sendEvent('status', ['message' => 'L\'architecte conçoit ta proposition…', 'step' => 'architect']);

                            $architectOutput = $this->architectAgent->call(Input::ofStructured([
                                'action' => $intent['action'],
                                'description' => $intent['description'],
                            ]));

                            $proposalData = $architectOutput->getData();
                            if (isset($proposalData['error'])) {
                                throw new \RuntimeException((string) $proposalData['error']);
                            }

                            // Persiste l'entité éphémère
                            $processorResult = $this->architectProposalProcessor->process($proposalData, 'agent:architect');
                            /** @var \ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseAgent|\ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseWorkflow $entity */
                            $entity = $processorResult['entity'];
                            $entityId = (int) $entity->getId();

                            // Construit les URLs des 3 boutons HITL
                            $isAgent = 'create_agent' === $intent['action'];
                            $inspectRoute = $isAgent ? 'synapse_admin_agents_edit' : 'synapse_admin_workflows_edit';
                            $promoteRoute = $isAgent ? 'synapse_admin_agents_promote' : 'synapse_admin_workflows_promote';
                            $rejectRoute = $isAgent ? 'synapse_admin_agents_reject' : 'synapse_admin_workflows_reject';

                            $inspectUrl = $this->urlGenerator->generate($inspectRoute, ['id' => $entityId]);
                            $promoteUrl = $this->urlGenerator->generate($promoteRoute, ['id' => $entityId]);
                            $rejectUrl = $this->urlGenerator->generate($rejectRoute, ['id' => $entityId]);

                            // Tokens CSRF scopés à l'entité — format identique à celui attendu
                            // par les validateCsrfToken() des admin controllers.
                            $promoteScope = $isAgent ? 'synapse_agent_promote_' : 'synapse_workflow_promote_';
                            $rejectScope = $isAgent ? 'synapse_agent_reject_' : 'synapse_workflow_reject_';
                            $promoteCsrfToken = null !== $this->csrfTokenManager
                                ? $this->csrfTokenManager->getToken($promoteScope.$entityId)->getValue()
                                : '';
                            $rejectCsrfToken = null !== $this->csrfTokenManager
                                ? $this->csrfTokenManager->getToken($rejectScope.$entityId)->getValue()
                                : '';

                            // Construit le preview adapté au type
                            if ($isAgent) {
                                $preview = (string) ($proposalData['system_prompt'] ?? '');
                                if (strlen($preview) > 300) {
                                    $preview = substr($preview, 0, 300).'…';
                                }
                            } else {
                                $steps = $proposalData['steps'] ?? [];
                                $previewLines = [];
                                if (is_array($steps)) {
                                    foreach ($steps as $idx => $st) {
                                        if (!is_array($st)) {
                                            continue;
                                        }
                                        $stepName = is_string($st['name'] ?? null) ? $st['name'] : '?';
                                        $stepType = is_string($st['type'] ?? null) ? $st['type'] : 'agent';
                                        $stepAgent = is_string($st['agent_name'] ?? null) ? ' → '.$st['agent_name'] : '';
                                        $previewLines[] = sprintf('%d. %s (%s)%s', $idx + 1, $stepName, $stepType, $stepAgent);
                                    }
                                }
                                $preview = implode("\n", $previewLines);
                            }

                            $entityName = method_exists($entity, 'getName') ? (string) $entity->getName() : '';
                            $entityKey = method_exists($entity, 'getKey')
                                ? (string) $entity->getKey()
                                : (method_exists($entity, 'getWorkflowKey') ? (string) $entity->getWorkflowKey() : '');
                            $entityDesc = method_exists($entity, 'getDescription') ? (string) ($entity->getDescription() ?? '') : '';
                            $reasoning = is_string($proposalData['reasoning'] ?? null) ? $proposalData['reasoning'] : null;

                            $this->dispatcher->dispatch(new SynapseArchitectProposalEvent(
                                action: $intent['action'],
                                entityId: $entityId,
                                entityKey: $entityKey,
                                entityName: $entityName,
                                entityDescription: $entityDesc,
                                preview: $preview,
                                inspectUrl: $inspectUrl,
                                promoteUrl: $promoteUrl,
                                rejectUrl: $rejectUrl,
                                promoteCsrfToken: $promoteCsrfToken,
                                rejectCsrfToken: $rejectCsrfToken,
                                reasoning: $reasoning,
                            ));

                            $architectHandled = true;
                            $architectResult = [
                                'answer' => sprintf(
                                    "✨ Proposition créée : **%s**.\n\nConsulte la sidebar pour l'inspecter, la promouvoir ou la rejeter.",
                                    $entityName,
                                ),
                                'usage' => $architectOutput->getUsage(),
                                'safety' => [],
                                'model' => null,
                                'preset_id' => null,
                                'agent_id' => null,
                                'debug_id' => $architectOutput->getDebugId(),
                                'generated_attachments' => [],
                                'call_id' => null,
                            ];
                        } catch (\Throwable $e) {
                            // Ne pas crasher le chat si l'architecte échoue — dégrader proprement.
                            // Log l'exception pour debug — le sendEvent est perdu côté user si la sidebar
                            // n'écoute pas les 'status' events, et sans log persistant on est aveugle.
                            error_log(sprintf(
                                '[ChatApiController] ArchitectAgent flow failed: %s in %s:%d\n%s',
                                $e->getMessage(),
                                $e->getFile(),
                                $e->getLine(),
                                $e->getTraceAsString(),
                            ));
                            $sendEvent('status', [
                                'message' => 'L\'architecte a échoué : '.$e->getMessage().'. Je continue en mode chat normal.',
                                'step' => 'architect_failed',
                            ]);
                        }
                    }
                }

                // ── Workflow delegation ──
                // Si l'agent sélectionné a un workflowKey, on court-circuite ChatService
                // et on délègue à WorkflowDelegatingAgent → WorkflowRunner → MultiAgent.
                // Les sous-agents du workflow appelleront eux-mêmes ChatService.
                $workflowAgent = null;
                if (!$architectHandled && isset($typedOptions['agent']) && is_string($typedOptions['agent']) && '' !== $typedOptions['agent']) {
                    $agentEntity = $this->agentRegistry->get($typedOptions['agent']);
                    if (null !== $agentEntity && null !== $agentEntity->getWorkflowKey()) {
                        $ctx = $this->agentResolver->createRootContext(
                            userId: $typedOptions['user_id'] ?? null,
                            origin: 'chat',
                        );
                        $workflowAgent = $this->agentResolver->resolve($typedOptions['agent'], $ctx);
                        // Activer le mode workflow : les tokens des sous-agents ne sont pas
                        // streamés dans le chat. Seuls les workflow_step events apparaissent
                        // dans la sidebar, et le résultat final est envoyé via l'event 'result'.
                        $workflowModeRef->active = true;
                    }
                }

                // Execute chat (ChatService dispatche les events SynapseTokenStreamedEvent, etc.)
                try {
                    if ($architectHandled && null !== $architectResult) {
                        $result = $architectResult;
                    } elseif (null !== $workflowAgent) {
                        $sendEvent('status', ['message' => 'Exécution du workflow…', 'step' => 'workflow']);
                        $agentOutput = $workflowAgent->call(new Input($message), $typedOptions);
                        // Convertir Output en format $result attendu par le reste du contrôleur
                        $result = [
                            'answer' => $agentOutput->getAnswer() ?? '',
                            'usage' => $agentOutput->getUsage(),
                            'safety' => $agentOutput->getMetadata()['safety'] ?? [],
                            'model' => $agentOutput->getMetadata()['model'] ?? null,
                            'preset_id' => $agentOutput->getMetadata()['preset_id'] ?? null,
                            'agent_id' => $agentEntity?->getId(),
                            'debug_id' => $agentOutput->getDebugId(),
                            'generated_attachments' => $agentOutput->getGeneratedAttachments(),
                            'call_id' => null, // Les call_ids sont sur les sous-agents
                        ];
                    } else {
                        $result = $this->chatService->ask($message, $typedOptions, $attachments);
                    }
                } finally {
                    $this->dispatcher->removeListener(SynapseStatusChangedEvent::class, $statusListener);
                    $this->dispatcher->removeListener(SynapseTokenStreamedEvent::class, $tokenListener);
                    $this->dispatcher->removeListener(SynapseToolCallCompletedEvent::class, $toolListener);
                    $this->dispatcher->removeListener(SynapseChunkReceivedEvent::class, $thinkingListener);
                    $this->dispatcher->removeListener(SynapseToolCallStartedEvent::class, $toolStartedListener);
                    $this->dispatcher->removeListener(SynapseMultiTurnIterationEvent::class, $turnIterationListener);
                    $this->dispatcher->removeListener(SynapseRagResultsEvent::class, $ragResultsListener);
                    $this->dispatcher->removeListener(SynapseMemoryResultsEvent::class, $memoryResultsListener);
                    $this->dispatcher->removeListener(SynapseUsageRecordedEvent::class, $usageListener);
                    $this->dispatcher->removeListener(SynapseWorkflowStepStartedEvent::class, $workflowStepStartedListener);
                    $this->dispatcher->removeListener(SynapseWorkflowStepCompletedEvent::class, $workflowStepListener);
                    $this->dispatcher->removeListener(SynapsePlannerPlanProducedEvent::class, $plannerPlanListener);
                    $this->dispatcher->removeListener(SynapseArchitectProposalEvent::class, $architectProposalListener);
                    $this->dispatcher->removeListener(SynapseCodeExecutedEvent::class, $codeExecutedListener);
                    $this->dispatcher->removeListener(SynapseGoalReachedEvent::class, $goalReachedListener);
                    $this->dispatcher->removeListener(SynapseGoalFailedEvent::class, $goalFailedListener);
                }

                // Save BOTH user message and assistant response to database after processing
                if ($conversation && $this->conversationManager) {
                    // Save user message (pas d'appel LLM associé)
                    if ('' !== $message || !empty($attachments)) {
                        $this->conversationManager->saveMessage($conversation, MessageRole::USER, $message, [], null, $attachments);
                    }

                    $hasGeneratedAttachments = !empty($result['generated_attachments']);
                    // Quand des images sont générées, supprimer les artefacts de formatage purs
                    // (ex: "```" retourné par certains modèles en multi-tour).
                    // Le texte légitime accompagnant une image est préservé.
                    if ($hasGeneratedAttachments && '' !== ($result['answer'] ?? '')) {
                        $stripped = trim(str_replace(['`', "\n", "\r"], '', (string) $result['answer']));
                        if ('' === $stripped) {
                            $result['answer'] = '';
                        }
                    }
                    if (!empty($result['answer']) || $hasGeneratedAttachments) {
                        $usage = $result['usage'] ?? [];
                        $safetyRatings = [];
                        foreach ($result['safety'] ?? [] as $rating) {
                            if (
                                isset($rating['category'], $rating['probability'])
                                && is_string($rating['category'])
                                && is_string($rating['probability'])
                            ) {
                                /** @var array{category: string, probability: string} $typedRating */
                                $typedRating = ['category' => $rating['category'], 'probability' => $rating['probability']];
                                $safetyRatings[$rating['category']] = $typedRating;
                            }
                        }

                        $metadata = [
                            'prompt_tokens' => $usage['prompt_tokens'] ?? 0,
                            'completion_tokens' => $usage['completion_tokens'] ?? 0,
                            'thinking_tokens' => $usage['thinking_tokens'] ?? 0,
                            'safety_ratings' => $safetyRatings,
                            'model' => $result['model'] ?? null,
                            'preset_id' => $result['preset_id'] ?? null,
                            'metadata' => ['debug_id' => $result['debug_id'] ?? null],
                        ];

                        // Le token accounting (SynapseLlmCall) est fait par ChatService (source unique).
                        // On récupère simplement le call_id retourné pour lier SynapseMessage à l'appel LLM.
                        // Voir feedback_token_cost_single_source : aucun logUsage() parallèle ici.
                        $callId = is_string($result['call_id'] ?? null) ? $result['call_id'] : null;

                        // Lier le message assistant à son appel LLM (callId)
                        /** @var list<array{mime_type: string, data: string}> $generatedAttachments */
                        $generatedAttachments = is_array($result['generated_attachments'] ?? null) ? $result['generated_attachments'] : [];
                        $answerText = ('' !== $result['answer']) ? $result['answer'] : ($hasGeneratedAttachments ? '[image]' : '');
                        $modelMessage = $this->conversationManager->saveMessage($conversation, MessageRole::MODEL, $answerText, $metadata, $callId, $generatedAttachments);

                        // Inclure les UUIDs des images générées dans le résultat (pour affichage front)
                        if (!empty($generatedAttachments)) {
                            $savedAttachments = $this->conversationManager->getAttachmentsByMessageId($modelMessage->getId());
                            $result['generated_attachments'] = array_map(
                                fn ($att) => ['uuid' => $att->getId(), 'mime_type' => $att->getMimeType(), 'display_name' => $att->getDisplayName()],
                                $savedAttachments
                            );
                        } else {
                            unset($result['generated_attachments']);
                        }
                    }
                }

                // Add conversation_id to result
                if ($conversation) {
                    $result['conversation_id'] = $conversation->getId();
                }

                // Transparency: send artifacts for sidebar
                if (!empty($result['generated_attachments'])) {
                    $sendEvent('artifacts', ['items' => $result['generated_attachments']]);
                }

                // Send final result
                $sendEvent('result', $result);

                // Auto-generate title for new conversations (first exchange)
                // Skip si le modèle n'a produit qu'une image (pas de texte à résumer)
                $hasTextAnswer = !empty($result['answer']) && '[image]' !== $result['answer'];
                if ($conversation && $this->conversationManager && !empty($message) && $hasTextAnswer) {
                    try {
                        $messages = $this->conversationManager->getMessages($conversation);

                        // Check if this is the first exchange (exactly 2 messages: 1 user + 1 model)
                        if (2 === count($messages)) {
                            $titlePrompt = $this->translator
                                ? $this->translator->trans('synapse.chat.api.title_generation_prompt', ['message' => $message], 'synapse_chat')
                                : "Génère un titre très court (max 6 mots) sans guillemets pour : '$message'";

                            // Generate title in stateless mode (don't pollute conversation history)
                            // Override system prompt to avoid preset instructions polluting the title.
                            // Token accounting : module/action passés à ChatService (point unique).
                            $titleAskOptions = [
                                'stateless' => true,
                                'debug' => true,
                                'system_prompt' => 'You are a title generator. Respond with only the title, nothing else.',
                                'module' => 'chat',
                                'action' => 'title_generation',
                                'conversation_id' => $conversation->getId(),
                            ];
                            $titleUser = $this->getUser();
                            if ($titleUser instanceof ConversationOwnerInterface) {
                                $titleAskOptions['user_id'] = (string) $titleUser->getId();
                            }
                            $titleResult = $this->chatService->ask($titlePrompt, $titleAskOptions);

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
                    $errorMessage = $this->translator ? $this->translator->trans('synapse.chat.api.error.llm_auth', [], 'synapse_chat') : "🔑 Erreur d'authentification : Les identifiants de l'IA sont incorrects ou expirés.";
                } elseif ($e instanceof LlmQuotaException) {
                    $errorMessage = $this->translator ? $this->translator->trans('synapse.chat.api.error.llm_quota', [], 'synapse_chat') : "⚠️ Quota dépassé : La limite de consommation de l'IA a été atteinte.";
                } elseif ($e instanceof LlmRateLimitException) {
                    $errorMessage = $this->translator ? $this->translator->trans('synapse.chat.api.error.llm_rate_limit', [], 'synapse_chat') : '⏳ Trop de requêtes : Veuillez patienter un instant avant de réessayer.';
                } elseif ($e instanceof LlmServiceUnavailableException) {
                    $errorMessage = $this->translator ? $this->translator->trans('synapse.chat.api.error.llm_unavailable', [], 'synapse_chat') : '🔧 Service indisponible : Le service IA est temporairement inaccessible.';
                } elseif ($e instanceof LlmException) {
                    $errorMessage = $this->translator ? $this->translator->trans('synapse.chat.api.error.llm_generic', ['error' => $e->getMessage()], 'synapse_chat') : '🤖 Erreur IA : '.$e->getMessage();
                } elseif (str_contains((string) $errorMessage, 'timeout') || str_contains((string) $errorMessage, 'Timeout')) {
                    $errorMessage = $this->translator ? $this->translator->trans('synapse.chat.api.error.timeout', [], 'synapse_chat') : "⏱️ Timeout : L'IA a mis trop de temps à répondre.";
                } else {
                    // Logger l'erreur complète côté serveur, ne jamais exposer de détails au client
                    if ($this->getParameter('kernel.debug')) {
                        error_log(sprintf('[Synapse] %s (%s:%d)', $e->getMessage(), $e->getFile(), $e->getLine()));
                    }
                    $errorMessage = $this->translator ? $this->translator->trans('synapse.chat.api.error.system', [], 'synapse_chat') : '❌ Erreur système : Une erreur inattendue est survenue.';
                }

                $sendEvent('error', (string) $errorMessage);
            }
        });

        $response->headers->set('Content-Type', 'application/x-ndjson');
        $response->headers->set('X-Accel-Buffering', 'no'); // Disable Nginx buffering
        $response->headers->set('Cache-Control', 'no-cache');
        $response->headers->set('X-Debug-Token', 'disabled'); // Prevent Symfony debug toolbar injection

        return $response;
    }
}
