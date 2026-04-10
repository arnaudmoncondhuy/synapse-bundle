<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseMcp\Tool;

use ArnaudMoncondhuy\SynapseCore\Agent\AgentResolver;
use ArnaudMoncondhuy\SynapseCore\Contract\PermissionCheckerInterface;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseWorkflow;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseWorkflowRepository;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Capability\Attribute\McpTool;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[McpTool(
    name: 'create_sandbox_workflow',
    description: 'Create a temporary workflow that chains agents into a multi-step pipeline. The definition must follow the pivot format: {"version":1,"steps":[{"name":"...","agent_name":"...","input_mapping":{...},"output_key":"..."}],"outputs":{...}}. All referenced agent_names must be resolvable. Cleaned up by cleanup_sandbox.'
)]
class CreateSandboxWorkflowTool
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SynapseWorkflowRepository $workflowRepository,
        private readonly AgentResolver $agentResolver,
        private readonly PermissionCheckerInterface $permissionChecker,
        #[Autowire('%synapse.ephemeral.retention_days%')]
        private readonly int $retentionDays = 7,
    ) {
    }

    /** @return array<string, mixed> */
    public function __invoke(
        string $workflowKey,
        string $name,
        string $definition,
        ?string $description = null,
    ): array {
        if (!$this->permissionChecker->canAccessAdmin()) {
            return [
                'status' => 'error',
                'error' => 'Access denied. Admin role required.',
                'timestamp' => (new \DateTime())->format('c'),
            ];
        }

        if (1 !== preg_match('/^[a-z0-9_-]+$/', $workflowKey) || strlen($workflowKey) > 100) {
            return [
                'status' => 'error',
                'error' => 'Invalid workflow key format. Use lowercase alphanumeric, hyphens, underscores. Max 100 chars.',
                'timestamp' => (new \DateTime())->format('c'),
            ];
        }

        $parsed = json_decode($definition, true);
        if (!is_array($parsed)) {
            return [
                'status' => 'error',
                'error' => 'Invalid JSON definition. Must be a valid JSON object.',
                'timestamp' => (new \DateTime())->format('c'),
            ];
        }

        $validationError = $this->validateDefinition($parsed);
        if (null !== $validationError) {
            return [
                'status' => 'error',
                'error' => $validationError,
                'timestamp' => (new \DateTime())->format('c'),
            ];
        }

        if (null !== $this->workflowRepository->findByKey($workflowKey)) {
            return [
                'status' => 'error',
                'error' => sprintf('Workflow key "%s" already exists.', $workflowKey),
                'timestamp' => (new \DateTime())->format('c'),
            ];
        }

        $workflow = new SynapseWorkflow();
        $workflow->setWorkflowKey($workflowKey);
        $workflow->setName($name);
        $workflow->setDescription($description);
        $workflow->setDefinition($parsed);
        $workflow->setIsEphemeral(true);
        $workflow->setRetentionUntil(
            (new \DateTimeImmutable())->modify(sprintf('+%d days', $this->retentionDays))
        );
        $workflow->setIsBuiltin(false);
        $workflow->setIsActive(true);

        $this->entityManager->persist($workflow);
        $this->entityManager->flush();

        /** @var array<int, array<string, mixed>> $steps */
        $steps = $parsed['steps'];

        return [
            'status' => 'success',
            'workflowKey' => $workflowKey,
            'name' => $name,
            'stepsCount' => count($steps),
            'agents' => array_map(static fn (array $s): string => (string) $s['agent_name'], $steps),
            'timestamp' => (new \DateTime())->format('c'),
        ];
    }

    /**
     * @param array<string, mixed> $definition
     */
    private function validateDefinition(array $definition): ?string
    {
        $steps = $definition['steps'] ?? null;
        if (!is_array($steps) || [] === $steps) {
            return 'Definition must contain a non-empty "steps" array.';
        }

        $names = [];
        foreach ($steps as $index => $step) {
            if (!is_array($step)) {
                return sprintf('Step at index %d is not an object.', $index);
            }
            if (!isset($step['name']) || !is_string($step['name']) || '' === $step['name']) {
                return sprintf('Step at index %d has no "name".', $index);
            }
            if (!isset($step['agent_name']) || !is_string($step['agent_name']) || '' === $step['agent_name']) {
                return sprintf('Step "%s" has no "agent_name".', $step['name']);
            }
            if (in_array($step['name'], $names, true)) {
                return sprintf('Duplicate step name "%s".', $step['name']);
            }
            $names[] = $step['name'];

            if (!$this->agentResolver->has($step['agent_name'])) {
                return sprintf(
                    'Agent "%s" (referenced in step "%s") is not resolvable. Create it first with create_sandbox_agent.',
                    $step['agent_name'],
                    $step['name'],
                );
            }
        }

        return null;
    }
}
