# SynapseBundle: Guide d'Implémentation - Créer un Client LLM Personnalisé

> **Audience** : Développeurs créant des clients LLM pour SynapseBundle (Mistral, Claude, Ollama, etc.)

---

## 📋 Vue d'ensemble

Depuis février 2026, SynapseBundle utilise **OpenAI Chat Completions** comme format interne standard. Cela signifie :

1. **ChatService** (le cœur) parle uniquement OpenAI
2. Votre client LLM reçoit des messages en format OpenAI
3. Votre client convertit OpenAI ↔ format de votre provider
4. Votre client retourne des réponses normalisées

**Résultat** : ChatService est 100% LLM-agnostique, et votre client n'est qu'une couche de traduction.

---

## 🎯 Architecture: Le Pattern

```
┌─────────────────────────────────────────────────────┐
│ ChatService (100% agnostique, jamais changé)       │
└──────────────────┬──────────────────────────────────┘
                   │
                   ↓ (OpenAI format)
┌─────────────────────────────────────────────────────┐
│ Votre Client LLM (Traducteur)                       │
│ • Reçoit: OpenAI format                             │
│ • Convertit: OpenAI → Format Provider               │
│ • Appelle: API Provider                             │
│ • Normalise: Provider → OpenAI format               │
└─────────────────────────────────────────────────────┘
                   │
                   ↓ (Chunks normalisés)
┌─────────────────────────────────────────────────────┐
│ ChatService accumule les réponses et retourne      │
└─────────────────────────────────────────────────────┘
```

---

## 🔧 Étapes d'Implémentation

### Étape 1 : Créer la classe client

```php
<?php
namespace App\Core\Client;

use ArnaudMoncondhuy\SynapseBundle\Contract\LlmClientInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class MistralClient implements LlmClientInterface
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private ConfigProviderInterface $configProvider,
    ) {}

    public function getProviderName(): string
    {
        return 'mistral';
    }

    public function generateContent(
        array $contents,  // OpenAI format
        array $tools = [],
        ?string $model = null,
        ?array $thinkingConfigOverride = null,
        array &$debugOut = [],
    ): array {
        // Implémentation...
    }

    public function streamGenerateContent(
        array $contents,  // OpenAI format
        array $tools = [],
        ?string $model = null,
        array &$debugOut = [],
    ): \Generator {
        // Implémentation...
    }
}
```

---

### Étape 2 : Implémenter `generateContent()` (mode synchrone)

```php
public function generateContent(
    array $contents,
    array $tools = [],
    ?string $model = null,
    ?array $thinkingConfigOverride = null,
    array &$debugOut = [],
): array {
    // 1. Configuration dynamique
    $config = $this->configProvider->getConfig();
    $effectiveModel = $model ?? $config['model'] ?? 'mistral-large';
    $apiKey = $config['provider_credentials']['api_key'] ?? '';

    // 2. EXTRAIRE le système de contents[0]
    $systemPrompt = '';
    $userMessages = $contents;

    if (!empty($contents) && ($contents[0]['role'] ?? '') === 'system') {
        $systemPrompt = $contents[0]['content'] ?? '';
        $userMessages = array_slice($contents, 1);
    }

    // 3. CONVERTIR vers le format Mistral
    $mistralMessages = $this->toMistralMessages($userMessages);

    // 4. Construire le payload
    $payload = [
        'model' => $effectiveModel,
        'messages' => $mistralMessages,
        'temperature' => $config['generation_config']['temperature'] ?? 1.0,
        'top_p' => $config['generation_config']['top_p'] ?? 0.9,
    ];

    // Ajouter le système si le provider le supporte
    if ($systemPrompt) {
        array_unshift($payload['messages'], [
            'role' => 'system',
            'content' => $systemPrompt,
        ]);
    }

    // Ajouter les outils si fournis
    if (!empty($tools)) {
        $payload['tools'] = $this->toMistralTools($tools);
    }

    // 5. Appeler l'API
    try {
        $response = $this->httpClient->request('POST', 'https://api.mistral.ai/v1/chat/completions', [
            'headers' => ['Authorization' => "Bearer {$apiKey}"],
            'json' => $payload,
        ]);

        $data = $response->toArray();
        $debugOut['raw_api_response'] = $data;

        // 6. NORMALISER la réponse
        return $this->normalizeResponse($data);
    } catch (\Throwable $e) {
        throw new \RuntimeException("Mistral API error: {$e->getMessage()}", 0, $e);
    }
}
```

---

### Étape 3 : Implémenter `streamGenerateContent()` (streaming)

```php
public function streamGenerateContent(
    array $contents,
    array $tools = [],
    ?string $model = null,
    array &$debugOut = [],
): \Generator {
    // Structure identique à generateContent(), mais :
    // - Ajouter 'stream': true au payload
    // - Parser les chunks SSE (data: {...})
    // - Yield chaque chunk normalisé

    $config = $this->configProvider->getConfig();
    $effectiveModel = $model ?? $config['model'] ?? 'mistral-large';

    // ... extraction du système et conversion (comme ci-dessus) ...

    $payload['stream'] = true;

    try {
        $response = $this->httpClient->request('POST', 'https://api.mistral.ai/v1/chat/completions', [
            'headers' => ['Authorization' => "Bearer {$apiKey}"],
            'json' => $payload,
            'buffer' => false,  // ← Important pour le streaming
        ]);

        $buffer = '';
        foreach ($this->httpClient->stream($response) as $chunk) {
            $buffer .= $chunk->getContent();

            // Parser les lignes SSE (format Mistral: "data: {...}")
            while (($lineEnd = strpos($buffer, "\n")) !== false) {
                $line = substr($buffer, 0, $lineEnd);
                $buffer = substr($buffer, $lineEnd + 1);

                if (str_starts_with($line, 'data: ')) {
                    $json = substr($line, 6);
                    if ($json === '[DONE]') {
                        return;
                    }

                    $data = json_decode($json, true);
                    if ($data) {
                        yield $this->normalizeStreamChunk($data);
                    }
                }
            }
        }
    } catch (\Throwable $e) {
        throw new \RuntimeException("Mistral streaming error: {$e->getMessage()}", 0, $e);
    }
}
```

---

### Étape 4 : Convertir les messages (OpenAI → Format Provider)

```php
private function toMistralMessages(array $openAiMessages): array
{
    $mistralMessages = [];

    foreach ($openAiMessages as $msg) {
        $role = $msg['role'] ?? '';

        if ($role === 'user') {
            $mistralMessages[] = [
                'role' => 'user',
                'content' => $msg['content'] ?? '',
            ];
        } elseif ($role === 'assistant') {
            $content = $msg['content'] ?? '';

            // Mistral supporte aussi les tool_calls (format OpenAI)
            $mistralMsg = [
                'role' => 'assistant',
                'content' => $content,
            ];

            if (!empty($msg['tool_calls'])) {
                $mistralMsg['tool_calls'] = $msg['tool_calls'];  // Déjà en format OpenAI!
            }

            $mistralMessages[] = $mistralMsg;
        } elseif ($role === 'tool') {
            $mistralMessages[] = [
                'role' => 'tool',
                'tool_call_id' => $msg['tool_call_id'] ?? '',
                'content' => $msg['content'] ?? '',
            ];
        }
    }

    return $mistralMessages;
}

private function toMistralTools(array $tools): array
{
    // Les outils sont déjà au format OpenAI/Mistral-compatible
    return array_map(fn($tool) => [
        'type' => 'function',
        'function' => $tool,  // {name, description, parameters}
    ], $tools);
}
```

---

### Étape 5 : Normaliser les réponses

Le format de chunk normalisé **obligatoire** :

```php
private function normalizeResponse(array $data): array
{
    $choice = $data['choices'][0] ?? [];
    $message = $choice['message'] ?? [];

    return [
        'text'           => $message['content'] ?? null,
        'thinking'       => null,  // Mistral n'a pas de thinking natif
        'function_calls' => $this->extractFunctionCalls($message['tool_calls'] ?? []),
        'usage'          => [
            'promptTokenCount'     => $data['usage']['prompt_tokens'] ?? 0,
            'candidatesTokenCount' => $data['usage']['completion_tokens'] ?? 0,
            'thoughtsTokenCount'   => 0,
            'totalTokenCount'      => $data['usage']['total_tokens'] ?? 0,
        ],
        'safety_ratings' => [],  // Mistral n'a pas de safety ratings
        'blocked'        => false,
        'blocked_reason' => null,
    ];
}

private function normalizeStreamChunk(array $data): array
{
    $delta = $data['choices'][0]['delta'] ?? [];

    return [
        'text'           => $delta['content'] ?? null,
        'thinking'       => null,
        'function_calls' => $this->extractFunctionCalls($delta['tool_calls'] ?? []),
        'usage'          => [],  // Fourni seulement en fin de stream
        'safety_ratings' => [],
        'blocked'        => false,
        'blocked_reason' => null,
    ];
}

private function extractFunctionCalls(array $toolCalls): array
{
    if (empty($toolCalls)) {
        return [];
    }

    $result = [];
    foreach ($toolCalls as $tc) {
        $result[] = [
            'id'   => $tc['id'] ?? '',
            'name' => $tc['function']['name'] ?? '',
            'args' => json_decode($tc['function']['arguments'] ?? '{}', true) ?? [],
        ];
    }

    return $result;
}
```

---

## 📋 Checklist d'Implémentation

- [ ] Classe client implémente `LlmClientInterface`
- [ ] Méthode `getProviderName()` retourne le bon identifiant
- [ ] `generateContent()` implémentée (mode synchrone)
- [ ] `streamGenerateContent()` implémentée (mode streaming)
- [ ] Messages OpenAI extraits et convertis correctement
- [ ] Réponses normalisées au format chunk standard
- [ ] Tests unitaires pour la conversion de messages
- [ ] Tests d'intégration avec l'API provider
- [ ] Gestion des erreurs API
- [ ] Enregistrement comme service Symfony

---

## 🔗 Enregistrement dans le DI

```yaml
# config/services.yaml
services:
    App\Core\Client\MistralClient:
        arguments:
            $httpClient: '@http_client'
            $configProvider: '@ArnaudMoncondhuy\SynapseBundle\Contract\ConfigProviderInterface'

    # Enregistrer auprès du LlmClientRegistry
    synapse.client.mistral:
        class: App\Core\Client\MistralClient
        factory: ['@service_container', 'get']
        arguments: ['App\Core\Client\MistralClient']
```

Puis, ajouter votre client au registry dans `SynapseExtension`:

```php
public function load(array $configs, ContainerBuilder $container)
{
    // ... code existant ...

    // Enregistrer le provider Mistral
    $clientRegistry = $container->getDefinition(LlmClientRegistry::class);
    $clientRegistry->addMethodCall('registerClient', ['mistral', 'App\Core\Client\MistralClient']);
}
```

---

## 🧪 Tests

Exemple de test unitaire :

```php
public function testMessageConversion(): void
{
    $client = new MistralClient($httpClient, $configProvider);

    $openAiMessages = [
        ['role' => 'system', 'content' => 'You are helpful'],
        ['role' => 'user', 'content' => 'Hello'],
    ];

    $mistralMessages = $client->toMistralMessages($openAiMessages);

    // Mistral reçoit le système comme premier message
    $this->assertEquals('system', $mistralMessages[0]['role']);
    $this->assertEquals('You are helpful', $mistralMessages[0]['content']);
}

public function testResponseNormalization(): void
{
    $mistralResponse = [
        'choices' => [
            ['message' => ['content' => 'Hello!']],
        ],
        'usage' => [
            'prompt_tokens' => 10,
            'completion_tokens' => 5,
            'total_tokens' => 15,
        ],
    ];

    $normalized = $client->normalizeResponse($mistralResponse);

    $this->assertEquals('Hello!', $normalized['text']);
    $this->assertEquals(10, $normalized['usage']['promptTokenCount']);
    $this->assertFalse($normalized['blocked']);
}
```

---

## 🚀 Exemple Complet : Mistral

Voir [GeminiClient](src/Core/Client/GeminiClient.php) et [OvhAiClient](src/Core/Client/OvhAiClient.php) comme références d'implémentation.

Le pattern est identique pour tous les providers :
1. Extraire le système
2. Convertir les messages
3. Appeler l'API
4. Normaliser la réponse

---

## 📚 Ressources

- [Format OpenAI Chat Completions](https://platform.openai.com/docs/api-reference/chat/create)
- [LlmClientInterface](src/Contract/LlmClientInterface.php)
- [ChatService](src/Core/Chat/ChatService.php) (le consommateur)
- [STANDARDIZATION_SUMMARY.md](STANDARDIZATION_SUMMARY.md) (architecture détaillée)

---

**Questions ?** Consultez la documentation ou créez une issue ! 🎉
