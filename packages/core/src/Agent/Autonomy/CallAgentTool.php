<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Agent\Autonomy;

use ArnaudMoncondhuy\SynapseCore\Agent\AgentContext;
use ArnaudMoncondhuy\SynapseCore\Agent\AgentResolver;
use ArnaudMoncondhuy\SynapseCore\Agent\Input;
use ArnaudMoncondhuy\SynapseCore\Contract\AgentInterface;
use ArnaudMoncondhuy\SynapseCore\Contract\AiToolInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Tool dynamique qui expose un {@see AgentInterface} comme tool LLM (Chantier D).
 *
 * Chaque instance wrappe **un** agent spécifique. Le nom du tool est calculé
 * automatiquement à partir du nom de l'agent : `call_agent__<agent_key>`.
 * Le description et l'input schema sont dérivés de l'agent lui-même.
 *
 * L'instance n'est **pas** un service Symfony — elle est créée à la volée par
 * le {@see AgentAsToolRegistry} pour chaque agent marqué
 * {@see \ArnaudMoncondhuy\SynapseCore\Contract\CallableByAgentsInterface}.
 *
 * ## Flow d'exécution
 *
 * 1. Le LLM appelle `call_agent__web_search({"query": "..."})`
 * 2. `CallAgentTool::execute()` construit un `Input::ofStructured(params)`
 * 3. Il résout l'agent cible via `AgentResolver::resolve($agentKey, $childContext)`
 * 4. Il appelle `$agent->call($input, ['context' => $childContext])`
 * 5. Il sérialise l'Output (answer + data + usage) en array, retourné au LLM
 *
 * ## Contexte enfant
 *
 * Le contexte parent (celui du planner appelant) doit être fourni via
 * `setParentContext()` avant chaque exécution (typiquement par le planner
 * juste avant de lancer le multi-turn). C'est un état mutable, mais strictement
 * local à une exécution — le tool est une factory légère, pas un singleton
 * long-vivant.
 *
 * Sans parent context, `execute()` lève une `LogicException`.
 */
final class CallAgentTool implements AiToolInterface
{
    private ?AgentContext $parentContext = null;
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly AgentInterface $agent,
        private readonly AgentResolver $resolver,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function getName(): string
    {
        return 'call_agent__'.$this->agent->getName();
    }

    public function getLabel(): string
    {
        return sprintf('↳ %s', $this->agent->getLabel());
    }

    public function getDescription(): string
    {
        return sprintf(
            'Délègue à l\'agent "%s" : %s. Utilise cet outil quand la tâche nécessite la compétence spécifique de cet agent. Retourne la réponse textuelle + données structurées.',
            $this->agent->getName(),
            $this->agent->getDescription(),
        );
    }

    public function getInputSchema(): array
    {
        // Schéma minimal générique : un champ `message` (string) + un champ
        // `structured_input` (object libre). Les agents plus sophistiqués
        // peuvent à terme déclarer leur propre schéma via une nouvelle méthode
        // d'interface (Chantier D phase 2).
        return [
            'type' => 'object',
            'properties' => [
                'message' => [
                    'type' => 'string',
                    'description' => 'Message texte à passer à l\'agent délégué. Utilise ce champ pour une invocation simple en langage naturel.',
                ],
                'structured_input' => [
                    'type' => 'object',
                    'description' => 'Input structuré optionnel. Utilise ce champ si l\'agent délégué attend des paramètres nommés spécifiques.',
                ],
            ],
            'required' => [],
        ];
    }

    /**
     * Définit le contexte parent avant exécution. À appeler par le caller
     * (PlannerAgent) juste avant le tool-calling LLM pour que chaque CallAgentTool
     * dans la liste ait un contexte à dériver.
     */
    public function setParentContext(AgentContext $parentContext): void
    {
        $this->parentContext = $parentContext;
    }

    public function execute(array $parameters): mixed
    {
        if (null === $this->parentContext) {
            throw new \LogicException(sprintf(
                'CallAgentTool (%s) executed without a parent context. The caller (typically a PlannerAgent) must call setParentContext() before triggering a multi-turn LLM loop that may invoke this tool.',
                $this->getName(),
            ));
        }

        $message = isset($parameters['message']) && is_string($parameters['message']) ? $parameters['message'] : '';
        $structured = isset($parameters['structured_input']) && is_array($parameters['structured_input'])
            ? $parameters['structured_input']
            : [];

        $input = [] !== $structured
            ? Input::ofStructured($structured)
            : Input::ofMessage($message);

        $childContext = $this->parentContext->createChild(
            parentRunId: $this->parentContext->getRequestId(),
            childOrigin: 'agent_as_tool',
        );

        $this->logger->info('CallAgentTool invoked: {tool} → agent {agentKey}', [
            'tool' => $this->getName(),
            'agentKey' => $this->agent->getName(),
            'parentRunId' => $this->parentContext->getRequestId(),
        ]);

        try {
            $output = $this->resolver->resolve($this->agent->getName(), $childContext)
                ->call($input, ['context' => $childContext]);
        } catch (\Throwable $e) {
            $this->logger->error('CallAgentTool {tool} failed: {message}', [
                'tool' => $this->getName(),
                'message' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }

        return [
            'success' => true,
            'answer' => $output->getAnswer(),
            'data' => $output->getData(),
            'usage' => $output->getUsage(),
        ];
    }
}
