<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseMcp\Tool;

use ArnaudMoncondhuy\SynapseCore\Contract\PermissionCheckerInterface;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseWorkflowRunRepository;
use Mcp\Capability\Attribute\McpTool;

#[McpTool(
    name: 'inspect_workflow_run',
    description: 'Inspect a completed or failed workflow run. Shows status, step progression, input/output, tokens, duration, and error details. Use the workflowRunId returned by run_workflow.'
)]
class InspectWorkflowRunTool
{
    public function __construct(
        private readonly SynapseWorkflowRunRepository $runRepository,
        private readonly PermissionCheckerInterface $permissionChecker,
    ) {
    }

    /** @return array<string, mixed> */
    public function __invoke(string $workflowRunId): array
    {
        if (!$this->permissionChecker->canAccessAdmin()) {
            return [
                'status' => 'error',
                'error' => 'Access denied. Admin role required.',
                'timestamp' => (new \DateTime())->format('c'),
            ];
        }

        $run = $this->runRepository->findByWorkflowRunId($workflowRunId);

        if (null === $run) {
            return [
                'status' => 'error',
                'error' => sprintf('Workflow run "%s" not found.', $workflowRunId),
                'timestamp' => (new \DateTime())->format('c'),
            ];
        }

        return [
            'status' => 'success',
            'workflowRunId' => $run->getWorkflowRunId(),
            'workflowKey' => $run->getWorkflowKey(),
            'workflowVersion' => $run->getWorkflowVersion(),
            'runStatus' => $run->getStatus()->value,
            'currentStepIndex' => $run->getCurrentStepIndex(),
            'stepsCount' => $run->getStepsCount(),
            'input' => $run->getInput(),
            'output' => $run->getOutput(),
            'errorMessage' => $run->getErrorMessage(),
            'totalTokens' => $run->getTotalTokens(),
            'durationSeconds' => $run->getDurationSeconds(),
            'startedAt' => $run->getStartedAt()->format('c'),
            'completedAt' => $run->getCompletedAt()?->format('c'),
            'timestamp' => (new \DateTime())->format('c'),
        ];
    }
}
