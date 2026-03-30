<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseMcp\Tool;

use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseDebugLogRepository;
use Mcp\Capability\Attribute\McpTool;

#[McpTool(
    name: 'inspect_agent_debug',
    description: 'Inspect debug log for an agent execution. Shows system prompt, pipeline, tokens, and full execution trace.'
)]
class InspectAgentDebugTool
{
    public function __construct(
        private readonly SynapseDebugLogRepository $debugLogRepository,
    ) {
    }

    /** @return array<string, mixed> */
    public function __invoke(string $debugId): array
    {
        $log = $this->debugLogRepository->findByDebugId($debugId);

        if (null === $log) {
            return [
                'status' => 'error',
                'error' => "Debug log not found: $debugId",
                'timestamp' => (new \DateTime())->format('c'),
            ];
        }

        $data = $log->getData();

        return [
            'status' => 'success',
            'debugId' => $debugId,
            'createdAt' => $log->getCreatedAt()->format('c'),
            'systemPrompt' => $this->extractSystemPrompt($data),
            'history' => $data['history'] ?? [],
            'turns' => $data['turns'] ?? [],
            'usage' => $data['usage'] ?? [],
            'safetyRatings' => $data['safety_ratings'] ?? [],
            'presetConfig' => $data['preset_config'] ?? [],
            'rawRequest' => isset($data['raw_request_body']) ? 'available' : 'not-available',
            'timestamp' => (new \DateTime())->format('c'),
        ];
    }

    /** @param array<string, mixed> $data */
    private function extractSystemPrompt(array $data): ?string
    {
        $history = $data['history'] ?? [];
        if (!is_array($history) || empty($history)) {
            return null;
        }

        // Find first message with role='system'
        foreach ($history as $message) {
            if (is_array($message) && ($message['role'] ?? null) === 'system') {
                return $message['content'] ?? null;
            }
        }

        return null;
    }
}
