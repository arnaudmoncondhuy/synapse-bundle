<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseMcp\Tool;

use ArnaudMoncondhuy\SynapseCore\Contract\PermissionCheckerInterface;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseWorkflowRepository;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Capability\Attribute\McpTool;

#[McpTool(
    name: 'update_workflow',
    description: 'Update an existing Synapse workflow (looked up by workflowKey). Editable fields: name, description, definition (full JSON of the steps pivot), isActive, sortOrder. The definition (if provided) must be a valid JSON object containing a non-empty `steps` array, each step having at least `name` and `type`. Parameters left null are left unchanged. The workflow version is bumped automatically when the definition changes.'
)]
class UpdateWorkflowTool
{
    public function __construct(
        private readonly SynapseWorkflowRepository $workflowRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly PermissionCheckerInterface $permissionChecker,
    ) {
    }

    /** @return array<string, mixed> */
    public function __invoke(
        string $workflowKey,
        ?string $name = null,
        ?string $description = null,
        ?string $definition = null,
        ?bool $isActive = null,
        ?int $sortOrder = null,
    ): array {
        if (!$this->permissionChecker->canAccessAdmin()) {
            return [
                'status' => 'error',
                'error' => 'Access denied. Admin role required.',
                'timestamp' => (new \DateTime())->format('c'),
            ];
        }

        try {
            $workflow = $this->workflowRepository->findByKey($workflowKey);
            if (null === $workflow) {
                return [
                    'status' => 'error',
                    'workflowKey' => $workflowKey,
                    'error' => sprintf('Workflow "%s" not found. Use list_workflows to see available keys.', $workflowKey),
                    'timestamp' => (new \DateTime())->format('c'),
                ];
            }

            $changed = [];

            if (null !== $name && '' !== $name) {
                $workflow->setName($name);
                $changed[] = 'name';
            }
            if (null !== $description) {
                $workflow->setDescription($description);
                $changed[] = 'description';
            }
            if (null !== $definition && '' !== $definition) {
                $parsed = json_decode($definition, true);
                if (!is_array($parsed)) {
                    return [
                        'status' => 'error',
                        'workflowKey' => $workflowKey,
                        'error' => 'Invalid definition JSON. Must decode to an object.',
                        'timestamp' => (new \DateTime())->format('c'),
                    ];
                }

                // Validation structurelle minimale (sans dépendre d'un validator
                // externe). Vérifie le contrat de base : steps doit exister,
                // être un array non vide, et chaque step doit avoir name + type.
                $stepsValidation = $this->validateStepsStructure($parsed);
                if (null !== $stepsValidation) {
                    return [
                        'status' => 'error',
                        'workflowKey' => $workflowKey,
                        'error' => 'Invalid workflow definition: '.$stepsValidation,
                        'timestamp' => (new \DateTime())->format('c'),
                    ];
                }

                $workflow->setDefinition($parsed);
                $changed[] = 'definition';
            }
            if (null !== $isActive) {
                $workflow->setIsActive($isActive);
                $changed[] = 'isActive';
            }
            if (null !== $sortOrder) {
                $workflow->setSortOrder($sortOrder);
                $changed[] = 'sortOrder';
            }

            if ([] === $changed) {
                return [
                    'status' => 'success',
                    'workflowKey' => $workflowKey,
                    'message' => 'No fields provided — nothing changed.',
                    'changed' => [],
                    'timestamp' => (new \DateTime())->format('c'),
                ];
            }

            $this->entityManager->flush();

            $finalDefinition = $workflow->getDefinition();
            $steps = $finalDefinition['steps'] ?? [];

            return [
                'status' => 'success',
                'workflowKey' => $workflowKey,
                'changed' => $changed,
                'workflow' => [
                    'workflowKey' => $workflow->getWorkflowKey(),
                    'name' => $workflow->getName(),
                    'description' => $workflow->getDescription(),
                    'version' => $workflow->getVersion(),
                    'isActive' => $workflow->isActive(),
                    'sortOrder' => $workflow->getSortOrder(),
                    'stepsCount' => is_array($steps) ? count($steps) : 0,
                ],
                'timestamp' => (new \DateTime())->format('c'),
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 'error',
                'workflowKey' => $workflowKey,
                'error' => "Failed to update workflow: {$e->getMessage()}",
                'timestamp' => (new \DateTime())->format('c'),
            ];
        }
    }

    /**
     * Valide la structure minimale d'une definition de workflow.
     *
     * @param array<string, mixed> $definition
     */
    private function validateStepsStructure(array $definition): ?string
    {
        $steps = $definition['steps'] ?? null;
        if (!is_array($steps)) {
            return 'definition must contain a "steps" array.';
        }
        if ([] === $steps) {
            return 'definition.steps must be a non-empty array.';
        }
        foreach ($steps as $i => $step) {
            if (!is_array($step)) {
                return sprintf('definition.steps[%d] must be an object.', $i);
            }
            if (!isset($step['name']) || !is_string($step['name']) || '' === $step['name']) {
                return sprintf('definition.steps[%d].name is required (non-empty string).', $i);
            }
            if (!isset($step['type']) || !is_string($step['type']) || '' === $step['type']) {
                return sprintf('definition.steps[%d].type is required (non-empty string).', $i);
            }
        }

        return null;
    }
}
