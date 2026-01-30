<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseBundle\Tool;

use ArnaudMoncondhuy\SynapseBundle\Contract\AiToolInterface;
use ArnaudMoncondhuy\SynapseBundle\Enum\RiskCategory;
use ArnaudMoncondhuy\SynapseBundle\Enum\RiskLevel;
use ArnaudMoncondhuy\SynapseBundle\Service\Manager\ConversationManager;
use Psr\Log\LoggerInterface;

/**
 * Outil "Ange Gardien" - Signalement de risques
 *
 * Permet à l'IA de signaler silencieusement des conversations présentant
 * un risque (suicide, harcèlement, violence, etc.) sans perturber l'utilisateur.
 *
 * Le signalement est tracé pour intervention humaine ultérieure.
 */
class ReportRiskTool implements AiToolInterface
{
    public function __construct(
        private ConversationManager $conversationManager,
        private ?LoggerInterface $logger = null
    ) {
    }

    public function getName(): string
    {
        return 'report_risk';
    }

    public function getDescription(): string
    {
        return <<<DESC
        🚨 OUTIL CRITIQUE - SIGNALEMENT DE RISQUE

        Utilise cet outil IMMÉDIATEMENT si tu détectes une situation préoccupante dans la conversation :
        - Menace suicidaire ou auto-mutilation
        - Harcèlement, intimidation, violence verbale
        - Violence physique ou menaces
        - Terrorisme, radicalisation
        - Activités illégales
        - Exploitation ou abus sexuel
        - Détresse psychologique importante

        ⚠️ IMPORTANT :
        - Appelle cet outil de manière SILENCIEUSE (ne dis pas à l'utilisateur que tu signales)
        - Continue la conversation normalement après l'appel
        - Reste bienveillant et à l'écoute
        - Un humain interviendra si nécessaire

        📝 Fournis :
        - risk_level : 'WARNING' (surveillance) ou 'CRITICAL' (intervention urgente)
        - category : Type de risque (SUICIDE, HARASSMENT, VIOLENCE, TERRORISM, ILLEGAL, EXPLOITATION, DISTRESS, OTHER)
        - reason : Brève explication factuelle (1-2 phrases) de ce qui t'a alerté
        DESC;
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'risk_level' => [
                    'type' => 'string',
                    'enum' => ['WARNING', 'CRITICAL'],
                    'description' => "Niveau de risque : WARNING (surveillance recommandée) ou CRITICAL (intervention urgente nécessaire)",
                ],
                'category' => [
                    'type' => 'string',
                    'enum' => [
                        'SUICIDE',          // Menace suicidaire, auto-mutilation
                        'HARASSMENT',       // Harcèlement, intimidation
                        'VIOLENCE',         // Violence physique, menaces
                        'TERRORISM',        // Terrorisme, radicalisation
                        'ILLEGAL',          // Activités illégales
                        'EXPLOITATION',     // Exploitation, abus sexuel
                        'DISTRESS',         // Détresse psychologique
                        'OTHER',            // Autre situation préoccupante
                    ],
                    'description' => 'Catégorie de risque détecté',
                ],
                'reason' => [
                    'type' => 'string',
                    'description' => 'Brève explication factuelle (1-2 phrases) de ce qui a déclenché l\'alerte',
                ],
            ],
            'required' => ['risk_level', 'category', 'reason'],
        ];
    }

    public function execute(array $parameters): mixed
    {
        $conversation = $this->conversationManager->getCurrentConversation();

        if ($conversation === null) {
            return [
                'success' => false,
                'message' => 'No active conversation to report risk',
            ];
        }

        try {
            // Parser les paramètres
            $riskLevel = RiskLevel::from($parameters['risk_level']);
            $riskCategory = RiskCategory::from($parameters['category']);
            $reason = $parameters['reason'] ?? 'No reason provided';

            // Marquer la conversation
            $this->conversationManager->markRisk($conversation, $riskLevel, $riskCategory);

            // Logger pour audit
            if ($this->logger !== null) {
                $this->logger->warning('Risk detected in conversation', [
                    'conversation_id' => $conversation->getId(),
                    'risk_level' => $riskLevel->value,
                    'risk_category' => $riskCategory->value,
                    'reason' => $reason,
                    'owner' => $conversation->getOwner()->getIdentifier(),
                ]);
            }

            return [
                'success' => true,
                'message' => 'Risk reported successfully. Continue conversation normally.',
                'risk_level' => $riskLevel->value,
                'category' => $riskCategory->value,
            ];
        } catch (\Exception $e) {
            if ($this->logger !== null) {
                $this->logger->error('Failed to report risk', [
                    'error' => $e->getMessage(),
                    'conversation_id' => $conversation->getId(),
                ]);
            }

            return [
                'success' => false,
                'message' => 'Failed to report risk: ' . $e->getMessage(),
            ];
        }
    }
}
