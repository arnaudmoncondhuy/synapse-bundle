<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseMcp\Tool;

use ArnaudMoncondhuy\SynapseCore\Agent\AbstractAgent;
use ArnaudMoncondhuy\SynapseCore\Agent\CodeAgentRegistry;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseAgentRepository;
use Mcp\Capability\Attribute\McpTool;

#[McpTool(
    name: 'list_agents',
    description: 'List all available Synapse agents (DB + code) with their configuration. Use includeSandbox=true to also see temporary sandbox agents.'
)]
class ListAgentsTool
{
    public function __construct(
        private readonly SynapseAgentRepository $agentRepository,
        private readonly CodeAgentRegistry $codeAgentRegistry,
    ) {
    }

    /** @return array<string, mixed> */
    public function __invoke(
        ?bool $includeSandbox = false,
    ): array {
        $dbAgents = $includeSandbox
            ? $this->agentRepository->findAll()
            : $this->agentRepository->findAllOrdered();

        $dbAgentKeys = array_map(static fn ($a) => $a->getKey(), $dbAgents);

        $dbList = array_map(static fn ($agent) => [
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
            'source' => 'db',
        ], $dbAgents);

        // Agents code non shadowed par un agent DB
        $codeList = [];
        foreach ($this->codeAgentRegistry->all() as $agent) {
            if (in_array($agent->getName(), $dbAgentKeys, true)) {
                continue;
            }
            $codeList[] = [
                'key' => $agent->getName(),
                'name' => $agent->getLabel(),
                'description' => $agent->getDescription(),
                'systemPrompt' => $agent instanceof AbstractAgent ? ('' !== $agent->getSystemPrompt() ? '(defined)' : '(orchestrator)') : '(raw)',
                'presetKey' => $agent instanceof AbstractAgent ? $agent->getPresetKey() : null,
                'allowedTools' => $agent instanceof AbstractAgent ? $agent->getAllowedToolNames() : [],
                'isActive' => true,
                'source' => 'code',
            ];
        }

        $all = array_merge($dbList, $codeList);

        return [
            'status' => 'success',
            'count' => count($all),
            'agents' => $all,
            'timestamp' => (new \DateTime())->format('c'),
        ];
    }
}
