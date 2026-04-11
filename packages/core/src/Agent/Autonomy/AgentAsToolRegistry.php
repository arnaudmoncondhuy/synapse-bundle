<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Agent\Autonomy;

use ArnaudMoncondhuy\SynapseCore\Agent\AgentContext;
use ArnaudMoncondhuy\SynapseCore\Agent\AgentResolver;
use ArnaudMoncondhuy\SynapseCore\Agent\CodeAgentRegistry;
use ArnaudMoncondhuy\SynapseCore\Contract\AgentInterface;
use ArnaudMoncondhuy\SynapseCore\Contract\CallableByAgentsInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

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

    /**
     * @param array<int, string> $configuredCallableAgentKeys Liste de clés
     *     déclarées dans `synapse.autonomy.callable_agents`. Alternative
     *     déclarative au marker `CallableByAgentsInterface` — permet
     *     d'exposer des agents DB comme délégables sans toucher à leur code.
     */
    public function __construct(
        private readonly CodeAgentRegistry $codeAgentRegistry,
        private readonly AgentResolver $resolver,
        ?LoggerInterface $logger = null,
        #[Autowire('%synapse.autonomy.callable_agents%')]
        private readonly array $configuredCallableAgentKeys = [],
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Retourne tous les agents exposables comme tools. Deux sources :
     *
     * 1. **Via marker** : agents code qui implémentent `CallableByAgentsInterface`
     *    (opt-in explicite au niveau du code de l'agent).
     * 2. **Via config** : clés listées dans `synapse.autonomy.callable_agents`
     *    résolues via `AgentResolver` (permet d'exposer aussi bien des
     *    agents DB que des agents code sans ajout de marker).
     *
     * Les deux sources sont mergées ; si une clé est présente dans les
     * deux, le marker wins pour préserver l'instance code concrète (qui
     * porte potentiellement sa propre logique `execute()`).
     *
     * @return array<string, AgentInterface>
     */
    public function getCallableAgents(): array
    {
        $callable = [];

        // Source 1 : marker interface sur les agents code
        foreach ($this->codeAgentRegistry->all() as $agent) {
            if ($agent instanceof CallableByAgentsInterface) {
                $callable[$agent->getName()] = $agent;
            }
        }

        // Source 2 : configuration déclarative — résoudre via AgentResolver
        // (qui supporte les agents DB ET les agents code). Utilise un root
        // context jetable pour la résolution — le vrai parent context est
        // injecté plus tard via `CallAgentTool::setParentContext()`.
        $resolveContext = $this->resolver->createRootContext(origin: 'registry_lookup');
        foreach ($this->configuredCallableAgentKeys as $key) {
            if (!is_string($key) || '' === $key) {
                continue;
            }
            if (isset($callable[$key])) {
                continue; // Marker wins
            }
            if (!$this->resolver->has($key)) {
                $this->logger->warning('AgentAsToolRegistry: configured callable agent "{key}" is not resolvable (check synapse.autonomy.callable_agents and that the agent exists).', ['key' => $key]);
                continue;
            }
            try {
                $callable[$key] = $this->resolver->resolve($key, $resolveContext);
            } catch (\Throwable $e) {
                $this->logger->warning('AgentAsToolRegistry: failed to resolve configured callable agent "{key}": {message}', [
                    'key' => $key,
                    'message' => $e->getMessage(),
                ]);
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
    public function buildCallTools(AgentContext $parentContext): array
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
     * Retourne true si au moins un agent est exposable comme tool.
     */
    public function hasCallableAgents(): bool
    {
        return [] !== $this->getCallableAgents();
    }
}
