<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseAdmin\Admin\Controller;

use ArnaudMoncondhuy\SynapseCore\Contract\EncryptionServiceInterface;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapsePresetRepository;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseTokenUsageRepository;
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
    /** @var float Tarif par million de tokens d'entrée (Gemini 2.5 Flash, Janvier 2026) */
    protected const PRICE_INPUT_PER_1M = 0.30;

    /** @var float Tarif par million de tokens de sortie (Gemini 2.5 Flash, Janvier 2026) */
    protected const PRICE_OUTPUT_PER_1M = 2.50;

    public function __construct(
        protected SynapseTokenUsageRepository $tokenUsageRepository,
        protected SynapsePresetRepository $synapsePresetRepository,
        protected EncryptionServiceInterface $encryption,
    ) {}

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
                'tokens_cost' => $cost,
                'users_count' => $this->getActiveUsersCount($start),
            ],
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
     *
     * @deprecated This method is kept for backward compatibility. Use PresetsController and SettingsController instead.
     */
    public function config(Request $request, EntityManagerInterface $entityManager): Response
    {
        $this->checkConfigAccess();

        // Récupérer le preset actif
        $config = $this->synapsePresetRepository->findActive();

        if ($request->isMethod('POST')) {
            // General Config
            $model = $request->request->get('gemini_model');
            if ($model) {
                $config->setModel($model);
            }

            // Safety Settings (Stored in providerOptions JSON)
            $providerOptions = $config->getProviderOptions() ?? [];
            $providerOptions['safety_settings'] = [
                'enabled' => $request->request->getBoolean('safety_enabled'),
                'default_threshold' => $request->request->get('safety_default_threshold', 'BLOCK_MEDIUM_AND_ABOVE'),
                'thresholds' => [
                    'HATE_SPEECH' => $request->request->get('safety_hate_speech', 'BLOCK_MEDIUM_AND_ABOVE'),
                    'DANGEROUS_CONTENT' => $request->request->get('safety_dangerous_content', 'BLOCK_MEDIUM_AND_ABOVE'),
                    'HARASSMENT' => $request->request->get('safety_harassment', 'BLOCK_MEDIUM_AND_ABOVE'),
                    'SEXUALLY_EXPLICIT' => $request->request->get('safety_sexually_explicit', 'BLOCK_MEDIUM_AND_ABOVE'),
                ]
            ];
            $config->setProviderOptions($providerOptions);

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
     * Vérifier l'accès à l'analytique.
     */
    abstract protected function checkAnalyticsAccess(): void;

    /**
     * Vérifier l'accès à la configuration.
     */
    abstract protected function checkConfigAccess(): void;

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
     * Récupérer le nombre d'utilisateurs actifs depuis une date.
     */
    abstract protected function getActiveUsersCount(\DateTimeImmutable $since): int;

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
