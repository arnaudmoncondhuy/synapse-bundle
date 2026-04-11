<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseMcp\Tool;

use ArnaudMoncondhuy\SynapseCore\Contract\PermissionCheckerInterface;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseModel;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseModelRepository;
use Mcp\Capability\Attribute\McpTool;

#[McpTool(
    name: 'list_models',
    description: 'List all LLM models known to Synapse, grouped by provider. Includes pricing (input/output/image per 1M tokens), currency, display label, enabled flag and sort order. Use providerFilter to restrict the output to a single provider slug. Read-only — to toggle a model use the admin UI.'
)]
class ListModelsTool
{
    public function __construct(
        private readonly SynapseModelRepository $modelRepository,
        private readonly PermissionCheckerInterface $permissionChecker,
    ) {
    }

    /** @return array<string, mixed> */
    public function __invoke(
        ?string $providerFilter = null,
    ): array {
        if (!$this->permissionChecker->canAccessAdmin()) {
            return [
                'status' => 'error',
                'error' => 'Access denied. Admin role required.',
                'timestamp' => (new \DateTime())->format('c'),
            ];
        }

        try {
            $grouped = $this->modelRepository->findAllGroupedByProvider();

            if (null !== $providerFilter && '' !== $providerFilter) {
                $grouped = isset($grouped[$providerFilter])
                    ? [$providerFilter => $grouped[$providerFilter]]
                    : [];
            }

            $out = [];
            $count = 0;
            foreach ($grouped as $providerName => $models) {
                $out[$providerName] = array_map(
                    static fn (SynapseModel $m): array => [
                        'modelId' => $m->getModelId(),
                        'label' => $m->getLabel(),
                        'isEnabled' => $m->isEnabled(),
                        'pricingInput' => $m->getPricingInput(),
                        'pricingOutput' => $m->getPricingOutput(),
                        'pricingOutputImage' => $m->getPricingOutputImage(),
                        'currency' => $m->getCurrency(),
                        'sortOrder' => $m->getSortOrder(),
                    ],
                    $models,
                );
                $count += count($models);
            }

            return [
                'status' => 'success',
                'count' => $count,
                'providerFilter' => $providerFilter,
                'models' => $out,
                'timestamp' => (new \DateTime())->format('c'),
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 'error',
                'error' => "Failed to list models: {$e->getMessage()}",
                'timestamp' => (new \DateTime())->format('c'),
            ];
        }
    }
}
