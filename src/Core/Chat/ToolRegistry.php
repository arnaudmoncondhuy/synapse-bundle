<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseBundle\Core\Chat;

use ArnaudMoncondhuy\SynapseBundle\Contract\AiToolInterface;

/**
 * Registre centralisé pour tous les outils (Tools) IA exposés.
 */
class ToolRegistry
{
    /** @var AiToolInterface[] */
    private array $tools = [];

    /**
     * @param iterable<AiToolInterface> $tools
     */
    public function __construct(iterable $tools)
    {
        foreach ($tools as $tool) {
            $this->tools[$tool->getName()] = $tool;
        }
    }

    /**
     * Retourne tous les outils enregistrés.
     *
     * @return AiToolInterface[]
     */
    public function getTools(): array
    {
        return $this->tools;
    }

    /**
     * Récupère un outil par son nom.
     */
    public function get(string $name): ?AiToolInterface
    {
        return $this->tools[$name] ?? null;
    }

    /**
     * Vérifie si un outil existe.
     */
    public function has(string $name): bool
    {
        return isset($this->tools[$name]);
    }

    /**
     * Retourne les définitions des outils au format standard (OpenAI-like).
     * Prêt à être envoyé au LLM via ChatService.
     *
     * @return array<int, array{name: string, description: string, parameters: array}>
     */
    public function getDefinitions(): array
    {
        $definitions = [];
        foreach ($this->tools as $tool) {
            $definitions[] = [
                'name' => $tool->getName(),
                'description' => $tool->getDescription(),
                'parameters' => $tool->getInputSchema(),
            ];
        }

        return $definitions;
    }
}
