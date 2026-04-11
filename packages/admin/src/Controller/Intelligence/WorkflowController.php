<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseAdmin\Controller\Intelligence;

use ArnaudMoncondhuy\SynapseCore\Agent\MultiAgent\WorkflowDefinitionValidator;
use ArnaudMoncondhuy\SynapseCore\Contract\PermissionCheckerInterface;
use ArnaudMoncondhuy\SynapseCore\Security\AdminSecurityTrait;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseWorkflow;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseAgentRepository;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseWorkflowRepository;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseWorkflowRunRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Gestion des workflows Synapse — Administration.
 *
 * Un workflow est une suite ordonnée d'étapes exécutées par des agents. Phase 7
 * fournit uniquement le stockage et l'admin CRUD : les exécutions sont read-only
 * (pas de moteur avant Phase 8). Les runs historiques survivent à la suppression
 * d'une définition via le champ dénormalisé `workflowKey`.
 *
 * Format pivot de `definition` validé ici : voir le docblock de {@see SynapseWorkflow}.
 */
#[Route('%synapse.admin_prefix%/intelligence/workflows', name: 'synapse_admin_')]
class WorkflowController extends AbstractController
{
    use AdminSecurityTrait;

    public function __construct(
        private readonly SynapseWorkflowRepository $workflowRepo,
        private readonly SynapseWorkflowRunRepository $runRepo,
        private readonly SynapseAgentRepository $agentRepo,
        private readonly EntityManagerInterface $em,
        private readonly PermissionCheckerInterface $permissionChecker,
        private readonly TranslatorInterface $translator,
        private readonly WorkflowDefinitionValidator $definitionValidator,
        private readonly ?CsrfTokenManagerInterface $csrfTokenManager = null,
    ) {
    }

    // ─── Index ─────────────────────────────────────────────────────────────────

    #[Route('', name: 'workflows', methods: ['GET'])]
    public function index(): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);

        $workflows = $this->workflowRepo->findAllOrdered();
        $ephemeralWorkflows = $this->workflowRepo->findEphemeral();

        $runsCounts = [];
        foreach ($workflows as $workflow) {
            $runsCounts[(int) $workflow->getId()] = $this->runRepo->countByStatus($workflow);
        }

        return $this->render('@Synapse/admin/intelligence/workflows.html.twig', [
            'workflows' => $workflows,
            'ephemeral_workflows' => $ephemeralWorkflows,
            'runs_counts' => $runsCounts,
        ]);
    }

    // ─── Promotion éphémère → persistant ───────────────────────────────────────

    #[Route('/{id}/promouvoir', name: 'workflows_promote', methods: ['POST'])]
    public function promote(SynapseWorkflow $workflow, Request $request): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);
        $this->validateCsrfToken($request, $this->csrfTokenManager, 'synapse_workflow_promote_'.$workflow->getId());

        if (!$workflow->isEphemeral()) {
            $this->addFlash('error', 'Ce workflow est déjà persistant.');

            return $this->redirectToRoute('synapse_admin_workflows');
        }

        $workflow->setIsEphemeral(false);
        $workflow->setRetentionUntil(null);
        $workflow->setIsActive(true); // Chantier I phase 2 : promouvoir = activer
        $this->em->flush();

        $this->addFlash('success', sprintf('Workflow « %s » promu en persistant. Tu peux maintenant l\'éditer librement.', $workflow->getName()));

        return $this->redirectToRoute('synapse_admin_workflows_edit', ['id' => $workflow->getId()]);
    }

    // ─── Rejet (Chantier I phase 2) ─────────────────────────────────────────────

    #[Route('/{id}/rejeter', name: 'workflows_reject', methods: ['POST'])]
    public function reject(SynapseWorkflow $workflow, Request $request): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);
        $this->validateCsrfToken($request, $this->csrfTokenManager, 'synapse_workflow_reject_'.$workflow->getId());

        // Rejet d'une proposition architecte : marquer pour garbage collection
        // immédiate en forçant retentionUntil à maintenant. Le GC
        // (synapse:ephemeral:gc) supprimera au prochain passage.
        // On ne delete pas ici pour laisser une trace auditable pendant
        // quelques minutes/heures avant le passage cron.
        if (!$workflow->isEphemeral()) {
            $this->addFlash('error', 'Impossible de rejeter un workflow persistant.');

            return $this->redirectToRoute('synapse_admin_workflows');
        }

        $workflow->setRetentionUntil(new \DateTimeImmutable());
        $this->em->flush();

        $this->addFlash('success', sprintf('Workflow « %s » rejeté — sera supprimé au prochain GC.', $workflow->getName()));

        // Si appelé en AJAX (depuis la sidebar), retourner une réponse JSON légère.
        if ('json' === $request->getPreferredFormat() || 'application/json' === $request->headers->get('Accept')) {
            return $this->json(['status' => 'rejected']);
        }

        return $this->redirectToRoute('synapse_admin_workflows');
    }

    // ─── Nouveau ───────────────────────────────────────────────────────────────

    #[Route('/nouveau', name: 'workflows_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);

        $workflow = new SynapseWorkflow();
        $workflow->setIsBuiltin(false);
        $errors = [];

        if ($request->isMethod('POST')) {
            $this->validateCsrfToken($request, $this->csrfTokenManager, 'synapse_workflow_edit');
            $errors = $this->applyFormData($workflow, $request->request->all());

            if ([] === $errors) {
                $this->em->persist($workflow);
                $this->em->flush();

                $this->addFlash('success', $this->translator->trans('synapse.admin.workflow.flash.created', ['name' => $workflow->getName()], 'synapse_admin'));

                return $this->redirectToRoute('synapse_admin_workflows_edit', ['id' => $workflow->getId()]);
            }
        }

        return $this->render('@Synapse/admin/intelligence/workflow_edit.html.twig', $this->buildEditContext($workflow, true, $errors, []));
    }

    // ─── Édition ───────────────────────────────────────────────────────────────

    #[Route('/{id}/editer', name: 'workflows_edit', methods: ['GET', 'POST'])]
    public function edit(SynapseWorkflow $workflow, Request $request): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);

        $errors = [];

        if ($request->isMethod('POST')) {
            $this->validateCsrfToken($request, $this->csrfTokenManager, 'synapse_workflow_edit');
            $errors = $this->applyFormData($workflow, $request->request->all());

            if ([] === $errors) {
                $this->em->flush();

                $this->addFlash('success', $this->translator->trans('synapse.admin.workflow.flash.updated', ['name' => $workflow->getName()], 'synapse_admin'));

                return $this->redirectToRoute('synapse_admin_workflows_edit', ['id' => $workflow->getId()]);
            }
        }

        $runs = $this->runRepo->findRecentForWorkflow($workflow, 10);

        return $this->render('@Synapse/admin/intelligence/workflow_edit.html.twig', $this->buildEditContext($workflow, false, $errors, $runs));
    }

    /**
     * Construit le contexte Twig pour le template workflow_edit, incluant les
     * JSON pré-encodés pour le Stimulus controller (Chantier J).
     *
     * Le builder Stimulus lit `agents_json` et `workflows_json` via
     * `data-synapse-workflow-builder-*-value` attributes. On encode les listes
     * côté PHP pour éviter toute interpolation Twig dans du JS (risque XSS
     * et lisibilité douteuse).
     *
     * @param array<string, mixed> $errors
     * @param list<\ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseWorkflowRun> $runs
     *
     * @return array<string, mixed>
     */
    private function buildEditContext(SynapseWorkflow $workflow, bool $isNew, array $errors, array $runs): array
    {
        $agents = $this->agentRepo->findAllOrdered();

        // Liste des agents sérialisée en JSON pour le controller Stimulus.
        // Champs minimums utilisés par le builder JS : key, name, emoji.
        $agentsList = array_map(static fn ($a) => [
            'key' => $a->getKey(),
            'name' => $a->getName(),
            'emoji' => $a->getEmoji(),
        ], $agents);

        // Liste des workflows actifs pour le sélecteur `sub_workflow` (Chantier J
        // partie 2). On exclut :
        //   - les workflows inactifs (ne peuvent pas être référencés au runtime)
        //   - le workflow courant (évite qu'il se référence lui-même via sub_workflow)
        //   - les éphémères (ne sont pas stables)
        $allWorkflows = $this->workflowRepo->findAllOrdered();
        $workflowsList = [];
        $currentId = $workflow->getId();
        foreach ($allWorkflows as $wf) {
            if (!$wf->isActive()) {
                continue;
            }
            if ($wf->isEphemeral()) {
                continue;
            }
            if (null !== $currentId && $wf->getId() === $currentId) {
                continue;
            }
            $workflowsList[] = [
                'key' => $wf->getWorkflowKey(),
                'name' => $wf->getName(),
            ];
        }

        return [
            'workflow' => $workflow,
            'is_new' => $isNew,
            'errors' => $errors,
            'runs' => $runs,
            'agents' => $agents,
            'agents_json' => json_encode($agentsList, \JSON_THROW_ON_ERROR),
            'workflows_json' => json_encode($workflowsList, \JSON_THROW_ON_ERROR),
        ];
    }

    // ─── Vue runs complète ─────────────────────────────────────────────────────

    #[Route('/{id}/runs', name: 'workflows_runs', methods: ['GET'])]
    public function runs(SynapseWorkflow $workflow): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);

        $runs = $this->runRepo->findRecentForWorkflow($workflow, 50);
        $counts = $this->runRepo->countByStatus($workflow);

        return $this->render('@Synapse/admin/intelligence/workflow_runs.html.twig', [
            'workflow' => $workflow,
            'runs' => $runs,
            'counts' => $counts,
        ]);
    }

    // ─── Toggle actif/inactif ──────────────────────────────────────────────────

    #[Route('/{id}/toggle', name: 'workflows_toggle', methods: ['POST'])]
    public function toggle(SynapseWorkflow $workflow, Request $request): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);
        $this->validateCsrfToken($request, $this->csrfTokenManager, 'synapse_workflow_toggle_'.$workflow->getId());

        $workflow->setIsActive(!$workflow->isActive());
        $this->em->flush();

        $stateKey = $workflow->isActive() ? 'synapse.admin.workflow.flash.enabled' : 'synapse.admin.workflow.flash.disabled';
        $this->addFlash('success', $this->translator->trans($stateKey, ['name' => $workflow->getName()], 'synapse_admin'));

        return $this->redirectToRoute('synapse_admin_workflows');
    }

    // ─── Suppression ───────────────────────────────────────────────────────────

    #[Route('/{id}/supprimer', name: 'workflows_delete', methods: ['POST'])]
    public function delete(SynapseWorkflow $workflow, Request $request): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);
        $this->validateCsrfToken($request, $this->csrfTokenManager, 'synapse_workflow_delete_'.$workflow->getId());

        if ($workflow->isBuiltin()) {
            $this->addFlash('error', $this->translator->trans('synapse.admin.workflow.flash.delete_builtin_error', [], 'synapse_admin'));

            return $this->redirectToRoute('synapse_admin_workflows');
        }

        $name = $workflow->getName();
        $this->em->remove($workflow);
        $this->em->flush();

        $this->addFlash('success', $this->translator->trans('synapse.admin.workflow.flash.deleted', ['name' => $name], 'synapse_admin'));

        return $this->redirectToRoute('synapse_admin_workflows');
    }

    // ─── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Applique les données POST sur l'entité et retourne les erreurs par champ (si présentes).
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, string> erreurs `field => message`
     */
    private function applyFormData(SynapseWorkflow $workflow, array $data): array
    {
        $errors = [];

        $keyVal = $data['workflow_key'] ?? null;
        if (is_string($keyVal)) {
            $key = trim($keyVal);
            if ('' === $key) {
                $errors['workflow_key'] = $this->translator->trans('synapse.admin.workflow.validation.key_required', [], 'synapse_admin');
            } elseif (1 !== preg_match('/^[a-z0-9_-]+$/', $key)) {
                $errors['workflow_key'] = $this->translator->trans('synapse.admin.workflow.validation.key_format', [], 'synapse_admin');
            } elseif (!$workflow->isBuiltin()) {
                $workflow->setWorkflowKey($key);
            }
        }

        $nameVal = $data['name'] ?? null;
        if (is_string($nameVal)) {
            $name = trim($nameVal);
            if ('' === $name) {
                $errors['name'] = $this->translator->trans('synapse.admin.workflow.validation.name_required', [], 'synapse_admin');
            } else {
                $workflow->setName($name);
            }
        }

        $descVal = $data['description'] ?? null;
        if (is_string($descVal)) {
            $trimmed = trim($descVal);
            $workflow->setDescription('' !== $trimmed ? $trimmed : null);
        }

        $workflow->setIsActive(isset($data['is_active']));

        $sortOrder = $data['sort_order'] ?? null;
        if (is_numeric($sortOrder)) {
            $workflow->setSortOrder((int) $sortOrder);
        }

        $definitionRaw = $data['definition'] ?? null;
        if (is_string($definitionRaw)) {
            $definitionError = $this->parseAndValidateDefinition($definitionRaw, $workflow);
            if (null !== $definitionError) {
                $errors['definition'] = $definitionError;
            }
        }

        return $errors;
    }

    /**
     * Parse et valide le JSON de définition puis l'applique à l'entité.
     * Retourne `null` en cas de succès ou le message d'erreur traduit sinon.
     */
    private function parseAndValidateDefinition(string $rawJson, SynapseWorkflow $workflow): ?string
    {
        if ('' === trim($rawJson)) {
            return $this->translator->trans('synapse.admin.workflow.validation.definition_required', [], 'synapse_admin');
        }

        try {
            $decoded = json_decode($rawJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return $this->translator->trans('synapse.admin.workflow.validation.definition_invalid_json', ['message' => $e->getMessage()], 'synapse_admin');
        }

        if (!is_array($decoded)) {
            return $this->translator->trans('synapse.admin.workflow.validation.definition_not_object', [], 'synapse_admin');
        }

        // Chantier F phase 2 : la logique de validation du pivot est extraite
        // dans le service `WorkflowDefinitionValidator`, partagé avec
        // `ArchitectProposalProcessor` pour rejeter les propositions LLM
        // malformées avant persistance.
        $error = $this->definitionValidator->validate($decoded);
        if (null !== $error) {
            return $error;
        }

        /* @var array<string, mixed> $decoded */
        $workflow->setDefinition($decoded);

        return null;
    }
}
