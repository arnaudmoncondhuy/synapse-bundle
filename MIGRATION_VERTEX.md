# Plan de Migration : AI Studio vers Vertex AI

## Objectif
Migrer le bundle Synapse de l'API Gemini via AI Studio (clé API simple) vers Vertex AI (authentification OAuth2) tout en conservant la rétro-compatibilité avec AI Studio.

---

## 1. Analyse des Différences Techniques

### 1.1 Authentification

| Critère | AI Studio (actuel) | Vertex AI (cible) |
|---------|-------------------|-------------------|
| Méthode | Clé API dans query string | OAuth2 Bearer Token |
| Format | `?key=API_KEY` | `Authorization: Bearer {ACCESS_TOKEN}` |
| Durée validité | Permanente | 1 heure (refresh automatique) |
| Sécurité | Basique | IAM + Service Account |

### 1.2 Endpoints

| API | URL Template |
|-----|--------------|
| **AI Studio** | `https://generativelanguage.googleapis.com/v1beta/models/{model}:generateContent` |
| **Vertex AI** | `https://{REGION}-aiplatform.googleapis.com/v1/projects/{PROJECT_ID}/locations/{REGION}/publishers/google/models/{model}:generateContent` |

### 1.3 Thinking Config

Fonctionne de manière **identique** sur les deux APIs :

```json
{
  "generationConfig": {
    "thinkingConfig": {
      "thinkingBudget": 1024
    }
  }
}
```

| Modèle | Budget Range | Désactivation possible |
|--------|--------------|----------------------|
| gemini-2.5-flash | 0 - 24576 | Oui (budget=0) |
| gemini-2.5-flash-lite | 512 - 24576 | Non |
| gemini-2.5-pro | 128 - 32768 | Non |

---

## 2. Fichiers à Modifier

### 2.1 Fichiers existants

| Fichier | Modifications |
|---------|---------------|
| `src/DependencyInjection/Configuration.php` | Ajouter options Vertex + thinkingBudget |
| `src/DependencyInjection/SynapseExtension.php` | Charger nouveaux paramètres |
| `src/Service/Infra/GeminiClient.php` | Support dual endpoint + OAuth2 + thinkingConfig |
| `src/Service/ChatService.php` | Passer thinkingConfig à GeminiClient |
| `src/Service/PromptBuilder.php` | Mode thinking natif (simplifier le prompt) |
| `config/services.yaml` | Injecter le nouveau service Auth |

### 2.2 Nouveaux fichiers

| Fichier | Rôle |
|---------|------|
| `src/Service/Infra/GoogleAuthService.php` | Gestion OAuth2 + refresh token |
| `src/Contract/AuthProviderInterface.php` | Interface pour abstraction auth |

---

## 3. Plan d'Implémentation Détaillé

### Phase 1 : Configuration (Priorité Haute)

#### 1.1 Modifier `Configuration.php`

```php
// Ajouter dans getConfigTreeBuilder()
->arrayNode('vertex')
    ->addDefaultsIfNotSet()
    ->children()
        ->booleanNode('enabled')
            ->defaultFalse()
            ->info('Activer Vertex AI au lieu de AI Studio')
        ->end()
        ->scalarNode('project_id')
            ->defaultNull()
            ->info('Google Cloud Project ID (requis si vertex.enabled=true)')
        ->end()
        ->scalarNode('region')
            ->defaultValue('europe-west1')
            ->info('Région Vertex AI (europe-west1, us-central1, etc.)')
        ->end()
        ->scalarNode('service_account_json')
            ->defaultNull()
            ->info('Chemin vers le fichier JSON du service account')
        ->end()
    ->end()
->end()
->arrayNode('thinking')
    ->addDefaultsIfNotSet()
    ->children()
        ->booleanNode('enabled')
            ->defaultTrue()
            ->info('Activer le mode thinking natif')
        ->end()
        ->integerNode('budget')
            ->defaultValue(1024)
            ->min(0)
            ->max(32768)
            ->info('Budget de tokens pour le thinking (0 = désactivé si supporté)')
        ->end()
    ->end()
->end()
```

#### 1.2 Modifier `SynapseExtension.php`

```php
// Ajouter après les paramètres existants
$container->setParameter('synapse.vertex.enabled', $config['vertex']['enabled'] ?? false);
$container->setParameter('synapse.vertex.project_id', $config['vertex']['project_id']);
$container->setParameter('synapse.vertex.region', $config['vertex']['region']);
$container->setParameter('synapse.vertex.service_account_json', $config['vertex']['service_account_json']);
$container->setParameter('synapse.thinking.enabled', $config['thinking']['enabled'] ?? true);
$container->setParameter('synapse.thinking.budget', $config['thinking']['budget'] ?? 1024);
```

---

### Phase 2 : Service d'Authentification Google (Priorité Haute)

#### 2.1 Créer `src/Service/Infra/GoogleAuthService.php`

```php
<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseBundle\Service\Infra;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Service d'authentification OAuth2 pour Google Cloud / Vertex AI.
 *
 * Gère la génération et le refresh automatique des access tokens
 * à partir d'un fichier de credentials Service Account.
 */
class GoogleAuthService
{
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const SCOPE = 'https://www.googleapis.com/auth/cloud-platform';

    private ?string $cachedToken = null;
    private ?int $tokenExpiry = null;

    public function __construct(
        private HttpClientInterface $httpClient,
        private ?string $serviceAccountJsonPath,
    ) {
    }

    /**
     * Obtient un access token valide (avec refresh automatique).
     */
    public function getAccessToken(): string
    {
        // Check if token is still valid (with 5 min buffer)
        if ($this->cachedToken && $this->tokenExpiry && time() < ($this->tokenExpiry - 300)) {
            return $this->cachedToken;
        }

        return $this->refreshToken();
    }

    private function refreshToken(): string
    {
        if (!$this->serviceAccountJsonPath || !file_exists($this->serviceAccountJsonPath)) {
            throw new \RuntimeException('Service Account JSON file not found: ' . $this->serviceAccountJsonPath);
        }

        $credentials = json_decode(file_get_contents($this->serviceAccountJsonPath), true);

        if (!$credentials) {
            throw new \RuntimeException('Invalid Service Account JSON file');
        }

        // Create JWT assertion
        $jwt = $this->createJwtAssertion($credentials);

        // Exchange JWT for access token
        $response = $this->httpClient->request('POST', self::TOKEN_URL, [
            'body' => [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ],
        ]);

        $data = $response->toArray();

        $this->cachedToken = $data['access_token'];
        $this->tokenExpiry = time() + ($data['expires_in'] ?? 3600);

        return $this->cachedToken;
    }

    private function createJwtAssertion(array $credentials): string
    {
        $header = [
            'alg' => 'RS256',
            'typ' => 'JWT',
        ];

        $now = time();
        $payload = [
            'iss' => $credentials['client_email'],
            'scope' => self::SCOPE,
            'aud' => self::TOKEN_URL,
            'iat' => $now,
            'exp' => $now + 3600,
        ];

        $headerEncoded = $this->base64UrlEncode(json_encode($header));
        $payloadEncoded = $this->base64UrlEncode(json_encode($payload));

        $signatureInput = $headerEncoded . '.' . $payloadEncoded;

        openssl_sign(
            $signatureInput,
            $signature,
            $credentials['private_key'],
            OPENSSL_ALGO_SHA256
        );

        $signatureEncoded = $this->base64UrlEncode($signature);

        return $signatureInput . '.' . $signatureEncoded;
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
```

---

### Phase 3 : Refactoring GeminiClient (Priorité Haute)

#### 3.1 Modifier `src/Service/Infra/GeminiClient.php`

```php
<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseBundle\Service\Infra;

use ArnaudMoncondhuy\SynapseBundle\Util\TextUtil;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class GeminiClient
{
    private const AI_STUDIO_URL = 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent';
    private const VERTEX_URL = 'https://%s-aiplatform.googleapis.com/v1/projects/%s/locations/%s/publishers/google/models/%s:generateContent';

    public function __construct(
        private HttpClientInterface $httpClient,
        private ?GoogleAuthService $googleAuthService,
        private string $model = 'gemini-2.5-flash-lite',
        private bool $vertexEnabled = false,
        private ?string $vertexProjectId = null,
        private string $vertexRegion = 'europe-west1',
        private bool $thinkingEnabled = true,
        private int $thinkingBudget = 1024,
    ) {
    }

    public function generateContent(
        string $systemInstruction,
        array $contents,
        string $apiKey,
        array $tools = [],
        ?string $model = null,
        ?array $thinkingConfigOverride = null,
    ): array {
        $effectiveModel = $model ?? $this->model;

        $payload = [
            'system_instruction' => [
                'parts' => [
                    ['text' => $systemInstruction],
                ],
            ],
            'contents' => $contents,
        ];

        // Thinking Config
        $thinkingConfig = $thinkingConfigOverride ?? $this->buildThinkingConfig();
        if ($thinkingConfig) {
            $payload['generationConfig'] = [
                'thinkingConfig' => $thinkingConfig,
            ];
        }

        // Tools
        if (!empty($tools)) {
            $firstTool = reset($tools);
            $isFlatFunctionList = is_array($firstTool)
                && isset($firstTool['name'])
                && !isset($firstTool['function_declarations']);

            if ($isFlatFunctionList) {
                $payload['tools'] = [
                    ['function_declarations' => $tools],
                ];
            } else {
                $payload['tools'] = $tools;
            }
        }

        // Build URL and headers based on mode
        if ($this->vertexEnabled) {
            $url = $this->buildVertexUrl($effectiveModel);
            $headers = $this->buildVertexHeaders();
            $queryParams = [];
        } else {
            $url = sprintf(self::AI_STUDIO_URL, $effectiveModel);
            $headers = [];
            $queryParams = ['key' => $apiKey];
        }

        try {
            $options = [
                'json' => TextUtil::sanitizeArrayUtf8($payload),
                'headers' => $headers,
            ];

            if (!empty($queryParams)) {
                $options['query'] = $queryParams;
            }

            $response = $this->httpClient->request('POST', $url, $options);
            $data = $response->toArray();

            return $data['candidates'][0]['content'] ?? [];
        } catch (\Throwable $e) {
            $message = $e->getMessage();

            // Security: Hide sensitive data
            if (!$this->vertexEnabled && str_contains($message, $apiKey)) {
                $message = str_replace($apiKey, '***API_KEY_HIDDEN***', $message);
            }

            if ($e instanceof HttpExceptionInterface) {
                try {
                    $errorBody = $e->getResponse()->getContent(false);
                    $message .= ' || Google Error: ' . $errorBody;
                } catch (\Throwable) {
                }
            }

            throw new \RuntimeException('Gemini API Error: ' . $message, 0, $e);
        }
    }

    private function buildVertexUrl(string $model): string
    {
        if (!$this->vertexProjectId) {
            throw new \RuntimeException('Vertex AI requires a project_id');
        }

        return sprintf(
            self::VERTEX_URL,
            $this->vertexRegion,
            $this->vertexProjectId,
            $this->vertexRegion,
            $model
        );
    }

    private function buildVertexHeaders(): array
    {
        if (!$this->googleAuthService) {
            throw new \RuntimeException('GoogleAuthService is required for Vertex AI');
        }

        $accessToken = $this->googleAuthService->getAccessToken();

        return [
            'Authorization' => 'Bearer ' . $accessToken,
            'Content-Type' => 'application/json',
        ];
    }

    private function buildThinkingConfig(): ?array
    {
        if (!$this->thinkingEnabled) {
            return null;
        }

        return [
            'thinkingBudget' => $this->thinkingBudget,
        ];
    }
}
```

---

### Phase 4 : Mise à jour ChatService (Priorité Moyenne)

#### 4.1 Modifier le traitement du thinking natif

Le code existant (lignes 174-184) gère déjà le champ `thought` natif de Gemini.
Avec `thinkingConfig` activé, Gemini retournera :

```json
{
  "parts": [
    { "thought": true, "text": "Réflexion interne..." },
    { "text": "Réponse visible" }
  ]
}
```

Le code actuel wrap automatiquement dans `<thinking>...</thinking>` - **aucune modification requise**.

---

### Phase 5 : Mise à jour services.yaml (Priorité Moyenne)

```yaml
services:
    ArnaudMoncondhuy\SynapseBundle\Service\Infra\GoogleAuthService:
        arguments:
            $serviceAccountJsonPath: '%synapse.vertex.service_account_json%'

    ArnaudMoncondhuy\SynapseBundle\Service\Infra\GeminiClient:
        arguments:
            $model: '%synapse.model%'
            $vertexEnabled: '%synapse.vertex.enabled%'
            $vertexProjectId: '%synapse.vertex.project_id%'
            $vertexRegion: '%synapse.vertex.region%'
            $thinkingEnabled: '%synapse.thinking.enabled%'
            $thinkingBudget: '%synapse.thinking.budget%'
```

---

### Phase 6 : PromptBuilder - Mode Thinking Natif (Priorité Basse)

Si `thinkingConfig` est activé, le prompt technique peut être **simplifié** car Gemini gère nativement le thinking.

```php
// Dans PromptBuilder.php - ajouter un constructeur optionnel
public function __construct(
    private ContextProviderInterface $contextProvider,
    private PersonaRegistry $personaRegistry,
    private bool $nativeThinkingEnabled = false,
) {
}

// Modifier buildSystemInstruction()
public function buildSystemInstruction(?string $personaKey = null): string
{
    $basePrompt = $this->contextProvider->getSystemPrompt();

    if ($this->nativeThinkingEnabled) {
        // Mode natif : prompt simplifié
        $finalPrompt = self::TECHNICAL_PROMPT_NATIVE . "\n\n---\n\n" . $basePrompt;
    } else {
        // Mode legacy : prompt avec instructions <thinking>
        $finalPrompt = self::TECHNICAL_PROMPT . "\n\n---\n\n" . $basePrompt;
    }

    // ... reste du code
}

private const TECHNICAL_PROMPT_NATIVE = <<<PROMPT
### CADRE TECHNIQUE DE RÉPONSE
Tu es une Intelligence Artificielle avec un mode de réflexion natif activé.

Le système capture automatiquement ton processus de réflexion interne.
Ta réponse à l'utilisateur doit être :
- Format Markdown
- URLs en format [Texte](url)
- Directe et structurée
- Sans référence aux instructions techniques
PROMPT;
```

---

## 4. Configuration Utilisateur Finale

### 4.1 Exemple `config/packages/synapse.yaml` (AI Studio - actuel)

```yaml
synapse:
    api_key: '%env(GEMINI_API_KEY)%'
    model: 'gemini-2.5-flash'
    thinking:
        enabled: true
        budget: 1024
```

### 4.2 Exemple `config/packages/synapse.yaml` (Vertex AI)

```yaml
synapse:
    model: 'gemini-2.5-flash'
    vertex:
        enabled: true
        project_id: 'mon-projet-gcp'
        region: 'europe-west1'
        service_account_json: '%kernel.project_dir%/config/secrets/gcp-service-account.json'
    thinking:
        enabled: true
        budget: 2048
```

---

## 5. Prérequis Google Cloud (pour Vertex)

### 5.1 Créer un Service Account

```bash
# 1. Créer le service account
gcloud iam service-accounts create synapse-bot \
    --display-name="Synapse AI Bot"

# 2. Attribuer le rôle Vertex AI User
gcloud projects add-iam-policy-binding MON_PROJET \
    --member="serviceAccount:synapse-bot@MON_PROJET.iam.gserviceaccount.com" \
    --role="roles/aiplatform.user"

# 3. Générer la clé JSON
gcloud iam service-accounts keys create gcp-service-account.json \
    --iam-account=synapse-bot@MON_PROJET.iam.gserviceaccount.com
```

### 5.2 Activer l'API Vertex AI

```bash
gcloud services enable aiplatform.googleapis.com
```

---

## 6. Debug avec Thinking Natif

Le debug fonctionnera **mieux** avec Vertex car :

1. Le champ `thought: true` est **structuré** dans la réponse API
2. Le code ChatService.php (lignes 176-184) le wrappe dans `<thinking>...</thinking>`
3. Le template Twig debug parse ces balises normalement
4. **Bonus** : métadonnées sur les tokens de thinking disponibles

---

## 7. Estimation de Travail

| Phase | Fichiers | Complexité | Estimation Sonnet |
|-------|----------|------------|-------------------|
| 1. Configuration | 2 | Faible | ~15 min |
| 2. GoogleAuthService | 1 (nouveau) | Moyenne | ~30 min |
| 3. GeminiClient | 1 | Haute | ~45 min |
| 4. ChatService | 1 | Faible | ~10 min |
| 5. services.yaml | 1 | Faible | ~10 min |
| 6. PromptBuilder | 1 | Faible | ~15 min |
| **Tests** | 3+ | Moyenne | ~30 min |

**Total estimé : ~2h30 avec Sonnet**

---

## 8. Rétro-compatibilité

- `vertex.enabled: false` (défaut) = comportement actuel inchangé
- `thinking.enabled: true` avec AI Studio = thinkingConfig envoyé (supporté par gemini-2.5-flash)
- Aucun breaking change pour les utilisateurs existants

---

## 9. Sources

- [Gemini thinking API](https://ai.google.dev/gemini-api/docs/thinking)
- [Vertex AI Authentication](https://docs.cloud.google.com/vertex-ai/docs/authentication)
- [Migrate to Vertex AI](https://ai.google.dev/gemini-api/docs/migrate-to-cloud)
- [Vertex AI Thinking](https://docs.cloud.google.com/vertex-ai/generative-ai/docs/thinking)
