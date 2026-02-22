<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseBundle\Core\Event;

use ArnaudMoncondhuy\SynapseBundle\Core\Event\SynapseToolCallRequestedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Executes PHP tools/functions requested by the LLM.
 *
 * Listens to SynapseToolCallRequestedEvent and:
 * - Finds matching AiToolInterface implementation
 * - Executes tool with provided arguments
 * - Registers result on event
 */
class ToolExecutionSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private iterable $tools,
    ) {
    }

    /**
     * Décrit l'événement écouté : SynapseToolCallRequestedEvent avec priorité normale (0).
     *
     * @return array<string, array{0: string, 1: int}> Mapping : {eventClass: [methodName, priority]}
     */
    public static function getSubscribedEvents(): array
    {
        return [
            SynapseToolCallRequestedEvent::class => ['onToolCallRequested', 0],
        ];
    }

    /**
     * Exécute les outils/fonctions demandées par le LLM.
     * Parcourt chaque appel d'outil, cherche l'implémentation correspondante, l'exécute,
     * et enregistre le résultat sur l'événement pour la prochaine itération.
     *
     * @param SynapseToolCallRequestedEvent $event L'événement contenant les appels d'outils
     */
    public function onToolCallRequested(SynapseToolCallRequestedEvent $event): void
    {
        foreach ($event->getToolCalls() as $toolCall) {
            $toolName = $toolCall['name'];
            $args = $toolCall['args'] ?? [];

            $result = $this->executeTool($toolName, $args);
            $event->setToolResult($toolName, $result);
        }
    }

    /**
     * Find and execute a tool by name.
     *
     * @return mixed Tool execution result (string, array, object, or null if tool not found)
     */
    private function executeTool(string $name, array $args): mixed
    {
        foreach ($this->tools as $tool) {
            if ($tool->getName() === $name) {
                $result = $tool->execute($args);

                // Ensure result is serializable
                if (is_string($result) || is_array($result) || is_object($result)) {
                    return $result;
                }

                return (string) $result;
            }
        }

        return null;
    }
}
