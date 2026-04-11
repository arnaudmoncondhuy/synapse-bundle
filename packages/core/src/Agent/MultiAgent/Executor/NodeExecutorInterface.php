<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Agent\MultiAgent\Executor;

use ArnaudMoncondhuy\SynapseCore\Agent\AgentContext;
use ArnaudMoncondhuy\SynapseCore\Agent\MultiAgent\Exception\WorkflowExecutionException;
use ArnaudMoncondhuy\SynapseCore\Agent\Output;

/**
 * Exécute un nœud de workflow (Chantier F — minimal DAG engine).
 *
 * Un nœud, c'est une étape de la définition du workflow. Jusqu'ici `MultiAgent`
 * ne savait exécuter qu'un seul type de nœud : `type: 'agent'` — il appelait
 * l'`AgentResolver`, résolvait l'agent nommé, et lui passait l'input résolu.
 * Chantier F introduit une indirection : chaque type de step est pris en
 * charge par un exécuteur dédié, découvrable via le tag DI
 * `synapse.node_executor`.
 *
 * ## Pourquoi une interface ?
 *
 * - **Extensibilité**. Ajouter un nouveau type (`conditional`, plus tard
 *   `parallel`, `loop`, `sub_workflow`) = écrire une nouvelle classe + tag. Pas
 *   besoin de toucher `MultiAgent`.
 * - **BC garantie**. `AgentNodeExecutor` reproduit à l'identique le comportement
 *   pré-chantier F : les workflows existants continuent de tourner sans
 *   modification de leur définition (type par défaut = `agent`).
 * - **Testabilité**. Chaque exécuteur est un service pur avec une seule
 *   responsabilité — facile à tester en isolation.
 *
 * ## Contrat
 *
 * Un exécuteur annonce les types qu'il prend en charge via {@see supports()}.
 * `MultiAgent` itère sur la collection des exécuteurs pour trouver le premier
 * qui matche. Si aucun ne matche, {@see WorkflowExecutionException::invalidDefinition()}.
 *
 * L'exécuteur reçoit en entrée :
 * - le `$step` brut (array pivot de la definition, avec `name`, `type`, et les
 *   clés propres à son type) ;
 * - l'`$resolvedInput` déjà passé dans la moulinette `input_mapping` + JSONPath
 *   par `MultiAgent` (donc l'exécuteur n'a pas à retoucher l'input) ;
 * - l'état accumulé `$state` (clés `inputs` et `steps`) pour les exécuteurs
 *   qui ont besoin de lire au-delà de leur propre input (ex: `conditional` qui
 *   évalue une expression sur `$.steps.X.output.Y`) ;
 * - le `$childContext` déjà forgé par `MultiAgent` avec `parentRunId` + `workflowRunId`,
 *   à propager tel quel si l'exécuteur délègue à un autre agent.
 *
 * L'exécuteur retourne un {@see Output} normalisé. Pour un nœud non-LLM (ex:
 * `conditional`), renvoyer des `usage` à zéro pour ne pas polluer les agrégations
 * de coût.
 */
interface NodeExecutorInterface
{
    /**
     * Le type de nœud (valeur de la clé `type` dans la définition du step)
     * que cet exécuteur sait traiter. `'agent'` est la valeur par défaut (et
     * celle utilisée par tous les workflows pré-chantier F).
     */
    public function supports(string $type): bool;

    /**
     * Exécute le nœud et retourne son output normalisé.
     *
     * @param array<string, mixed> $step          Le step brut de la définition.
     * @param array<string, mixed> $resolvedInput L'input déjà résolu par `MultiAgent`.
     * @param array<string, mixed> $state         L'état accumulé du run (`inputs`, `steps`).
     * @param AgentContext         $childContext  Contexte enfant déjà forgé par `MultiAgent`.
     *
     * @throws WorkflowExecutionException Si le step est invalide ou si l'exécution échoue
     *                                    de manière non récupérable.
     */
    public function execute(array $step, array $resolvedInput, array $state, AgentContext $childContext): Output;
}
