<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Contract;

/**
 * Marker interface : tout agent qui l'implémente est **délégable par un autre
 * agent**. Chantier D (autonomie).
 *
 * Un agent "callable by agents" est automatiquement exposé comme tool
 * (`call_agent__<key>`) par le
 * {@see \ArnaudMoncondhuy\SynapseCore\Agent\Autonomy\AgentAsToolRegistry}
 * auprès des planners et de tout agent consommateur qui souhaite l'utiliser.
 *
 * ## Pourquoi une interface marker et pas simplement une méthode ?
 *
 * Parce que c'est une **capacité explicite et irréversible** du point de vue
 * du design : un agent dont la vocation est d'être appelé par d'autres agents
 * a des responsabilités différentes d'un agent conversationnel user-facing :
 *
 * 1. Son `getDescription()` doit être orientée « à quoi je sers dans la chaîne »,
 *    pas « comment je me présente à l'utilisateur ».
 * 2. Son `execute()` doit être robuste à un input structuré potentiellement
 *    produit par un autre LLM (validation plus stricte).
 * 3. Ses outputs doivent être sérialisables / inspectables par l'agent appelant.
 * 4. Il ne doit pas modifier d'état global non-réversible sans passer par des
 *    tools dédiés (ex: pas de send_email depuis un sub-agent sans confirmation).
 *
 * Hériter d'`AbstractAgent` ne suffit pas : il faut *déclarer* que l'agent
 * accepte cette responsabilité.
 *
 * ## Exemple
 *
 * ```php
 * final class WebSearchAgent extends AbstractAgent implements CallableByAgentsInterface
 * {
 *     public function getName(): string { return 'web_search'; }
 *     public function getDescription(): string {
 *         return 'Cherche sur le web et retourne les 5 premiers résultats (titre + URL + extrait).';
 *     }
 *     // ...
 * }
 * ```
 *
 * Le planificateur verra automatiquement un tool `call_agent__web_search` dans
 * sa liste de tools disponibles, avec le même description.
 */
interface CallableByAgentsInterface
{
}
