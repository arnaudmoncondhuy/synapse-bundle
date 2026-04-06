<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseMcp\Tool;

use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseAgentRepository;
use Mcp\Capability\Attribute\McpTool;

#[McpTool(
    name: 'list_agents',
    description: 'List all available Synapse agents with their configuration (key, name, description, preset, tools, status). Use includeSandbox=true to also see temporary sandbox agents.'
)]
class ListAgentsTool
{
    public function __construct(
        private readonly SynapseAgentRepository $agentRepository,
    ) {
    }

    /** @return array<string, mixed> */
    public function __invoke(
        ?bool $includeSandbox = false,
    ): array {
        $agents = $includeSandbox
            ? $this->agentRepository->findAll()
            : $this->agentRepository->findAllOrdered();

        return [
            'status' => 'success',
            'count' => count($agents),
            'agents' => array_map(static fn ($agent) => [
                'key' => $agent->getKey(),
                'name' => $agent->getName(),
                'description' => $agent->getDescription(),
                'modelPreset' => $agent->getModelPreset()?->getName(),
                'model' => $agent->getModelPreset()?->getModel(),
                'tone' => $agent->getTone()?->getKey(),
                'allowedTools' => $agent->getAllowedToolNames(),
                'isActive' => $agent->isActive(),
                'isBuiltin' => $agent->isBuiltin(),
                'isPublic' => $agent->isPublic(),
                'isSandbox' => $agent->isSandbox(),
            ], $agents),
            'timestamp' => (new \DateTime())->format('c'),
        ];
    }
}
