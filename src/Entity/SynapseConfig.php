<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseBundle\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Configuration dynamique du bundle Synapse
 *
 * Permet de modifier la configuration en temps réel sans redémarrage.
 * Remplace les paramètres statiques YAML.
 *
 * Support multi-scope pour différents contextes (ex: default, admin, support-client)
 */
#[ORM\Entity]
#[ORM\Table(name: 'synapse_config')]
#[ORM\HasLifecycleCallbacks]
class SynapseConfig
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    /**
     * Scope de la configuration (permet plusieurs configs)
     *
     * Exemples : 'default', 'admin', 'support-client', 'moderation'
     */
    #[ORM\Column(type: Types::STRING, length: 50, unique: true)]
    private string $scope = 'default';

    /**
     * Modèle Gemini à utiliser
     */
    #[ORM\Column(type: Types::STRING, length: 100)]
    private string $model = 'gemini-2.0-flash-exp';

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

    // Custom Prompt

    /**
     * Prompt système personnalisé
     *
     * S'ajoute au prompt système de base du bundle.
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $systemPrompt = null;

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

    public function getScope(): string
    {
        return $this->scope;
    }

    public function setScope(string $scope): self
    {
        $this->scope = $scope;
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

    public function getSystemPrompt(): ?string
    {
        return $this->systemPrompt;
    }

    public function setSystemPrompt(?string $systemPrompt): self
    {
        $this->systemPrompt = $systemPrompt;
        return $this;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * Convertit la configuration en tableau pour ChatService
     *
     * @return array Configuration formatée pour le ChatService
     */
    public function toArray(): array
    {
        $config = [
            'model' => $this->model,
        ];

        // Safety Settings
        if ($this->safetyEnabled) {
            $config['safety_settings'] = [
                'enabled' => true,
                'default_threshold' => $this->safetyDefaultThreshold,
                'thresholds' => array_filter([
                    'hate_speech' => $this->safetyHateSpeech,
                    'dangerous_content' => $this->safetyDangerousContent,
                    'harassment' => $this->safetyHarassment,
                    'sexually_explicit' => $this->safetySexuallyExplicit,
                ]),
            ];
        }

        // Generation Config
        $config['generation_config'] = [
            'temperature' => $this->generationTemperature,
            'top_p' => $this->generationTopP,
            'top_k' => $this->generationTopK,
        ];

        if ($this->generationMaxOutputTokens !== null) {
            $config['generation_config']['max_output_tokens'] = $this->generationMaxOutputTokens;
        }

        if ($this->generationStopSequences !== null && count($this->generationStopSequences) > 0) {
            $config['generation_config']['stop_sequences'] = $this->generationStopSequences;
        }

        // Thinking Config
        $config['thinking'] = [
            'enabled' => $this->thinkingEnabled,
            'budget' => $this->thinkingBudget,
        ];

        // Context Caching
        if ($this->contextCachingEnabled && $this->contextCachingId !== null) {
            $config['context_caching'] = [
                'enabled' => true,
                'cached_content_id' => $this->contextCachingId,
            ];
        }

        return $config;
    }
}
