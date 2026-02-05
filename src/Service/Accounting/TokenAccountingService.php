<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseBundle\Service\Accounting;

use ArnaudMoncondhuy\SynapseBundle\Entity\TokenUsage;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Service de tracking centralisé des tokens IA
 *
 * Permet de logger la consommation de tokens pour toutes les fonctionnalités
 * IA de l'application (pas seulement les conversations).
 *
 * Les conversations (chat) sont trackées via Message.tokens,
 * ce service est pour les tâches automatisées et agrégations.
 */
class TokenAccountingService
{
    public function __construct(
        private EntityManagerInterface $em
    ) {
    }

    /**
     * Log l'usage de tokens pour une action IA
     *
     * @param string $module Module concerné (ex: 'gmail', 'calendar', 'summarization')
     * @param string $action Action spécifique (ex: 'email_draft', 'event_suggestion')
     * @param string $model Modèle IA utilisé (ex: 'gemini-2.5-flash')
     * @param array $usage Usage détaillé ['prompt' => int, 'completion' => int, 'thinking' => int]
     * @param string|int|null $userId ID de l'utilisateur concerné (nullable pour tâches système)
     * @param string|null $conversationId ID de la conversation concernée (si applicable)
     * @param array|null $metadata Métadonnées additionnelles (coût, durée, etc.)
     */
    public function logUsage(
        string $module,
        string $action,
        string $model,
        array $usage,
        string|int|null $userId = null,
        ?string $conversationId = null,
        ?array $metadata = null
    ): void {
        $tokenUsage = new TokenUsage();
        $tokenUsage->setModule($module);
        $tokenUsage->setAction($action);
        $tokenUsage->setModel($model);

        // Tokens
        // Supporte plusieurs formats :
        // - format interne : ['prompt'|'prompt_tokens', 'completion'|'completion_tokens', 'thinking'|'thinking_tokens']
        // - format Vertex/Gemini usageMetadata : ['promptTokenCount', 'candidatesTokenCount', 'thoughtsTokenCount', ...]
        $promptTokens = $usage['prompt']
            ?? $usage['prompt_tokens']
            ?? $usage['promptTokenCount']
            ?? 0;

        $thinkingTokens = $usage['thinking']
            ?? $usage['thinking_tokens']
            ?? $usage['thoughtsTokenCount']
            ?? 0;

        // Completion tokens :
        // - soit explicitement fourni
        // - soit dérivé du format Vertex : candidates + thoughts
        $completionTokens = $usage['completion']
            ?? $usage['completion_tokens']
            ?? (($usage['candidatesTokenCount'] ?? 0) + $thinkingTokens)
            ?? 0;

        $tokenUsage->setPromptTokens($promptTokens);
        $tokenUsage->setCompletionTokens($completionTokens);
        $tokenUsage->setThinkingTokens($thinkingTokens);
        $tokenUsage->calculateTotalTokens();

        // User ID (convertir en string pour uniformiser)
        if ($userId !== null) {
            $tokenUsage->setUserId((string) $userId);
        }

        // Conversation ID
        if ($conversationId !== null) {
            $tokenUsage->setConversationId($conversationId);
        }

        // Métadonnées
        if ($metadata !== null) {
            $tokenUsage->setMetadata($metadata);
        }

        $this->em->persist($tokenUsage);
        $this->em->flush();
    }

    /**
     * Log l'usage depuis une réponse Gemini
     *
     * @param string $module Module concerné
     * @param string $action Action spécifique
     * @param string $model Modèle utilisé
     * @param array $geminiResponse Réponse complète de l'API Gemini
     * @param string|int|null $userId ID de l'utilisateur
     * @param string|null $conversationId ID de la conversation
     */
    public function logFromGeminiResponse(
        string $module,
        string $action,
        string $model,
        array $geminiResponse,
        string|int|null $userId = null,
        ?string $conversationId = null
    ): void {
        $usage = $this->extractUsageFromGeminiResponse($geminiResponse);

        $metadata = [
            'debug_id' => $geminiResponse['debug_id'] ?? null,
            'finish_reason' => $geminiResponse['candidates'][0]['finishReason'] ?? null,
        ];

        $this->logUsage($module, $action, $model, $usage, $userId, $conversationId, $metadata);
    }

    /**
     * Extrait l'usage des tokens depuis une réponse Gemini
     *
     * @param array $response Réponse Gemini
     * @return array{prompt: int, completion: int, thinking: int}
     */
    private function extractUsageFromGeminiResponse(array $response): array
    {
        $usageMetadata = $response['usageMetadata'] ?? [];

        $promptTokens = $usageMetadata['promptTokenCount'] ?? 0;
        $candidatesTokens = $usageMetadata['candidatesTokenCount'] ?? 0;
        $thinkingTokens = 0;

        // Gemini 2.5+ : thinking tokens
        if (isset($usageMetadata['thoughtsTokenCount'])) {
            $thinkingTokens = $usageMetadata['thoughtsTokenCount'];
        }

        // Completion tokens = candidates + thoughts
        $completionTokens = $candidatesTokens + $thinkingTokens;

        return [
            'prompt' => $promptTokens,
            'completion' => $completionTokens,
            'thinking' => $thinkingTokens,
        ];
    }

    /**
     * Calcule le coût estimé d'un usage
     *
     * @param array $usage Usage détaillé
     * @param array $pricing Tarifs ['input' => float, 'output' => float] ($/1M tokens)
     * @return float Coût en dollars
     */
    public function calculateCost(array $usage, array $pricing): float
    {
        $promptTokens = $usage['prompt'] ?? $usage['prompt_tokens'] ?? 0;
        $completionTokens = $usage['completion'] ?? $usage['completion_tokens'] ?? 0;
        $thinkingTokens = $usage['thinking'] ?? $usage['thinking_tokens'] ?? 0;

        $inputCost = ($promptTokens / 1_000_000) * ($pricing['input'] ?? 0);
        $outputCost = (($completionTokens + $thinkingTokens) / 1_000_000) * ($pricing['output'] ?? 0);

        return round($inputCost + $outputCost, 6);
    }
}
