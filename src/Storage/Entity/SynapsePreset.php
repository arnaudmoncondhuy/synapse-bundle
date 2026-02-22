<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseBundle\Storage\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Preset de configuration LLM (multi-preset sans scope)
 *
 * Un preset associe un provider + un modèle + des paramètres de génération.
 * Un seul preset peut être actif à la fois (enforced by application).
 *
 * Les credentials du provider sont stockés dans SynapseProvider.
 */
#[ORM\Entity]
#[ORM\Table(name: 'synapse_preset')]
#[ORM\HasLifecycleCallbacks]
class SynapsePreset
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    /**
     * Nom lisible du preset
     */
    #[ORM\Column(type: Types::STRING, length: 100)]
    private string $name = 'Preset par défaut';

    /**
     * Preset actif (un seul actif à la fois)
     */
    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $isActive = false;

    /**
     * Provider LLM actif pour ce preset ('gemini', 'ovh', etc.)
     */
    #[ORM\Column(type: Types::STRING, length: 50)]
    private string $providerName = 'gemini';

    /**
     * Modèle LLM à utiliser (dépend du provider actif)
     */
    #[ORM\Column(type: Types::STRING, length: 100)]
    private string $model = 'gemini-2.5-flash';

    // Safety Settings

    /**
     * Activer les filtres de sécurité
     */
    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $safetyEnabled = false;

    /**
     * Seuil par défaut pour toutes les catégories
     */
    #[ORM\Column(type: Types::STRING, length: 50, nullable: true)]
    private ?string $safetyDefaultThreshold = 'BLOCK_MEDIUM_AND_ABOVE';

    /**
     * Seuil pour le hate speech
     */
    #[ORM\Column(type: Types::STRING, length: 50, nullable: true)]
    private ?string $safetyHateSpeech = null;

    /**
     * Seuil pour le contenu dangereux
     */
    #[ORM\Column(type: Types::STRING, length: 50, nullable: true)]
    private ?string $safetyDangerousContent = null;

    /**
     * Seuil pour le harcèlement
     */
    #[ORM\Column(type: Types::STRING, length: 50, nullable: true)]
    private ?string $safetyHarassment = null;

    /**
     * Seuil pour le contenu sexuellement explicite
     */
    #[ORM\Column(type: Types::STRING, length: 50, nullable: true)]
    private ?string $safetySexuallyExplicit = null;

    // Generation Config

    /**
     * Température (0.0 - 2.0) - Créativité
     */
    #[ORM\Column(type: Types::FLOAT, options: ['default' => 1.0])]
    private float $generationTemperature = 1.0;

    /**
     * Top P (0.0 - 1.0) - Nucleus sampling
     */
    #[ORM\Column(type: Types::FLOAT, options: ['default' => 0.95])]
    private float $generationTopP = 0.95;

    /**
     * Top K (1 - 100) - Filtrage tokens
     */
    #[ORM\Column(type: Types::INTEGER, options: ['default' => 40])]
    private int $generationTopK = 40;

    /**
     * Nombre maximum de tokens de sortie
     */
    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $generationMaxOutputTokens = null;

    /**
     * Séquences d'arrêt (JSON array)
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $generationStopSequences = null;

    // Thinking Config

    /**
     * Activer le thinking natif (Gemini 2.5+)
     */
    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    private bool $thinkingEnabled = true;

    /**
     * Budget thinking (0 - 24576 tokens)
     */
    #[ORM\Column(type: Types::INTEGER, options: ['default' => 1024])]
    private int $thinkingBudget = 1024;

    /**
     * Effort de réflexion pour OVH (high, medium, low, minimal)
     */
    #[ORM\Column(type: Types::STRING, length: 20, options: ['default' => 'high'])]
    private string $reasoningEffort = 'high';

    // Context Caching

    /**
     * Activer le context caching
     */
    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $contextCachingEnabled = false;

    /**
     * ID du cached content (Vertex AI)
     */
    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $contextCachingId = null;

    /**
     * Activer le streaming (SSE). Si désactivé, mode synchrone pour debug facile
     */
    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    private bool $streamingEnabled = true;

    /**
     * Date de dernière mise à jour
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function updateTimestamp(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    // Getters et Setters

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): self
    {
        $this->isActive = $isActive;
        return $this;
    }

    public function getProviderName(): string
    {
        return $this->providerName;
    }

    public function setProviderName(string $providerName): self
    {
        $this->providerName = $providerName;
        return $this;
    }

    public function getModel(): string
    {
        return $this->model;
    }

    public function setModel(string $model): self
    {
        $this->model = $model;
        return $this;
    }

    public function isSafetyEnabled(): bool
    {
        return $this->safetyEnabled;
    }

    public function setSafetyEnabled(bool $safetyEnabled): self
    {
        $this->safetyEnabled = $safetyEnabled;
        return $this;
    }

    public function getSafetyDefaultThreshold(): ?string
    {
        return $this->safetyDefaultThreshold;
    }

    public function setSafetyDefaultThreshold(?string $safetyDefaultThreshold): self
    {
        $this->safetyDefaultThreshold = $safetyDefaultThreshold;
        return $this;
    }

    public function getSafetyHateSpeech(): ?string
    {
        return $this->safetyHateSpeech;
    }

    public function setSafetyHateSpeech(?string $safetyHateSpeech): self
    {
        $this->safetyHateSpeech = $safetyHateSpeech;
        return $this;
    }

    public function getSafetyDangerousContent(): ?string
    {
        return $this->safetyDangerousContent;
    }

    public function setSafetyDangerousContent(?string $safetyDangerousContent): self
    {
        $this->safetyDangerousContent = $safetyDangerousContent;
        return $this;
    }

    public function getSafetyHarassment(): ?string
    {
        return $this->safetyHarassment;
    }

    public function setSafetyHarassment(?string $safetyHarassment): self
    {
        $this->safetyHarassment = $safetyHarassment;
        return $this;
    }

    public function getSafetySexuallyExplicit(): ?string
    {
        return $this->safetySexuallyExplicit;
    }

    public function setSafetySexuallyExplicit(?string $safetySexuallyExplicit): self
    {
        $this->safetySexuallyExplicit = $safetySexuallyExplicit;
        return $this;
    }

    public function getGenerationTemperature(): float
    {
        return $this->generationTemperature;
    }

    public function setGenerationTemperature(float $generationTemperature): self
    {
        $this->generationTemperature = $generationTemperature;
        return $this;
    }

    public function getGenerationTopP(): float
    {
        return $this->generationTopP;
    }

    public function setGenerationTopP(float $generationTopP): self
    {
        $this->generationTopP = $generationTopP;
        return $this;
    }

    public function getGenerationTopK(): int
    {
        return $this->generationTopK;
    }

    public function setGenerationTopK(int $generationTopK): self
    {
        $this->generationTopK = $generationTopK;
        return $this;
    }

    public function getGenerationMaxOutputTokens(): ?int
    {
        return $this->generationMaxOutputTokens;
    }

    public function setGenerationMaxOutputTokens(?int $generationMaxOutputTokens): self
    {
        $this->generationMaxOutputTokens = $generationMaxOutputTokens;
        return $this;
    }

    public function getGenerationStopSequences(): ?array
    {
        return $this->generationStopSequences;
    }

    public function setGenerationStopSequences(?array $generationStopSequences): self
    {
        $this->generationStopSequences = $generationStopSequences;
        return $this;
    }

    public function isThinkingEnabled(): bool
    {
        return $this->thinkingEnabled;
    }

    public function setThinkingEnabled(bool $thinkingEnabled): self
    {
        $this->thinkingEnabled = $thinkingEnabled;
        return $this;
    }

    public function getThinkingBudget(): int
    {
        return $this->thinkingBudget;
    }

    public function setThinkingBudget(int $thinkingBudget): self
    {
        $this->thinkingBudget = $thinkingBudget;
        return $this;
    }

    public function getReasoningEffort(): string
    {
        return $this->reasoningEffort;
    }

    public function setReasoningEffort(string $reasoningEffort): self
    {
        $this->reasoningEffort = $reasoningEffort;
        return $this;
    }

    public function isContextCachingEnabled(): bool
    {
        return $this->contextCachingEnabled;
    }

    public function setContextCachingEnabled(bool $contextCachingEnabled): self
    {
        $this->contextCachingEnabled = $contextCachingEnabled;
        return $this;
    }

    public function getContextCachingId(): ?string
    {
        return $this->contextCachingId;
    }

    public function setContextCachingId(?string $contextCachingId): self
    {
        $this->contextCachingId = $contextCachingId;
        return $this;
    }

    public function isStreamingEnabled(): bool
    {
        return $this->streamingEnabled;
    }

    public function setStreamingEnabled(bool $streamingEnabled): self
    {
        $this->streamingEnabled = $streamingEnabled;
        return $this;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * Convertit le preset en tableau pour ChatService / LLM clients
     *
     * Note : les credentials du provider (provider_credentials) sont ajoutés
     * par DatabaseConfigProvider après fusion avec SynapseProvider.
     * Les settings globaux (retention, context, system_prompt) sont ajoutés par
     * DatabaseConfigProvider depuis SynapseConfig.
     *
     * @return array Configuration formatée pour les services LLM
     */
    public function toArray(): array
    {
        $config = [
            'provider'  => $this->providerName,
            'model'     => $this->model,
        ];

        // Safety Settings
        $config['safety_settings'] = [
            'enabled'           => $this->safetyEnabled,
            'default_threshold' => $this->safetyDefaultThreshold,
            'thresholds'        => array_filter([
                'hate_speech'       => $this->safetyHateSpeech,
                'dangerous_content' => $this->safetyDangerousContent,
                'harassment'        => $this->safetyHarassment,
                'sexually_explicit' => $this->safetySexuallyExplicit,
            ]),
        ];

        // Generation Config
        $config['generation_config'] = [
            'temperature' => $this->generationTemperature,
            'top_p'       => $this->generationTopP,
            'top_k'       => $this->generationTopK,
        ];

        if ($this->generationMaxOutputTokens !== null) {
            $config['generation_config']['max_output_tokens'] = $this->generationMaxOutputTokens;
        }

        if ($this->generationStopSequences !== null && count($this->generationStopSequences) > 0) {
            $config['generation_config']['stop_sequences'] = $this->generationStopSequences;
        }

        // Thinking Config
        $config['thinking'] = [
            'enabled'           => $this->thinkingEnabled,
            'budget'            => $this->thinkingBudget,
            'reasoning_effort'  => $this->reasoningEffort,
        ];

        // Context Caching
        if ($this->contextCachingEnabled && $this->contextCachingId !== null) {
            $config['context_caching'] = [
                'enabled'           => true,
                'cached_content_id' => $this->contextCachingId,
            ];
        }

        // Streaming Mode
        $config['streaming_enabled'] = $this->streamingEnabled;

        return $config;
    }
}
