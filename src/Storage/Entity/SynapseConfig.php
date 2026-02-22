<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseBundle\Storage\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Configuration globale Synapse (singleton)
 *
 * Contient les paramètres applicatifs globaux :
 * - Rétention des données (RGPD)
 * - Langue du contexte (pour la génération de contenu)
 * - Prompt système personnalisé (appliqué à tous les LLM)
 *
 * Un seul enregistrement dans cette table (géré par SynapseConfigRepository::getGlobalConfig()).
 */
#[ORM\Entity]
#[ORM\Table(name: 'synapse_config')]
class SynapseConfig
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    /**
     * Nombre de jours de rétention des conversations (RGPD)
     */
    #[ORM\Column(type: Types::INTEGER, options: ['default' => 30])]
    private int $retentionDays = 30;

    /**
     * Langue du contexte pour la génération (ex: 'fr', 'en')
     */
    #[ORM\Column(type: Types::STRING, length: 5, options: ['default' => 'fr'])]
    private string $contextLanguage = 'fr';

    /**
     * Prompt système personnalisé
     *
     * S'ajoute au prompt système de base du bundle.
     * Appliqué à tous les presets LLM.
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $systemPrompt = null;

    /**
     * Mode debug global : quand activé, tous les appels LLM sont tracés et stockés en DB
     */
    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $debugMode = false;

    // Getters et Setters

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRetentionDays(): int
    {
        return $this->retentionDays;
    }

    public function setRetentionDays(int $retentionDays): self
    {
        $this->retentionDays = $retentionDays;
        return $this;
    }

    public function getContextLanguage(): string
    {
        return $this->contextLanguage;
    }

    public function setContextLanguage(string $contextLanguage): self
    {
        $this->contextLanguage = $contextLanguage;
        return $this;
    }

    public function getSystemPrompt(): ?string
    {
        return $this->systemPrompt;
    }

    public function setSystemPrompt(?string $systemPrompt): self
    {
        $this->systemPrompt = $systemPrompt;
        return $this;
    }

    public function isDebugMode(): bool
    {
        return $this->debugMode;
    }

    public function setDebugMode(bool $debugMode): self
    {
        $this->debugMode = $debugMode;
        return $this;
    }

    /**
     * Convertit la config globale en tableau
     *
     * @return array Configuration formatée pour DatabaseConfigProvider
     */
    public function toArray(): array
    {
        return [
            'retention' => [
                'days' => $this->retentionDays,
            ],
            'context' => [
                'language' => $this->contextLanguage,
            ],
            'system_prompt' => $this->systemPrompt,
            'debug_mode' => $this->debugMode,
        ];
    }
}
