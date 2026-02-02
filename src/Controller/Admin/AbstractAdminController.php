<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseBundle\Controller\Admin;

use ArnaudMoncondhuy\SynapseBundle\Contract\EncryptionServiceInterface;
use ArnaudMoncondhuy\SynapseBundle\Repository\SynapseConfigRepository;
use ArnaudMoncondhuy\SynapseBundle\Repository\TokenUsageRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Contrôleur d'administration abstrait du bundle Synapse.
 *
 * Le projet qui utilise le bundle doit étendre ce contrôleur
 * et implémenter les méthodes abstraites pour personnalisation.
 */
abstract class AbstractAdminController extends AbstractController
{
    // Tarifs Gemini 2.5 Flash (Janvier 2026)
    protected const PRICE_INPUT_PER_1M = 0.30;
    protected const PRICE_OUTPUT_PER_1M = 2.50;

    public function __construct(
        protected TokenUsageRepository $tokenUsageRepository,
        protected SynapseConfigRepository $synapseConfigRepository,
        protected EncryptionServiceInterface $encryption,
    ) {
    }

    /**
     * Dashboard : Vue d'ensemble (KPIs, État système).
     */
    public function dashboard(): Response
    {
        $this->checkDashboardAccess();

        // Périodes (alignées avec Analytics pour cohérence)
        $end = (new \DateTimeImmutable())->setTime(23, 59, 59);
        $start = $end->modify('first day of this month 00:00:00');

        // Calcul des coûts (combine tchat + appels IA automatisés)
        $globalStats = $this->tokenUsageRepository->getGlobalStats($start, $end);
        $cost = ($globalStats['prompt_tokens'] / 1_000_000 * self::PRICE_INPUT_PER_1M) +
                ($globalStats['completion_tokens'] / 1_000_000 * self::PRICE_OUTPUT_PER_1M);

        return $this->render($this->getTemplatePath('dashboard'), [
            'module_color' => $this->getModuleColor(),
            'module_icon' => $this->getModuleIcon(),
            'nav_active' => 'dashboard',
            'kpis' => [
                'active_conversations' => $this->getActiveConversationsCount(),
                'risks_pending' => $this->getPendingRisksCount(),
                'tokens_cost' => $cost,
                'users_count' => $this->getActiveUsersCount($start),
            ],
        ]);
    }

    /**
     * Sécurité : Gestion des risques (Ange Gardien).
     */
    public function risks(): Response
    {
        $this->checkRisksAccess();

        return $this->render($this->getTemplatePath('risks'), [
            'module_color' => $this->getModuleColor(),
            'module_icon' => $this->getModuleIcon(),
            'nav_active' => 'risks',
            'alerts' => $this->getPendingRisks(),
        ]);
    }

    /**
     * Analytique : Stats d'usage.
     */
    public function analytics(): Response
    {
        $this->checkAnalyticsAccess();

        $end = (new \DateTimeImmutable())->setTime(23, 59, 59);
        $start = $end->modify('first day of this month 00:00:00');

        $globalStats = $this->tokenUsageRepository->getGlobalStats($start, $end);

        $automatedStats = $this->tokenUsageRepository->getAutomatedTaskStats($start, $end);
        $conversationStats = $this->tokenUsageRepository->getConversationStats($start, $end);

        $dailySeries = $this->tokenUsageRepository->getDailyUsage($start, $end);

        // Calcul des coûts (Gemini 2.5 Flash)
        $cost = ($globalStats['prompt_tokens'] / 1_000_000 * self::PRICE_INPUT_PER_1M) +
                ($globalStats['completion_tokens'] / 1_000_000 * self::PRICE_OUTPUT_PER_1M);

        return $this->render($this->getTemplatePath('analytics'), [
            'module_color' => $this->getModuleColor(),
            'module_icon' => $this->getModuleIcon(),
            'nav_active' => 'analytics',
            'stats' => $globalStats,
            'automated_stats' => $automatedStats,
            'conversation_stats' => $conversationStats,
            'modules' => [],
            'daily' => $dailySeries,
            'cost' => $cost,
            'period_label' => $start->format('d/m/Y') . ' - ' . $end->format('d/m/Y'),
        ]);
    }

    /**
     * Configuration : Paramètres techniques.
     */
    public function config(Request $request, EntityManagerInterface $entityManager): Response
    {
        $this->checkConfigAccess();

        // Récupérer la configuration (singleton)
        $config = $this->synapseConfigRepository->getConfig();

        if ($request->isMethod('POST')) {
            // General Config
            $model = $request->request->get('gemini_model');
            if ($model) {
                $config->setModel($model);
            }

            // Safety Settings
            $config->setSafetyEnabled($request->request->getBoolean('safety_enabled'));
            $config->setSafetyDefaultThreshold($request->request->get('safety_default_threshold', 'BLOCK_MEDIUM_AND_ABOVE'));
            $config->setSafetyHateSpeech($request->request->get('safety_hate_speech', 'BLOCK_MEDIUM_AND_ABOVE'));
            $config->setSafetyDangerousContent($request->request->get('safety_dangerous_content', 'BLOCK_MEDIUM_AND_ABOVE'));
            $config->setSafetyHarassment($request->request->get('safety_harassment', 'BLOCK_MEDIUM_AND_ABOVE'));
            $config->setSafetySexuallyExplicit($request->request->get('safety_sexually_explicit', 'BLOCK_MEDIUM_AND_ABOVE'));

            // Generation Config
            $config->setGenerationTemperature((float) $request->request->get('generation_temperature', 1.0));
            $config->setGenerationTopP((float) $request->request->get('generation_top_p', 0.95));
            $config->setGenerationTopK((int) $request->request->get('generation_top_k', 40));

            $maxTokens = $request->request->get('generation_max_output_tokens');
            $config->setGenerationMaxOutputTokens('' !== $maxTokens ? (int) $maxTokens : null);

            // Stop sequences (comma-separated)
            $stopSeqStr = $request->request->get('generation_stop_sequences', '');
            $stopSequences = array_filter(array_map('trim', explode(',', $stopSeqStr)));
            $config->setGenerationStopSequences($stopSequences);

            // Context Caching
            $config->setContextCachingEnabled($request->request->getBoolean('context_caching_enabled'));
            $cachingId = $request->request->get('context_caching_id', '');
            $config->setContextCachingId('' !== $cachingId ? $cachingId : null);

            // Custom Prompt
            $systemPrompt = $request->request->get('system_prompt');
            $config->setSystemPrompt(!empty($systemPrompt) ? $systemPrompt : null);

            $entityManager->flush();

            $this->addFlash('success', 'Configuration enregistrée avec succès');

            return $this->redirectToRoute($this->getConfigRouteName());
        }

        return $this->render($this->getTemplatePath('config'), [
            'config' => $config,
            'module_color' => $this->getModuleColor(),
            'module_icon' => $this->getModuleIcon(),
            'nav_active' => 'config',
            'safety_thresholds' => $this->getSafetyThresholds(),
        ]);
    }

    /**
     * Vue Conversation : Lecture d'une conversation à risque (Break-Glass).
     */
    public function conversation(string $id, Request $request): Response
    {
        $this->checkConversationAccess();

        $conversation = $this->getConversationById($id);

        if (!$conversation) {
            throw $this->createNotFoundException('Conversation introuvable');
        }

        // RGPD : Tracer l'accès administrateur à cette donnée privée
        $this->logSecurityAccess($id, $conversation, $request);

        // Déchiffrement (transparent via getTitle() et getContent())
        try {
            $title = $conversation->getTitle() ?: 'Sans titre';
        } catch (\Exception $e) {
            $title = '[Erreur Déchiffrement Titre]';
        }

        $messages = [];
        foreach ($conversation->getMessages() as $msg) {
            try {
                $content = $msg->getContent();
            } catch (\Exception $e) {
                $content = '[Message Indéchiffrable]';
            }

            $messages[] = [
                'role' => strtolower($msg->getRole()->value),
                'content' => $content,
                'createdAt' => $msg->getCreatedAt(),
            ];
        }

        return $this->render($this->getTemplatePath('conversation'), [
            'module_color' => $this->getModuleColor(),
            'module_icon' => $this->getModuleIcon(),
            'conversation' => $conversation,
            'decrypted_title' => $title,
            'messages' => $messages,
            'nav_active' => 'risks',
        ]);
    }

    protected function getSafetyThresholds(): array
    {
        return [
            'BLOCK_NONE' => 'Aucun blocage',
            'BLOCK_ONLY_HIGH' => 'Bloquer seulement haute probabilité',
            'BLOCK_MEDIUM_AND_ABOVE' => 'Bloquer moyenne et haute (Recommandé)',
            'BLOCK_LOW_AND_ABOVE' => 'Bloquer toute probabilité (Très strict)',
        ];
    }

    // ========== Méthodes abstraites à implémenter par le projet ==========

    /**
     * Vérifier l'accès au dashboard.
     */
    abstract protected function checkDashboardAccess(): void;

    /**
     * Vérifier l'accès aux risques.
     */
    abstract protected function checkRisksAccess(): void;

    /**
     * Vérifier l'accès à l'analytique.
     */
    abstract protected function checkAnalyticsAccess(): void;

    /**
     * Vérifier l'accès à la configuration.
     */
    abstract protected function checkConfigAccess(): void;

    /**
     * Vérifier l'accès à la lecture de conversation.
     */
    abstract protected function checkConversationAccess(): void;

    /**
     * Récupérer la couleur du module.
     */
    abstract protected function getModuleColor(): string;

    /**
     * Récupérer l'icône du module.
     */
    abstract protected function getModuleIcon(): string;

    /**
     * Récupérer le nombre de conversations actives.
     */
    abstract protected function getActiveConversationsCount(): int;

    /**
     * Récupérer le nombre de risques en attente.
     */
    abstract protected function getPendingRisksCount(): int;

    /**
     * Récupérer le nombre d'utilisateurs actifs depuis une date.
     */
    abstract protected function getActiveUsersCount(\DateTimeImmutable $since): int;

    /**
     * Récupérer les risques en attente.
     */
    abstract protected function getPendingRisks(): array;

    /**
     * Récupérer une conversation par ID.
     */
    abstract protected function getConversationById(string $id): mixed;

    /**
     * Logger l'accès sécurité (RGPD).
     */
    abstract protected function logSecurityAccess(string $conversationId, mixed $conversation, Request $request): void;

    /**
     * Récupérer le nom de la route de configuration.
     */
    abstract protected function getConfigRouteName(): string;

    /**
     * Récupérer le chemin du template.
     *
     * @param string $name Nom du template (dashboard, risks, analytics, config, conversation)
     */
    abstract protected function getTemplatePath(string $name): string;
}
