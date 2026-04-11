<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Agent\MultiAgent\Executor;

use ArnaudMoncondhuy\SynapseCore\Agent\AgentContext;
use ArnaudMoncondhuy\SynapseCore\Agent\Input;
use ArnaudMoncondhuy\SynapseCore\Agent\MultiAgent\Exception\WorkflowExecutionException;
use ArnaudMoncondhuy\SynapseCore\Agent\MultiAgent\StepInputResolver;
use ArnaudMoncondhuy\SynapseCore\Agent\MultiAgent\WorkflowRunner;
use ArnaudMoncondhuy\SynapseCore\Agent\Output;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseWorkflowRepository;
use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;

/**
 * Exécuteur `sub_workflow` (Chantier F phase 2).
 *
 * Délègue à un workflow persistant existant en l'exécutant de manière
 * synchrone comme un step de premier niveau, puis en réinjectant son
 * output dans le state du workflow parent. Permet la composition de
 * workflows : un « workflow d'ingestion » peut réutiliser un « workflow
 * de classification » comme une étape.
 *
 * ## Format step accepté
 *
 * ```json
 * {
 *   "name": "delegate_classify",
 *   "type": "sub_workflow",
 *   "workflow_key": "classification_pipeline",
 *   "input_mapping": { "message": "$.inputs.raw_text" }
 * }
 * ```
 *
 * - `workflow_key` (requis) : clé du workflow cible. Doit pointer sur un
 *   workflow **actif** (`isActive: true`). Un workflow éphémère non-promu
 *   n'est pas utilisable comme sub-workflow — ça force les users à valider
 *   avant composition.
 * - `input_mapping` standard : les valeurs résolues deviennent l'Input
 *   structuré passé au sub-workflow.
 *
 * ## Output produit
 *
 * L'Output du sub-workflow est retourné tel quel. Son `data` devient
 * accessible via `$.steps.<name>.output.data.<key>` dans le workflow parent.
 *
 * ## Protection contre les cycles
 *
 * Aucune logique dédiée — on s'appuie sur le `AgentContext::maxDepth`
 * existant (défaut 2, configurable via `synapse.agents.max_depth`). Un
 * sub_workflow qui s'appelle lui-même (directement ou via une chaîne)
 * dépasse `maxDepth` et se fait rejeter par `AgentResolver::resolve()`.
 *
 * ## Dépendance circulaire : pourquoi AutowireLocator
 *
 * `SubWorkflowNodeExecutor` a besoin de `WorkflowRunner` pour exécuter
 * le workflow cible. Mais `WorkflowRunner` reçoit la collection des
 * `NodeExecutor` (dont ce service) via `AutowireIterator`. C'est une
 * dépendance circulaire indirecte. `AutowireLocator` casse le cycle en
 * résolvant `WorkflowRunner` lazy (à la première utilisation, pas au
 * moment du boot du container).
 */
final class SubWorkflowNodeExecutor implements NodeExecutorInterface
{
    public function __construct(
        private readonly SynapseWorkflowRepository $workflowRepository,
        #[AutowireLocator([WorkflowRunner::class])]
        private readonly ContainerInterface $locator,
    ) {
    }

    public function supports(string $type): bool
    {
        return 'sub_workflow' === $type;
    }

    public function execute(array $step, array $resolvedInput, array $state, AgentContext $childContext): Output
    {
        $stepName = (string) ($step['name'] ?? 'sub_workflow');

        // Chantier K2 : workflow_key dans config.workflow_key avec fallback flat.
        $workflowKey = StepInputResolver::readConfigField($step, 'workflow_key');
        if (!is_string($workflowKey) || '' === $workflowKey) {
            throw WorkflowExecutionException::invalidDefinition(
                sprintf('sub_workflow step "%s" missing "workflow_key"', $stepName)
            );
        }

        $workflow = $this->workflowRepository->findActiveByKey($workflowKey);
        if (null === $workflow) {
            throw WorkflowExecutionException::invalidDefinition(
                sprintf('sub_workflow step "%s": workflow "%s" not found or not active', $stepName, $workflowKey)
            );
        }

        /** @var WorkflowRunner $runner */
        $runner = $this->locator->get(WorkflowRunner::class);

        // Le context enfant est déjà incrémenté en depth par MultiAgent
        // avant de nous le passer — AgentResolver refusera de créer un
        // contexte qui dépasse maxDepth dans la chaîne.
        return $runner->run(
            workflow: $workflow,
            input: Input::ofStructured($resolvedInput),
            options: ['context' => $childContext],
        );
    }
}
