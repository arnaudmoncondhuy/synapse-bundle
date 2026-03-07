<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseAdmin\Controller\Intelligence;

use ArnaudMoncondhuy\SynapseCore\Contract\PermissionCheckerInterface;
use ArnaudMoncondhuy\SynapseCore\Security\AdminSecurityTrait;
use ArnaudMoncondhuy\SynapseCore\Shared\Enum\SpendingLimitPeriod;
use ArnaudMoncondhuy\SynapseCore\Shared\Enum\SpendingLimitScope;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseAgent;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseSpendingLimit;
use ArnaudMoncondhuy\SynapseCore\Engine\ToolRegistry;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseAgentRepository;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseModelPresetRepository;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseSpendingLimitRepository;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseToneRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * Gestion des agents IA — Administration Synapse.
 *
 * Un agent combine un prompt système dédié avec un preset LLM et un ton optionnels.
 * Les agents builtin fournis par le bundle ne peuvent pas être supprimés.
 */
#[Route('%synapse.admin_prefix%/intelligence/agents', name: 'synapse_admin_')]
class AgentController extends AbstractController
{
    use AdminSecurityTrait;

    public function __construct(
        private SynapseAgentRepository $agentRepo,
        private SynapseModelPresetRepository $presetRepo,
        private SynapseToneRepository $toneRepo,
        private SynapseSpendingLimitRepository $spendingLimitRepo,
        private EntityManagerInterface $em,
        private PermissionCheckerInterface $permissionChecker,
        private ToolRegistry $toolRegistry,
        private ?CsrfTokenManagerInterface $csrfTokenManager = null,
    ) {}

    // ─── Index ─────────────────────────────────────────────────────────────────

    #[Route('', name: 'agents', methods: ['GET'])]
    public function index(): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);

        $agents = $this->agentRepo->findAllOrdered();

        return $this->render('@Synapse/admin/intelligence/agents.html.twig', [
            'agents' => $agents,
        ]);
    }

    // ─── Nouveau ───────────────────────────────────────────────────────────────

    #[Route('/nouveau', name: 'agents_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);

        $agent = new SynapseAgent();
        $agent->setIsBuiltin(false);

        if ($request->isMethod('POST')) {
            $this->validateCsrfToken($request, $this->csrfTokenManager, 'synapse_agent_edit');
            $this->applyFormData($agent, $request->request->all());
            $this->em->persist($agent);
            $this->em->flush();

            $this->addFlash('success', sprintf('Agent "%s" créé avec succès.', $agent->getName()));

            return $this->redirectToRoute('synapse_admin_agents');
        }

        $presets = $this->presetRepo->findAll();
        $tones = $this->toneRepo->findAllOrdered();
        $availableTools = array_map(fn ($tool) => [
            'name' => $tool->getName(),
            'description' => $tool->getDescription(),
        ], $this->toolRegistry->getTools());

        return $this->render('@Synapse/admin/intelligence/agent_edit.html.twig', [
            'agent' => $agent,
            'is_new' => true,
            'presets' => $presets,
            'tones' => $tones,
            'available_tools' => $availableTools,
        ]);
    }

    // ─── Édition ───────────────────────────────────────────────────────────────

    #[Route('/{id}/editer', name: 'agents_edit', methods: ['GET', 'POST'])]
    public function edit(SynapseAgent $agent, Request $request): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);

        if ($request->isMethod('POST')) {
            $this->validateCsrfToken($request, $this->csrfTokenManager, 'synapse_agent_edit');
            $this->applyFormData($agent, $request->request->all());
            $this->em->flush();

            $this->addFlash('success', sprintf('Agent "%s" mis à jour.', $agent->getName()));

            return $this->redirectToRoute('synapse_admin_agents');
        }

        $presets = $this->presetRepo->findAll();
        $tones = $this->toneRepo->findAllOrdered();
        $agentLimits = $this->spendingLimitRepo->findForAgent((int) $agent->getId());
        $spendingLimit = $agentLimits[0] ?? null;
        $availableTools = array_map(fn ($tool) => [
            'name' => $tool->getName(),
            'description' => $tool->getDescription(),
        ], $this->toolRegistry->getTools());

        return $this->render('@Synapse/admin/intelligence/agent_edit.html.twig', [
            'agent' => $agent,
            'is_new' => false,
            'presets' => $presets,
            'tones' => $tones,
            'available_tools' => $availableTools,
            'spending_limit' => $spendingLimit,
            'periods' => [
                SpendingLimitPeriod::SLIDING_DAY->value => 'Glissante (4h)',
                SpendingLimitPeriod::SLIDING_MONTH->value => 'Glissante 30j',
                SpendingLimitPeriod::CALENDAR_DAY->value => 'Jour calendaire',
                SpendingLimitPeriod::CALENDAR_MONTH->value => 'Mois calendaire',
            ],
        ]);
    }

    // ─── Limite de dépense (agent) ───────────────────────────────────────────

    #[Route('/{id}/limite', name: 'agents_limit', methods: ['POST'])]
    public function saveLimit(SynapseAgent $agent, Request $request): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);
        $this->validateCsrfToken($request, $this->csrfTokenManager, 'synapse_agent_limit_' . $agent->getId());

        $action = $request->request->get('action', 'save');
        $agentLimits = $this->spendingLimitRepo->findForAgent((int) $agent->getId());
        $limit = $agentLimits[0] ?? null;

        if ('delete' === $action && null !== $limit) {
            $this->em->remove($limit);
            $this->em->flush();
            $this->addFlash('success', 'Limite de dépense supprimée pour cet agent.');

            return $this->redirectToRoute('synapse_admin_agents_edit', ['id' => $agent->getId()]);
        }

        $amount = trim((string) $request->request->get('amount', ''));
        $currency = trim((string) $request->request->get('currency', 'EUR'));
        $period = $request->request->get('period');
        $name = trim((string) $request->request->get('name', ''));

        if ('' === $amount) {
            $this->addFlash('error', 'Le montant est requis.');

            return $this->redirectToRoute('synapse_admin_agents_edit', ['id' => $agent->getId()]);
        }

        $isNew = (null === $limit);
        if (null === $limit) {
            $limit = new SynapseSpendingLimit();
            $limit->setScope(SpendingLimitScope::AGENT);
            $limit->setScopeId((string) $agent->getId());
            $this->em->persist($limit);
        }

        $limit->setAmount($amount);
        $limit->setCurrency('' !== $currency ? $currency : 'EUR');
        $limit->setPeriod($this->parsePeriod(is_string($period) ? $period : null));
        $limit->setName('' !== $name ? $name : null);
        $this->em->flush();

        $this->addFlash('success', $isNew ? 'Limite de dépense ajoutée pour cet agent.' : 'Limite de dépense mise à jour.');

        return $this->redirectToRoute('synapse_admin_agents_edit', ['id' => $agent->getId()]);
    }

    private function parsePeriod(?string $period): SpendingLimitPeriod
    {
        if (null !== $period && in_array($period, ['sliding_day', 'sliding_month', 'calendar_day', 'calendar_month'], true)) {
            return SpendingLimitPeriod::from($period);
        }

        return SpendingLimitPeriod::CALENDAR_MONTH;
    }

    // ─── Toggle actif/inactif ──────────────────────────────────────────────────

    #[Route('/{id}/toggle', name: 'agents_toggle', methods: ['POST'])]
    public function toggle(SynapseAgent $agent, Request $request): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);
        $this->validateCsrfToken($request, $this->csrfTokenManager, 'synapse_agent_toggle_' . $agent->getId());

        $agent->setIsActive(!$agent->isActive());
        $this->em->flush();

        $state = $agent->isActive() ? 'activé' : 'désactivé';
        $this->addFlash('success', sprintf('Agent "%s" %s.', $agent->getName(), $state));

        return $this->redirectToRoute('synapse_admin_agents');
    }

    // ─── Suppression ───────────────────────────────────────────────────────────

    #[Route('/{id}/supprimer', name: 'agents_delete', methods: ['POST'])]
    public function delete(SynapseAgent $agent, Request $request): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);
        $this->validateCsrfToken($request, $this->csrfTokenManager, 'synapse_agent_delete_' . $agent->getId());

        if ($agent->isBuiltin()) {
            $this->addFlash('error', 'Les agents intégrés au bundle ne peuvent pas être supprimés.');

            return $this->redirectToRoute('synapse_admin_agents');
        }

        $name = $agent->getName();
        $this->em->remove($agent);
        $this->em->flush();

        $this->addFlash('success', sprintf('Agent "%s" supprimé.', $name));

        return $this->redirectToRoute('synapse_admin_agents');
    }

    // ─── Helpers ───────────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $data
     */
    private function applyFormData(SynapseAgent $agent, array $data): void
    {
        $keyVal = $data['key'] ?? null;
        if (is_string($keyVal) && !$agent->isBuiltin()) {
            $agent->setKey(trim($keyVal));
        }

        $emojiVal = $data['emoji'] ?? null;
        if (is_string($emojiVal)) {
            $agent->setEmoji(trim($emojiVal));
        }

        $nameVal = $data['name'] ?? null;
        if (is_string($nameVal)) {
            $agent->setName(trim($nameVal));
        }

        $descVal = $data['description'] ?? null;
        if (is_string($descVal)) {
            $agent->setDescription(trim($descVal));
        }

        $promptVal = $data['system_prompt'] ?? null;
        if (is_string($promptVal)) {
            $agent->setSystemPrompt(trim($promptVal));
        }

        // Set model preset if provided, otherwise null
        $modelPresetId = $data['model_preset_id'] ?? null;
        if (!empty($modelPresetId) && (is_string($modelPresetId) || is_int($modelPresetId))) {
            $preset = $this->presetRepo->find((int) $modelPresetId);
            $agent->setModelPreset($preset);
        } else {
            $agent->setModelPreset(null);
        }

        // Set tone if provided, otherwise null
        $toneId = $data['tone_id'] ?? null;
        if (!empty($toneId) && (is_string($toneId) || is_int($toneId))) {
            $tone = $this->toneRepo->find((int) $toneId);
            $agent->setTone($tone);
        } else {
            $agent->setTone(null);
        }

        $agent->setIsActive(isset($data['is_active']));

        $sortOrder = $data['sort_order'] ?? null;
        if (is_numeric($sortOrder)) {
            $agent->setSortOrder((int) $sortOrder);
        }

        // Outils autorisés
        $toolNames = $data['allowed_tools'] ?? [];
        $agent->setAllowedToolNames(is_array($toolNames) ? $toolNames : []);
    }
}
