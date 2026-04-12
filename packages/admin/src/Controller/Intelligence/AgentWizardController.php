<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseAdmin\Controller\Intelligence;

use ArnaudMoncondhuy\SynapseCore\Agent\Input;
use ArnaudMoncondhuy\SynapseCore\Contract\PermissionCheckerInterface;
use ArnaudMoncondhuy\SynapseCore\Engine\ModelCapabilityRegistry;
use ArnaudMoncondhuy\SynapseCore\Engine\ToolRegistry;
use ArnaudMoncondhuy\SynapseCore\Governance\AgentArchitect\AgentArchitect;
use ArnaudMoncondhuy\SynapseCore\Governance\AgentArchitect\AgentSystemPromptTemplates;
use ArnaudMoncondhuy\SynapseCore\Governance\PresetArchitect\CandidateScanner;
use ArnaudMoncondhuy\SynapseCore\Governance\PresetArchitect\HeuristicRecommender;
use ArnaudMoncondhuy\SynapseCore\Governance\PromptVersionRecorder;
use ArnaudMoncondhuy\SynapseCore\Security\AdminSecurityTrait;
use ArnaudMoncondhuy\SynapseCore\Shared\Enum\ModelRange;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseAgent;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseAgentRepository;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseModelPresetRepository;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseRagSourceRepository;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseToneRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Wizard de création d'agent — assistant guidé ou IA.
 */
#[Route('%synapse.admin_prefix%/intelligence/agents/wizard', name: 'synapse_admin_')]
class AgentWizardController extends AbstractController
{
    use AdminSecurityTrait;

    public function __construct(
        private readonly SynapseAgentRepository $agentRepo,
        private readonly SynapseModelPresetRepository $presetRepo,
        private readonly SynapseToneRepository $toneRepo,
        private readonly SynapseRagSourceRepository $ragSourceRepo,
        private readonly ToolRegistry $toolRegistry,
        private readonly ModelCapabilityRegistry $capabilityRegistry,
        private readonly AgentArchitect $architectAgent,
        private readonly CandidateScanner $candidateScanner,
        private readonly HeuristicRecommender $heuristicRecommender,
        private readonly PromptVersionRecorder $promptVersionRecorder,
        private readonly EntityManagerInterface $em,
        private readonly PermissionCheckerInterface $permissionChecker,
        private readonly CacheInterface $cache,
        private readonly ?CsrfTokenManagerInterface $csrfTokenManager = null,
    ) {
    }

    #[Route('/generate/status/{cacheKey}', name: 'agents_wizard_generate_status', methods: ['GET'])]
    public function wizardGenerateStatus(string $cacheKey): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);

        /** @var array{status: string, input: array, result: ?array, error: ?string}|null $data */
        $data = $this->cache->get($cacheKey, fn () => null);

        if (!$data) {
            return new JsonResponse(['status' => 'not_found'], 404);
        }

        if ('pending' === $data['status']) {
            set_time_limit(120);

            try {
                $aiDescription = (string) ($data['input']['ai_description'] ?? '');
                $output = $this->architectAgent->call(Input::ofStructured([
                    'action' => 'create_agent',
                    'description' => $aiDescription,
                ]));

                $proposal = $output->getData();

                if (isset($proposal['error'])) {
                    $data['status'] = 'error';
                    $data['error'] = $proposal['error'];
                } else {
                    $data['status'] = 'completed';
                    $data['result'] = $proposal;
                }
            } catch (\Throwable $e) {
                $data['status'] = 'error';
                $data['error'] = $e->getMessage();
            }

            $this->cache->delete($cacheKey);
            $this->cache->get($cacheKey, function (ItemInterface $item) use ($data): array {
                $item->expiresAfter(3600);

                return $data;
            });
        }

        if ('completed' === $data['status'] && null !== $data['result']) {
            $presetId = $data['input']['preset_id'] ?? null;

            return $this->render('@Synapse/admin/intelligence/_agent_wizard_result.html.twig',
                $this->getWizardResultData($data['result'], true, $presetId, ['function_calling']),
            );
        }

        if ('error' === $data['status']) {
            return new JsonResponse(['status' => 'error', 'error' => $data['error'] ?? 'Erreur inconnue']);
        }

        return new JsonResponse(['status' => $data['status']]);
    }

    #[Route('', name: 'agents_wizard', methods: ['GET'])]
    public function wizard(Request $request): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);

        $hasActivePreset = null !== $this->presetRepo->findOneBy(['isActive' => true]);

        return $this->render('@Synapse/admin/intelligence/agent_wizard.html.twig', [
            'has_active_preset' => $hasActivePreset,
            'preset_id' => $request->query->get('preset_id'),
            'presets' => $this->presetRepo->findAllPresets(),
            'tones' => $this->toneRepo->findAll(),
        ]);
    }

    #[Route('/generate', name: 'agents_wizard_generate', methods: ['POST'])]
    public function wizardGenerate(Request $request): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);
        $this->validateCsrfToken($request, $this->csrfTokenManager, 'synapse_agent_wizard');

        $data = $request->request->all();
        $aiMode = !empty($data['ai_mode']);
        $aiDescription = (string) ($data['ai_description'] ?? '');

        // Mode IA : async via cache + polling
        if ($aiMode && '' !== $aiDescription) {
            $cacheKey = 'synapse_wizard_agent_'.uniqid();
            $this->cache->delete($cacheKey);
            $this->cache->get($cacheKey, function (ItemInterface $item) use ($aiDescription, $data): array {
                $item->expiresAfter(3600);

                return [
                    'status' => 'pending',
                    'input' => [
                        'ai_description' => $aiDescription,
                        'preset_id' => $data['preset_id'] ?? null,
                    ],
                    'result' => null,
                    'error' => null,
                ];
            });

            return $this->render('@Synapse/admin/intelligence/agent_wizard.html.twig', [
                'has_active_preset' => true,
                'step' => 'waiting',
                'status_url' => $this->generateUrl('synapse_admin_agents_wizard_generate_status', ['cacheKey' => $cacheKey]),
            ]);
        }

        // Ce block est gardé pour compatibilité si le mode IA fallback
        if (false) {
            } catch (\Throwable $e) {
                $this->addFlash('warning', 'Mode IA indisponible : '.$e->getMessage().'. Basculement en mode guidé.');
            }
        }

        // Mode guidé : templates déterministes
        $useCase = (string) ($data['use_case'] ?? 'redaction');
        $capabilities = is_array($data['capabilities'] ?? null) ? $data['capabilities'] : [];
        $tone = (string) ($data['tone'] ?? 'professionnel');

        $proposal = AgentSystemPromptTemplates::generate($useCase, $capabilities, $tone);

        // Mapper les capabilities du wizard vers les capabilities du modèle
        $modelCapabilities = [];
        if (\in_array('tools', $capabilities, true)) {
            $modelCapabilities[] = 'function_calling';
        }
        if (\in_array('thinking', $capabilities, true)) {
            $modelCapabilities[] = 'thinking';
        }

        return $this->render('@Synapse/admin/intelligence/agent_wizard.html.twig',
            $this->getWizardResultData($proposal, false, $data['preset_id'] ?? null, $modelCapabilities),
        );
    }

    #[Route('/create', name: 'agents_wizard_create', methods: ['POST'])]
    public function wizardCreate(Request $request): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);
        $this->validateCsrfToken($request, $this->csrfTokenManager, 'synapse_agent_wizard_create');

        $data = $request->request->all();

        $agent = new SynapseAgent();
        $agent->setKey((string) ($data['key'] ?? ''));
        $agent->setName((string) ($data['name'] ?? ''));
        $agent->setEmoji((string) ($data['emoji'] ?? '🤖'));
        $agent->setDescription((string) ($data['description'] ?? ''));
        $agent->setSystemPrompt((string) ($data['system_prompt'] ?? ''));
        $agent->setIsBuiltin(false);
        $agent->setIsActive(!empty($data['activate']));

        // Preset optionnel
        $presetId = $data['model_preset_id'] ?? null;
        if (is_numeric($presetId)) {
            $preset = $this->presetRepo->find((int) $presetId);
            if (null !== $preset) {
                $agent->setModelPreset($preset);
            }
        }

        // Ton optionnel
        $toneId = $data['tone_id'] ?? null;
        if (is_numeric($toneId)) {
            $tone = $this->toneRepo->find((int) $toneId);
            if (null !== $tone) {
                $agent->setTone($tone);
            }
        }

        $this->em->persist($agent);
        $this->em->flush();

        // Snapshot du prompt pour traçabilité
        $changedBy = !empty($data['llm_assisted']) ? 'wizard:agent:ia' : 'wizard:agent:guided';
        $this->promptVersionRecorder->snapshot(
            $agent,
            $agent->getSystemPrompt(),
            $changedBy,
            'Création via l\'assistant',
            flush: true,
        );

        if ($agent->isActive()) {
            $this->addFlash('success', sprintf('Agent « %s » créé et activé.', $agent->getName()));
        } else {
            $this->addFlash('success', sprintf('Agent « %s » créé (inactif).', $agent->getName()));
        }

        return $this->redirectToRoute('synapse_admin_agents');
    }

    /**
     * Construit les données template pour l'écran de résultat, incluant le warning RGPD.
     *
     * @param array<string, mixed> $proposal
     *
     * @return array<string, mixed>
     */
    /**
     * @param string[] $requiredCapabilities Capabilities requises par l'agent (ex: ['function_calling', 'thinking'])
     */
    private function getWizardResultData(array $proposal, bool $llmAssisted, mixed $presetId, array $requiredCapabilities = []): array
    {
        $allPresets = $this->presetRepo->findAllPresets();
        $suggestedPresetId = is_numeric($presetId) ? (int) $presetId : null;
        $rgpdWarning = null;
        $rgpdNote = null;
        $autoCreatedPreset = false;

        if (null === $suggestedPresetId) {
            // Chercher le meilleur preset existant (RGPD + capabilities)
            $bestPreset = $this->findBestPreset($allPresets, $requiredCapabilities);
            $bestRgpd = null !== $bestPreset
                ? $this->capabilityRegistry->getCapabilities($bestPreset->getModel())->rgpdRisk
                : 'danger';

            // Si le meilleur preset n'est pas EU (rgpdRisk != null), tenter d'en créer un RGPD-safe
            if (null !== $bestRgpd) {
                $autoPreset = $this->autoCreatePreset($requiredCapabilities);
                if (null !== $autoPreset) {
                    $suggestedPresetId = $autoPreset->getId();
                    $autoCreatedPreset = true;
                    $allPresets = $this->presetRepo->findAllPresets();
                    $autoCaps = $this->capabilityRegistry->getCapabilities($autoPreset->getModel());

                    if (null === $autoCaps->rgpdRisk) {
                        $rgpdNote = sprintf(
                            'Preset « %s » créé automatiquement (provider européen, pas de Cloud Act).',
                            $autoPreset->getName(),
                        );
                    } else {
                        $rgpdWarning = sprintf(
                            'Preset « %s » créé automatiquement, mais aucun provider européen disponible (Cloud Act applicable).',
                            $autoPreset->getName(),
                        );
                    }
                } elseif (null !== $bestPreset) {
                    // Pas pu auto-créer → utiliser le meilleur existant avec warning
                    $suggestedPresetId = $bestPreset->getId();
                    $rgpdWarning = sprintf(
                        'Preset « %s » sélectionné (provider US, Cloud Act applicable). Pour des données sensibles, créez un preset avec un provider européen.',
                        $bestPreset->getName(),
                    );
                } else {
                    $rgpdWarning = 'Aucun preset adapté. Créez un preset manuellement.';
                }
            } else {
                // Le meilleur preset est EU — parfait
                $suggestedPresetId = $bestPreset->getId();
                $rgpdNote = sprintf(
                    'Preset « %s » pré-sélectionné (provider européen, pas de Cloud Act).',
                    $bestPreset->getName(),
                );
            }
        }

        return [
            'has_active_preset' => true,
            'step' => 'result',
            'proposal' => $proposal,
            'llm_assisted' => $llmAssisted,
            'preset_id' => $suggestedPresetId,
            'presets' => $allPresets,
            'tones' => $this->toneRepo->findAll(),
            'model_capabilities' => $this->capabilityRegistry->getAllCapabilitiesMap(),
            'rgpd_warning' => $rgpdWarning,
            'rgpd_note' => $rgpdNote,
            'auto_created_preset' => $autoCreatedPreset,
        ];
    }

    /**
     * Trouve le meilleur preset existant : text-generation + capabilities requises + meilleur RGPD.
     *
     * @param \ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseModelPreset[] $presets
     * @param string[] $requiredCapabilities
     */
    private function findBestPreset(array $presets, array $requiredCapabilities): ?\ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseModelPreset
    {
        $rgpdPriority = [null => 0, 'tolerated' => 1, 'risk' => 2, 'danger' => 3];
        $best = null;
        $bestScore = 99;

        foreach ($presets as $preset) {
            $caps = $this->capabilityRegistry->getCapabilities($preset->getModel());

            if (!$caps->supportsTextGeneration) {
                continue;
            }

            // Vérifier les capabilities requises
            $meetsRequirements = true;
            foreach ($requiredCapabilities as $req) {
                if (!$caps->supports($req)) {
                    $meetsRequirements = false;
                    break;
                }
            }
            if (!$meetsRequirements) {
                continue;
            }

            $score = $rgpdPriority[$caps->rgpdRisk] ?? 50;
            if ($score < $bestScore) {
                $bestScore = $score;
                $best = $preset;
            }
        }

        return $best;
    }

    /**
     * Crée automatiquement un preset adapté via le PresetArchitect (heuristique).
     *
     * @param string[] $requiredCapabilities
     */
    private function autoCreatePreset(array $requiredCapabilities): ?\ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseModelPreset
    {
        try {
            // Scanner avec le filtre text_generation + RGPD safe
            $candidates = $this->candidateScanner->scan(
                requiredCapability: 'text_generation',
                rgpdSensitive: true,
            );

            // Filtrer par capabilities requises
            if ([] !== $requiredCapabilities) {
                $candidates = array_values(array_filter(
                    $candidates,
                    function (array $c) use ($requiredCapabilities): bool {
                        foreach ($requiredCapabilities as $req) {
                            if (!$c['capabilities']->supports($req)) {
                                return false;
                            }
                        }

                        return true;
                    },
                ));
            }

            // Fallback : si aucun candidat RGPD-safe, elargir sans filtre RGPD
            if ([] === $candidates) {
                $candidates = $this->candidateScanner->scan(requiredCapability: 'text_generation');
                if ([] !== $requiredCapabilities) {
                    $candidates = array_values(array_filter(
                        $candidates,
                        function (array $c) use ($requiredCapabilities): bool {
                            foreach ($requiredCapabilities as $req) {
                                if (!$c['capabilities']->supports($req)) {
                                    return false;
                                }
                            }

                            return true;
                        },
                    ));
                }
            }

            if ([] === $candidates) {
                return null;
            }

            $recommendation = $this->heuristicRecommender->recommend($candidates, ModelRange::BALANCED);
            $preset = $recommendation->toPresetEntity();
            $this->em->persist($preset);
            $this->em->flush();

            return $preset;
        } catch (\Throwable) {
            return null;
        }
    }
}
