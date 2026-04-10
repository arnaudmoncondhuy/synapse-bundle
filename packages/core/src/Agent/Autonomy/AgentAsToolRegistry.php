<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Agent\Autonomy;

use ArnaudMoncondhuy\SynapseCore\Agent\AgentResolver;
use ArnaudMoncondhuy\SynapseCore\Agent\CodeAgentRegistry;
use ArnaudMoncondhuy\SynapseCore\Contract\AgentInterface;
use ArnaudMoncondhuy\SynapseCore\Contract\CallableByAgentsInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Registre des agents exposables comme tools pour d'autres agents (Chantier D).
 *
 * Découvre tous les agents code qui implémentent
 * {@see CallableByAgentsInterface} et crée à la demande un
 * {@see CallAgentTool} pour chacun. Les agents "config" (DB) ne sont pas encore
 * supportés — une extension future pourra ajouter un flag
 * `callable_by_agents` sur l'entité `SynapseAgent` pour exposer les agents DB
 * via le même mécanisme.
 *
 * ## Usage typique
 *
 * Un {@see AbstractPlannerAgent} qui démarre un run autonome appelle :
 *
 * ```php
 * $tools = $this->agentAsToolRegistry->buildCallTools($context);
 * $askOptions = $this->buildAskOptions([
 *     'tools' => array_merge($defaultTools, $tools),
 * ]);
 * ```
 *
 * Le LLM voit alors les `call_agent__*` dans sa liste de tools et peut
 * déléguer à volonté. Chaque CallAgentTool a déjà son parent context
 * injecté (`setParentContext()`), donc le tool-calling LLM dans
 * `MultiTurnExecutor` fonctionne sans modification.
 */
final class AgentAsToolRegistry
{
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly CodeAgentRegistry $codeAgentRegistry,
        private readonly AgentResolver $resolver,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Retourne tous les agents code qui implémentent CallableByAgentsInterface.
     *
     * @return array<string, AgentInterface>
     */
    public function getCallableAgents(): array
    {
        $callable = [];
        foreach ($this->codeAgentRegistry->all() as $agent) {
            if ($agent instanceof CallableByAgentsInterface) {
                $callable[$agent->getName()] = $agent;
            }
        }

        return $callable;
    }

    /**
     * Construit une liste de {@see CallAgentTool} pour tous les agents
     * callable, avec le `parentContext` déjà injecté pour le tool-calling.
     *
     * @return array<int, CallAgentTool>
     */
    public function buildCallTools(\ArnaudMoncondhuy\SynapseCore\Agent\AgentContext $parentContext): array
    {
        $tools = [];
        foreach ($this->getCallableAgents() as $agent) {
            $tool = new CallAgentTool($agent, $this->resolver, $this->logger);
            $tool->setParentContext($parentContext);
            $tools[] = $tool;
        }

        $this->logger->debug('AgentAsToolRegistry built {count} call tools for parentRunId {runId}', [
            'count' => count($tools),
            'runId' => $parentContext->getRequestId(),
        ]);

        return $tools;
    }

    /**
     * Retourne true si au moins un agent implémente CallableByAgentsInterface.
     * Permet au planner de savoir s'il vaut la peine d'enrichir sa liste.
     */
    public function hasCallableAgents(): bool
    {
        return [] !== $this->getCallableAgents();
    }
}
