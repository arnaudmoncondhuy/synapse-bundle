<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Event;

/**
 * Dispatché par {@see \ArnaudMoncondhuy\SynapseChat\Controller\Api\ChatApiController}
 * quand le {@see \ArnaudMoncondhuy\SynapseCore\Chat\ChatIntentRouter} détecte
 * une demande de création d'agent/workflow dans le message utilisateur, que
 * l'ArchitectAgent produit sa proposition, et que `ArchitectProposalProcessor`
 * l'a persistée comme entité éphémère (Chantier I phase 2).
 *
 * ## Utilisation front (principe 8)
 *
 * La transparency sidebar du chat écoute cet event (type NDJSON
 * `architect_proposal`) et affiche une section `proposals` avec :
 * - un badge (agent vs workflow)
 * - le nom et la description de l'entité proposée
 * - un preview (system prompt tronqué ou liste des steps du workflow)
 * - 3 boutons inline : Inspecter (lien vers l'admin edit) /
 *   Promouvoir (POST CSRF vers la route promote) / Rejeter (POST CSRF
 *   vers la route reject).
 *
 * ## Flux d'autorisation
 *
 * L'entité est créée **éphémère + inactive** par `ArchitectProposalProcessor`.
 * La promotion (bouton Promouvoir) flip `isEphemeral=false` +
 * `retentionUntil=null` + `isActive=true`. Le rejet (bouton Rejeter) set
 * `retentionUntil=now` pour garbage collection immédiate au prochain
 * passage de `synapse:ephemeral:gc`.
 */
final class SynapseArchitectProposalEvent
{
    public function __construct(
        public readonly string $action,
        public readonly int $entityId,
        public readonly string $entityKey,
        public readonly string $entityName,
        public readonly string $entityDescription,
        public readonly string $preview,
        public readonly string $inspectUrl,
        public readonly string $promoteUrl,
        public readonly string $rejectUrl,
        public readonly string $promoteCsrfToken,
        public readonly string $rejectCsrfToken,
        public readonly ?string $reasoning = null,
    ) {
    }

    /**
     * Sérialisation en array pour le streaming NDJSON vers le front.
     *
     * Aligne les clés sur ce que le listener JS
     * `synapse_chat_controller.js::renderArchitectProposal()` attend.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'action' => $this->action,
            'entity_id' => $this->entityId,
            'entity_key' => $this->entityKey,
            'entity_name' => $this->entityName,
            'entity_description' => $this->entityDescription,
            'preview' => $this->preview,
            'inspect_url' => $this->inspectUrl,
            'promote_url' => $this->promoteUrl,
            'reject_url' => $this->rejectUrl,
            'promote_csrf_token' => $this->promoteCsrfToken,
            'reject_csrf_token' => $this->rejectCsrfToken,
            'reasoning' => $this->reasoning,
        ];
    }
}
