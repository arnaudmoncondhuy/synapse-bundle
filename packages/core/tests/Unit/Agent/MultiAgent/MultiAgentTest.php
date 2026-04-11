<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Agent\MultiAgent;

use ArnaudMoncondhuy\SynapseCore\Agent\AgentContext;
use ArnaudMoncondhuy\SynapseCore\Agent\AgentResolver;
use ArnaudMoncondhuy\SynapseCore\Agent\CodeAgentRegistry;
use ArnaudMoncondhuy\SynapseCore\Agent\Input;
use ArnaudMoncondhuy\SynapseCore\Agent\MultiAgent\Exception\WorkflowExecutionException;
use ArnaudMoncondhuy\SynapseCore\Agent\MultiAgent\Executor\AgentNodeExecutor;
use ArnaudMoncondhuy\SynapseCore\Agent\MultiAgent\Executor\ConditionalNodeExecutor;
use ArnaudMoncondhuy\SynapseCore\Agent\MultiAgent\MultiAgent;
use ArnaudMoncondhuy\SynapseCore\Agent\MultiAgent\WorkflowRunner;
use ArnaudMoncondhuy\SynapseCore\Agent\Output;
use ArnaudMoncondhuy\SynapseCore\AgentRegistry;
use ArnaudMoncondhuy\SynapseCore\Contract\AgentInterface;
use ArnaudMoncondhuy\SynapseCore\Engine\ChatService;
use ArnaudMoncondhuy\SynapseCore\Shared\Enum\WorkflowRunStatus;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseWorkflow;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseWorkflowRun;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseWorkflowRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * @covers \ArnaudMoncondhuy\SynapseCore\Agent\MultiAgent\MultiAgent
 */
final class MultiAgentTest extends TestCase
{
    public function testCallExecutesStepsSequentiallyAndAggregatesOutputs(): void
    {
        $analyze = $this->makeSpyAgent('analyze_agent', function (Input $input): Output {
            // Reçoit {text: "Lorem ipsum"} via input_mapping
            $structured = $input->getStructured();
            $this->assertSame('Lorem ipsum', $structured['text'] ?? null);

            return new Output(
                answer: 'Analyse terminée.',
                data: ['score' => 0.9],
                usage: ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
            );
        });

        $summarize = $this->makeSpyAgent('summarize_agent', function (Input $input): Output {
            // Reçoit l'output texte du step précédent
            $structured = $input->getStructured();
            $this->assertSame('Analyse terminée.', $structured['input_text'] ?? null);
            $this->assertSame(0.9, $structured['input_score'] ?? null);

            return new Output(
                answer: 'Résumé final.',
                usage: ['prompt_tokens' => 20, 'completion_tokens' => 8, 'total_tokens' => 28],
            );
        });

        $resolver = $this->makeResolver([$analyze, $summarize]);

        $workflow = $this->makeWorkflow([
            'version' => 1,
            'steps' => [
                [
                    'name' => 'analyze',
                    'agent_name' => 'analyze_agent',
                    'input_mapping' => [
                        'text' => '$.inputs.document',
                    ],
                ],
                [
                    'name' => 'summarize',
                    'agent_name' => 'summarize_agent',
                    'input_mapping' => [
                        'input_text' => '$.steps.analyze.output.text',
                        'input_score' => '$.steps.analyze.output.data.score',
                    ],
                ],
            ],
            'outputs' => [
                'final_summary' => '$.steps.summarize.output.text',
                'analysis_score' => '$.steps.analyze.output.data.score',
            ],
        ]);

        $run = new SynapseWorkflowRun();
        $run->setWorkflow($workflow);

        $multiAgent = new MultiAgent($workflow, $run, $resolver);

        $output = $multiAgent->call(
            Input::ofStructured(['document' => 'Lorem ipsum'])
        );

        // Output final construit via la clause `outputs`
        $this->assertSame('Résumé final.', $output->getData()['final_summary'] ?? null);
        $this->assertSame(0.9, $output->getData()['analysis_score'] ?? null);

        // Usage cumulé
        $this->assertSame(30, $output->getUsage()['prompt_tokens']);
        $this->assertSame(13, $output->getUsage()['completion_tokens']);
        $this->assertSame(43, $output->getUsage()['total_tokens']);

        // État final du run
        $this->assertSame(WorkflowRunStatus::COMPLETED, $run->getStatus());
        $this->assertSame(2, $run->getCurrentStepIndex());
        $this->assertSame(43, $run->getTotalTokens());
        $this->assertNotNull($run->getCompletedAt());
        $this->assertNull($run->getErrorMessage());

        // Metadata workflow
        $this->assertSame('mon_workflow', $output->getMetadata()['workflow_key'] ?? null);
        $this->assertSame(2, $output->getMetadata()['steps_executed'] ?? null);
    }

    public function testCallPropagatesWorkflowRunIdToChildContexts(): void
    {
        $capturedRunIds = [];
        $spy = $this->makeSpyAgent('spy_agent', function (Input $input, array $options) use (&$capturedRunIds): Output {
            $context = $options['context'] ?? null;
            $this->assertInstanceOf(AgentContext::class, $context);
            $capturedRunIds[] = $context->getWorkflowRunId();

            return new Output(answer: 'ok');
        });

        $resolver = $this->makeResolver([$spy]);

        $workflow = $this->makeWorkflow([
            'version' => 1,
            'steps' => [
                ['name' => 'step1', 'agent_name' => 'spy_agent'],
                ['name' => 'step2', 'agent_name' => 'spy_agent'],
            ],
        ]);

        $run = new SynapseWorkflowRun();
        $run->setWorkflow($workflow);
        $expectedRunId = $run->getWorkflowRunId();

        (new MultiAgent($workflow, $run, $resolver))->call(Input::ofMessage('hello'));

        $this->assertCount(2, $capturedRunIds);
        $this->assertSame($expectedRunId, $capturedRunIds[0]);
        $this->assertSame($expectedRunId, $capturedRunIds[1]);
    }

    public function testCallWrapsStepFailureAndMarksRunFailed(): void
    {
        $failing = $this->makeSpyAgent('failing_agent', function (): Output {
            throw new \RuntimeException('boom');
        });

        $resolver = $this->makeResolver([$failing]);

        $workflow = $this->makeWorkflow([
            'version' => 1,
            'steps' => [
                ['name' => 'broken_step', 'agent_name' => 'failing_agent'],
            ],
        ]);

        $run = new SynapseWorkflowRun();
        $run->setWorkflow($workflow);

        $multiAgent = new MultiAgent($workflow, $run, $resolver);

        try {
            $multiAgent->call(Input::ofMessage('x'));
            $this->fail('Expected WorkflowExecutionException');
        } catch (WorkflowExecutionException $e) {
            $this->assertSame('broken_step', $e->getStepName());
            $this->assertStringContainsString('boom', $e->getMessage());
        }

        $this->assertSame(WorkflowRunStatus::FAILED, $run->getStatus());
        $this->assertNotNull($run->getErrorMessage());
        $this->assertNotNull($run->getCompletedAt());
    }

    public function testCallThrowsWhenStepsMissing(): void
    {
        $resolver = $this->makeResolver([]);

        $workflow = $this->makeWorkflow(['version' => 1]); // pas de steps

        $run = new SynapseWorkflowRun();
        $run->setWorkflow($workflow);

        $this->expectException(WorkflowExecutionException::class);
        $this->expectExceptionMessage('steps array is missing or empty');

        (new MultiAgent($workflow, $run, $resolver))->call(Input::ofMessage('x'));
    }

    public function testCallThrowsWhenAgentNotResolvable(): void
    {
        $resolver = $this->makeResolver([]); // aucun agent enregistré

        $workflow = $this->makeWorkflow([
            'version' => 1,
            'steps' => [
                ['name' => 'oops', 'agent_name' => 'ghost_agent'],
            ],
        ]);

        $run = new SynapseWorkflowRun();
        $run->setWorkflow($workflow);

        try {
            (new MultiAgent($workflow, $run, $resolver))->call(Input::ofMessage('x'));
            $this->fail('Expected WorkflowExecutionException');
        } catch (WorkflowExecutionException $e) {
            $this->assertSame('oops', $e->getStepName());
            $this->assertStringContainsString('ghost_agent', $e->getMessage());
        }
    }

    public function testCallCreatesRootContextWhenNoneProvided(): void
    {
        $capturedContext = null;
        $spy = $this->makeSpyAgent('spy', function (Input $input, array $options) use (&$capturedContext): Output {
            $capturedContext = $options['context'] ?? null;

            return new Output(answer: 'ok');
        });

        $resolver = $this->makeResolver([$spy]);

        $workflow = $this->makeWorkflow([
            'version' => 1,
            'steps' => [['name' => 'only', 'agent_name' => 'spy']],
        ]);

        $run = new SynapseWorkflowRun();
        $run->setWorkflow($workflow);
        $run->setUserId('user-123');

        (new MultiAgent($workflow, $run, $resolver))->call(Input::ofMessage('x'));

        $this->assertInstanceOf(AgentContext::class, $capturedContext);
        $this->assertSame('user-123', $capturedContext->getUserId());
        $this->assertSame($run->getWorkflowRunId(), $capturedContext->getWorkflowRunId());
    }

    public function testChantierFDispatchesConditionalStepToDedicatedExecutor(): void
    {
        // Un workflow qui mixe un step "agent" (classifie) puis un step
        // "conditional" qui évalue le résultat et matérialise un flag dans
        // son output, consommé ensuite par un deuxième step "agent".
        $capturedGoFlag = null;

        $classifier = $this->makeSpyAgent('classifier', function (Input $input): Output {
            return new Output(
                answer: 'urgent',
                data: ['priority' => 'urgent'],
                usage: ['prompt_tokens' => 5, 'completion_tokens' => 2, 'total_tokens' => 7],
            );
        });

        $handler = $this->makeSpyAgent('urgent_handler', function (Input $input) use (&$capturedGoFlag): Output {
            $capturedGoFlag = $input->getStructured()['go'] ?? null;

            return new Output(
                answer: 'handled',
                usage: ['prompt_tokens' => 10, 'completion_tokens' => 3, 'total_tokens' => 13],
            );
        });

        $resolver = $this->makeResolver([$classifier, $handler]);

        $workflow = $this->makeWorkflow([
            'version' => 1,
            'steps' => [
                [
                    'name' => 'classify',
                    'agent_name' => 'classifier',
                    'input_mapping' => ['message' => '$.inputs.message'],
                ],
                [
                    'name' => 'is_urgent',
                    'type' => 'conditional',
                    'condition' => '$.steps.classify.output.data.priority',
                    'equals' => 'urgent',
                ],
                [
                    'name' => 'handle',
                    'agent_name' => 'urgent_handler',
                    'input_mapping' => [
                        'message' => '$.inputs.message',
                        'go' => '$.steps.is_urgent.output.data.matched',
                    ],
                ],
            ],
            'outputs' => [
                'result' => '$.steps.handle.output.text',
                'matched' => '$.steps.is_urgent.output.data.matched',
            ],
        ]);

        $run = new SynapseWorkflowRun();
        $run->setWorkflow($workflow);

        $multiAgent = new MultiAgent(
            $workflow,
            $run,
            $resolver,
            null,
            null,
            [new AgentNodeExecutor($resolver), new ConditionalNodeExecutor()],
        );

        $output = $multiAgent->call(Input::ofStructured(['message' => 'au secours']));

        // Le step conditional a matérialisé le flag, le handler l'a bien reçu.
        $this->assertTrue($capturedGoFlag);

        // Outputs finaux exposent le matched et la réponse du handler.
        $this->assertSame('handled', $output->getData()['result']);
        $this->assertTrue($output->getData()['matched']);

        // Le step conditional n'ajoute aucun token (usage: 0), donc le total
        // reflète uniquement les 2 appels LLM (7 + 13 = 20).
        $this->assertSame(20, $output->getUsage()['total_tokens']);

        // Les 3 steps ont bien été exécutés séquentiellement.
        $this->assertSame(3, $run->getCurrentStepIndex());
        $this->assertSame(WorkflowRunStatus::COMPLETED, $run->getStatus());
    }

    public function testChantierFRejectsUnknownStepType(): void
    {
        $resolver = $this->makeResolver([]);

        $workflow = $this->makeWorkflow([
            'version' => 1,
            'steps' => [
                ['name' => 'weird', 'type' => 'unknown_type'],
            ],
        ]);

        $run = new SynapseWorkflowRun();
        $run->setWorkflow($workflow);

        $multiAgent = new MultiAgent(
            $workflow,
            $run,
            $resolver,
            null,
            null,
            [new AgentNodeExecutor($resolver), new ConditionalNodeExecutor()],
        );

        $this->expectException(WorkflowExecutionException::class);
        $this->expectExceptionMessage('unknown type "unknown_type"');

        $multiAgent->call(Input::ofMessage('x'));
    }

    public function testChantierFFallbackAgentNodeExecutorWhenNoneInjected(): void
    {
        // BC : les tests pré-F construisent MultiAgent sans passer d'executors.
        // Le fallback doit câbler AgentNodeExecutor automatiquement pour que
        // les workflows type="agent" continuent de tourner.
        $spy = $this->makeSpyAgent('spy', fn (): Output => new Output(answer: 'ok'));

        $resolver = $this->makeResolver([$spy]);
        $workflow = $this->makeWorkflow([
            'version' => 1,
            'steps' => [['name' => 'only', 'agent_name' => 'spy']],
        ]);

        $run = new SynapseWorkflowRun();
        $run->setWorkflow($workflow);

        // Aucun $nodeExecutors passé → fallback automatique
        $output = (new MultiAgent($workflow, $run, $resolver))->call(Input::ofMessage('x'));

        $this->assertSame(WorkflowRunStatus::COMPLETED, $run->getStatus());
        $this->assertNotNull($output);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * @param callable(Input, array<string, mixed>): Output $handler
     */
    private function makeSpyAgent(string $name, callable $handler): AgentInterface
    {
        return new class($name, $handler) implements AgentInterface {
            /**
             * @param callable(Input, array<string, mixed>): Output $handler
             */
            public function __construct(
                private readonly string $name,
                private readonly mixed $handler,
            ) {
            }

            public function getName(): string
            {
                return $this->name;
            }

            public function getLabel(): string
            {
                return $this->name;
            }

            public function getDescription(): string
            {
                return 'spy agent for tests';
            }

            public function call(Input $input, array $options = []): Output
            {
                return ($this->handler)($input, $options);
            }
        };
    }

    /**
     * @param list<AgentInterface> $agents
     */
    private function makeResolver(array $agents): AgentResolver
    {
        $configAgents = $this->createStub(AgentRegistry::class);
        $configAgents->method('get')->willReturn(null);

        return new AgentResolver(
            new CodeAgentRegistry($agents),
            $configAgents,
            $this->createStub(ChatService::class),
            $this->createStub(EventDispatcherInterface::class),
            $this->createStub(WorkflowRunner::class),
            $this->createStub(SynapseWorkflowRepository::class),
            maxDepth: 5,
        );
    }

    /**
     * @param array<string, mixed> $definition
     */
    private function makeWorkflow(array $definition): SynapseWorkflow
    {
        $workflow = new SynapseWorkflow();
        $workflow->setWorkflowKey('mon_workflow');
        $workflow->setName('Mon workflow');
        $workflow->setDefinition($definition);

        return $workflow;
    }
}
