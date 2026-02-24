# Guide d'utilisation

Ce document couvre l'utilisation avancée du bundle SynapseBundle : ChatService, création d'outils IA, events, et intégration avec Doctrine.

## ChatService : utiliser l'orchestrateur LLM

`ChatService` est le point d'entrée principal pour interagir avec les modèles LLM.

### Signature complète

```php
public function ask(
    string $message,
    array $options = [],
    ?callable $onStatusUpdate = null,  // fn(string $message, string $step): void
    ?callable $onToken = null          // fn(string $token): void
): array
```

### Structure du retour

```php
[
    'answer'   => string,       // Réponse complète de l'IA
    'debug_id' => ?string,      // ID du log debug (si mode debug)
    'usage'    => [             // Métadonnées tokens
        'prompt_tokens'     => int,
        'completion_tokens' => int,
        'total_tokens'      => int,
    ],
    'safety'   => array,        // Safety ratings (Gemini)
    'model'    => string,       // Modèle utilisé (ex: 'gemini-2.5-flash')
]
```

### Options disponibles

| Option | Type | Description |
|---|---|---|
| `preset` | `SynapsePreset` | Surcharge le preset actif pour cet appel uniquement |
| `persona` | string | Clé de persona (ex: `'juridique'`) pour personnaliser le prompt système |
| `history` | array | Historique de conversation au format OpenAI (voir section ci-dessous) |
| `stateless` | bool | Ignorer l'historique persisté, traiter comme un appel isolé |
| `tools` | array | Outils supplémentaires pour cet appel (au-delà des outils globaux) |
| `debug` | bool | Forcer/désactiver le debug pour cet appel (log complet en BDD) |
| `reset_conversation` | bool | Réinitialiser la conversation (supprimer l'historique) |

### Format de l'historique (OpenAI canonical)

```php
$history = [
    ['role' => 'user', 'content' => 'Bonjour'],
    ['role' => 'assistant', 'content' => 'Bonjour ! Comment puis-je vous aider ?'],
    ['role' => 'user', 'content' => 'Calcule 2 + 2'],
    // Après un function call :
    ['role' => 'assistant', 'content' => null, 'tool_calls' => [
        [
            'id' => 'call_abc123',
            'function' => ['name' => 'calcul', 'arguments' => '{"a": 2, "b": 2}']
        ]
    ]],
    ['role' => 'tool', 'tool_call_id' => 'call_abc123', 'content' => '4'],
    ['role' => 'assistant', 'content' => 'Le résultat est 4'],
];

$result = $this->chatService->ask(
    message: 'Explique le résultat',
    options: ['history' => $history]
);
```

### Exemples d'utilisation

#### 1. Appel simple (one-shot, stateless)

```php
class MyController extends AbstractController
{
    public function __construct(private ChatService $chatService) {}

    public function askAction(Request $request): JsonResponse
    {
        $result = $this->chatService->ask(
            message: $request->get('message'),
            options: ['stateless' => true]
        );

        return $this->json(['answer' => $result['answer']]);
    }
}
```

#### 2. Appel avec streaming (Server-Sent Events)

```php
public function streamAction(Request $request): StreamedResponse
{
    return new StreamedResponse(function () use ($request) {
        $result = $this->chatService->ask(
            message: $request->get('message'),
            options: [
                'history'  => $request->get('history', []),
                'stateless' => false,
            ],
            onToken: function (string $token) {
                echo 'data: ' . json_encode(['token' => $token]) . "\n\n";
                ob_flush();
                flush();
            }
        );

        echo 'data: ' . json_encode([
            'done' => true,
            'usage' => $result['usage'],
        ]) . "\n\n";
    }, 200, [
        'Content-Type'  => 'text/event-stream',
        'Cache-Control' => 'no-cache',
        'X-Accel-Buffering' => 'no',
    ]);
}
```

#### 3. Avec indicators de statut (feedback utilisateur)

```php
$result = $this->chatService->ask(
    message: 'Analyse ce document...',
    options: ['debug' => true],
    onStatusUpdate: function (string $message, string $step) {
        // $step peut être : 'thinking', 'tool:nom_outil', etc.
        logger()->info("Status [$step]: $message");
    },
    onToken: fn(string $token) => print($token)
);
```

#### 4. Avec persona et preset forcé

```php
$preset = $this->presetRepository->find($presetId);

$result = $this->chatService->ask(
    message: 'Rédige un contrat de travail',
    options: [
        'preset'  => $preset,
        'persona' => 'juridique',
        'history' => $conversationHistory,
    ]
);
```

---

## Créer des outils IA (Function Calling)

Les outils permettent au LLM d'appeler des fonctions métier pour obtenir des données ou exécuter des actions.

### Contrat AiToolInterface

```php
interface AiToolInterface
{
    /**
     * Nom unique de l'outil (identifiant pour le LLM).
     * Convention: snake_case explicite
     * Exemples: 'get_current_weather', 'search_products', 'calcul_tva'
     */
    public function getName(): string;

    /**
     * Description CRITIQUE : le LLM l'utilise pour décider QUAND appeler l'outil.
     * Soyez précis sur ce que l'outil fait et ne fait PAS.
     *
     * Exemple : "Calcule le prix TTC à partir d'un prix HT et d'un taux de TVA.
     *            Utiliser cet outil quand l'utilisateur demande le prix final.
     *            Ne pas utiliser pour des devises étrangères."
     */
    public function getDescription(): string;

    /**
     * Schéma JSON des paramètres (format OpenAPI / JSON Schema).
     *
     * @return array{type: string, properties: array, required?: string[]}
     *
     * Exemple :
     * [
     *     'type' => 'object',
     *     'properties' => [
     *         'prix_ht' => ['type' => 'number', 'description' => 'Prix HT en €'],
     *         'taux_tva' => ['type' => 'number', 'description' => 'Taux TVA %'],
     *     ],
     *     'required' => ['prix_ht', 'taux_tva'],
     * ]
     */
    public function getInputSchema(): array;

    /**
     * Logique métier : appel réel à votre code.
     *
     * @param array<string, mixed> $parameters Paramètres extraits par le LLM
     * @return mixed Résultat sérialisé (array ou string) envoyé au LLM
     */
    public function execute(array $parameters): mixed;
}
```

### Enregistrement automatique

Implémentez `AiToolInterface` dans une classe Symfony :

```php
<?php
// src/Tool/CalculPrixTtcTool.php
declare(strict_types=1);

namespace App\Tool;

use ArnaudMoncondhuy\SynapseBundle\Contract\AiToolInterface;

class CalculPrixTtcTool implements AiToolInterface
{
    public function getName(): string
    {
        return 'calcul_prix_ttc';
    }

    public function getDescription(): string
    {
        return 'Calcule le prix TTC à partir d\'un prix HT et d\'un taux de TVA. '
             . 'Utiliser quand l\'utilisateur demande le prix final TTC.';
    }

    public function getInputSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'prix_ht' => [
                    'type'        => 'number',
                    'description' => 'Le prix Hors Taxes en euros',
                ],
                'taux_tva' => [
                    'type'        => 'number',
                    'description' => 'Le taux de TVA en pourcentage (5.5, 10, 20)',
                    'enum'        => [5.5, 10, 20],
                ],
            ],
            'required' => ['prix_ht', 'taux_tva'],
        ];
    }

    public function execute(array $parameters): mixed
    {
        $prixHt  = (float) $parameters['prix_ht'];
        $tauxTva = (float) $parameters['taux_tva'];
        $prixTtc = $prixHt * (1 + $tauxTva / 100);

        return [
            'prix_ht'      => $prixHt,
            'taux_tva'     => $tauxTva,
            'prix_ttc'     => round($prixTtc, 2),
            'montant_tva'  => round($prixTtc - $prixHt, 2),
        ];
    }
}
```

Configuration (auto-détection via tag) :

```yaml
# config/services.yaml
services:
    _instanceof:
        ArnaudMoncondhuy\SynapseBundle\Contract\AiToolInterface:
            tags: ['synapse.tool']
```

### Exemple avec injection de dépendance

```php
<?php
// src/Tool/RechercheProductTool.php
declare(strict_types=1);

namespace App\Tool;

use ArnaudMoncondhuy\SynapseBundle\Contract\AiToolInterface;
use App\Repository\ProductRepository;

class RechercheProductTool implements AiToolInterface
{
    public function __construct(
        private ProductRepository $productRepository
    ) {}

    public function getName(): string
    {
        return 'recherche_produit';
    }

    public function getDescription(): string
    {
        return 'Recherche des produits dans le catalogue par nom ou catégorie. '
             . 'Retourne une liste avec prix et disponibilité. '
             . 'Appeler quand l\'utilisateur cherche un produit spécifique.';
    }

    public function getInputSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'query' => [
                    'type'        => 'string',
                    'description' => 'Terme de recherche (nom, catégorie)',
                ],
                'limit' => [
                    'type'        => 'integer',
                    'description' => 'Nombre max de résultats',
                    'default'     => 5,
                ],
            ],
            'required' => ['query'],
        ];
    }

    public function execute(array $parameters): mixed
    {
        $query   = $parameters['query'];
        $limit   = (int) ($parameters['limit'] ?? 5);
        $products = $this->productRepository->search($query, $limit);

        if (empty($products)) {
            return 'Aucun produit trouvé pour : ' . $query;
        }

        return array_map(fn($p) => [
            'id'         => $p->getId(),
            'nom'        => $p->getNom(),
            'prix'       => $p->getPrix(),
            'disponible' => $p->isDisponible(),
            'url'        => '/produits/' . $p->getSlug(),
        ], $products);
    }
}
```

---

## Events Symfony (extension avancée)

Le bundle dispatchait des events Symfony durant le cycle de vie de l'échange avec le LLM.

### `SynapsePrePromptEvent`

**Dispatché** : AVANT l'envoi du message au LLM
**Priorité** : 100 (ContextBuilderSubscriber construit le prompt)

```php
// Écouter l'événement
#[AsEventListener(event: SynapsePrePromptEvent::class)]
public function onPrePrompt(SynapsePrePromptEvent $event): void
{
    // Modifier le prompt système avant envoi
    $event->setSystemInstruction($event->getSystemInstruction() . "\n[INFO SUPPLÉMENTAIRE]");

    // Ajouter du contexte initial
    $contents = $event->getContents();
    // ... modifications ...
    $event->setContents($contents);
}
```

### `SynapseChunkReceivedEvent`

**Dispatché** : À CHAQUE chunk reçu du LLM en streaming
**Utilisation** : Logger les tokens, mettre à jour une UI en temps réel

```php
#[AsEventListener(event: SynapseChunkReceivedEvent::class)]
public function onChunkReceived(SynapseChunkReceivedEvent $event): void
{
    $token = $event->getChunk();
    $debugId = $event->getDebugId();

    // Logger, broadcaster, etc.
    logger()->debug("Chunk received: $token (debug: $debugId)");
}
```

### `SynapseExchangeCompletedEvent`

**Dispatché** : APRÈS la boucle complète (fin de réponse, pas de tool call)
**Utilisation** : Finaliser les logs, publier les métriques

```php
#[AsEventListener(event: SynapseExchangeCompletedEvent::class)]
public function onExchangeCompleted(SynapseExchangeCompletedEvent $event): void
{
    $answer = $event->getAnswer();
    $usage = $event->getUsage();
    $debugId = $event->getDebugId();

    // Sauvegarder des métriques, envoyer événement métier, etc.
    logger()->info("Exchange completed. Tokens: {$usage['total_tokens']}");
}
```

### `SynapseToolCallRequestedEvent`

**Dispatché** : QUAND le LLM demande l'exécution d'un outil
**Utilisation** : Valider les arguments, logger l'appel

```php
#[AsEventListener(event: SynapseToolCallRequestedEvent::class)]
public function onToolCallRequested(SynapseToolCallRequestedEvent $event): void
{
    $toolName = $event->getToolName();
    $arguments = $event->getArguments();

    // Valider ou logger
    logger()->info("Tool requested: $toolName with " . json_encode($arguments));

    // Le ToolExecutionSubscriber va exécuter l'outil et attacher le résultat
    // via $event->setResult($result);
}
```

---

## Personas (Personnalités IA)

Les personas permettent de prédéfinir des rôles et tons pour l'IA.

### Format du fichier JSON

```json
{
    "juridique": {
        "name": "Juriste Expert",
        "emoji": "⚖️",
        "system_prompt": "Tu es un expert en droit français. Réponds avec précision légale, cite les articles du code civil quand approprié."
    },
    "marketing": {
        "name": "Spécialiste Marketing",
        "emoji": "📢",
        "system_prompt": "Tu es un expert en stratégie marketing digital. Fournis des conseils actionables et data-driven."
    },
    "support": {
        "name": "Agent Support Client",
        "emoji": "🎧",
        "system_prompt": "Tu es un agent support courtois et efficace. Aide l'utilisateur à résoudre son problème rapidement."
    }
}
```

### Configuration

```yaml
# config/packages/synapse.yaml
synapse:
    personas_path: '%kernel.project_dir%/config/personas.json'
```

### Utilisation

```php
// Utiliser un persona dans ChatService
$result = $this->chatService->ask(
    message: 'Conseille-moi sur une stratégie marketing',
    options: ['persona' => 'marketing']
);

// Lister les personas disponibles (en Twig)
{% set personas = synapse_get_personas() %}
```

---

## Entités Doctrine : persister conversations

`Conversation` et `Message` sont des `MappedSuperclass` à étendre.

### Créer vos entités

```php
<?php
// src/Entity/Conversation.php
declare(strict_types=1);

namespace App\Entity;

use ArnaudMoncondhuy\SynapseBundle\Storage\Entity\Conversation as BaseConversation;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SynapseConversationRepository::class)]
#[ORM\Table(name: 'conversation')]
class Conversation extends BaseConversation
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'ULID')]
    #[ORM\Column(type: 'ulid')]
    protected $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    protected User $owner;

    // Ajouter des champs métier si besoin
    // ...
}
```

```php
<?php
// src/Entity/Message.php
declare(strict_types=1);

namespace App\Entity;

use ArnaudMoncondhuy\SynapseBundle\Storage\Entity\Message as BaseMessage;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SynapseMessageRepository::class)]
#[ORM\Table(name: 'message')]
class Message extends BaseMessage
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'ULID')]
    #[ORM\Column(type: 'ulid')]
    protected $id;

    #[ORM\ManyToOne(targetEntity: Conversation::class)]
    #[ORM\JoinColumn(nullable: false)]
    protected Conversation $conversation;

    // Ajouter des champs métier si besoin
    // ...
}
```

### Implémenter ConversationOwnerInterface sur User

```php
<?php
// src/Entity/User.php
declare(strict_types=1);

namespace App\Entity;

use ArnaudMoncondhuy\SynapseBundle\Contract\ConversationOwnerInterface;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class User implements ConversationOwnerInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private int $id;

    #[ORM\Column(type: 'string', unique: true)]
    private string $email;

    #[ORM\Column(type: 'string')]
    private string $nom;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getIdentifier(): string
    {
        return $this->email;  // Ou $this->getNom(), dépend de votre logique
    }
}
```

### Configuration

```yaml
# config/packages/synapse.yaml
synapse:
    persistence:
        enabled: true
        conversation_class: App\Entity\Conversation
        message_class: App\Entity\Message
```

Créer les migrations et exécuter :
```bash
php bin/console make:migration
php bin/console doctrine:migrations:migrate
```

---

## AbstractAdminController : étendre l'admin

Créez votre propre interface d'administration Synapse en étendant `AbstractAdminController`.

```php
<?php
// src/Admin/MyAdminController.php
declare(strict_types=1);

namespace App\Admin;

use ArnaudMoncondhuy\SynapseBundle\Admin\Controller\AbstractAdminController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/mon-admin', name: 'app_my_admin_')]
class MyAdminController extends AbstractAdminController
{
    public function checkDashboardAccess(Request $request): void
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
    }

    public function checkAnalyticsAccess(Request $request): void
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
    }

    public function getModuleColor(): string
    {
        return '#e63946';  // Couleur custom du module
    }

    public function getModuleIcon(): string
    {
        return 'brain-circuit';  // Icône Lucide
    }

    public function getActiveConversationsCount(): int
    {
        return $this->conversationManager->getActiveCount();
    }

    public function getTemplatePath(string $name): string
    {
        // Si vous utilisez un layout_module, déclarer le chemin du template parent
        if ($name === 'layout') {
            return 'bundles/SynapseBundle/admin/layout_module.html.twig';
        }
        return 'bundles/SynapseBundle/admin/layout.html.twig';
    }

    #[Route('/dashboard', name: 'dashboard')]
    public function dashboardAction(Request $request): Response
    {
        $this->checkDashboardAccess($request);

        return $this->render('@Synapse/admin/dashboard.html.twig', [
            'activeConversations' => $this->getActiveConversationsCount(),
        ]);
    }
}
```

---

## Commandes CLI

### `synapse:purge` - Purger les conversations par ancienneté

Supprime DÉFINITIVEMENT les conversations plus anciennes que N jours (RGPD).

```bash
# Simuler la suppression (sans danger)
php bin/console synapse:purge --dry-run

# Nettoyer les conversations > 30 jours (défaut de config)
php bin/console synapse:purge

# Nettoyer les conversations > 90 jours
php bin/console synapse:purge --days=90

# Combiner simulation + jours custom
php bin/console synapse:purge --days=60 --dry-run
```

**Attention** : Cette commande est destructrice. Toujours tester avec `--dry-run` d'abord.

---

## API REST interne

Le bundle expose des endpoints REST internes pour le chat et les conversations.

### `POST /synapse/api/chat` — Envoyer un message (streaming NDJSON)

```javascript
fetch('/synapse/api/chat', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
        message: 'Bonjour',
        history: [],
        conversation_id: null  // ou ID d'une conversation existante
    })
})
.then(res => res.body.getReader())
.then(reader => {
    // Lire les chunks NDJSON
    const decoder = new TextDecoder();
    reader.read().then(function process({done, value}) {
        if (done) return;
        const lines = decoder.decode(value).split('\n');
        lines.forEach(line => {
            if (line.trim()) {
                const data = JSON.parse(line);
                console.log(data);  // {token: '...', or done: true, ...}
            }
        });
        return reader.read().then(process);
    });
});
```

### `GET /synapse/api/conversations` — Lister les conversations

```bash
curl -X GET \
  'https://example.com/synapse/api/conversations' \
  -H 'Authorization: Bearer YOUR_TOKEN'
```

Retour :
```json
{
    "conversations": [
        {"id": "abc123", "title": "Aide fiscale", "created_at": "2024-02-22T10:30:00Z"},
        ...
    ]
}
```

### `DELETE /synapse/api/conversations/{id}` — Supprimer une conversation

```bash
curl -X DELETE \
  'https://example.com/synapse/api/conversations/abc123' \
  -H 'Authorization: Bearer YOUR_TOKEN'
```

### `PATCH /synapse/api/conversations/{id}/rename` — Renommer une conversation

```bash
curl -X PATCH \
  'https://example.com/synapse/api/conversations/abc123/rename' \
  -H 'Content-Type: application/json' \
  -d '{"title": "Nouveau titre"}' \
  -H 'Authorization: Bearer YOUR_TOKEN'
```

### `GET /synapse/api/conversations/{id}/messages` — Récupérer les messages

```bash
curl -X GET \
  'https://example.com/synapse/api/conversations/abc123/messages' \
  -H 'Authorization: Bearer YOUR_TOKEN'
```

Retour :
```json
{
    "messages": [
        {"role": "user", "content": "Bonjour"},
        {"role": "assistant", "content": "Bonjour ! Comment puis-je vous aider ?"},
        ...
    ]
}
```

---

## Voir aussi

- [Configuration](configuration.md) — Options `synapse.yaml` complètes
- [Intégration des vues](views.md) — Templates Twig et CSS
- [Changelog](changelog.md) — Historique des versions
