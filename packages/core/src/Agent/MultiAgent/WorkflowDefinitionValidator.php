<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Agent\MultiAgent;

/**
 * Validateur du format pivot d'une définition de workflow (Chantier F phase 2).
 *
 * Extrait de l'historique `WorkflowController::validatePivotStructure()` pour
 * être appelable depuis **deux sites distincts** :
 *
 * 1. **L'éditeur admin** ({@see \ArnaudMoncondhuy\SynapseAdmin\Controller\Intelligence\WorkflowController}).
 *    L'utilisateur saisit du JSON à la main, la validation bloque la sauvegarde
 *    si le pivot est mal formé.
 * 2. **L'`ArchitectAgent`** via son processor ({@see \ArnaudMoncondhuy\SynapseCore\Governance\Architect\ArchitectProposalProcessor}).
 *    Le LLM génère un workflow, on refuse de le persister s'il ne passe pas
 *    la validation. Sans ça, un hallucination fait passer une definition
 *    cassée en base qui crasherait au premier `run()`.
 *
 * ## Règles validées
 *
 * - `steps` : clé présente, array non-vide, chaque step est un objet.
 * - Chaque step a un `name` string non-vide et un `type` reconnu (défaut `agent`).
 * - Type `agent` → `agent_name` obligatoire.
 * - Type `conditional` → `condition` obligatoire.
 * - Type `parallel` → `branches` array non-vide, chaque branche est elle-même
 *   un step valide (récursion).
 * - Type `loop` → `items_path` string non-vide et `step` object (step template).
 * - Type `sub_workflow` → `workflow_key` string non-vide. Pas de vérification
 *   d'existence DB (laissée au runtime).
 * - Noms de steps uniques au niveau racine.
 * - Références croisées `$.steps.<name>.*` dans `input_mapping` et `outputs`
 *   pointent vers des steps existants.
 *
 * Ne valide PAS : la sémantique runtime des JSONPath (un chemin `$.steps.X.output.Y`
 * peut pointer sur une clé qui n'existe pas dans l'output effectif — c'est
 * l'affaire du warning runtime de {@see MultiAgent::resolveStepInput()}).
 *
 * Retourne soit `null` si tout est OK, soit un message d'erreur **en clair
 * français** (pas de clé de traduction — le validateur est agnostique du i18n).
 * Les callers formatent l'erreur comme ils veulent (flash message, exception,
 * réponse JSON).
 */
final class WorkflowDefinitionValidator
{
    /**
     * Types de steps reconnus. Un step avec un type hors de cette liste est
     * rejeté à la validation. Quand on ajoute un nouveau type, il faut
     * mettre à jour cette constante **et** la méthode `validateStep()`.
     *
     * @var list<string>
     */
    public const SUPPORTED_TYPES = ['agent', 'conditional', 'parallel', 'loop', 'sub_workflow'];

    /**
     * Valide la definition pivot complète.
     *
     * @param array<string, mixed> $definition
     *
     * @return string|null Message d'erreur français, ou null si tout est OK
     */
    public function validate(array $definition): ?string
    {
        if (!isset($definition['steps']) || !is_array($definition['steps'])) {
            return 'La définition doit contenir une clé « steps » sous forme de tableau.';
        }

        $steps = $definition['steps'];
        if ([] === $steps) {
            return 'Le tableau « steps » ne peut pas être vide.';
        }

        $names = [];
        foreach ($steps as $index => $step) {
            if (!is_array($step)) {
                return sprintf('Le step d\'index %s doit être un objet.', (string) $index);
            }

            $stepError = $this->validateStep($step, (string) $index);
            if (null !== $stepError) {
                return $stepError;
            }

            /** @var string $name */
            $name = $step['name'];
            if (in_array($name, $names, true)) {
                return sprintf('Le nom de step « %s » est dupliqué.', $name);
            }
            $names[] = $name;
        }

        // Références croisées
        $referencedNames = $this->collectReferencedStepNames($steps, $definition['outputs'] ?? []);
        foreach ($referencedNames as $ref) {
            if (!in_array($ref, $names, true)) {
                return sprintf('La référence pointe vers un step inexistant : « %s ».', $ref);
            }
        }

        return null;
    }

    /**
     * Valide un step individuel (utilisé en récursif pour les branches de
     * `parallel` et le template de `loop`).
     *
     * @param array<string, mixed> $step
     * @param string $locationHint Hint pour les messages d'erreur (index numérique ou chemin « parallel/branches/0 »)
     */
    private function validateStep(array $step, string $locationHint): ?string
    {
        $name = $step['name'] ?? null;
        if (!is_string($name) || '' === $name) {
            return sprintf('Le step à l\'emplacement %s doit avoir un champ « name » non vide.', $locationHint);
        }

        $type = $step['type'] ?? 'agent';
        if (!is_string($type) || !in_array($type, self::SUPPORTED_TYPES, true)) {
            return sprintf(
                'Step « %s » : type « %s » inconnu (attendu : %s).',
                $name,
                is_string($type) ? $type : gettype($type),
                implode(', ', self::SUPPORTED_TYPES),
            );
        }

        return match ($type) {
            'agent' => $this->validateAgentStep($step, $name),
            'conditional' => $this->validateConditionalStep($step, $name),
            'parallel' => $this->validateParallelStep($step, $name),
            'loop' => $this->validateLoopStep($step, $name),
            default => $this->validateSubWorkflowStep($step, $name), // 'sub_workflow', seul cas restant après in_array ci-dessus
        };
    }

    /**
     * @param array<string, mixed> $step
     */
    private function validateAgentStep(array $step, string $name): ?string
    {
        $agentName = StepInputResolver::readConfigField($step, 'agent_name');
        if (!is_string($agentName) || '' === $agentName) {
            return sprintf('Le step « %s » doit avoir un champ « agent_name » non vide (dans config ou à la racine).', $name);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $step
     */
    private function validateConditionalStep(array $step, string $name): ?string
    {
        $condition = StepInputResolver::readConfigField($step, 'condition');
        if (!is_string($condition) || '' === $condition) {
            return sprintf('Step « %s » de type « conditional » : clé « condition » manquante ou vide.', $name);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $step
     */
    private function validateParallelStep(array $step, string $name): ?string
    {
        $branches = StepInputResolver::readConfigField($step, 'branches');
        if (!is_array($branches) || [] === $branches) {
            return sprintf('Step « %s » de type « parallel » : clé « branches » manquante ou vide.', $name);
        }

        $branchNames = [];
        foreach ($branches as $idx => $branch) {
            if (!is_array($branch)) {
                return sprintf('Step « %s » : la branche d\'index %s doit être un objet.', $name, (string) $idx);
            }
            $branchError = $this->validateStep($branch, sprintf('%s.branches[%s]', $name, (string) $idx));
            if (null !== $branchError) {
                return $branchError;
            }

            /** @var string $branchName */
            $branchName = $branch['name'];
            if (in_array($branchName, $branchNames, true)) {
                return sprintf('Step « %s » : nom de branche « %s » dupliqué.', $name, $branchName);
            }
            $branchNames[] = $branchName;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $step
     */
    private function validateLoopStep(array $step, string $name): ?string
    {
        $itemsPath = StepInputResolver::readConfigField($step, 'items_path');
        if (!is_string($itemsPath) || '' === $itemsPath) {
            return sprintf('Step « %s » de type « loop » : clé « items_path » manquante ou vide.', $name);
        }

        $template = StepInputResolver::readConfigField($step, 'step');
        if (!is_array($template)) {
            return sprintf('Step « %s » de type « loop » : clé « step » (template) manquante ou invalide.', $name);
        }

        return $this->validateStep($template, sprintf('%s.step', $name));
    }

    /**
     * @param array<string, mixed> $step
     */
    private function validateSubWorkflowStep(array $step, string $name): ?string
    {
        $workflowKey = StepInputResolver::readConfigField($step, 'workflow_key');
        if (!is_string($workflowKey) || '' === $workflowKey) {
            return sprintf('Step « %s » de type « sub_workflow » : clé « workflow_key » manquante ou vide.', $name);
        }

        return null;
    }

    /**
     * Collecte tous les noms de steps référencés par JSONPath dans les
     * `input_mapping` et les `outputs` de premier niveau.
     *
     * @param array<int, mixed> $steps
     * @param array<string, mixed>|mixed $outputs
     *
     * @return list<string>
     */
    private function collectReferencedStepNames(array $steps, mixed $outputs): array
    {
        $refs = [];

        foreach ($steps as $step) {
            if (!is_array($step)) {
                continue;
            }
            // Chantier K2 : input_mapping peut être dans config.* (nouveau) ou à la racine (ancien)
            $mapping = StepInputResolver::readConfigField($step, 'input_mapping') ?? [];
            if (is_array($mapping)) {
                foreach ($mapping as $path) {
                    if (is_string($path)) {
                        $ref = $this->extractStepRef($path);
                        if (null !== $ref) {
                            $refs[] = $ref;
                        }
                    }
                }
            }
        }

        if (is_array($outputs)) {
            foreach ($outputs as $path) {
                if (is_string($path)) {
                    $ref = $this->extractStepRef($path);
                    if (null !== $ref) {
                        $refs[] = $ref;
                    }
                }
            }
        }

        return $refs;
    }

    private function extractStepRef(string $path): ?string
    {
        if (1 === preg_match('/^\$\.steps\.([a-zA-Z0-9_-]+)/', $path, $matches)) {
            return $matches[1];
        }

        return null;
    }
}
