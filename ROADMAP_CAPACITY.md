# ROADMAP: Évolution des Capacités Modèles LLM (ModelCapabilities)

**Document de référence pour l'enrichissement de ModelCapabilities**
- **Dernière mise à jour**: 2026-03-07
- **Status**: ✅ Phases 1 & 1.5 implémentées — Phases 2-4 planifiées
- **Source d'inspiration**: [LiteLLM model_prices_and_context_window.json](https://github.com/BerriAI/litellm)

---

## 1. État Actuel — Ce qui Existe

### 1.1 Structure `ModelCapabilities` (readonly class)

**Fichier**: `packages/core/src/Shared/Model/ModelCapabilities.php`

#### Propriétés originales

| Propriété | Type | Défaut | Usage actuel |
|-----------|------|--------|-------------|
| `model` | `string` | — | ID envoyé à l'API |
| `provider` | `string` | `'unknown'` | Routing vers le bon client |
| `type` | `string` | `'chat'` | Filtre embedding vs chat |
| `dimensions` | `int[]` | `[]` | Tailles disponibles pour embeddings |
| `thinking` | `bool` | `false` | Active thinkingConfig (Gemini) ou reasoning_effort (OVH) |
| `safetySettings` | `bool` | `false` | Active les filtres de sécurité Gemini |
| `topK` | `bool` | `false` | Active le paramètre topK dans generation_config |
| `functionCalling` | `bool` | `true` | Envoie les tools au provider |
| `streaming` | `bool` | `true` | Mode SSE/NDJSON |
| `systemPrompt` | `bool` | `true` | Envoie system instruction |
| `contextWindow` | `?int` | `null` | Troncature automatique des messages (legacy) |
| `pricingInput` | `?float` | `null` | Coût par 1M tokens (input) — fallback YAML |
| `pricingOutput` | `?float` | `null` | Coût par 1M tokens (output) — fallback YAML |
| `modelId` | `?string` | `null` | Alias technique (ex: ID Vertex AI) |

#### ✅ Nouvelles propriétés Phase 1 (implémentées 2026-03-07)

| Propriété | Type | Défaut | Usage |
|-----------|------|--------|-------|
| `maxInputTokens` | `?int` | `null` | Max tokens en entrée — `ContextTruncationSubscriber` via `getEffectiveMaxInputTokens()` |
| `maxOutputTokens` | `?int` | `null` | Max tokens en sortie — informatif, exposé en admin UI |
| `supportsVision` | `bool` | `false` | Affiché en admin UI (grisé, implémentation future) |
| `supportsParallelToolCalls` | `bool` | `false` | Affiché en admin UI (grisé, implémentation future) |
| `supportsResponseSchema` | `bool` | `false` | Affiché en admin UI (grisé, implémentation future) |
| `deprecatedAt` | `?string` | `null` | Date YYYY-MM-DD — helper `isDeprecated()` disponible |

#### ✅ Nouveaux helpers Phase 1

| Méthode | Retour | Description |
|---------|--------|-------------|
| `getEffectiveMaxInputTokens()` | `?int` | `maxInputTokens ?? contextWindow` — utilisé par `ContextTruncationSubscriber` |
| `isDeprecated(?\DateTimeInterface $at)` | `bool` | Vérifie si `deprecatedAt` est dans le passé |

#### ✅ `supports()` étendu

Nouvelles clés acceptées : `'vision'`, `'parallel_tool_calls'`, `'response_schema'`

### 1.2 Registre (`ModelCapabilityRegistry`)

**Fichier**: `packages/core/src/Engine/ModelCapabilityRegistry.php`

- Charge les YAML depuis `Resources/config/models/*.yaml`
- Fournit `getCapabilities(string $model): ModelCapabilities`
- Fournit `getModelsForProvider()`, `getKnownModels()`, `isKnownModel()`
- Fallback vers `DEFAULTS` si modèle inconnu
- ✅ Phase 1 : `DEFAULTS` étendu avec les 6 nouveaux champs

### 1.3 Fichiers YAML des modèles

| Fichier | Rôle |
|---------|------|
| `_default.yaml` | ✅ **NOUVEAU** — Référence documentaire (tous les champs + 2 templates copiables). `models: {}` → n'enregistre rien. |
| `gemini.yaml` | ✅ Enrichi Phase 1 — `max_input_tokens`, `max_output_tokens`, `supports_vision: true`, `supports_parallel_tool_calls: true`, `supports_response_schema: true` |
| `ovh.yaml` | ✅ Enrichi Phase 1 — `max_input_tokens`, `max_output_tokens`, `supports_response_schema: true` (gpt-oss, Qwen3), `false` (Llama, Mistral) |

### 1.4 `ContextTruncationSubscriber`

**Fichier**: `packages/core/src/Event/ContextTruncationSubscriber.php`

- ✅ Utilise désormais `getEffectiveMaxInputTokens()` au lieu de `->contextWindow`
- Comportement inchangé pour les modèles sans `maxInputTokens` (fallback transparent)

### 1.5 Admin UI — `ModelPresetController`

**Fichier**: `packages/admin/src/Controller/Intelligence/ModelPresetController.php`

- ✅ `getFullModelsCapabilities()` expose `supportsVision`, `supportsParallelToolCalls`, `supportsResponseSchema`, `maxInputTokens`, `maxOutputTokens`, `deprecatedAt`
- Ces champs sont disponibles en JS via `modelCapabilities[modelId]`

### 1.6 Admin UI — Templates Twig

**`preset_edit.html.twig`** — ✅ 3 nouveaux badges dans `updateCapsBadges()` :
- Vision (`eye`), Parallel Tools (`git-fork`), JSON Mode (`braces`)

**`configuration_llm.html.twig`** — ✅ Onglet Modèles + onglet Presets :
- 2 nouveaux boutons de filtre : Vision, JSON Mode
- Icônes Vision et JSON Mode affichées **grisées** (opacity 0.35) avec tooltip *"Déclaré, implémentation à venir"*
- `data-vision` et `data-jsonmode` sur les `<tr>` pour le filtrage JS

### 1.7 Fichiers de Consommation (Audit)

| Fichier | Capacités utilisées |
|---------|-------------------|
| `GeminiClient.php` | `thinking`, `safetySettings`, `topK`, `functionCalling`, `modelId` |
| `OvhAiClient.php` | `thinking`, `functionCalling`, `systemPrompt` |
| `TokenAccountingService.php` | `pricingInput`, `pricingOutput`, `provider` (devise) |
| `ContextTruncationSubscriber.php` | `getEffectiveMaxInputTokens()` ← était `contextWindow` |
| `EmbeddingService.php` | `type` (filtre `'embedding'`), `dimensions` |
| `ModelPresetController.php` | Toutes (affichage UI + validation) |
| `PresetValidator.php` | `isKnownModel()` |
| `ConfigurationLlmController.php` | `pricingInput`, `pricingOutput`, `provider`, Phase 1 : `supportsVision`, `supportsResponseSchema` |
| `EmbeddingController.php` | `type`, `dimensions` |

### 1.8 Points Forts de l'Architecture

- **Zéro hardcoding** — Toutes les capacités lues depuis le registre
- **Hiérarchie BDD > YAML > fallback** — Flexible et maintenable
- **Architecture extensible** — Ajouter un champ = 1 propriété + 1 ligne YAML + 1 ligne Registry

---

## 2. Analyse Comparative : Synapse vs LiteLLM

### 2.1 Mapping Complet

> **Convention de nommage YAML adoptée** (Phase 1.5) :
> - Toutes les capacités booléennes → préfixe `supports_*`
> - Tarification standard → préfixe `pricing_*` (ex: `pricing_input`, `pricing_thinking_output`)
> - Caching → préfixe `cache_*` pour les métadonnées ; **`pricing_*` prime toujours pour les prix** (ex: `pricing_cache_write`, `pricing_cache_read`)
> - `context_window` → **déprécié** (remplacé par `max_input_tokens`, conservé comme fallback)
> - `model_id` → **supprimé** (la clé YAML est déjà le slug du modèle)

| Capacité LiteLLM | Clé YAML Synapse | Propriété PHP | Phase | Notes |
|-------------------|-----------------|---------------|-------|-------|
| **Identité & Type** | | | | |
| `litellm_provider` | `provider` | `$provider` | — | Identique |
| `mode` | `type` | `$type` | — | `chat` \| `embedding` \| … (Phase 4: enum `ModelType`) |
| *(pas d'équiv.)* | ~~`model_id`~~ supprimé | ~~`$modelId`~~ | — | **Supprimé** — la clé YAML = le slug du modèle |
| **Contexte** | | | | |
| `max_tokens` | ~~`context_window`~~ *(déprécié)* | `$contextWindow` | — | Legacy — fallback via `getEffectiveMaxInputTokens()` |
| `max_input_tokens` | `max_input_tokens` | `$maxInputTokens` | ✅ Phase 1 | Utilisé par `ContextTruncationSubscriber` |
| `max_output_tokens` | `max_output_tokens` | `$maxOutputTokens` | ✅ Phase 1 | Informatif, exposé en admin UI |
| **Capacités booléennes — existantes (Phase 1.5 : renommage `supports_*`)** | | | | |
| `supports_function_calling` | ~~`function_calling`~~ → `supports_function_calling` | `$supportsFunctionCalling` | Phase 1.5 | Convention harmonisée |
| `supports_system_messages` | ~~`system_prompt`~~ → `supports_system_prompt` | `$supportsSystemPrompt` | Phase 1.5 | Convention harmonisée |
| `supports_reasoning` | ~~`thinking`~~ → `supports_thinking` | `$supportsThinking` | Phase 1.5 | Convention harmonisée |
| *(pas d'équiv.)* | ~~`safety_settings`~~ → `supports_safety_settings` | `$supportsSafetySettings` | Phase 1.5 | Spécifique Gemini, convention harmonisée |
| *(pas d'équiv.)* | ~~`top_k`~~ → `supports_top_k` | `$supportsTopK` | Phase 1.5 | Paramètre de génération, convention harmonisée |
| *(pas d'équiv.)* | ~~`streaming`~~ → `supports_streaming` | `$supportsStreaming` | Phase 1.5 | Convention harmonisée |
| **Capacités booléennes — Phase 1 (déjà avec `supports_`)** | | | | |
| `supports_parallel_function_calling` | `supports_parallel_tool_calls` | `$supportsParallelToolCalls` | ✅ Phase 1 | Déclaré en YAML, UI grisée |
| `supports_vision` | `supports_vision` | `$supportsVision` | ✅ Phase 1 | Déclaré en YAML, UI grisée, client Phase 2 |
| `supports_response_schema` | `supports_response_schema` | `$supportsResponseSchema` | ✅ Phase 1 | JSON Mode, UI grisée, client Phase 2 |
| **Capacités booléennes — Phase 2** | | | | |
| `supports_audio_input` | `supports_audio_input` | `$supportsAudioInput` | Phase 2 | Gemini 2.0+ |
| `supports_audio_output` | `supports_audio_output` | `$supportsAudioOutput` | Phase 2 | TTS natif |
| `supports_prompt_caching` | `supports_prompt_caching` | `$supportsPromptCaching` | Phase 2 | Réduction coûts 75-90% |
| `supports_web_search` | `supports_web_search` | `$supportsWebSearch` | Phase 2 | Gemini Grounding, Perplexity |
| **Tarification** | | | | |
| `input_cost_per_token` | `pricing_input` | `$pricingInput` | — | Par 1M tokens |
| `output_cost_per_token` | `pricing_output` | `$pricingOutput` | — | Par 1M tokens |
| `output_cost_per_reasoning_token` | `pricing_thinking_output` | `$pricingThinkingOutput` | Phase 2 | Fallback → `pricing_output` |
| `input_cost_per_audio_token` | `pricing_audio_input` | `$pricingAudioInput` | Phase 3 | Quand audio supporté |
| `output_cost_per_audio_token` | `pricing_audio_output` | `$pricingAudioOutput` | Phase 3 | TTS |
| `input_cost_per_pixel` | `pricing_vision_input` | `$pricingVisionInput` | Phase 3 | Vision granulaire |
| `search_context_cost_per_query` | `pricing_web_search` | `$pricingWebSearch` | Phase 3 | Web search |
| **Caching** | | | | |
| `cache_creation_input_token_cost` | `pricing_cache_write` | `$pricingCacheWrite` | Phase 2 | Prix écriture cache / 1M tokens |
| `cache_read_input_token_cost` | `pricing_cache_read` | `$pricingCacheRead` | Phase 2 | Prix lecture cache / 1M tokens (souvent -90%) |
| **Lifecycle** | | | | |
| `deprecation_date` | `deprecated_at` | `$deprecatedAt` | ✅ Phase 1 | Helper `isDeprecated()` disponible |
| *(pas d'équiv.)* | `is_preview` | `$isPreview` | Phase 3 | Modèle en preview (pas encore GA) |
| `supported_regions` | `supported_regions` | `$supportedRegions` | Phase 3 | Multi-région latence/compliance |
| **Spécifiques LiteLLM (non retenus)** | | | | |
| `code_interpreter_cost_per_session` | ❌ | — | — | Synapse a son propre PythonExecutor |
| `file_search_cost_per_1k_calls` | ❌ | — | — | Synapse a son propre RAG |
| `vector_store_cost_per_gb_per_day` | ❌ | — | — | Géré par l'infra, pas le provider |
| `computer_use_*` | ❌ | — | — | Pas de cas d'usage Synapse |

---

## 3. Plan d'Implémentation par Phase

### ✅ Phase 1.5 : Harmonisation Convention `supports_*` (COMPLÉTÉE — 2026-03-07)

**Objectif** : Uniformiser tous les champs booléens YAML sous la convention `supports_*`, supprimer `model_id` (le slug YAML suffit), déprécier `context_window` au profit de `max_input_tokens`.

#### Renommages effectués

| Ancienne clé YAML | Nouvelle clé YAML | Propriété PHP |
|---|---|---|
| `thinking` | `supports_thinking` | `$supportsThinking` |
| `safety_settings` | `supports_safety_settings` | `$supportsSafetySettings` |
| `top_k` | `supports_top_k` | `$supportsTopK` |
| `function_calling` | `supports_function_calling` | `$supportsFunctionCalling` |
| `streaming` | `supports_streaming` | `$supportsStreaming` |
| `system_prompt` | `supports_system_prompt` | `$supportsSystemPrompt` |
| `model_id` | ~~supprimé~~ | ~~`$modelId`~~ |

#### Rétrocompatibilité

`ModelCapabilityRegistry::getCapabilities()` accepte les deux formes pour les 6 champs renommés :
```php
supportsThinking: (bool) ($data['supports_thinking'] ?? $data['thinking'] ?? false),
```
Les anciens YAML de providers tiers continuent de fonctionner sans modification.

#### Fichiers modifiés
- `ModelCapabilities.php` — 6 propriétés renommées, `$modelId` supprimé, `supports()` étendu avec aliases
- `ModelCapabilityRegistry.php` — DEFAULTS + getCapabilities() mis à jour
- `gemini.yaml`, `ovh.yaml`, `_default.yaml` — champs YAML renommés
- `GeminiClient.php`, `OvhAiClient.php` — accès aux propriétés mis à jour
- `SynapseAgentBuilder.php`, `PresetValidatorAgent.php`, `ModelPresetController.php` — idem

---

### ✅ Phase 1 : Fondations Future-Proof (COMPLÉTÉE — 2026-03-07)

**Objectif**: Préparer l'architecture sans changer le comportement existant. Tous les nouveaux champs sont optionnels avec des défauts rétrocompatibles.

#### 3.1.1 Nouveaux Champs `ModelCapabilities`

```php
class ModelCapabilities
{
    public function __construct(
        // ── EXISTANT (inchangé) ──────────────────────────────
        public readonly string $model,
        public readonly string $provider,
        public readonly string $type = 'chat',
        public readonly array $dimensions = [],
        public readonly bool $thinking = false,
        public readonly bool $safetySettings = false,
        public readonly bool $topK = false,
        public readonly bool $functionCalling = true,
        public readonly bool $streaming = true,
        public readonly bool $systemPrompt = true,
        public readonly ?int $contextWindow = null,
        public readonly ?float $pricingInput = null,
        public readonly ?float $pricingOutput = null,
        public readonly ?string $modelId = null,

        // ── PHASE 1 : Contexte asymétrique ───────────────────
        /** Max tokens en entrée (null = utiliser contextWindow) */
        public readonly ?int $maxInputTokens = null,
        /** Max tokens en sortie (null = pas de limite explicite connue) */
        public readonly ?int $maxOutputTokens = null,

        // ── PHASE 1 : Modalités ──────────────────────────────
        /** Supporte l'analyse d'images en entrée */
        public readonly bool $supportsVision = false,
        /** Supporte l'appel parallèle de plusieurs tools */
        public readonly bool $supportsParallelToolCalls = false,
        /** Supporte le JSON Mode / Structured Outputs */
        public readonly bool $supportsResponseSchema = false,

        // ── PHASE 1 : Lifecycle ──────────────────────────────
        /** Date de dépréciation du modèle (YYYY-MM-DD) */
        public readonly ?string $deprecatedAt = null,
    ) {
    }

    /**
     * Retourne le max de tokens en entrée.
     * Fallback: maxInputTokens → contextWindow → null.
     */
    public function getEffectiveMaxInputTokens(): ?int
    {
        return $this->maxInputTokens ?? $this->contextWindow;
    }

    /**
     * Vérifie si le modèle est déprécié à une date donnée.
     */
    public function isDeprecated(?\DateTimeInterface $at = null): bool
    {
        if (null === $this->deprecatedAt) {
            return false;
        }
        $deprecation = \DateTimeImmutable::createFromFormat('Y-m-d', $this->deprecatedAt);
        if (!$deprecation) {
            return false;
        }
        $reference = $at ?? new \DateTimeImmutable();
        return $reference >= $deprecation;
    }

    public function supports(string $capability): bool
    {
        return match ($capability) {
            // Existant
            'thinking' => $this->thinking,
            'safety_settings' => $this->safetySettings,
            'top_k' => $this->topK,
            'function_calling' => $this->functionCalling,
            'streaming' => $this->streaming,
            'system_prompt' => $this->systemPrompt,
            // Phase 1
            'vision' => $this->supportsVision,
            'parallel_tool_calls' => $this->supportsParallelToolCalls,
            'response_schema' => $this->supportsResponseSchema,
            default => false,
        };
    }
}
```

#### 3.1.2 Impact sur `ModelCapabilityRegistry`

Ajouter au tableau `DEFAULTS` :
```php
private const DEFAULTS = [
    // ... existant ...
    'max_input_tokens' => null,
    'max_output_tokens' => null,
    'supports_vision' => false,
    'supports_parallel_tool_calls' => false,
    'supports_response_schema' => false,
    'deprecated_at' => null,
];
```

Ajouter dans `getCapabilities()` :
```php
return new ModelCapabilities(
    // ... existant ...
    maxInputTokens: is_numeric($data['max_input_tokens'] ?? null) ? (int) $data['max_input_tokens'] : null,
    maxOutputTokens: is_numeric($data['max_output_tokens'] ?? null) ? (int) $data['max_output_tokens'] : null,
    supportsVision: (bool) ($data['supports_vision'] ?? false),
    supportsParallelToolCalls: (bool) ($data['supports_parallel_tool_calls'] ?? false),
    supportsResponseSchema: (bool) ($data['supports_response_schema'] ?? false),
    deprecatedAt: isset($data['deprecated_at']) && is_string($data['deprecated_at']) ? $data['deprecated_at'] : null,
);
```

#### 3.1.3 Impact sur `ContextTruncationSubscriber`

Mettre à jour pour utiliser `getEffectiveMaxInputTokens()` :
```php
// Avant
$contextWindow = $capabilities->contextWindow;
// Après
$contextWindow = $capabilities->getEffectiveMaxInputTokens();
```

#### 3.1.4 Impact sur l'Admin UI

Dans `ModelPresetController::getFullModelsCapabilities()`, exposer les nouveaux champs :
```php
$result[$modelId] = [
    // ... existant ...
    'supportsVision' => $caps->supportsVision,
    'supportsResponseSchema' => $caps->supportsResponseSchema,
    'supportsParallelToolCalls' => $caps->supportsParallelToolCalls,
    'maxInputTokens' => $caps->maxInputTokens,
    'maxOutputTokens' => $caps->maxOutputTokens,
    'deprecatedAt' => $caps->deprecatedAt,
];
```

Dans le template `preset_edit.html.twig`, afficher les nouveaux badges de capacités (vision, JSON mode, etc.).

#### 3.1.5 Effort & Risques

- **Effort**: 1 jour
- **Risque**: Aucun — tous les champs optionnels, backward-compatible
- **Tests**: Ajouter cas dans `ModelCapabilityRegistryTest` pour les nouveaux champs

---

### Phase 2 : Optimisations Coûts & Fonctionnalités Avancées

**Objectif**: Exploiter les fonctionnalités d'optimisation des providers modernes.

#### 3.2.1 Nouveaux Champs

```php
// ── PHASE 2 : Audio & Multimédia ─────────────────────
/** Supporte l'audio en entrée (Gemini 2.0+) */
public readonly bool $supportsAudioInput = false,
/** Supporte la génération audio en sortie */
public readonly bool $supportsAudioOutput = false,

// ── PHASE 2 : Optimisations ──────────────────────────
/** Supporte le prompt caching (Anthropic, Gemini, OpenAI) */
public readonly bool $supportsPromptCaching = false,
/** Supporte la recherche web intégrée (Perplexity, Gemini Grounding) */
public readonly bool $supportsWebSearch = false,

// ── PHASE 2 : Prix granulaires ───────────────────────
/** Prix par 1M tokens de raisonnement (thinking/reasoning) */
public readonly ?float $pricingReasoningOutput = null,
/** Prix par 1M tokens d'écriture cache */
public readonly ?float $pricingCacheWrite = null,
/** Prix par 1M tokens de lecture cache */
public readonly ?float $pricingCacheRead = null,
```

#### 3.2.2 Impact sur `TokenAccountingService`

Le calcul de coût doit être enrichi :

```php
// Avant (actuel)
$outputCost = (($completionTokens + $thinkingTokens) / 1_000_000) * $pricing['output'];

// Après (Phase 2)
$standardOutputCost = ($completionTokens / 1_000_000) * $pricing['output'];
$reasoningCost = ($thinkingTokens / 1_000_000) * ($pricing['reasoning_output'] ?? $pricing['output']);
$cacheSavings = ($cachedTokens / 1_000_000) * ($pricing['output'] - ($pricing['cache_read'] ?? $pricing['output']));
$totalOutputCost = $standardOutputCost + $reasoningCost - $cacheSavings;
```

#### 3.2.3 Impact sur les Clients LLM

**Prompt Caching** (Gemini, Anthropic) :
```php
// Dans GeminiClient::buildPayload()
if ($caps->supportsPromptCaching && $this->cachingEnabled) {
    // Ajouter cached_content ou cache_control headers
}
```

**Web Search** (Gemini Grounding, Perplexity) :
```php
// Dans GeminiClient::buildPayload()
if ($caps->supportsWebSearch && $this->webSearchEnabled) {
    $payload['tools'][] = ['google_search' => new \stdClass()];
}
```

#### 3.2.4 Effort & Risques

- **Effort**: 3-4 jours (dont intégration dans les clients)
- **Risque moyen**: Le prompt caching nécessite des changements dans PromptBuilder pour identifier les portions cacheable
- **Dépendances**: Phase 1 complète

---

### Phase 3 : Tarification Granulaire & Multi-Région

**Objectif**: Précision maximale sur les coûts et support du déploiement multi-région.

#### 3.3.1 Nouveaux Champs

```php
// ── PHASE 3 : Prix spécialisés ───────────────────────
/** Prix par 1M tokens audio en entrée */
public readonly ?float $pricingAudioInput = null,
/** Prix par 1M tokens audio en sortie */
public readonly ?float $pricingAudioOutput = null,
/** Prix par image/requête vision (si applicable) */
public readonly ?float $pricingVisionInput = null,

// ── PHASE 3 : Régions & Déploiement ─────────────────
/** Régions supportées par le modèle */
public readonly array $supportedRegions = [],
/** Indique si le modèle est en preview (pas GA) */
public readonly bool $isPreview = false,
```

#### 3.3.2 Impact

- **TokenAccountingService**: Ventilation audio/vision/texte dans les rapports de coûts
- **Admin Dashboard**: Alerte visuelle si un modèle est preview ou déprécié
- **Routing**: Sélection de région basée sur la localisation de l'utilisateur ou la compliance RGPD

#### 3.3.3 Effort & Risques

- **Effort**: 2-3 jours
- **Risque faible**: Champs purement informatifs, pas d'impact sur le flux principal

---

### Phase 4 : Types de Modèles Étendus

**Objectif**: Supporter les cas d'usage au-delà du chat et des embeddings.

#### 3.4.1 Enum `ModelType`

```php
enum ModelType: string
{
    case Chat = 'chat';
    case Embedding = 'embedding';
    case ImageGeneration = 'image_generation';
    case AudioTranscription = 'audio_transcription';
    case AudioSpeech = 'audio_speech';
    case Moderation = 'moderation';
    case Rerank = 'rerank';
}
```

#### 3.4.2 Impact

- Remplacer `string $type` par `ModelType $type` dans `ModelCapabilities`
- Adapter `EmbeddingService` pour utiliser l'enum
- Backward-compatible : `ModelType::tryFrom($data['type']) ?? ModelType::Chat`

#### 3.4.3 Effort & Risques

- **Effort**: 1 jour
- **Risque**: Migration de `string` vers `enum` — tests requis

---

## 4. Format YAML Cible (Toutes Phases)

### 4.1 Spécification Complète d'un Modèle

```yaml
models:
  # ──────────────────────────────────────────────────────
  # Exemple complet — Gemini 2.5 Pro (toutes capacités)
  # ──────────────────────────────────────────────────────
  gemini-2.5-pro:
    # ── Identité (obligatoire) ──────────────────────────
    provider: gemini                    # Requis

    # ── Type (optionnel, défaut: chat) ──────────────────
    type: chat                          # chat | embedding | image_generation | ...

    # ── Contexte (optionnel) ────────────────────────────
    context_window: 1048576             # Legacy — fenêtre totale (tokens)
    max_input_tokens: 1000000           # Phase 1 — Max tokens en entrée
    max_output_tokens: 65536            # Phase 1 — Max tokens en sortie

    # ── Capacités booléennes (optionnelles) ─────────────
    thinking: true                      # Existant — Reasoning/thinking étendu
    safety_settings: true               # Existant — Filtres de sécurité Gemini
    top_k: true                         # Existant — Paramètre topK
    function_calling: true              # Existant — Tool use
    streaming: true                     # Existant — Mode SSE
    system_prompt: true                 # Existant — System instructions

    supports_vision: true               # Phase 1 — Analyse d'images
    supports_parallel_tool_calls: true  # Phase 1 — Appels d'outils parallèles
    supports_response_schema: true      # Phase 1 — JSON Mode / Structured Output
    supports_audio_input: false         # Phase 2 — Audio en entrée
    supports_audio_output: false        # Phase 2 — Audio en sortie
    supports_prompt_caching: true       # Phase 2 — Cache de prompt
    supports_web_search: true           # Phase 2 — Recherche web intégrée

    # ── Tarification (optionnelle, par 1M tokens) ──────
    pricing_input: 1.25                 # Existant — Input standard
    pricing_output: 5.00                # Existant — Output standard
    pricing_reasoning_output: 5.00      # Phase 2 — Thinking tokens
    pricing_cache_write: 0.3125         # Phase 2 — Écriture cache
    pricing_cache_read: 0.078           # Phase 2 — Lecture cache (souvent -90%)
    pricing_audio_input: null           # Phase 3 — Audio input
    pricing_audio_output: null          # Phase 3 — Audio output
    pricing_vision_input: null          # Phase 3 — Vision input

    # ── Lifecycle (optionnel) ──────────────────────────
    deprecated_at: null                 # Phase 1 — Date YYYY-MM-DD ou null
    is_preview: false                   # Phase 3 — Modèle en preview

    # ── Régions (optionnel) ────────────────────────────
    supported_regions: []               # Phase 3 — ['us', 'eu', 'asia']

    # ── Technique (optionnel) ──────────────────────────
    model_id: null                      # Existant — ID alternatif (Vertex)
    dimensions: []                      # Existant — Tailles embedding
```

### 4.2 Exemple Minimal (Modèle chat simple)

```yaml
models:
  Mistral-7B-Instruct-v0.3:
    provider: ovh
    context_window: 32768
    pricing_input: 0.10
    pricing_output: 0.10
```

Tous les champs non spécifiés prennent leur valeur par défaut (voir section 5).

### 4.3 Exemple Embedding

```yaml
models:
  gemini-embedding-001:
    type: embedding
    provider: gemini
    dimensions: [3072, 1536, 768]
    function_calling: false
    streaming: false
    system_prompt: false
    thinking: false
    context_window: 2048
    pricing_input: 0.15
    pricing_output: 0.00
```

### 4.4 Exemple Modèle Déprécié

```yaml
models:
  gemini-1.5-flash:
    provider: gemini
    context_window: 1048576
    deprecated_at: "2026-06-15"
    pricing_input: 0.075
    pricing_output: 0.30
```

---

## 5. Valeurs par Défaut (DEFAULTS)

Quand une capacité n'est **pas spécifiée** dans le YAML, la valeur par défaut s'applique.

### 5.1 Tableau des Défauts

| Clé YAML | Type | Défaut | Justification |
|----------|------|--------|---------------|
| **Identité** | | | |
| `provider` | string | `'unknown'` | Provider non identifié |
| `type` | string | `'chat'` | La majorité des modèles sont des modèles chat |
| `model_id` | ?string | `null` | Pas d'alias technique par défaut |
| **Contexte** | | | |
| `context_window` | ?int | `null` | Pas de troncature si inconnu |
| `max_input_tokens` | ?int | `null` | Fallback → `context_window` via `getEffectiveMaxInputTokens()` |
| `max_output_tokens` | ?int | `null` | Pas de limite connue |
| **Capacités — Par défaut TRUE** | | | |
| `function_calling` | bool | `true` | La plupart des modèles chat supportent le tool use |
| `streaming` | bool | `true` | La plupart des APIs supportent le streaming |
| `system_prompt` | bool | `true` | La plupart des modèles acceptent un system prompt |
| **Capacités — Par défaut FALSE** | | | |
| `thinking` | bool | `false` | Seuls quelques modèles récents supportent le thinking |
| `safety_settings` | bool | `false` | Spécifique Gemini |
| `top_k` | bool | `false` | Peu de providers l'exposent |
| `supports_vision` | bool | `false` | Opt-in, nécessite adaptation du payload |
| `supports_parallel_tool_calls` | bool | `false` | Opt-in, risque si non supporté |
| `supports_response_schema` | bool | `false` | Opt-in, nécessite adaptation du payload |
| `supports_audio_input` | bool | `false` | Opt-in |
| `supports_audio_output` | bool | `false` | Opt-in |
| `supports_prompt_caching` | bool | `false` | Opt-in, nécessite adaptation du payload |
| `supports_web_search` | bool | `false` | Opt-in |
| **Tarification** | | | |
| `pricing_input` | ?float | `null` | Inconnu → pas de calcul de coût |
| `pricing_output` | ?float | `null` | Inconnu → pas de calcul de coût |
| `pricing_reasoning_output` | ?float | `null` | Fallback → `pricing_output` dans le calcul |
| `pricing_cache_write` | ?float | `null` | Pas de pricing cache |
| `pricing_cache_read` | ?float | `null` | Pas de pricing cache |
| `pricing_audio_input` | ?float | `null` | Pas de pricing audio |
| `pricing_audio_output` | ?float | `null` | Pas de pricing audio |
| `pricing_vision_input` | ?float | `null` | Pas de pricing vision |
| **Lifecycle** | | | |
| `deprecated_at` | ?string | `null` | Pas de date de dépréciation |
| `is_preview` | bool | `false` | Considéré GA par défaut |
| `supported_regions` | array | `[]` | Disponible partout par défaut |
| **Embeddings** | | | |
| `dimensions` | int[] | `[]` | Pas de dimensions spécifiées |

### 5.2 Logique de Défaut pour la Tarification

```
Coût reasoning    = pricing_reasoning_output ?? pricing_output
Coût cache write  = pricing_cache_write      ?? pricing_input
Coût cache read   = pricing_cache_read       ?? pricing_input * 0.1 (si caching supporté)
Coût audio input  = pricing_audio_input      ?? pricing_input
Coût audio output = pricing_audio_output     ?? pricing_output
Coût vision       = pricing_vision_input     ?? pricing_input
```

### 5.3 Constante PHP (ModelCapabilityRegistry)

```php
private const DEFAULTS = [
    // Identité
    'provider' => 'unknown',
    'type' => 'chat',
    'model_id' => null,
    // Contexte
    'context_window' => null,
    'max_input_tokens' => null,
    'max_output_tokens' => null,
    // Capacités (défaut true)
    'function_calling' => true,
    'streaming' => true,
    'system_prompt' => true,
    // Capacités (défaut false)
    'thinking' => false,
    'safety_settings' => false,
    'top_k' => false,
    'supports_vision' => false,
    'supports_parallel_tool_calls' => false,
    'supports_response_schema' => false,
    'supports_audio_input' => false,
    'supports_audio_output' => false,
    'supports_prompt_caching' => false,
    'supports_web_search' => false,
    // Tarification
    'pricing_input' => null,
    'pricing_output' => null,
    'pricing_reasoning_output' => null,
    'pricing_cache_write' => null,
    'pricing_cache_read' => null,
    'pricing_audio_input' => null,
    'pricing_audio_output' => null,
    'pricing_vision_input' => null,
    // Lifecycle
    'deprecated_at' => null,
    'is_preview' => false,
    'supported_regions' => [],
    // Embeddings
    'dimensions' => [],
];
```

---

## 6. YAML Enrichis — Valeurs Réelles par Modèle

### 6.1 `gemini.yaml` (Phase 1)

```yaml
# Source: https://ai.google.dev/gemini-api/docs/pricing
models:
  gemini-3.1-pro:
    provider: gemini
    thinking: true
    safety_settings: true
    top_k: true
    function_calling: true
    streaming: true
    system_prompt: true
    context_window: 1048576
    max_input_tokens: 1000000
    max_output_tokens: 65536
    supports_vision: true
    supports_parallel_tool_calls: true
    supports_response_schema: true
    pricing_input: 2.00
    pricing_output: 12.00
    model_id: gemini-3.1-pro-preview

  gemini-3.1-flash-lite:
    provider: gemini
    thinking: true
    safety_settings: true
    top_k: true
    function_calling: true
    streaming: true
    system_prompt: true
    context_window: 1048576
    max_input_tokens: 1000000
    max_output_tokens: 65536
    supports_vision: true
    supports_parallel_tool_calls: true
    supports_response_schema: true
    pricing_input: 0.25
    pricing_output: 1.50
    model_id: gemini-3.1-flash-lite-preview

  gemini-3-flash:
    provider: gemini
    thinking: true
    safety_settings: true
    top_k: true
    function_calling: true
    streaming: true
    system_prompt: true
    context_window: 1048576
    max_input_tokens: 1000000
    max_output_tokens: 65536
    supports_vision: true
    supports_parallel_tool_calls: true
    supports_response_schema: true
    pricing_input: 0.50
    pricing_output: 3.00
    model_id: gemini-3-flash-preview

  gemini-2.5-pro:
    provider: gemini
    thinking: true
    safety_settings: true
    top_k: true
    function_calling: true
    streaming: true
    system_prompt: true
    context_window: 1048576
    max_input_tokens: 1000000
    max_output_tokens: 65536
    supports_vision: true
    supports_parallel_tool_calls: true
    supports_response_schema: true
    pricing_input: 1.25
    pricing_output: 5.00

  gemini-2.5-flash:
    provider: gemini
    thinking: true
    safety_settings: true
    top_k: true
    function_calling: true
    streaming: true
    system_prompt: true
    context_window: 1048576
    max_input_tokens: 1000000
    max_output_tokens: 65536
    supports_vision: true
    supports_parallel_tool_calls: true
    supports_response_schema: true
    pricing_input: 0.30
    pricing_output: 2.50

  gemini-2.5-flash-lite:
    provider: gemini
    thinking: true
    safety_settings: true
    top_k: true
    function_calling: true
    streaming: true
    system_prompt: true
    context_window: 1048576
    max_input_tokens: 1000000
    max_output_tokens: 65536
    supports_vision: true
    supports_parallel_tool_calls: true
    supports_response_schema: true
    pricing_input: 0.10
    pricing_output: 0.40

  gemini-embedding-001:
    type: embedding
    provider: gemini
    dimensions: [3072, 1536, 768]
    thinking: false
    safety_settings: false
    top_k: false
    function_calling: false
    streaming: false
    system_prompt: false
    context_window: 2048
    pricing_input: 0.15
    pricing_output: 0.00
```

### 6.2 `ovh.yaml` (Phase 1)

```yaml
# Source: https://www.ovhcloud.com/fr/public-cloud/ai-endpoints/
models:
  gpt-oss-20b:
    provider: ovh
    thinking: true
    safety_settings: false
    top_k: false
    function_calling: true
    streaming: true
    system_prompt: true
    context_window: 131072
    max_input_tokens: 131072
    max_output_tokens: 16384
    supports_vision: false
    supports_response_schema: true
    pricing_input: 0.04
    pricing_output: 0.15

  gpt-oss-120b:
    provider: ovh
    thinking: true
    safety_settings: false
    top_k: false
    function_calling: true
    streaming: true
    system_prompt: true
    context_window: 131072
    max_input_tokens: 131072
    max_output_tokens: 16384
    supports_vision: false
    supports_response_schema: true
    pricing_input: 0.08
    pricing_output: 0.40

  Qwen3-32B:
    provider: ovh
    thinking: true
    safety_settings: false
    top_k: false
    function_calling: true
    streaming: true
    system_prompt: true
    context_window: 32768
    max_input_tokens: 32768
    max_output_tokens: 8192
    supports_vision: false
    supports_response_schema: true
    pricing_input: 0.08
    pricing_output: 0.23

  Llama-3.1-8B-Instruct:
    provider: ovh
    thinking: false
    safety_settings: false
    top_k: false
    function_calling: true
    streaming: true
    system_prompt: true
    context_window: 131072
    max_input_tokens: 131072
    max_output_tokens: 4096
    supports_vision: false
    supports_response_schema: false
    pricing_input: 0.10
    pricing_output: 0.10

  Mistral-7B-Instruct-v0.3:
    provider: ovh
    thinking: false
    safety_settings: false
    top_k: false
    function_calling: true
    streaming: true
    system_prompt: true
    context_window: 32768
    max_input_tokens: 32768
    max_output_tokens: 8192
    supports_vision: false
    supports_response_schema: false
    pricing_input: 0.10
    pricing_output: 0.10

  bge-m3:
    type: embedding
    provider: ovh
    dimensions: [1024]
    thinking: false
    safety_settings: false
    top_k: false
    function_calling: false
    streaming: false
    system_prompt: false
    context_window: 8192
    pricing_input: 0.01
    pricing_output: 0.00
```

---

## 7. Aperçu du Développement par Fonctionnalité

### 7.1 Vision (`supports_vision`)

**Ce que ça implique** :
- L'utilisateur envoie une image (upload ou URL) dans le chat
- Le client LLM doit encoder l'image dans le payload (base64 ou URL)
- Le format diffère selon le provider

**Développement requis** :
1. **ChatService** : Accepter les pièces jointes multimédia dans le message utilisateur
2. **Format du message** : Transformer en format multimodal
   ```php
   // OpenAI format
   ['role' => 'user', 'content' => [
       ['type' => 'text', 'text' => 'Que vois-tu ?'],
       ['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,...']],
   ]]
   ```
3. **GeminiClient** : Convertir en format Gemini `inlineData`
4. **OvhAiClient** : Vérifier compatibilité (la plupart ne supportent pas)
5. **Chat UI** : Bouton d'upload d'image dans l'interface
6. **Storage** : Stocker les images uploadées (filesystem ou S3)

**Garde-fou** : `if (!$caps->supportsVision) { /* rejeter l'image, message d'erreur */ }`

**Effort estimé** : 3-5 jours

---

### 7.2 Structured Output / JSON Mode (`supports_response_schema`)

**Ce que ça implique** :
- Forcer le modèle à répondre dans un schéma JSON précis
- Critique pour les agents qui doivent parser la sortie

**Développement requis** :
1. **ChatService** : Accepter un paramètre `response_schema` optionnel
2. **GeminiClient** : Ajouter `response_mime_type: 'application/json'` + `response_schema` dans generation_config
3. **OvhAiClient** : Ajouter `response_format: { type: 'json_schema', json_schema: {...} }`
4. **PromptBuilder** : Option pour injecter des instructions de format dans le system prompt (fallback pour modèles sans support natif)

**Garde-fou** : `if (!$caps->supportsResponseSchema) { /* fallback: instruction dans le prompt */ }`

**Effort estimé** : 2 jours

---

### 7.3 Prompt Caching (`supports_prompt_caching`)

**Ce que ça implique** :
- Les system prompts et contextes longs sont cachés côté provider
- Réduction de coût de 75-90% sur les tokens récurrents
- Chaque provider a un mécanisme différent

**Développement requis** :
1. **PromptBuilder** : Marquer les portions cacheable (system prompt, contexte RAG)
2. **GeminiClient** : Utiliser `cachedContent` API (créer un cache, puis le référencer)
3. **OvhAiClient** : Si basé sur vLLM, le caching est automatique côté serveur
4. **TokenAccountingService** : Comptabiliser les tokens cached vs non-cached séparément
5. **Admin UI** : Afficher les économies de cache dans le dashboard

**Garde-fou** : `if (!$caps->supportsPromptCaching) { /* envoi normal */ }`

**Effort estimé** : 4-5 jours (le plus complexe, APIs très différentes entre providers)

---

### 7.4 Web Search (`supports_web_search`)

**Ce que ça implique** :
- Le modèle peut chercher sur le web avant de répondre
- Gemini: "Grounding with Google Search"
- Perplexity: natif

**Développement requis** :
1. **ChatService** : Option `web_search_enabled` par conversation ou par agent
2. **GeminiClient** : Ajouter `google_search` dans les tools
3. **Nouveau client Perplexity** : Client dédié (API compatible OpenAI)
4. **Admin UI** : Toggle dans la config de l'agent
5. **Affichage** : Citer les sources web dans la réponse

**Garde-fou** : `if (!$caps->supportsWebSearch) { /* ignorer l'option */ }`

**Effort estimé** : 3-4 jours

---

### 7.5 Audio Input/Output (`supports_audio_input`, `supports_audio_output`)

**Ce que ça implique** :
- Transcription audio (STT) en entrée
- Génération audio (TTS) en sortie
- Conversation vocale

**Développement requis** :
1. **Chat UI** : Bouton microphone, enregistrement audio
2. **ChatService** : Accepter des messages audio (blob)
3. **GeminiClient** : Envoyer en `inlineData` avec mimeType audio
4. **Storage** : Stocker les fichiers audio
5. **Streaming audio** : Adapter le SSE pour streamer de l'audio (complexe)

**Garde-fou** : `if (!$caps->supportsAudioInput) { /* rejeter, message d'erreur */ }`

**Effort estimé** : 5-7 jours (STT seul), 10+ jours (STT + TTS)

---

### 7.6 Parallel Tool Calls (`supports_parallel_tool_calls`)

**Ce que ça implique** :
- Le modèle peut appeler plusieurs outils simultanément dans une seule réponse
- Optimise le temps de réponse des agents

**Développement requis** :
1. **ChatService** : Exécuter les tool calls en parallèle (Fiber/async ou sequential avec batching)
2. **GeminiClient/OvhAiClient** : Déjà supporté dans le parsing (multiple `function_calls` dans un chunk)
3. **Vérification** : S'assurer que le ChatService gère déjà `n` tool_calls par tour

**Garde-fou** : `if (!$caps->supportsParallelToolCalls) { /* envoyer les tools un par un si nécessaire */ }`

**Effort estimé** : 1-2 jours (vérification + ajustement)

---

### 7.7 Deprecation Alerting (`deprecated_at`)

**Ce que ça implique** :
- Alerter l'administrateur quand un modèle va être déprécié
- Bloquer la sélection d'un modèle déjà déprécié

**Développement requis** :
1. **Admin Dashboard** : Banner d'alerte si un preset actif utilise un modèle avec `deprecated_at` < now + 30 jours
2. **PresetValidator** : Warning (pas blocage) si modèle déprécié bientôt
3. **synapse:doctor** : Nouveau check `checkDeprecatedModels()`
4. **Preset edit UI** : Badge "Deprecated" à côté du modèle

**Effort estimé** : 0.5 jour

---

## 8. Capacités LiteLLM Non Retenues

| Capacité LiteLLM | Raison du rejet |
|-------------------|-----------------|
| `code_interpreter_cost_per_session` | Synapse a son propre PythonExecutor (roadmap agents) |
| `file_search_cost_per_1k_calls` | Synapse a son propre RAG (roadmap agents) |
| `vector_store_cost_per_gb_per_day` | Géré par l'infrastructure Synapse, pas par le provider |
| `computer_use_*` | Pas de cas d'usage identifié dans Synapse |

---

## 9. Checklist d'Implémentation

### ✅ Phase 1 (Complétée — 2026-03-07)
- [x] Ajouter `maxInputTokens`, `maxOutputTokens` à `ModelCapabilities`
- [x] Ajouter `supportsVision`, `supportsParallelToolCalls`, `supportsResponseSchema`
- [x] Ajouter `deprecatedAt`
- [x] Mettre à jour `ModelCapabilityRegistry::DEFAULTS`
- [x] Mettre à jour `ModelCapabilityRegistry::getCapabilities()`
- [x] Mettre à jour `supports()` avec les nouvelles clés
- [x] Ajouter helper `getEffectiveMaxInputTokens()`
- [x] Ajouter helper `isDeprecated()`
- [x] Mettre à jour `ContextTruncationSubscriber` → `getEffectiveMaxInputTokens()`
- [x] Créer `_default.yaml` (fichier de référence documentaire)
- [x] Enrichir `gemini.yaml` avec les nouveaux champs
- [x] Enrichir `ovh.yaml` avec les nouveaux champs
- [x] Mettre à jour `ModelPresetController::getFullModelsCapabilities()`
- [x] Ajouter badges dans `preset_edit.html.twig` (Vision, Parallel Tools, JSON Mode)
- [x] Ajouter filtres Vision / JSON Mode dans `configuration_llm.html.twig`
- [x] Afficher icônes grisées "Déclaré, implémentation à venir" dans onglet Modèles et Presets
- [ ] Ajouter tests unitaires pour les nouveaux champs (`ModelCapabilityRegistryTest`)
- [ ] Ajouter test `isDeprecated()`

### ✅ Phase 1.5 (Complétée — 2026-03-07)
- [x] Renommer `thinking` → `supports_thinking` (YAML + PHP)
- [x] Renommer `safety_settings` → `supports_safety_settings` (YAML + PHP)
- [x] Renommer `top_k` → `supports_top_k` (YAML + PHP)
- [x] Renommer `function_calling` → `supports_function_calling` (YAML + PHP)
- [x] Renommer `streaming` → `supports_streaming` (YAML + PHP)
- [x] Renommer `system_prompt` → `supports_system_prompt` (YAML + PHP)
- [x] Supprimer `model_id` (PHP + YAML)
- [x] `context_window` marqué déprécié dans `_default.yaml`
- [x] Rétrocompatibilité via double lookup dans `getCapabilities()`
- [x] Méthode `supports()` étendue avec les nouvelles clés + aliases

### Phase 2 (Quand nécessaire)
- [ ] Ajouter `supportsAudioInput`, `supportsAudioOutput`
- [ ] Ajouter `supportsPromptCaching`, `supportsWebSearch`
- [ ] Ajouter `pricingReasoningOutput`, `pricingCacheWrite`, `pricingCacheRead`
- [ ] Adapter `TokenAccountingService` pour les prix granulaires
- [ ] Implémenter prompt caching dans les clients

### Phase 3 (Optionnel)
- [ ] Ajouter `pricingAudioInput`, `pricingAudioOutput`, `pricingVisionInput`
- [ ] Ajouter `supportedRegions`, `isPreview`
- [ ] Alertes dépréciation dans le dashboard admin

### Phase 4 (Optionnel)
- [ ] Créer `ModelType` enum
- [ ] Migrer `string $type` → `ModelType $type`

---

## 10. Sources & Références

- [LiteLLM Model Database](https://github.com/BerriAI/litellm/blob/main/model_prices_and_context_window.json)
- [Gemini API Pricing](https://ai.google.dev/gemini-api/docs/pricing)
- [Gemini API Models](https://ai.google.dev/gemini-api/docs/models)
- [Anthropic Prompt Caching](https://docs.anthropic.com/en/docs/build-with-claude/prompt-caching)
- [OpenAI Structured Outputs](https://platform.openai.com/docs/guides/structured-outputs)

---

**Last Updated**: 2026-03-07
**Next Review**: Phase 2 — Audio, Prompt Caching, Web Search, Pricing granulaire
