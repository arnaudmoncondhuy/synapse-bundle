<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Chat;

/**
 * Détection d'intentions « meta » dans les messages chat — Chantier I (cheap).
 *
 * Le but de ce service est de repérer les messages où l'utilisateur demande
 * au système d'**improviser un agent ou un workflow** plutôt que de répondre
 * directement. C'est le pivot qui transforme Synapse d'un « chatbot qui
 * utilise des agents » en un « meta-agent créateur d'agents » — la vision
 * capstone du plan.
 *
 * ## Flux cible
 *
 * 1. L'utilisateur tape dans le chat : « crée-moi un agent qui résume un
 *    texte en 3 bullets » ou « fais-moi un workflow pour analyser un PDF
 *    puis le traduire ».
 * 2. `ChatApiController` appelle {@see route()} avec ce message brut.
 * 3. Si une intention est détectée, il court-circuite `ChatService::ask()`
 *    et délègue à {@see \ArnaudMoncondhuy\SynapseCore\Governance\Architect\ArchitectAgent}
 *    avec `action` + `description` extraits.
 * 4. La proposition éphémère revient dans la conversation comme un widget
 *    HITL (promouvoir / rejeter / tester).
 *
 * ## Scope volontairement minimal
 *
 * Ce premier jet est **intentionnellement** un simple regex-matcher :
 * - pas de LLM de classification (coût + latence additionnels pour un cas
 *   qui marche à 95% avec de la regex) ;
 * - pas de nuances fines (la frontière agent/workflow est déterminée par
 *   des mots-clés évidents — « agent » vs « workflow/pipeline/flow »). Les
 *   cas ambigus tombent en `null` et suivent le flux chat standard ;
 * - pas de support multi-langue dynamique — le français est hardcodé (c'est
 *   la langue de travail de cette codebase). L'anglais est supporté en
 *   bonus parce que les mots-clés sont de toute façon universels.
 *
 * Le jour où ça devient insuffisant, on remplace par un LLM de classification
 * léger (gemini-flash-lite, ~50 tokens, quelques ms) qui retourne la même
 * structure.
 *
 * ## Pourquoi pas un subscriber Symfony
 *
 * Un subscriber d'event nécessiterait d'émettre un event avant l'appel à
 * ChatService, ce qui demande un refactor du controller et introduit un
 * couplage event-based là où un simple appel impératif suffit. Pour un
 * chantier « cheap » volontairement minimal, on garde du code impératif
 * trivial que l'appelant invoque quand il veut.
 */
final class ChatIntentRouter
{
    /**
     * Patterns de détection d'une demande de **création d'agent**.
     * L'ordre importe : les patterns les plus spécifiques d'abord.
     *
     * Chaque pattern est une regex case-insensitive qui doit matcher une
     * portion du message (pas un match complet) pour déclencher l'action.
     *
     * @var list<string>
     */
    private const AGENT_PATTERNS = [
        // Français — formes impératives directes (modifier /u pour les accents UTF-8).
        // Toutes les variantes d'impératif acceptent un suffixe [-\s]?(?:moi|nous)? pour
        // capturer « crée-moi », « génère-moi », « fais-moi », etc.
        '/\b(?:cr[ée]{1,2}|g[ée]n[èé]re|fabrique|construis|fais|b[âa]tis)[-\s]?(?:moi|nous)?\s+(?:[mnts]\'?|un|une|le|la)?\s*(?:nouvel?(?:le)?\s+)?agent\b/iu',
        // Français — formes narratives
        '/\b(?:je\s+(?:veux|voudrais|aimerais|souhaite))\s+(?:un\s+)?(?:nouvel?(?:le)?\s+)?agent\b/iu',
        // Anglais
        '/\b(?:create|make|build|generate)\s+(?:me\s+)?(?:a\s+new\s+|an?\s+)?agent\b/iu',
        '/\b(?:i\s+(?:want|need|would\s+like))\s+(?:an?\s+)?(?:new\s+)?agent\b/iu',
    ];

    /**
     * Patterns de détection d'une demande de **création de workflow**.
     *
     * @var list<string>
     */
    private const WORKFLOW_PATTERNS = [
        // Français
        '/\b(?:cr[ée]{1,2}|g[ée]n[èé]re|fabrique|construis|fais|b[âa]tis)[-\s]?(?:moi|nous)?\s+(?:[mnts]\'?|un|une|le|la)?\s*(?:nouveau\s+)?(?:workflow|pipeline|flow|cha[îi]ne\s+d[\'\s]agents?)\b/iu',
        '/\b(?:je\s+(?:veux|voudrais|aimerais|souhaite))\s+(?:un\s+)?(?:nouveau\s+)?(?:workflow|pipeline|flow)\b/iu',
        // Anglais
        '/\b(?:create|make|build|generate)\s+(?:me\s+)?(?:a\s+new\s+|an?\s+)?(?:workflow|pipeline|flow|agent\s+chain)\b/iu',
        '/\b(?:i\s+(?:want|need|would\s+like))\s+(?:an?\s+)?(?:new\s+)?(?:workflow|pipeline|flow)\b/iu',
    ];

    /**
     * Analyse un message et retourne une intention architecte si détectée,
     * sinon `null` pour laisser le flux chat standard prendre le relais.
     *
     * @return array{action: 'create_agent'|'create_workflow', description: string}|null
     */
    public function route(string $message): ?array
    {
        $trimmed = trim($message);
        if ('' === $trimmed) {
            return null;
        }

        // Workflow d'abord : un message contenant à la fois « agent » ET
        // « workflow » est plus probablement une demande de workflow (le
        // workflow étant l'objet plus spécifique).
        foreach (self::WORKFLOW_PATTERNS as $pattern) {
            if (1 === preg_match($pattern, $trimmed)) {
                return [
                    'action' => 'create_workflow',
                    'description' => $trimmed,
                ];
            }
        }

        foreach (self::AGENT_PATTERNS as $pattern) {
            if (1 === preg_match($pattern, $trimmed)) {
                return [
                    'action' => 'create_agent',
                    'description' => $trimmed,
                ];
            }
        }

        return null;
    }

    /**
     * Raccourci pratique : true si une intention architecte est détectée.
     */
    public function shouldRouteToArchitect(string $message): bool
    {
        return null !== $this->route($message);
    }
}
