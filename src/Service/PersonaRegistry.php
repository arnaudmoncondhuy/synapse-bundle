<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseBundle\Service;

/**
 * Registry to manage available AI personas.
 */
class PersonaRegistry
{
    private array $personas = [];

    public function __construct(
        private string $configPath,
    ) {
        $this->loadPersonas();
    }

    private function loadPersonas(): void
    {
        if (!file_exists($this->configPath)) {
            return;
        }

        $content = file_get_contents($this->configPath);
        $data = json_decode($content, true);

        if (is_array($data)) {
            foreach ($data as $persona) {
                if (isset($persona['key'], $persona['system_prompt'])) {
                    $this->personas[$persona['key']] = $persona;
                }
            }
        }
    }

    public function getAll(): array
    {
        return $this->personas;
    }

    public function get(string $key): ?array
    {
        return $this->personas[$key] ?? null;
    }

    public function getSystemPrompt(string $key): ?string
    {
        return $this->personas[$key]['system_prompt'] ?? null;
    }
}
