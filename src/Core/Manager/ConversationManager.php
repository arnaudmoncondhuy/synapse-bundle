<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseBundle\Core\Manager;

use ArnaudMoncondhuy\SynapseBundle\Contract\ConversationOwnerInterface;
use ArnaudMoncondhuy\SynapseBundle\Contract\EncryptionServiceInterface;
use ArnaudMoncondhuy\SynapseBundle\Contract\PermissionCheckerInterface;
use ArnaudMoncondhuy\SynapseBundle\Storage\Entity\Conversation;
use ArnaudMoncondhuy\SynapseBundle\Storage\Entity\Message;
use ArnaudMoncondhuy\SynapseBundle\Shared\Enum\ConversationStatus;
use ArnaudMoncondhuy\SynapseBundle\Shared\Enum\MessageRole;
use ArnaudMoncondhuy\SynapseBundle\Storage\Repository\ConversationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Gestionnaire centralisé des conversations
 *
 * Responsabilités :
 * - CRUD conversations avec chiffrement transparent
 * - Gestion des messages
 * - Gestion des risques (Ange Gardien)
 * - Vérification des permissions
 * - Contexte thread-local (conversation courante)
 */
class ConversationManager
{
    private ?Conversation $currentConversation = null;
    private ?ConversationRepository $resolvedConversationRepo = null;

    public function __construct(
        private EntityManagerInterface $em,
        private ?ConversationRepository $conversationRepo = null,
        private ?EncryptionServiceInterface $encryptionService = null,
        private ?PermissionCheckerInterface $permissionChecker = null,
        private ?string $conversationClass = null,
        private ?string $messageClass = null,
        private ?\ArnaudMoncondhuy\SynapseBundle\Core\Accounting\TokenAccountingService $accountingService = null,
        private ?\ArnaudMoncondhuy\SynapseBundle\Storage\Repository\SynapseModelRepository $modelRepo = null,
    ) {}

    /**
     * Récupère le repository de conversations (injecté ou résolu dynamiquement)
     */
    private function getConversationRepo(): ConversationRepository
    {
        if ($this->conversationRepo !== null) {
            return $this->conversationRepo;
        }

        if ($this->resolvedConversationRepo === null) {
            $this->resolvedConversationRepo = $this->em->getRepository($this->getConversationClass());
        }

        return $this->resolvedConversationRepo;
    }

    /**
     * Crée une nouvelle conversation
     *
     * @param ConversationOwnerInterface $owner Propriétaire
     * @param string|null $title Titre (sera chiffré si encryption activée)
     * @return Conversation Nouvelle conversation
     */
    public function createConversation(
        ConversationOwnerInterface $owner,
        ?string $title = null
    ): Conversation {
        $conversation = $this->instantiateConversation();
        $conversation->setOwner($owner);

        if ($title !== null) {
            $this->setTitle($conversation, $title);
        }

        $this->em->persist($conversation);
        $this->em->flush();

        return $conversation;
    }

    /**
     * Met à jour le titre d'une conversation
     *
     * @param Conversation $conversation Conversation
     * @param string $title Nouveau titre (sera chiffré si encryption activée)
     */
    public function updateTitle(Conversation $conversation, string $title): void
    {
        $this->checkPermission($conversation, 'edit');
        $this->setTitle($conversation, $title);
        $this->em->flush();
    }

    /**
     * Sauvegarde un message dans une conversation
     *
     * @param Conversation $conversation Conversation
     * @param MessageRole $role Rôle du message
     * @param string $content Contenu (sera chiffré si encryption activée)
     * @param array $metadata Métadonnées (tokens, safety_ratings, etc.)
     * @return Message Message créé
     */
    public function saveMessage(
        Conversation $conversation,
        MessageRole $role,
        string $content,
        array $metadata = []
    ): Message {
        $message = $this->instantiateMessage();
        $message->setConversation($conversation);
        $message->setRole($role);
        $this->setMessageContent($message, $content);

        // Métadonnées
        if (isset($metadata['prompt_tokens'])) {
            $message->setPromptTokens($metadata['prompt_tokens']);
        }
        if (isset($metadata['completion_tokens'])) {
            $message->setCompletionTokens($metadata['completion_tokens']);
        }
        if (isset($metadata['thinking_tokens'])) {
            $message->setThinkingTokens($metadata['thinking_tokens']);
        }
        if (isset($metadata['safety_ratings'])) {
            $message->setSafetyRatings($metadata['safety_ratings']);
        }
        if (isset($metadata['blocked'])) {
            $message->setBlocked($metadata['blocked']);
        }
        if (isset($metadata['metadata'])) {
            $message->setMetadata($metadata['metadata']);
        }

        // Enregistrer le modèle et calculer le coût
        $model = $metadata['model'] ?? null;
        if ($model) {
            $message->setMetadataValue('model', $model);

            // Calculer le coût si le service est disponible
            if ($this->accountingService && $this->modelRepo) {
                $pricingMap = $this->modelRepo->findAllPricingMap();
                $modelPricing = $pricingMap[$model] ?? ['input' => 0.0, 'output' => 0.0];
                $usage = [
                    'prompt' => $message->getPromptTokens() ?? 0,
                    'completion' => $message->getCompletionTokens() ?? 0,
                    'thinking' => $message->getThinkingTokens() ?? 0,
                ];
                $cost = $this->accountingService->calculateCost($usage, $modelPricing);
                $message->setMetadataValue('cost', $cost);
                $message->setMetadataValue('pricing', $modelPricing);
            }
        }

        // Calculer total tokens
        $message->calculateTotalTokens();

        // Éviter les doublons si l'objet est déjà dans la collection 
        // (cas où on sauve un message qui a déjà été ajouté manuellement avant persist)
        if (!$conversation->getMessages()->contains($message)) {
            $conversation->addMessage($message);
        }
        $this->em->persist($message);
        $this->em->flush();

        return $message;
    }

    /**
     * Récupère une conversation avec vérification des permissions
     *
     * @param string $id ID de la conversation
     * @param ConversationOwnerInterface|null $owner Propriétaire (optionnel, pour filtrer)
     * @return Conversation|null Conversation ou null si non trouvée
     * @throws AccessDeniedException Si pas de permission
     */
    public function getConversation(string $id, ?ConversationOwnerInterface $owner = null): ?Conversation
    {
        $conversation = $this->getConversationRepo()->find($id);

        if ($conversation === null) {
            return null;
        }

        // Vérifier ownership si fourni
        if ($owner !== null && $conversation->getOwner()->getId() !== $owner->getId()) {
            throw new AccessDeniedException('Access denied to this conversation');
        }

        // Vérifier permission
        $this->checkPermission($conversation, 'view');

        return $conversation;
    }

    /**
     * Récupère les conversations d'un utilisateur avec déchiffrement des titres
     *
     * @param ConversationOwnerInterface $owner Propriétaire
     * @param ConversationStatus|null $status Filtrer par statut
     * @param int $limit Nombre maximum de résultats
     * @return Conversation[] Conversations avec titres déchiffrés
     */
    public function getUserConversations(
        ConversationOwnerInterface $owner,
        ?ConversationStatus $status = null,
        int $limit = 50
    ): array {
        if ($status !== null) {
            $conversations = $this->getConversationRepo()->findBy(
                ['owner' => $owner, 'status' => $status],
                ['updatedAt' => 'DESC'],
                $limit
            );
        } else {
            $conversations = $this->getConversationRepo()->findActiveByOwner($owner, $limit);
        }

        // Déchiffrer les titres
        foreach ($conversations as $conversation) {
            if ($conversation->getTitle() !== null && $this->encryptionService !== null) {
                if ($this->encryptionService->isEncrypted($conversation->getTitle())) {
                    $decrypted = $this->encryptionService->decrypt($conversation->getTitle());
                    $conversation->setTitle($decrypted);
                }
            }
        }

        return $conversations;
    }

    /**
     * Récupère les messages d'une conversation avec déchiffrement
     *
     * @param Conversation $conversation Conversation
     * @param int $limit Nombre maximum de messages (0 = tous)
     * @return Message[] Messages déchiffrés
     */
    public function getMessages(Conversation $conversation, int $limit = 0): array
    {
        $this->checkPermission($conversation, 'view');

        $messageClass = $this->getMessageClass();
        $messageRepo = $this->em->getRepository($messageClass);
        $messages = $messageRepo->findByConversation($conversation, $limit);

        // FIX: Convert Doctrine Collection to plain array to avoid serialization issues with reset()
        if (is_object($messages)) {
            $messages = array_values($messages instanceof \Traversable ? iterator_to_array($messages) : (array)$messages);
        }

        // Déchiffrer les contenus ou normaliser
        foreach ($messages as $message) {
            if ($this->encryptionService !== null && $this->encryptionService->isEncrypted($message->getContent())) {
                $decrypted = $this->encryptionService->decrypt($message->getContent());
                $message->setDecryptedContent($decrypted);
            } else {
                // Fallback explicite pour les messages non chiffrés
                $message->setDecryptedContent($message->getContent());
            }
        }

        // CRITICAL FIX: Clone objects to detach from Doctrine session
        // Doctrine converts entities to arrays when accessed in closure context - cloning breaks this
        $clonedMessages = [];
        foreach ($messages as $msg) {
            $clonedMessages[] = clone $msg;
        }

        return $clonedMessages;
    }

    /**
     * Supprime une conversation (soft delete)
     *
     * @param Conversation $conversation Conversation à supprimer
     */
    public function deleteConversation(Conversation $conversation): void
    {
        $this->checkPermission($conversation, 'delete');
        $conversation->softDelete();
        $this->em->flush();
    }

    /**
     * Retourne les messages d'une conversation formatés en tableau pour le rendu Twig.
     *
     * Gère les deux formats possibles (objet Message ou tableau legacy).
     * Filtre les messages non affichables (système, fonction, etc.).
     *
     * @param Conversation $conversation Conversation à formater
     * @return array<int, array{role: string, content: string, parts: array, metadata: array}>
     */
    public function getHistoryArray(Conversation $conversation): array
    {
        $history = [];
        $messages = $this->getMessages($conversation);

        foreach ($messages as $msg) {
            if (is_array($msg)) {
                $role = strtolower($msg['role'] ?? 'model');
                if (!in_array($role, ['user', 'model', 'assistant'], true)) {
                    continue;
                }
                $content  = $msg['content'] ?? ($msg['parts'][0]['text'] ?? '');
                $parts    = $msg['parts'] ?? [['text' => $content]];
                $metadata = $msg['metadata'] ?? [];
            } else {
                if (!$msg->isDisplayable()) {
                    continue;
                }
                $role     = $msg->getRole()->value;
                $content  = $msg->getDecryptedContent() ?? $msg->getContent();
                $metadata = $msg->getMetadata() ?? [];
                $parts    = [['text' => $content]];
            }

            $history[] = [
                'role'     => $role,
                'content'  => $content,
                'parts'    => $parts,
                'metadata' => $metadata,
            ];
        }

        return $history;
    }

    /**
     * Définit la conversation courante (contexte thread-local)
     *
     * @param Conversation|null $conversation Conversation courante
     */
    public function setCurrentConversation(?Conversation $conversation): void
    {
        $this->currentConversation = $conversation;
    }

    /**
     * Récupère la conversation courante
     *
     * @return Conversation|null Conversation courante ou null
     */
    public function getCurrentConversation(): ?Conversation
    {
        return $this->currentConversation;
    }

    // Méthodes privées

    /**
     * Définit le titre d'une conversation avec chiffrement transparent
     */
    private function setTitle(Conversation $conversation, string $title): void
    {
        if ($this->encryptionService !== null) {
            $title = $this->encryptionService->encrypt($title);
        }
        $conversation->setTitle($title);
    }

    /**
     * Définit le contenu d'un message avec chiffrement transparent
     */
    private function setMessageContent(Message $message, string $content): void
    {
        $message->setDecryptedContent($content);

        if ($this->encryptionService !== null) {
            $content = $this->encryptionService->encrypt($content);
        }
        $message->setContent($content);
    }

    /**
     * Vérifie les permissions sur une conversation
     *
     * @throws AccessDeniedException Si pas de permission
     */
    private function checkPermission(Conversation $conversation, string $action): void
    {
        if ($this->permissionChecker === null) {
            return; // Pas de vérification si pas de checker
        }

        $allowed = match ($action) {
            'view' => $this->permissionChecker->canView($conversation),
            'edit' => $this->permissionChecker->canEdit($conversation),
            'delete' => $this->permissionChecker->canDelete($conversation),
            default => false,
        };

        if (!$allowed) {
            throw new AccessDeniedException("Access denied: cannot {$action} this conversation");
        }
    }

    /**
     * Instancie une nouvelle conversation
     *
     * À override dans les projets si classe custom
     */
    protected function instantiateConversation(): Conversation
    {
        $class = $this->getConversationClass();
        return new $class();
    }

    /**
     * Instancie un nouveau message
     *
     * À override dans les projets si classe custom
     */
    protected function instantiateMessage(): Message
    {
        $class = $this->getMessageClass();
        return new $class();
    }

    /**
     * Retourne la classe Conversation à utiliser
     *
     * À override dans les projets
     */
    protected function getConversationClass(): string
    {
        return $this->conversationClass ?? Conversation::class;
    }

    /**
     * Retourne la classe Message à utiliser
     *
     * À override dans les projets
     */
    protected function getMessageClass(): string
    {
        return $this->messageClass ?? Message::class;
    }
}
