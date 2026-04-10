<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseMcp\Tool;

use ArnaudMoncondhuy\SynapseCore\Contract\PermissionCheckerInterface;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseWorkflowRunRepository;
use Mcp\Capability\Attribute\McpTool;

#[McpTool(
    name: 'inspect_workflow_run',
    description: 'Inspect a completed or failed workflow run. Shows status, step progression, input/output, tokens, duration, error details, and an attachments summary (count, mime types, sizes — raw base64 content not included to avoid response bloat). Use the workflowRunId returned by run_workflow.'
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
            'attachments' => $this->buildAttachmentsSummary($run->getGeneratedAttachments()),
            'errorMessage' => $run->getErrorMessage(),
            'totalTokens' => $run->getTotalTokens(),
            'durationSeconds' => $run->getDurationSeconds(),
            'startedAt' => $run->getStartedAt()->format('c'),
            'completedAt' => $run->getCompletedAt()?->format('c'),
            'timestamp' => (new \DateTime())->format('c'),
        ];
    }

    /**
     * Produit une vue résumée des attachments sans leur contenu base64.
     *
     * Objectif : un LLM qui inspecte un run doit **savoir** qu'un attachment
     * existe (combien, quel type, quelle taille, quel step l'a produit) sans
     * polluer la réponse MCP avec des payloads de plusieurs Mo.
     *
     * Pour récupérer le contenu binaire, passer par l'admin UI ou une route
     * dédiée (à venir Chantier H).
     *
     * @param array<int, array<string, mixed>>|null $attachments
     *
     * @return array{count: int, totalSizeBytes: int, items: array<int, array{stepName: string, stepIndex: int, mimeType: string, sizeBytes: int}>}
     */
    private function buildAttachmentsSummary(?array $attachments): array
    {
        if (null === $attachments || [] === $attachments) {
            return ['count' => 0, 'totalSizeBytes' => 0, 'items' => []];
        }

        $items = [];
        $totalSize = 0;
        foreach ($attachments as $att) {
            if (!is_array($att)) {
                continue;
            }
            $size = isset($att['size_bytes']) && is_int($att['size_bytes']) ? $att['size_bytes'] : 0;
            $totalSize += $size;
            $items[] = [
                'stepName' => (string) ($att['step_name'] ?? 'unknown'),
                'stepIndex' => isset($att['step_index']) && is_int($att['step_index']) ? $att['step_index'] : -1,
                'mimeType' => (string) ($att['mime_type'] ?? 'application/octet-stream'),
                'sizeBytes' => $size,
            ];
        }

        return [
            'count' => count($items),
            'totalSizeBytes' => $totalSize,
            'items' => $items,
        ];
    }
}
