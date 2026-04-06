<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseAdmin\Controller\Intelligence;

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
        private readonly ?CsrfTokenManagerInterface $csrfTokenManager = null,
    ) {
    }

    // ─── Index ─────────────────────────────────────────────────────────────────

    #[Route('', name: 'workflows', methods: ['GET'])]
    public function index(): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);

        $workflows = $this->workflowRepo->findAllOrdered();

        $runsCounts = [];
        foreach ($workflows as $workflow) {
            $runsCounts[(int) $workflow->getId()] = $this->runRepo->countByStatus($workflow);
        }

        return $this->render('@Synapse/admin/intelligence/workflows.html.twig', [
            'workflows' => $workflows,
            'runs_counts' => $runsCounts,
        ]);
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

        return $this->render('@Synapse/admin/intelligence/workflow_edit.html.twig', [
            'workflow' => $workflow,
            'is_new' => true,
            'errors' => $errors,
            'runs' => [],
            'agents' => $this->agentRepo->findAllOrdered(),
        ]);
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

        return $this->render('@Synapse/admin/intelligence/workflow_edit.html.twig', [
            'workflow' => $workflow,
            'is_new' => false,
            'errors' => $errors,
            'runs' => $runs,
            'agents' => $this->agentRepo->findAllOrdered(),
        ]);
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

        $error = $this->validatePivotStructure($decoded);
        if (null !== $error) {
            return $error;
        }

        /* @var array<string, mixed> $decoded */
        $workflow->setDefinition($decoded);

        return null;
    }

    /**
     * Valide le format pivot : `steps` non-vide, chaque step avec `name`+`agent_name`,
     * noms uniques, références `$.steps.NAME.*` cohérentes.
     *
     * @param array<string, mixed> $definition
     */
    private function validatePivotStructure(array $definition): ?string
    {
        if (!isset($definition['steps']) || !is_array($definition['steps'])) {
            return $this->translator->trans('synapse.admin.workflow.validation.steps_missing', [], 'synapse_admin');
        }

        $steps = $definition['steps'];
        if ([] === $steps) {
            return $this->translator->trans('synapse.admin.workflow.validation.steps_empty', [], 'synapse_admin');
        }

        $names = [];
        foreach ($steps as $index => $step) {
            if (!is_array($step)) {
                return $this->translator->trans('synapse.admin.workflow.validation.step_not_object', ['index' => (string) $index], 'synapse_admin');
            }
            $name = $step['name'] ?? null;
            if (!is_string($name) || '' === $name) {
                return $this->translator->trans('synapse.admin.workflow.validation.step_name_missing', ['index' => (string) $index], 'synapse_admin');
            }
            $agentName = $step['agent_name'] ?? null;
            if (!is_string($agentName) || '' === $agentName) {
                return $this->translator->trans('synapse.admin.workflow.validation.step_agent_missing', ['name' => $name], 'synapse_admin');
            }
            if (in_array($name, $names, true)) {
                return $this->translator->trans('synapse.admin.workflow.validation.step_duplicate_name', ['name' => $name], 'synapse_admin');
            }
            $names[] = $name;
        }

        // Références croisées `$.steps.NAME.*` dans input_mapping / outputs
        $referencedNames = [];
        foreach ($steps as $step) {
            if (!is_array($step)) {
                continue;
            }
            $mapping = $step['input_mapping'] ?? [];
            if (is_array($mapping)) {
                foreach ($mapping as $path) {
                    if (is_string($path)) {
                        $ref = $this->extractStepRef($path);
                        if (null !== $ref) {
                            $referencedNames[] = $ref;
                        }
                    }
                }
            }
        }
        $outputs = $definition['outputs'] ?? [];
        if (is_array($outputs)) {
            foreach ($outputs as $path) {
                if (is_string($path)) {
                    $ref = $this->extractStepRef($path);
                    if (null !== $ref) {
                        $referencedNames[] = $ref;
                    }
                }
            }
        }
        foreach ($referencedNames as $ref) {
            if (!in_array($ref, $names, true)) {
                return $this->translator->trans('synapse.admin.workflow.validation.dangling_reference', ['name' => $ref], 'synapse_admin');
            }
        }

        return null;
    }

    /**
     * Extrait le nom de step référencé dans une expression `$.steps.NAME.xxx`.
     * Retourne null si l'expression ne référence pas un step.
     */
    private function extractStepRef(string $path): ?string
    {
        if (1 === preg_match('/^\$\.steps\.([a-zA-Z0-9_-]+)/', $path, $matches)) {
            return $matches[1];
        }

        return null;
    }
}
