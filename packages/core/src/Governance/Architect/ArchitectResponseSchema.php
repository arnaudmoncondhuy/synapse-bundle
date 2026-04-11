<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Governance\Architect;

/**
 * Schémas JSON Mode pour les différentes actions de l'{@see ArchitectAgent}.
 *
 * Chaque méthode retourne un tableau `response_format` au format canonique
 * attendu par {@see \ArnaudMoncondhuy\SynapseCore\Engine\ResponseFormatNormalizer}
 * (OpenAI structured outputs). Les schémas définissent le contrat entre le LLM
 * et le {@see ArchitectProposalProcessor} qui applique les propositions.
 *
 * @internal utilitaire statique, pas un service DI
 */
final class ArchitectResponseSchema
{
    /**
     * Schéma pour la création d'un nouvel agent.
     *
     * @return array<string, mixed>
     */
    public static function createAgent(): array
    {
        return [
            'type' => 'json_schema',
            'json_schema' => [
                'name' => 'architect_create_agent',
                'schema' => [
                    'type' => 'object',
                    'properties' => [
                        'key' => [
                            'type' => 'string',
                            'description' => 'Clé unique slug (a-z0-9_) pour identifier l\'agent.',
                        ],
                        'name' => [
                            'type' => 'string',
                            'description' => 'Nom lisible de l\'agent.',
                        ],
                        'emoji' => [
                            'type' => 'string',
                            'description' => 'Un seul emoji illustrant l\'agent.',
                        ],
                        'description' => [
                            'type' => 'string',
                            'description' => 'Description courte de l\'objectif de l\'agent (1-2 phrases).',
                        ],
                        'system_prompt' => [
                            'type' => 'string',
                            'description' => 'System prompt complet de l\'agent, structuré et précis.',
                        ],
                        'allowed_tools' => [
                            'type' => 'array',
                            'items' => ['type' => 'string'],
                            'description' => 'Chantier E : liste des outils que cet agent peut utiliser. Valeurs courantes : `code_execute` (exécute du Python pour des calculs/parsing/manipulation de données), `propose_to_remember` (mémorise un fait pour l\'utilisateur). Si vide ou omis, l\'agent aura accès à tous les outils enregistrés du système. Ne déclare un outil que s\'il est vraiment pertinent pour la mission de l\'agent.',
                        ],
                        'reasoning' => [
                            'type' => 'string',
                            'description' => 'Explication des choix de conception du prompt (et du choix des allowed_tools si renseigné).',
                        ],
                    ],
                    'required' => ['key', 'name', 'emoji', 'description', 'system_prompt', 'reasoning'],
                ],
                // Chantier E : strict=false pour permettre l'optionnel allowed_tools.
                // OpenAI strict mode exige que toutes les propriétés soient required,
                // ce qui forcerait l'agent à toujours déclarer allowed_tools même vide.
                'strict' => false,
            ],
        ];
    }

    /**
     * Schéma pour l'amélioration du prompt d'un agent existant.
     *
     * @return array<string, mixed>
     */
    public static function improvePrompt(): array
    {
        return [
            'type' => 'json_schema',
            'json_schema' => [
                'name' => 'architect_improve_prompt',
                'schema' => [
                    'type' => 'object',
                    'properties' => [
                        'new_system_prompt' => [
                            'type' => 'string',
                            'description' => 'Le nouveau system prompt complet (remplacement intégral, pas un diff).',
                        ],
                        'changes_summary' => [
                            'type' => 'string',
                            'description' => 'Résumé concis des modifications apportées (2-5 phrases).',
                        ],
                        'reasoning' => [
                            'type' => 'string',
                            'description' => 'Justification détaillée de chaque changement.',
                        ],
                    ],
                    'required' => ['new_system_prompt', 'changes_summary', 'reasoning'],
                ],
                'strict' => true,
            ],
        ];
    }

    /**
     * Schéma pour la création d'un nouveau workflow.
     *
     * Le champ `definition` suit le format pivot de {@see \ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseWorkflow}.
     *
     * ## Chantier K2 (2026-04-11) : fix schema discriminator via wrapper `config`
     *
     * Diagnostic du bug précédent : le schéma flat listait `agent_name`,
     * `condition`, `branches`, `items_path`, `step`, `workflow_key` côte-à-côte
     * dans chaque objet step. Testé sur 3 modèles (Gemini 2.5 Pro, 2.5 Flash,
     * gpt-oss-120b), TOUS faisaient la même erreur : ils remplissaient
     * `workflow_key` avec une valeur aléatoire pour n'importe quel type au
     * lieu de remplir le bon champ. Le LLM ne savait pas naviguer la
     * discrimination champ → type.
     *
     * Solution : regrouper TOUS les champs type-spécifiques dans un sous-objet
     * `config`. Le step lui-même n'a plus que `name`, `type`, `description`
     * (les 3 champs toujours présents). Le LLM voit alors une structure claire :
     *
     * ```json
     * {
     *   "name": "classify",
     *   "type": "agent",
     *   "config": { "agent_name": "email_classifier" }
     * }
     * ```
     *
     * Pour chaque type, la documentation du champ `config` indique quels
     * sous-champs mettre. L'objet `config` est déclaré `additionalProperties:
     * true` pour accepter n'importe quels sous-champs sans schéma strict.
     *
     * Le {@see \ArnaudMoncondhuy\SynapseCore\Agent\MultiAgent\WorkflowDefinitionValidator}
     * et les executors lisent via `$step['config']['champ']` avec un fallback
     * sur `$step['champ']` pour rester compatible avec les workflows existants
     * générés pré-K2 ou écrits manuellement.
     *
     * @return array<string, mixed>
     */
    public static function createWorkflow(): array
    {
        return [
            'type' => 'json_schema',
            'json_schema' => [
                'name' => 'architect_create_workflow',
                'schema' => [
                    'type' => 'object',
                    'properties' => [
                        'key' => [
                            'type' => 'string',
                            'description' => 'Clé unique slug (a-z0-9_-) pour identifier le workflow.',
                        ],
                        'name' => [
                            'type' => 'string',
                            'description' => 'Nom lisible du workflow.',
                        ],
                        'description' => [
                            'type' => 'string',
                            'description' => 'Description de ce que fait le workflow.',
                        ],
                        'steps' => [
                            'type' => 'array',
                            'description' => 'Liste ordonnée des étapes du workflow. Chaque step a un `name` unique, un `type` parmi 5 valeurs, et un `config` qui contient les paramètres spécifiques au type. Ne mets JAMAIS les paramètres spécifiques directement sur le step — ils doivent TOUJOURS être dans `config`.',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'name' => [
                                        'type' => 'string',
                                        'description' => 'Identifiant unique de l\'étape dans ce workflow (slug, pas de caractères spéciaux).',
                                    ],
                                    'type' => [
                                        'type' => 'string',
                                        'enum' => ['agent', 'conditional', 'parallel', 'loop', 'sub_workflow'],
                                        'description' => 'Type du step. Défaut: `agent`.',
                                    ],
                                    'config' => [
                                        'type' => 'object',
                                        'description' => 'Paramètres spécifiques au type. agent: {agent_name, input_mapping?}. conditional: {condition, equals?}. parallel: {branches: [steps]}. loop: {items_path, step, item_alias?, max_iterations?}. sub_workflow: {workflow_key, input_mapping?}.',
                                    ],
                                ],
                                'required' => ['name', 'type', 'config'],
                            ],
                        ],
                        'reasoning' => [
                            'type' => 'string',
                            'description' => 'Justification de l\'architecture du workflow, notamment le choix des types de steps (pourquoi parallel ici, pourquoi pas un agent unique, etc.).',
                        ],
                    ],
                    'required' => ['key', 'name', 'description', 'steps', 'reasoning'],
                ],
                // strict: false parce que le sous-objet `config` est volontairement
                // sans `properties` définies (chaque type a des champs différents).
                // La validation stricte est faite post-génération par
                // WorkflowDefinitionValidator.
                'strict' => false,
            ],
        ];
    }

    /**
     * Retourne le schéma correspondant à l'action demandée.
     *
     * @throws \InvalidArgumentException si l'action est inconnue
     *
     * @return array<string, mixed>
     */
    public static function forAction(string $action): array
    {
        return match ($action) {
            'create_agent' => self::createAgent(),
            'improve_prompt' => self::improvePrompt(),
            'create_workflow' => self::createWorkflow(),
            default => throw new \InvalidArgumentException(sprintf('Action architecte inconnue : "%s". Actions valides : create_agent, improve_prompt, create_workflow.', $action)),
        };
    }
}
