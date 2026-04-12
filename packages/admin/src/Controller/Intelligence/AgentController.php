<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseAdmin\Controller\Intelligence;

use ArnaudMoncondhuy\SynapseCore\Agent\CodeAgentRegistry;
use ArnaudMoncondhuy\SynapseCore\AgentValidator;
use ArnaudMoncondhuy\SynapseCore\Contract\PermissionCheckerInterface;
use ArnaudMoncondhuy\SynapseCore\Engine\ModelCapabilityRegistry;
use ArnaudMoncondhuy\SynapseCore\Engine\ToolRegistry;
use ArnaudMoncondhuy\SynapseCore\Governance\PromptVersionRecorder;
use ArnaudMoncondhuy\SynapseCore\PresetValidator;
use ArnaudMoncondhuy\SynapseCore\Security\AdminSecurityTrait;
use ArnaudMoncondhuy\SynapseCore\Security\RoleProvider;
use ArnaudMoncondhuy\SynapseCore\Shared\Enum\SpendingLimitPeriod;
use ArnaudMoncondhuy\SynapseCore\Shared\Enum\SpendingLimitScope;
use ArnaudMoncondhuy\SynapseCore\Shared\Exception\CannotActivateException;
use ArnaudMoncondhuy\SynapseCore\Shared\Model\DeactivationCascade;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseAgent;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseAgentPromptVersion;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseSpendingLimit;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseAgentPromptVersionRepository;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseAgentRepository;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseModelPresetRepository;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseRagSourceRepository;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseSpendingLimitRepository;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseToneRepository;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseWorkflowRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

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
        private readonly SynapseAgentRepository $agentRepo,
        private readonly SynapseModelPresetRepository $presetRepo,
        private readonly SynapseToneRepository $toneRepo,
        private readonly SynapseSpendingLimitRepository $spendingLimitRepo,
        private readonly SynapseAgentPromptVersionRepository $promptVersionRepo,
        private readonly PromptVersionRecorder $promptVersionRecorder,
        private readonly EntityManagerInterface $em,
        private readonly PermissionCheckerInterface $permissionChecker,
        private readonly ToolRegistry $toolRegistry,
        private readonly RoleProvider $roleProvider,
        private readonly SynapseRagSourceRepository $ragSourceRepository,
        private readonly ModelCapabilityRegistry $capabilityRegistry,
        private readonly SynapseWorkflowRepository $workflowRepo,
        private readonly TranslatorInterface $translator,
        private readonly CodeAgentRegistry $codeAgentRegistry,
        private readonly AgentValidator $agentValidator,
        private readonly PresetValidator $presetValidator,
        private readonly ?CsrfTokenManagerInterface $csrfTokenManager = null,
    ) {
    }

    /**
     * 🛡️ Force la désactivation de l'agent si son preset explicite est invalide,
     * quel que soit l'état du checkbox `is_active` du formulaire. Sans ce garde
     * fou, l'admin pourrait activer un agent via le form edit alors que son
     * preset sous-jacent pointe vers un modèle/provider désactivé.
     */
    private function enforceAgentValidityBeforeFlush(SynapseAgent $agent): void
    {
        if ($this->agentValidator->isValid($agent)) {
            return;
        }

        if ($agent->isActive()) {
            $agent->setIsActive(false);
            $this->addFlash('warning', sprintf(
                'L\'agent « %s » a été forcé à l\'état inactif : %s',
                $agent->getName(),
                $this->agentValidator->getInvalidReason($agent) ?? 'preset invalide'
            ));
        }
    }

    /**
     * Ruissellement : propage la désactivation d'un agent vers ses workflows
     * si l'agent est (ou vient d'être rendu) inactif. Renvoie le cascade pour
     * que le caller puisse l'afficher APRÈS la transaction.
     */
    private function cascadeAgentDeactivation(SynapseAgent $agent): DeactivationCascade
    {
        if ($agent->isActive()) {
            return DeactivationCascade::empty();
        }

        return $this->agentRepo->deactivate($agent);
    }

    private function flashCascade(DeactivationCascade $cascade, string $because = ''): void
    {
        $suffix = '' !== $because ? ' '.$because : '';
        if (!empty($cascade->agents)) {
            $this->addFlash('warning', sprintf('Agents désactivés%s : %s', $suffix, implode(', ', $cascade->agents)));
        }
        if (!empty($cascade->workflows)) {
            $this->addFlash('warning', sprintf('Workflows désactivés%s : %s', $suffix, implode(', ', $cascade->workflows)));
        }
    }

    /**
     * Retourne uniquement les presets actuellement valides (provider configuré,
     * modèle activé, etc.). Utilisé pour peupler les dropdowns de l'édition
     * d'agent — on ne veut pas que l'admin puisse assigner un agent à un preset
     * cassé.
     *
     * @return array<int, \ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseModelPreset>
     */
    private function getSelectablePresets(?SynapseAgent $currentAgent = null): array
    {
        $result = [];
        foreach ($this->presetRepo->findAll() as $preset) {
            if ($this->presetValidator->isValid($preset)) {
                $result[] = $preset;
            }
        }

        // En édition, on conserve le preset actuellement sélectionné même s'il est
        // invalide — pour que l'admin puisse le voir/choisir d'en changer sans que
        // la dropdown ne se retrouve « vide » sur un agent hérité d'un état cassé.
        $currentPreset = $currentAgent?->getModelPreset();
        if (null !== $currentPreset && !$this->presetValidator->isValid($currentPreset)) {
            $result[] = $currentPreset;
        }

        return $result;
    }

    // ─── Index ─────────────────────────────────────────────────────────────────

    #[Route('', name: 'agents', methods: ['GET'])]
    public function index(): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);

        $agents = $this->agentRepo->findAllOrdered();

        $activePreset = $this->presetRepo->findOneBy(['isActive' => true]);

        // Agents code : on exclut ceux dont une entrée DB existe déjà (même clé)
        // car AgentResolver leur donne la priorité — ils sont déjà listés dans $agents.
        $dbAgentKeys = array_map(fn ($a) => $a->getKey(), $agents);
        $codeAgents = [];
        foreach ($this->codeAgentRegistry->all() as $agent) {
            if (in_array($agent->getName(), $dbAgentKeys, true)) {
                continue;
            }
            $namespace = (new \ReflectionClass($agent))->getNamespaceName();
            $codeAgents[] = [
                'name' => $agent->getName(),
                'label' => $agent->getLabel(),
                'description' => $agent->getDescription(),
                'source' => str_starts_with($namespace, 'ArnaudMoncondhuy\\') ? 'bundle' : 'host',
                'class' => $agent::class,
            ];
        }

        return $this->render('@Synapse/admin/intelligence/agents.html.twig', [
            'agents' => $agents,
            'code_agents' => $codeAgents,
            'model_capabilities' => $this->capabilityRegistry->getAllCapabilitiesMap(),
            'default_preset_model' => $activePreset?->getModel(),
            'agent_workflow_map' => $this->buildAgentWorkflowMap(),
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
            $this->enforceAgentValidityBeforeFlush($agent);

            // Tout le save + cascade + snapshot du prompt dans une seule
            // transaction : si un élément rate, rien n'est flushé.
            $cascade = $this->em->wrapInTransaction(function () use ($agent, $request): DeactivationCascade {
                $result = $this->cascadeAgentDeactivation($agent);
                $this->em->persist($agent);
                $this->em->flush();

                // Garde-fou #1 : snapshot initial du prompt (création = version 1).
                // Le flush ci-dessus garantit que l'agent a un ID avant la requête DQL du recorder.
                $this->promptVersionRecorder->snapshot(
                    $agent,
                    $agent->getSystemPrompt(),
                    'human:'.$this->resolveAdminIdentifier(),
                    $this->extractVersionReason($request),
                );
                $this->em->flush();

                return $result;
            });

            $this->flashCascade($cascade, sprintf('à la suite de la désactivation de l\'agent « %s »', $agent->getName()));
            $this->addFlash('success', $this->translator->trans('synapse.admin.agent.flash.created', ['name' => $agent->getName()], 'synapse_admin'));

            return $this->redirectToRoute('synapse_admin_agents');
        }

        $presets = $this->getSelectablePresets();
        $tones = $this->toneRepo->findAllOrdered();
        $availableTools = array_map(fn ($tool) => [
            'name' => $tool->getName(),
            'description' => $tool->getDescription(),
        ], $this->toolRegistry->getTools());
        $availableRagSources = array_map(fn ($s) => $s->toArray(), $this->ragSourceRepository->findActive());

        return $this->render('@Synapse/admin/intelligence/agent_edit.html.twig', [
            'agent' => $agent,
            'is_new' => true,
            'presets' => $presets,
            'tones' => $tones,
            'available_tools' => $availableTools,
            'available_rag_sources' => $availableRagSources,
            'available_roles' => $this->roleProvider->getAvailableRoles(),
            'model_capabilities' => $this->capabilityRegistry->getAllCapabilitiesMap(),
            'workflows' => $this->workflowRepo->findAllOrdered(),
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
            $this->enforceAgentValidityBeforeFlush($agent);

            $cascade = $this->em->wrapInTransaction(function () use ($agent, $request): DeactivationCascade {
                $result = $this->cascadeAgentDeactivation($agent);

                // Garde-fou #1 : snapshot du prompt s'il a effectivement changé.
                // Le recorder applique l'idempotence — pas de pollution si le save
                // du formulaire ne touche pas au prompt.
                $this->promptVersionRecorder->snapshot(
                    $agent,
                    $agent->getSystemPrompt(),
                    'human:'.$this->resolveAdminIdentifier(),
                    $this->extractVersionReason($request),
                );
                $this->em->flush();

                return $result;
            });

            $this->flashCascade($cascade, sprintf('à la suite de la désactivation de l\'agent « %s »', $agent->getName()));
            $this->addFlash('success', $this->translator->trans('synapse.admin.agent.flash.updated', ['name' => $agent->getName()], 'synapse_admin'));

            return $this->redirectToRoute('synapse_admin_agents');
        }

        $presets = $this->getSelectablePresets($agent);
        $tones = $this->toneRepo->findAllOrdered();
        $agentLimits = $this->spendingLimitRepo->findForAgent((int) $agent->getId());
        $spendingLimit = $agentLimits[0] ?? null;
        $availableTools = array_map(fn ($tool) => [
            'name' => $tool->getName(),
            'description' => $tool->getDescription(),
        ], $this->toolRegistry->getTools());
        $availableRagSources = array_map(fn ($s) => $s->toArray(), $this->ragSourceRepository->findActive());

        return $this->render('@Synapse/admin/intelligence/agent_edit.html.twig', [
            'agent' => $agent,
            'is_new' => false,
            'presets' => $presets,
            'tones' => $tones,
            'available_tools' => $availableTools,
            'available_rag_sources' => $availableRagSources,
            'available_roles' => $this->roleProvider->getAvailableRoles(),
            'spending_limit' => $spendingLimit,
            'model_capabilities' => $this->capabilityRegistry->getAllCapabilitiesMap(),
            'workflows' => $this->workflowRepo->findAllOrdered(),
            'periods' => [
                SpendingLimitPeriod::SLIDING_DAY->value => $this->translator->trans('synapse.admin.agent.limit.period.sliding_day', [], 'synapse_admin'),
                SpendingLimitPeriod::SLIDING_MONTH->value => $this->translator->trans('synapse.admin.agent.limit.period.sliding_month', [], 'synapse_admin'),
                SpendingLimitPeriod::CALENDAR_DAY->value => $this->translator->trans('synapse.admin.agent.limit.period.calendar_day', [], 'synapse_admin'),
                SpendingLimitPeriod::CALENDAR_MONTH->value => $this->translator->trans('synapse.admin.agent.limit.period.calendar_month', [], 'synapse_admin'),
            ],
        ]);
    }

    // ─── Limite de dépense (agent) ───────────────────────────────────────────

    #[Route('/{id}/limite', name: 'agents_limit', methods: ['POST'])]
    public function saveLimit(SynapseAgent $agent, Request $request): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);
        $this->validateCsrfToken($request, $this->csrfTokenManager, 'synapse_agent_limit_'.$agent->getId());

        $action = $request->request->get('action', 'save');
        $agentLimits = $this->spendingLimitRepo->findForAgent((int) $agent->getId());
        $limit = $agentLimits[0] ?? null;

        if ('delete' === $action && null !== $limit) {
            $this->em->remove($limit);
            $this->em->flush();
            $this->addFlash('success', $this->translator->trans('synapse.admin.agent.limit.flash.deleted', [], 'synapse_admin'));

            return $this->redirectToRoute('synapse_admin_agents_edit', ['id' => $agent->getId()]);
        }

        $amount = trim((string) $request->request->get('amount', ''));
        $currency = trim((string) $request->request->get('currency', 'EUR'));
        $period = $request->request->get('period');
        $name = trim((string) $request->request->get('name', ''));

        if ('' === $amount) {
            $this->addFlash('error', $this->translator->trans('synapse.admin.agent.limit.flash.amount_required', [], 'synapse_admin'));

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

        $flashKey = $isNew ? 'synapse.admin.agent.limit.flash.added' : 'synapse.admin.agent.limit.flash.updated';
        $this->addFlash('success', $this->translator->trans($flashKey, [], 'synapse_admin'));

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
        $this->validateCsrfToken($request, $this->csrfTokenManager, 'synapse_agent_toggle_'.$agent->getId());

        // La logique de lifecycle (validation, cascade) est centralisée dans
        // les méthodes activate() / deactivate() du repo.
        // L'ensemble est encapsulé dans une transaction pour rester atomique :
        // si la cascade échoue, rien n'est flushé.
        try {
            $cascade = $this->em->wrapInTransaction(function () use ($agent): DeactivationCascade {
                $result = DeactivationCascade::empty();
                if ($agent->isActive()) {
                    $result = $this->agentRepo->deactivate($agent);
                } else {
                    $this->agentRepo->activate($agent);
                }
                $this->em->flush();

                return $result;
            });
        } catch (CannotActivateException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('synapse_admin_agents');
        }

        $this->flashCascade($cascade, sprintf('à la suite de la désactivation de l\'agent « %s »', $agent->getName()));

        $stateKey = $agent->isActive() ? 'synapse.admin.agent.flash.enabled' : 'synapse.admin.agent.flash.disabled';
        $this->addFlash('success', $this->translator->trans($stateKey, ['name' => $agent->getName()], 'synapse_admin'));

        return $this->redirectToRoute('synapse_admin_agents');
    }

    // ─── Historique des prompts (Garde-fou #1) ─────────────────────────────────

    #[Route('/{id}/historique-prompt', name: 'agents_prompt_history', methods: ['GET'])]
    public function promptHistory(SynapseAgent $agent): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);

        $versions = $this->promptVersionRepo->findByAgent($agent);
        $currentPrompt = $agent->getSystemPrompt();

        return $this->render('@Synapse/admin/intelligence/agent_prompt_history.html.twig', [
            'agent' => $agent,
            'versions' => $versions,
            'current_prompt' => $currentPrompt,
        ]);
    }

    #[Route('/{id}/historique-prompt/{versionId}/restaurer', name: 'agents_prompt_restore', methods: ['POST'])]
    public function restorePromptVersion(SynapseAgent $agent, int $versionId, Request $request): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);
        $this->validateCsrfToken($request, $this->csrfTokenManager, 'synapse_agent_prompt_restore_'.$agent->getId());

        /** @var SynapseAgentPromptVersion|null $version */
        $version = $this->promptVersionRepo->find($versionId);
        if (null === $version || $version->getAgent()?->getId() !== $agent->getId()) {
            $this->addFlash('error', $this->translator->trans('synapse.admin.agent.prompt_history.flash.version_not_found', [], 'synapse_admin'));

            return $this->redirectToRoute('synapse_admin_agents_prompt_history', ['id' => $agent->getId()]);
        }

        // Snapshot d'abord le nouveau state (= contenu de la version restaurée) :
        // le recorder créera une nouvelle ligne d'historique marquée "rollback"
        // pointant vers le contenu ancien. L'historique reste strictement append-only.
        $reason = sprintf('Rollback vers la version #%d du %s', $version->getId(), $version->getCreatedAt()->format('Y-m-d H:i'));
        $this->promptVersionRecorder->snapshot(
            $agent,
            $version->getSystemPrompt(),
            'human:'.$this->resolveAdminIdentifier(),
            $reason,
        );

        // Appliquer le prompt sur l'agent.
        $agent->setSystemPrompt($version->getSystemPrompt());
        $this->em->flush();

        $this->addFlash('success', $this->translator->trans('synapse.admin.agent.prompt_history.flash.restored', ['version' => (string) $version->getId()], 'synapse_admin'));

        return $this->redirectToRoute('synapse_admin_agents_prompt_history', ['id' => $agent->getId()]);
    }

    // ─── HITL : approbation / rejet d'une version pending (Garde-fou #3) ───

    #[Route('/{id}/historique-prompt/{versionId}/approuver', name: 'agents_prompt_approve', methods: ['POST'])]
    public function approvePromptVersion(SynapseAgent $agent, int $versionId, Request $request): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);
        $this->validateCsrfToken($request, $this->csrfTokenManager, 'synapse_agent_prompt_approve_'.$agent->getId());

        /** @var SynapseAgentPromptVersion|null $version */
        $version = $this->promptVersionRepo->find($versionId);
        if (null === $version || $version->getAgent()?->getId() !== $agent->getId()) {
            $this->addFlash('error', $this->translator->trans('synapse.admin.agent.prompt_history.flash.version_not_found', [], 'synapse_admin'));

            return $this->redirectToRoute('synapse_admin_agents_prompt_history', ['id' => $agent->getId()]);
        }

        try {
            $this->promptVersionRecorder->approve($version, 'human:'.$this->resolveAdminIdentifier());
        } catch (\LogicException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('synapse_admin_agents_prompt_history', ['id' => $agent->getId()]);
        }

        // Approbation = application live du prompt sur l'agent.
        $agent->setSystemPrompt($version->getSystemPrompt());
        $this->em->flush();

        $this->addFlash('success', $this->translator->trans('synapse.admin.agent.prompt_history.flash.approved', ['version' => (string) $version->getId()], 'synapse_admin'));

        return $this->redirectToRoute('synapse_admin_agents_prompt_history', ['id' => $agent->getId()]);
    }

    #[Route('/{id}/historique-prompt/{versionId}/rejeter', name: 'agents_prompt_reject', methods: ['POST'])]
    public function rejectPromptVersion(SynapseAgent $agent, int $versionId, Request $request): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);
        $this->validateCsrfToken($request, $this->csrfTokenManager, 'synapse_agent_prompt_reject_'.$agent->getId());

        /** @var SynapseAgentPromptVersion|null $version */
        $version = $this->promptVersionRepo->find($versionId);
        if (null === $version || $version->getAgent()?->getId() !== $agent->getId()) {
            $this->addFlash('error', $this->translator->trans('synapse.admin.agent.prompt_history.flash.version_not_found', [], 'synapse_admin'));

            return $this->redirectToRoute('synapse_admin_agents_prompt_history', ['id' => $agent->getId()]);
        }

        $rejectionReasonRaw = $request->request->get('rejection_reason');
        $rejectionReason = is_string($rejectionReasonRaw) && '' !== trim($rejectionReasonRaw) ? trim($rejectionReasonRaw) : null;

        try {
            $this->promptVersionRecorder->reject($version, 'human:'.$this->resolveAdminIdentifier(), $rejectionReason);
        } catch (\LogicException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('synapse_admin_agents_prompt_history', ['id' => $agent->getId()]);
        }

        $this->em->flush();
        $this->addFlash('success', $this->translator->trans('synapse.admin.agent.prompt_history.flash.rejected', ['version' => (string) $version->getId()], 'synapse_admin'));

        return $this->redirectToRoute('synapse_admin_agents_prompt_history', ['id' => $agent->getId()]);
    }

    // ─── Suppression ───────────────────────────────────────────────────────────

    #[Route('/{id}/supprimer', name: 'agents_delete', methods: ['POST'])]
    public function delete(SynapseAgent $agent, Request $request): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);
        $this->validateCsrfToken($request, $this->csrfTokenManager, 'synapse_agent_delete_'.$agent->getId());

        if ($agent->isBuiltin()) {
            $this->addFlash('error', $this->translator->trans('synapse.admin.agent.flash.delete_builtin_error', [], 'synapse_admin'));

            return $this->redirectToRoute('synapse_admin_agents');
        }

        // Cascade : avant de supprimer l'agent, on désactive les workflows
        // qui le référencent. Même principe que la cascade sur désactivation,
        // mais déclenchée par la suppression définitive de l'entité.
        $name = $agent->getName();
        $agentKey = $agent->getKey();
        $cascade = $this->em->wrapInTransaction(function () use ($agent, $agentKey): DeactivationCascade {
            $result = $this->workflowRepo->deactivateAllByAgentKey($agentKey);
            $this->em->remove($agent);
            $this->em->flush();

            return $result;
        });

        $this->flashCascade($cascade, sprintf('à la suite de la suppression de l\'agent « %s »', $name));
        $this->addFlash('success', $this->translator->trans('synapse.admin.agent.flash.deleted', ['name' => $name], 'synapse_admin'));

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
        if (is_string($emojiVal) && '' !== trim($emojiVal)) {
            $agent->setEmoji(trim($emojiVal));
        } elseif ('' === $agent->getEmoji()) {
            $agent->setEmoji("\u{1F916}");
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
        $agent->setVisibleInChat(isset($data['visible_in_chat']));

        $sortOrder = $data['sort_order'] ?? null;
        if (is_numeric($sortOrder)) {
            $agent->setSortOrder((int) $sortOrder);
        }

        // Outils autorisés
        $toolNames = $data['allowed_tools'] ?? [];
        $agent->setAllowedToolNames(is_array($toolNames) ? $toolNames : []);

        // Sources RAG autorisées
        $ragSources = $data['allowed_rag_sources'] ?? [];
        $agent->setAllowedRagSources(is_array($ragSources) ? $ragSources : []);

        $ragMaxResults = $data['rag_max_results'] ?? null;
        if (is_numeric($ragMaxResults)) {
            $agent->setRagMaxResults(max(1, (int) $ragMaxResults));
        }

        $ragMinScore = $data['rag_min_score'] ?? null;
        if (is_numeric($ragMinScore)) {
            $agent->setRagMinScore(max(0.0, min(1.0, (float) $ragMinScore)));
        }

        // Workflow délégué
        $workflowKeyVal = $data['workflow_key'] ?? '';
        $agent->setWorkflowKey(is_string($workflowKeyVal) && '' !== trim($workflowKeyVal) ? trim($workflowKeyVal) : null);

        // Contrôle d'accès (rôles et utilisateurs autorisés)
        // Les rôles arrivent comme un tableau depuis les checkboxes
        $rolesRaw = $data['access_roles'] ?? [];
        $roles = is_array($rolesRaw) ? $rolesRaw : [];

        // Les identifiants utilisateur arrivent toujours en textarea
        $usersRaw = $data['access_users'] ?? '';
        $usersSplit = preg_split('/[\n,]+/', is_string($usersRaw) ? $usersRaw : '');
        $userIdentifiers = array_filter(
            array_map('trim', false !== $usersSplit ? $usersSplit : []),
            fn ($id) => '' !== $id
        );

        // Si les deux listes sont vides, on met null (agent public)
        if (empty($roles) && empty($userIdentifiers)) {
            $agent->setAccessControl(null);
        } else {
            $agent->setAccessControl([
                'roles' => $roles,
                'userIdentifiers' => array_values($userIdentifiers),
            ]);
        }
    }

    /**
     * Identifiant de l'admin connecté pour l'audit trail du Garde-fou #1.
     * Retombe sur `anonymous` si aucun user en session (CLI, script).
     */
    private function resolveAdminIdentifier(): string
    {
        $user = $this->getUser();

        return $user?->getUserIdentifier() ?? 'anonymous';
    }

    /**
     * Lit le champ optionnel `version_reason` soumis avec le formulaire d'édition.
     * Retourne null si vide afin de ne pas polluer la colonne `reason` de
     * `SynapseAgentPromptVersion` avec des chaînes vides.
     */
    private function extractVersionReason(Request $request): ?string
    {
        $raw = $request->request->get('version_reason');
        if (!is_string($raw)) {
            return null;
        }
        $trimmed = trim($raw);

        return '' === $trimmed ? null : $trimmed;
    }

    /**
     * Construit un map agentKey → [workflow names] pour l'affichage admin.
     *
     * @return array<string, list<string>>
     */
    private function buildAgentWorkflowMap(): array
    {
        $workflows = $this->workflowRepo->findAllOrdered();
        $map = [];

        foreach ($workflows as $workflow) {
            $steps = $workflow->getDefinition()['steps'] ?? [];
            foreach ($steps as $step) {
                $agentKey = $step['agent_name'] ?? null;
                if (null !== $agentKey) {
                    $map[$agentKey][] = $workflow->getName();
                }
            }
        }

        return $map;
    }
}
