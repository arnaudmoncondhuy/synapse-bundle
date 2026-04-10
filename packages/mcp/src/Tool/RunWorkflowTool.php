<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseMcp\Tool;

use ArnaudMoncondhuy\SynapseCore\Agent\AgentResolver;
use ArnaudMoncondhuy\SynapseCore\Agent\Input;
use ArnaudMoncondhuy\SynapseCore\Agent\MultiAgent\Exception\WorkflowExecutionException;
use ArnaudMoncondhuy\SynapseCore\Agent\MultiAgent\WorkflowRunner;
use ArnaudMoncondhuy\SynapseCore\Contract\PermissionCheckerInterface;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseWorkflowRepository;
use Mcp\Capability\Attribute\McpTool;

#[McpTool(
    name: 'run_workflow',
    description: 'Execute a workflow via WorkflowRunner. Sync mode (default): waits for the run to complete and returns outputs + tokens + cost. Async mode (async=true): dispatches via Symfony Messenger, returns immediately with a runId in PENDING status — poll via inspect_workflow_run to follow progress. Use async for long-running workflows (>30s) that would block the MCP response.'
)]
class RunWorkflowTool
{
    public function __construct(
        private readonly WorkflowRunner $workflowRunner,
        private readonly SynapseWorkflowRepository $workflowRepository,
        private readonly AgentResolver $agentResolver,
        private readonly PermissionCheckerInterface $permissionChecker,
    ) {
    }

    /** @return array<string, mixed> */
    public function __invoke(
        string $workflowKey,
        ?string $inputs = null,
        ?string $message = null,
        ?bool $async = false,
    ): array {
        if (!$this->permissionChecker->canAccessAdmin()) {
            return [
                'status' => 'error',
                'error' => 'Access denied. Admin role required.',
                'timestamp' => (new \DateTime())->format('c'),
            ];
        }

        $workflow = $this->workflowRepository->findByKey($workflowKey);
        if (null === $workflow) {
            return [
                'status' => 'error',
                'workflowKey' => $workflowKey,
                'error' => sprintf('Workflow "%s" not found. Use create_sandbox_workflow to create one.', $workflowKey),
                'timestamp' => (new \DateTime())->format('c'),
            ];
        }

        if (!$workflow->isActive()) {
            return [
                'status' => 'error',
                'workflowKey' => $workflowKey,
                'error' => sprintf('Workflow "%s" is not active.', $workflowKey),
                'timestamp' => (new \DateTime())->format('c'),
            ];
        }

        $input = $this->buildInput($inputs, $message);

        // Chantier G : mode async — dispatch via Messenger, retour immédiat.
        if (true === $async) {
            try {
                $run = $this->workflowRunner->runAsync($workflow, $input, []);
            } catch (\Throwable $e) {
                return [
                    'status' => 'error',
                    'workflowKey' => $workflowKey,
                    'error' => sprintf('Async dispatch failed: %s', $e->getMessage()),
                    'timestamp' => (new \DateTime())->format('c'),
                ];
            }

            return [
                'status' => 'success',
                'mode' => 'async',
                'workflowKey' => $workflowKey,
                'workflowRunId' => $run->getWorkflowRunId(),
                'runStatus' => $run->getStatus()->value,
                'hint' => 'Poll via inspect_workflow_run to follow progress. A worker must be running: `bin/console messenger:consume synapse_async`.',
                'timestamp' => (new \DateTime())->format('c'),
            ];
        }

        // Mode sync (défaut)
        $context = $this->agentResolver->createRootContext(origin: 'mcp');

        try {
            $output = $this->workflowRunner->run($workflow, $input, ['context' => $context]);
        } catch (WorkflowExecutionException $e) {
            return [
                'status' => 'error',
                'workflowKey' => $workflowKey,
                'error' => $e->getMessage(),
                'timestamp' => (new \DateTime())->format('c'),
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 'error',
                'workflowKey' => $workflowKey,
                'error' => sprintf('Unexpected error: %s', $e->getMessage()),
                'timestamp' => (new \DateTime())->format('c'),
            ];
        }

        return [
            'status' => 'success',
            'mode' => 'sync',
            'workflowKey' => $workflowKey,
            'workflowRunId' => $output->getMetadata()['workflow_run_id'] ?? null,
            'stepsExecuted' => $output->getMetadata()['steps_executed'] ?? null,
            'outputs' => $output->getData(),
            'answer' => $output->getAnswer(),
            'usage' => $output->getUsage(),
            'timestamp' => (new \DateTime())->format('c'),
        ];
    }

    private function buildInput(?string $inputs, ?string $message): Input
    {
        if (null !== $inputs && '' !== $inputs) {
            $parsed = json_decode($inputs, true);
            if (is_array($parsed)) {
                return Input::ofStructured($parsed);
            }
        }

        return Input::ofMessage($message ?? '');
    }
}
