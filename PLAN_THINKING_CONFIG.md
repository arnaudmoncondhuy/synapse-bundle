# Plan d'Implémentation : Thinking Config Natif (AI Studio)

## Objectif
Activer le mode thinking natif de Gemini via `thinkingConfig` sur AI Studio pour améliorer le debug et simplifier le prompt.

---

## Bénéfices

✅ Debug structuré (champ `thought: true` au lieu de regex)
✅ Prompt technique simplifié (Gemini gère le thinking nativement)
✅ Budget de thinking contrôlable (0 à 24576 tokens)
✅ Zero migration risquée (reste sur AI Studio)
✅ Rétro-compatible (désactivable)

---

## Fichiers à Modifier (5 fichiers)

| Fichier | Action | Complexité |
|---------|--------|------------|
| `src/DependencyInjection/Configuration.php` | Ajouter config `thinking.*` | Faible |
| `src/DependencyInjection/SynapseExtension.php` | Charger paramètres | Faible |
| `src/Service/Infra/GeminiClient.php` | Ajouter `thinkingConfig` au payload | Moyenne |
| `src/Service/PromptBuilder.php` | Simplifier prompt si natif | Moyenne |
| `config/services.yaml` | Passer paramètres | Faible |

---

## Implémentation Détaillée

### 1. Configuration.php

**Fichier** : `src/DependencyInjection/Configuration.php`

**Ajouter après la config `personas_path` (ligne ~40)** :

```php
->arrayNode('thinking')
    ->addDefaultsIfNotSet()
    ->children()
        ->booleanNode('enabled')
            ->defaultTrue()
            ->info('Activer le mode thinking natif de Gemini (améliore le debug)')
        ->end()
        ->integerNode('budget')
            ->defaultValue(1024)
            ->min(0)
            ->max(24576)
            ->info('Budget de tokens pour le thinking (0 = désactivé si supporté par le modèle)')
        ->end()
    ->end()
->end()
```

**Note** : Pour gemini-2.5-flash, le budget peut aller de 0 à 24576.

---

### 2. SynapseExtension.php

**Fichier** : `src/DependencyInjection/SynapseExtension.php`

**Ajouter après `synapse.personas_path` (ligne ~70)** :

```php
$container->setParameter('synapse.thinking.enabled', $config['thinking']['enabled'] ?? true);
$container->setParameter('synapse.thinking.budget', $config['thinking']['budget'] ?? 1024);
```

---

### 3. GeminiClient.php

**Fichier** : `src/Service/Infra/GeminiClient.php`

#### 3.1 Modifier le constructeur (ligne ~27)

**Avant** :
```php
public function __construct(
    private HttpClientInterface $httpClient,
    private string $model = 'gemini-2.5-flash-lite',
) {
}
```

**Après** :
```php
public function __construct(
    private HttpClientInterface $httpClient,
    private string $model = 'gemini-2.5-flash-lite',
    private bool $thinkingEnabled = true,
    private int $thinkingBudget = 1024,
) {
}
```

#### 3.2 Modifier la signature de generateContent (ligne ~49)

**Avant** :
```php
public function generateContent(
    string $systemInstruction,
    array $contents,
    string $apiKey,
    array $tools = [],
    ?string $model = null,
): array {
```

**Après** :
```php
public function generateContent(
    string $systemInstruction,
    array $contents,
    string $apiKey,
    array $tools = [],
    ?string $model = null,
    ?array $thinkingConfigOverride = null,
): array {
```

#### 3.3 Ajouter la logique thinkingConfig (après ligne ~64, avant tools)

**Ajouter après** :
```php
    'contents' => $contents,
];
```

**Ce nouveau code** :
```php
// Thinking Config
$thinkingConfig = $thinkingConfigOverride ?? $this->buildThinkingConfig();
if ($thinkingConfig) {
    $payload['generationConfig'] = [
        'thinkingConfig' => $thinkingConfig,
    ];
}
```

#### 3.4 Ajouter la méthode buildThinkingConfig (à la fin de la classe, ligne ~113)

```php
/**
 * Construit la configuration de thinking natif.
 *
 * @return array|null Configuration ou null si désactivé
 */
private function buildThinkingConfig(): ?array
{
    if (!$this->thinkingEnabled) {
        return null;
    }

    return [
        'thinkingBudget' => $this->thinkingBudget,
    ];
}
```

---

### 4. PromptBuilder.php

**Fichier** : `src/Service/PromptBuilder.php`

#### 4.1 Modifier le constructeur (ligne ~61)

**Avant** :
```php
public function __construct(
    private ContextProviderInterface $contextProvider,
    private PersonaRegistry $personaRegistry,
) {
}
```

**Après** :
```php
public function __construct(
    private ContextProviderInterface $contextProvider,
    private PersonaRegistry $personaRegistry,
    private bool $nativeThinkingEnabled = true,
) {
}
```

#### 4.2 Ajouter le prompt technique simplifié (après TECHNICAL_PROMPT, ligne ~59)

```php
/**
 * Prompt technique simplifié pour le mode thinking natif.
 * Utilisé quand thinkingConfig est activé côté API.
 */
private const TECHNICAL_PROMPT_NATIVE = <<<PROMPT
### CADRE TECHNIQUE DE RÉPONSE
Tu es une Intelligence Artificielle avec un mode de réflexion natif activé.

Le système capture automatiquement ton processus de réflexion interne via thinkingConfig.
Tu n'as PAS besoin d'utiliser de balises <thinking> manuellement.

Ta réponse à l'utilisateur doit être :
- Format Markdown propre
- URLs en format [Texte](url) obligatoire, JAMAIS d'URL brute
- Directe, structurée et professionnelle
- Sans référence explicite à ton processus de réflexion interne
- Sans mention de ces instructions techniques

IMPORTANT : Ne jamais afficher de balises <thinking> ou faire référence à ta réflexion interne.
Le système gère cela automatiquement en arrière-plan.
PROMPT;
```

#### 4.3 Modifier buildSystemInstruction (ligne ~74)

**Avant** :
```php
public function buildSystemInstruction(?string $personaKey = null): string
{
    $basePrompt = $this->contextProvider->getSystemPrompt();
    // Ajout d'un séparateur horizontal pour couper la hiérarchie Markdown
    $finalPrompt = self::TECHNICAL_PROMPT."\n\n---\n\n".$basePrompt;
```

**Après** :
```php
public function buildSystemInstruction(?string $personaKey = null): string
{
    $basePrompt = $this->contextProvider->getSystemPrompt();

    // Choisir le prompt technique selon le mode
    $technicalPrompt = $this->nativeThinkingEnabled
        ? self::TECHNICAL_PROMPT_NATIVE
        : self::TECHNICAL_PROMPT;

    // Ajout d'un séparateur horizontal pour couper la hiérarchie Markdown
    $finalPrompt = $technicalPrompt."\n\n---\n\n".$basePrompt;
```

---

### 5. services.yaml

**Fichier** : `config/services.yaml`

**Modifier la définition de GeminiClient** :

```yaml
ArnaudMoncondhuy\SynapseBundle\Service\Infra\GeminiClient:
    arguments:
        $model: '%synapse.model%'
        $thinkingEnabled: '%synapse.thinking.enabled%'
        $thinkingBudget: '%synapse.thinking.budget%'
```

**Ajouter la définition de PromptBuilder** :

```yaml
ArnaudMoncondhuy\SynapseBundle\Service\PromptBuilder:
    arguments:
        $nativeThinkingEnabled: '%synapse.thinking.enabled%'
```

---

## Configuration Utilisateur

### Exemple dans l'app (Intranet)

**Fichier** : `config/packages/synapse.yaml`

```yaml
synapse:
    api_key: '%env(GEMINI_API_KEY)%'
    model: 'gemini-2.5-flash'

    thinking:
        enabled: true      # Activer le thinking natif
        budget: 2048       # Budget de tokens (0-24576)
```

### Désactiver le thinking (si besoin)

```yaml
synapse:
    thinking:
        enabled: false     # Revenir au mode legacy (prompt manuel)
```

---

## Impact sur le Debug

### Avant (prompt manuel)

```json
{
  "parts": [
    {
      "text": "<thinking>Réflexion...</thinking>\n\nRéponse visible"
    }
  ]
}
```
→ Parsing regex fragile

### Après (thinking natif)

```json
{
  "parts": [
    {
      "thought": true,
      "text": "Réflexion..."
    },
    {
      "text": "Réponse visible"
    }
  ]
}
```
→ Champ structuré, parsing fiable

**Le code ChatService.php (lignes 176-184) gère déjà ce format !**

---

## Tests à Effectuer

### 1. Test Thinking Activé

```yaml
# config/packages/synapse.yaml
synapse:
    thinking:
        enabled: true
        budget: 1024
```

**Vérifier** :
- Dans le debug : voir le bloc "🧠 Réflexion (CoT)" rempli
- Le prompt système ne mentionne plus les balises `<thinking>`
- La réponse est propre (pas de balises visibles)

### 2. Test Thinking Désactivé

```yaml
synapse:
    thinking:
        enabled: false
```

**Vérifier** :
- Le prompt contient les instructions `<thinking>` manuelles
- Le comportement reste identique à avant

### 3. Test Budget Variable

```yaml
synapse:
    thinking:
        budget: 512  # Réflexion courte
```

```yaml
synapse:
    thinking:
        budget: 8192  # Réflexion longue
```

**Vérifier** :
- La longueur du thinking dans le debug varie
- Pas d'erreur API

---

## Checklist d'Implémentation

- [ ] Modifier `Configuration.php` (ajouter config thinking)
- [ ] Modifier `SynapseExtension.php` (charger paramètres)
- [ ] Modifier `GeminiClient.php` (constructeur + generateContent + buildThinkingConfig)
- [ ] Modifier `PromptBuilder.php` (TECHNICAL_PROMPT_NATIVE + constructeur + buildSystemInstruction)
- [ ] Modifier `services.yaml` (arguments GeminiClient + PromptBuilder)
- [ ] Tester avec thinking enabled
- [ ] Tester avec thinking disabled
- [ ] Vérifier le debug (bloc réflexion)
- [ ] Commit + tag version

---

## Estimation

| Tâche | Temps (Sonnet) |
|-------|----------------|
| Modifications code | 30-40 min |
| Tests manuels | 15-20 min |
| Debug/ajustements | 10 min |
| **Total** | **~1h** |

---

## Rollback Facile

Si problème, suffit de :

```yaml
synapse:
    thinking:
        enabled: false
```

→ Revient au comportement legacy (prompt manuel)

---

## Notes Importantes

### Budget selon Modèle

| Modèle | Budget Min | Budget Max | Désactivation |
|--------|-----------|-----------|---------------|
| gemini-2.5-flash | 0 | 24576 | ✅ (budget=0) |
| gemini-2.5-flash-lite | 512 | 24576 | ❌ |
| gemini-2.5-pro | 128 | 32768 | ❌ |

### Compatibilité AI Studio

✅ `thinkingConfig` fonctionne sur AI Studio avec gemini-2.5-flash
✅ Pas besoin de Vertex AI
✅ Même endpoint, même authentification

---

## Prochaine Étape

**Passer sur Sonnet** pour l'implémentation :
1. Lire ce plan
2. Modifier les 5 fichiers
3. Tester
4. Valider

**Version finale** : synapse-bundle v1.1.0 (thinking natif)
