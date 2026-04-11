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
     * Chantier F phase 2 : supporte 5 types de steps (`agent`, `conditional`,
     * `parallel`, `loop`, `sub_workflow`). Le schéma est volontairement
     * non-strict (`strict: false`) parce qu'OpenAI structured outputs ne
     * gère pas bien les champs optionnels divergents selon un discriminator.
     * La validation sémantique stricte est faite post-génération par
     * {@see \ArnaudMoncondhuy\SynapseCore\Agent\MultiAgent\WorkflowDefinitionValidator}
     * appelé depuis {@see \ArnaudMoncondhuy\SynapseCore\Governance\Architect\ArchitectProposalProcessor}.
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
                            'description' => 'Liste ordonnée des étapes du workflow. Chaque step a un `name` unique et un `type`. Les 5 types supportés sont : `agent` (défaut, appelle un agent nommé), `conditional` (évalue une expression pour produire un flag), `parallel` (exécute N branches indépendantes), `loop` (itère sur un array), `sub_workflow` (délègue à un workflow persistant existant). Voir les descriptions des champs pour les exigences de chaque type.',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'name' => [
                                        'type' => 'string',
                                        'description' => 'Identifiant unique de l\'étape dans ce workflow.',
                                    ],
                                    'type' => [
                                        'type' => 'string',
                                        'enum' => ['agent', 'conditional', 'parallel', 'loop', 'sub_workflow'],
                                        'description' => 'Type du step. Défaut: `agent`.',
                                    ],
                                    'agent_name' => [
                                        'type' => 'string',
                                        'description' => 'Pour type=agent : clé de l\'agent qui exécute cette étape. Obligatoire si type=agent, ignoré sinon.',
                                    ],
                                    'input_mapping' => [
                                        'type' => 'object',
                                        'description' => 'Mapping des inputs — clé: nom du paramètre, valeur: expression JSONPath-lite ($.inputs.X ou $.steps.STEP.output.X). S\'applique à agent, conditional, loop (pour le step template), sub_workflow.',
                                    ],
                                    'condition' => [
                                        'type' => 'string',
                                        'description' => 'Pour type=conditional : expression JSONPath à évaluer (ex: "$.steps.classify.output.data.priority"). Obligatoire si type=conditional. L\'output du step sera {matched: bool, value: <valeur>}.',
                                    ],
                                    'equals' => [
                                        'description' => 'Pour type=conditional : valeur de comparaison stricte. Si absent, le matched est un truthy check sur la valeur évaluée.',
                                    ],
                                    'branches' => [
                                        'type' => 'array',
                                        'description' => 'Pour type=parallel : liste des branches à exécuter. Chaque branche est un step complet (avec name + type + champs spécifiques). Les branches partagent le state initial mais ne peuvent pas se voir entre elles. Output sous $.steps.X.output.data.branches.<branchName>.',
                                        'items' => ['type' => 'object'],
                                    ],
                                    'items_path' => [
                                        'type' => 'string',
                                        'description' => 'Pour type=loop : expression JSONPath vers un array d\'items (ex: "$.inputs.documents"). Obligatoire si type=loop.',
                                    ],
                                    'step' => [
                                        'type' => 'object',
                                        'description' => 'Pour type=loop : step template à exécuter pour chaque item. L\'item courant est exposé sous $.inputs.<item_alias> (défaut "item"). Obligatoire si type=loop.',
                                    ],
                                    'item_alias' => [
                                        'type' => 'string',
                                        'description' => 'Pour type=loop : alias de la variable item courant (défaut "item"). L\'index 0-based est toujours exposé sous $.inputs.index.',
                                    ],
                                    'max_iterations' => [
                                        'type' => 'integer',
                                        'description' => 'Pour type=loop : limite dure du nombre d\'itérations (défaut 50). Garde-fou coût tokens.',
                                    ],
                                    'workflow_key' => [
                                        'type' => 'string',
                                        'description' => 'Pour type=sub_workflow : clé d\'un workflow actif existant à invoquer. Son output devient l\'output de ce step. Obligatoire si type=sub_workflow.',
                                    ],
                                ],
                                'required' => ['name'],
                            ],
                        ],
                        'reasoning' => [
                            'type' => 'string',
                            'description' => 'Justification de l\'architecture du workflow, notamment le choix des types de steps (pourquoi parallel ici, pourquoi pas un agent unique, etc.).',
                        ],
                    ],
                    'required' => ['key', 'name', 'description', 'steps', 'reasoning'],
                ],
                // Chantier F phase 2 : strict=false parce que OpenAI structured
                // outputs refuse les propriétés optionnelles divergentes selon
                // un type discriminator. La validation sémantique est faite
                // post-génération par WorkflowDefinitionValidator.
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
