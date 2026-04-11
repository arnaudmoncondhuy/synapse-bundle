<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Agent\Autonomy;

/**
 * JSON Schema (OpenAI-compatible) pour le structured output d'un {@see \ArnaudMoncondhuy\SynapseCore\Shared\Model\Plan}.
 *
 * Chantier D. Utilisé par {@see AbstractPlannerAgent} pour contraindre le LLM
 * à produire un JSON strict — plus fiable que le parsing best-effort depuis
 * une réponse texte libre.
 *
 * Format : conforme au schéma JSON Schema 7, adapté à `response_format` de
 * l'API OpenAI / Gemini (via `response_schema`).
 *
 * Aligné mot-pour-mot sur ce que `Plan::fromArray()` attend pour éviter les
 * divergences silencieuses.
 */
final class PlanResponseSchema
{
    /**
     * Schéma au format canonique OpenAI structured outputs, normalisé par
     * {@see \ArnaudMoncondhuy\SynapseCore\Engine\ResponseFormatNormalizer}
     * (cf. {@see \ArnaudMoncondhuy\SynapseCore\Governance\Architect\ArchitectResponseSchema}
     * pour le format de référence attendu).
     *
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        return [
            'type' => 'json_schema',
            'json_schema' => [
                'name' => 'planner_plan',
                'schema' => [
                    'type' => 'object',
                    'properties' => [
                        'reasoning' => [
                            'type' => 'string',
                            'description' => 'Phrase courte (2-4 lignes) expliquant la stratégie globale du plan.',
                        ],
                        'steps' => [
                            'type' => 'array',
                            'description' => 'Liste ordonnée des étapes à exécuter séquentiellement.',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'name' => [
                                        'type' => 'string',
                                        'description' => 'Identifiant unique du step dans le plan (snake_case, pas d\'espaces).',
                                    ],
                                    'agent_name' => [
                                        'type' => 'string',
                                        'description' => 'Clé de l\'agent à appeler pour cette étape. Doit être l\'un des agents listés dans la section "Agents disponibles" du prompt.',
                                    ],
                                    'input_mapping' => [
                                        'type' => 'object',
                                        'description' => 'Mapping des entrées au format pivot. Chaque clé = nom d\'argument de l\'agent cible, chaque valeur = expression JSONPath-lite du type "$.inputs.FOO" ou "$.steps.AUTRE_STEP.output.text".',
                                    ],
                                    'output_key' => [
                                        'type' => 'string',
                                        'description' => 'Clé de stockage du résultat de ce step. Par défaut, égale à `name`.',
                                    ],
                                    'rationale' => [
                                        'type' => 'string',
                                        'description' => 'Phrase courte expliquant POURQUOI ce step est nécessaire dans la chaîne.',
                                    ],
                                ],
                                'required' => ['name', 'agent_name', 'rationale'],
                            ],
                        ],
                        'outputs' => [
                            'type' => 'object',
                            'description' => 'Mapping des outputs finaux du plan. Clé = nom humain, valeur = expression JSONPath-lite type "$.steps.DERNIER_STEP.output.text".',
                        ],
                    ],
                    'required' => ['reasoning', 'steps'],
                ],
            ],
        ];
    }
}
