<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Storage\Entity\Brain;

use ArnaudMoncondhuy\SynapseCore\Storage\Repository\Brain\MemorySourceRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * MemorySource — point d'entrée unique pour tout stimulus brut entrant.
 *
 * Une source = un stimulus identifié par un UUID. Une source produit ensuite
 * N neurones répartis dans N aires cérébrales (jusqu'à 7), chacun étant une
 * **lecture distincte** de la même source par son aire correspondante.
 *
 * Analogie biologique : un stimulus unique (un visage croisé) active
 * simultanément cortex visuel (formes), hippocampe (déjà vu), néocortex
 * (c'est X), amygdale (émotion). Un stimulus, N processings, N traces.
 *
 * La source est **immutable** une fois persistée. Le payload brut sert de
 * référence pour re-vectoriser les neurones quand on change d'extracteur,
 * sans toucher à l'original (charte §2.4 — pattern Prisma).
 *
 * Cf. {@link docs/brain-v3-design.md} §4 ("Source commune").
 */
#[ORM\Entity(repositoryClass: MemorySourceRepository::class)]
#[ORM\Table(name: 'brain_memory_source')]
#[ORM\Index(columns: ['provider'], name: 'idx_brain_memory_source_provider')]
#[ORM\Index(columns: ['external_id'], name: 'idx_brain_memory_source_external_id')]
#[ORM\Index(columns: ['received_at'], name: 'idx_brain_memory_source_received_at')]
class MemorySource
{
    /**
     * UUID v7 (ordre temporel naturel). Généré par le constructeur, immutable.
     */
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    /**
     * Identifiant abstrait du producteur du stimulus.
     *
     * Vocabulaire **agnostique** côté noyau : `manual`, `webhook_generic`,
     * `rag`, etc. Les valeurs concrètes ("gmail", "calendar", "pipedrive"…)
     * sont fournies par les apps hôtes via Module/{Domain} — le noyau Brain
     * ne sait pas ce qu'elles signifient.
     */
    #[ORM\Column(type: Types::STRING, length: 50)]
    private string $provider;

    /**
     * Identifiant du stimulus côté provider externe (mailId, eventId, dealId).
     *
     * Nullable car les sources `manual` n'ont pas d'externalId. Indexé pour
     * permettre la déduplication / résolution lors de réceptions répétées.
     */
    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $externalId;

    /**
     * Payload brut reçu, tel quel.
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(type: 'json')]
    private array $rawPayload;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $receivedAt;

    /**
     * Identifiant abstrait de l'utilisateur / propriétaire du stimulus.
     *
     * UUID nullable — la résolution vers une entité applicative concrète
     * est de la responsabilité de l'hôte (charte §2.1).
     */
    #[ORM\Column(type: 'uuid', nullable: true)]
    private ?Uuid $ownerId;

    /**
     * @param array<string, mixed> $rawPayload
     */
    public function __construct(
        string $provider,
        array $rawPayload,
        ?string $externalId = null,
        ?Uuid $ownerId = null,
    ) {
        $this->id = Uuid::v7();
        $this->provider = $provider;
        $this->externalId = $externalId;
        $this->rawPayload = $rawPayload;
        $this->ownerId = $ownerId;
        $this->receivedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function getExternalId(): ?string
    {
        return $this->externalId;
    }

    /**
     * @return array<string, mixed>
     */
    public function getRawPayload(): array
    {
        return $this->rawPayload;
    }

    public function getReceivedAt(): \DateTimeImmutable
    {
        return $this->receivedAt;
    }

    public function getOwnerId(): ?Uuid
    {
        return $this->ownerId;
    }
}
