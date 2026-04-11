<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tool;

use ArnaudMoncondhuy\SynapseCore\Contract\AiToolInterface;
use ArnaudMoncondhuy\SynapseCore\Contract\CodeExecutorInterface;
use ArnaudMoncondhuy\SynapseCore\Event\SynapseCodeExecutedEvent;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseCodeExecution;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Outil LLM qui expose l'exécution de code arbitraire via le
 * {@see CodeExecutorInterface}.
 *
 * Chantier E — scaffolding. Cet outil est câblé dans le {@see
 * \ArnaudMoncondhuy\SynapseCore\Engine\ToolRegistry} via le tag auto-configuré
 * `synapse.tool` et est donc disponible pour tout agent qui déclare
 * `code_execute` dans ses `allowedTools`. Tant que le backend configuré est
 * le {@see \ArnaudMoncondhuy\SynapseCore\CodeExecutor\NullCodeExecutor}, les
 * appels retournent une erreur `BackendUnavailable` lisible plutôt que de
 * crasher.
 *
 * ## Équivalent Claude Code
 *
 * C'est le cousin de l'outil `Bash` de Claude Code : un multiplicateur
 * massif de capacités pour l'agent autonome. Avec cet outil, un agent peut
 * parser un CSV, calculer une moyenne mobile, appeler pandas, générer un
 * graphique matplotlib en base64, manipuler un JSON complexe — sans que
 * l'hôte ait à câbler un outil dédié pour chaque cas. Le LLM écrit du
 * Python, l'outil l'exécute, le stdout revient comme contexte.
 *
 * ## Relation avec BudgetLimit
 *
 * Chantier B a introduit `AgentContext::$budget`. Quand un vrai backend
 * sera câblé, il devra lire `$context->getBudget()?->getMaxExecutionSeconds()`
 * et s'en servir comme timeout hard. Pour le NullCodeExecutor, la question
 * ne se pose pas.
 */
class CodeExecuteTool implements AiToolInterface
{
    public function __construct(
        private readonly CodeExecutorInterface $executor,
        // Optionnel : quand disponible, l'event est dispatché après chaque
        // exécution pour que la transparency sidebar puisse afficher une
        // carte dédiée (principe 8). Non-bloquant si absent (tests unitaires).
        private readonly ?EventDispatcherInterface $eventDispatcher = null,
        // Optionnel : quand disponible, persiste chaque exécution dans
        // `synapse_code_execution` pour l'audit a posteriori (Chantier E,
        // plan ligne 178). Laissé optionnel pour les tests unitaires qui
        // instancient le tool sans conteneur DI.
        private readonly ?EntityManagerInterface $entityManager = null,
    ) {
    }

    public function getName(): string
    {
        return 'code_execute';
    }

    public function getLabel(): string
    {
        return 'Exécuter du code';
    }

    public function getDescription(): string
    {
        return 'Exécute du code Python dans un environnement isolé et retourne le stdout, stderr, '
            .'et la valeur retournée. Utilise cet outil quand tu dois faire des calculs non-triviaux, '
            .'manipuler des données tabulaires (CSV, JSON), parser du texte avec des regex, ou quand '
            .'écrire un script est plus fiable que de raisonner le résultat toi-même. Le code s\'exécute '
            .'dans un sandbox sans accès réseau par défaut.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'code' => [
                    'type' => 'string',
                    'description' => 'Le code source à exécuter. Pour Python : pose le résultat final dans une variable `result` qui sera retournée comme `return_value`.',
                ],
                'language' => [
                    'type' => 'string',
                    'enum' => ['python'],
                    'description' => 'Langage du code. Seul Python est supporté pour l\'instant.',
                    'default' => 'python',
                ],
            ],
            'required' => ['code'],
        ];
    }

    public function execute(array $parameters): mixed
    {
        $code = $parameters['code'] ?? null;
        if (!is_string($code) || '' === $code) {
            return [
                'success' => false,
                'error_type' => 'InvalidInput',
                'error_message' => 'Parameter "code" is required and must be a non-empty string.',
            ];
        }

        $language = isset($parameters['language']) && is_string($parameters['language'])
            ? $parameters['language']
            : 'python';

        $result = $this->executor->execute($code, $language);
        $resultArray = $result->toArray();

        // Audit trail persistant (Chantier E phase 4) : stocke l'exécution
        // dans `synapse_code_execution` pour pouvoir la retrouver
        // a posteriori. On le fait **avant** de dispatcher l'event pour que
        // la ligne soit présente en DB si un listener avale ou délaye le flush.
        if (null !== $this->entityManager) {
            try {
                $execution = (new SynapseCodeExecution())
                    ->setCode($code)
                    ->setLanguage($language)
                    ->setSuccess((bool) ($resultArray['success'] ?? false))
                    ->setStdout(is_string($resultArray['stdout'] ?? null) ? $resultArray['stdout'] : null)
                    ->setStderr(is_string($resultArray['stderr'] ?? null) ? $resultArray['stderr'] : null)
                    ->setReturnValue($resultArray['return_value'] ?? null)
                    ->setDurationMs(is_int($resultArray['duration_ms'] ?? null) ? $resultArray['duration_ms'] : 0)
                    ->setErrorType(is_string($resultArray['error_type'] ?? null) ? $resultArray['error_type'] : null)
                    ->setErrorMessage(is_string($resultArray['error_message'] ?? null) ? $resultArray['error_message'] : null);

                $this->entityManager->persist($execution);
                $this->entityManager->flush();
            } catch (\Throwable $e) {
                // L'audit trail ne doit JAMAIS bloquer l'exécution du code —
                // si la DB est down ou la table manque, on continue et on
                // laisse la sidebar afficher le résultat pour le LLM.
            }
        }

        // Principe 8 : dispatch d'un event porteur du code source + résultat
        // complet pour que la transparency sidebar du chat puisse afficher
        // une carte dédiée avec syntax-highlighted Python + stdout + return_value.
        if (null !== $this->eventDispatcher) {
            $this->eventDispatcher->dispatch(new SynapseCodeExecutedEvent(
                code: $code,
                language: $language,
                result: $resultArray,
            ));
        }

        return $resultArray;
    }
}
