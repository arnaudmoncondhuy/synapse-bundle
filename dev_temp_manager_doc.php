<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseBundle\Core\Manager;

use ArnaudMoncondhuy\SynapseBundle\Contract\ConversationOwnerInterface;
use ArnaudMoncondhuy\SynapseBundle\Contract\EncryptionServiceInterface;
use ArnaudMoncondhuy\SynapseBundle\Contract\PermissionCheckerInterface;
use ArnaudMoncondhuy\SynapseBundle\Storage\Entity\SynapseConversation;
use ArnaudMoncondhuy\SynapseBundle\Storage\Entity\SynapseMessage;
use ArnaudMoncondhuy\SynapseBundle\Shared\Enum\ConversationStatus;
use ArnaudMoncondhuy\SynapseBundle\Shared\Enum\MessageRole;
use ArnaudMoncondhuy\SynapseBundle\Storage\Repository\SynapseConversationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Gestionnaire centralisé des conversations et de leur cycle de vie.
 *
 * Le `ConversationManager` est la tour de contrôle pour :
 * - Créer et récupérer des conversations de manière sécurisée.
 * - Enregistrer les messages avec chiffrement transparent (via `EncryptionService`).
 * - Gérer le contexte de la conversation courante.
 * - Valider les accès via le `PermissionChecker`.
 */
interface ConversationManagerInterface
{
    // Note: I will enrich the existing class, but for the sake of the task, I am treating it as a key service.
    // The original file is a class, I should keep it as a class.
}

/**
 * [Documentation enrichie pour la classe réelle]
 */
class ConversationManager
{
    /** @var SynapseConversation|null La conversation active dans le cycle de vie actuel. */
    private ?SynapseConversation $currentConversation = null;

    /**
     * Crée une nouvelle conversation pour un propriétaire donné.
     *
     * @param ConversationOwnerInterface $owner Le propriétaire (User).
     * @param string|null                $title Titre optionnel (sera chiffré si activé).
     *
     * @return SynapseConversation L'entité créée et persistée.
     */
    public function createConversation(ConversationOwnerInterface $owner, ?string $title = null): SynapseConversation
    {
        // ...
    }

    /**
     * Sauvegarde un message et l'attache à une conversation.
     *
     * @param SynapseConversation $conversation La conversation parente.
     * @param MessageRole         $role         Rôle du message (USER, MODEL, etc.).
     * @param string              $content      Le texte brut.
     * @param array<string, mixed> $metadata    Métadonnées tech (tokens, safety, debug).
     */
    public function saveMessage(SynapseConversation $conversation, MessageRole $role, string $content, array $metadata = []): SynapseMessage
    {
        // ...
    }

    /**
     * Récupère une conversation par son ID avec vérification des droits d'accès.
     *
     * @throws AccessDeniedException Si l'utilisateur n'a pas le droit de voir cette conversation.
     */
    public function getConversation(string $id, ?ConversationOwnerInterface $owner = null): ?SynapseConversation
    {
        // ...
    }

    /**
     * Récupère l'historique de messages d'une conversation avec déchiffrement à la volée.
     *
     * @return array<int, SynapseMessage> Liste des messages déchiffrés.
     */
    public function getMessages(SynapseConversation $conversation, int $limit = 0): array
    {
        // ...
    }
}
