# SynapseBundle

Un bundle Symfony pour intégrer facilement des assistants IA dans votre application, avec support multi-providers (Google Gemini, OVH AI Endpoints) et interface d'administration complète.

> **📣 Février 2026** : Standardisation sur le format OpenAI pour 100% d'agnostisme LLM.
> Si vous avez créé un client personnalisé, consultez [IMPLEMENTATION_GUIDE.md](IMPLEMENTATION_GUIDE.md) et le [Changelog](docs/changelog.md#-breaking-changes---standardisation-openai) pour les breaking changes.

## ✨ Fonctionnalités

- 🤖 **Multi-providers** : Google Vertex AI (Gemini 2.5+) et OVH AI Endpoints (OpenAI-compatible)
- 🔧 **Function Calling** : Système extensible pour ajouter des outils IA personnalisés
- 📡 **Streaming** : Réponses en temps réel via NDJSON
- 💾 **Persistance** : Historique des conversations en base de données (Doctrine)
- 🔒 **Sécurité** :
  - Chiffrement des messages (XSalsa20-Poly1305)
  - Chiffrement des credentials des providers
  - Filtres de sécurité configurables
- 🎨 **Interface Admin** : Dashboard, analytiques, gestion des presets et modèles
- 🎯 **Personas** : Personnalités IA prédéfinies ou custom
- 💭 **Thinking Mode** : Support natif du raisonnement Chain-of-Thought (Gemini 2.5+)
- 📊 **Token Tracking** : Suivi de la consommation et calcul des coûts
- 🧩 **Modes flexibles** : Standalone ou intégration dans votre design system

## 📋 Prérequis

- **PHP** : 8.2 ou supérieur
- **Symfony** : 7.0 ou supérieur
- **Extension PHP** : `sodium` (pour le chiffrement)
- **Provider LLM** :
  - Google Cloud avec Vertex AI activé (pour Gemini), OU
  - Compte OVH avec accès aux AI Endpoints

## 🚀 Installation

```bash
composer require arnaudmoncondhuy/synapse-bundle
```

## ⚙️ Configuration minimale

```yaml
# config/packages/synapse.yaml
synapse:
    persistence:
        enabled: true
        handler: doctrine
        conversation_class: App\Entity\Conversation
        message_class: App\Entity\Message

    admin:
        enabled: true
```

Pour plus d'options, voir [Configuration](docs/configuration.md).

## 📖 Usage rapide

### 1. Widget de chat (Plug-and-play)

```twig
{# templates/page.html.twig #}
{{ include('@Synapse/chat/component.html.twig') }}
```

### 2. Utilisation programmatique (ChatService)

```php
// Dans un controller ou service
class MyController extends AbstractController
{
    public function __construct(
        private ChatService $chatService
    ) {}

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

### 3. Interface d'administration

Accès à `/synapse/admin` pour :
- Gérer les providers LLM et leurs credentials
- Créer et tester des presets de configuration
- Visualiser les conversations et analytics
- Configurer les paramètres globaux
- Consulter les logs de debug

## 📚 Documentation complète

La documentation est organisée dans le dossier `docs/` :

- **[Configuration](docs/configuration.md)** — Référence complète de `synapse.yaml`, variables d'environnement, configuration des providers
- **[Usage](docs/usage.md)** — Guide d'utilisation : ChatService, création d'outils IA, events Symfony, personas
- **[Intégration des vues](docs/views.md)** — Templates Twig, layouts admin, personnalisation CSS
- **[Changelog](docs/changelog.md)** — Historique des versions

## 🏗️ Architecture

### Couches de prompts

Le bundle gère les prompts en 3 couches :

1. **Technical Prompt** (Interne) : Règles de formatage et de réflexion native (via la config `thinking`)
2. **System Prompt** (Applicatif) : Contexte métier configuré dans l'admin ou le code
3. **User Prompt** : Demande directe de l'utilisateur

### Providers supportés

#### Google Vertex AI (Gemini)
- Modèles : `gemini-2.5-flash`, `gemini-2.5-pro`, etc.
- Région : `europe-west1`, `europe-west4`, `us-central1`, etc.
- Capacités : streaming, thinking natif, safety settings

#### OVH AI Endpoints (OpenAI-compatible)
- Endpoint customizable (défaut : `https://oai.endpoints.kepler.ai.cloud.ovh.net/v1`)
- Supports models OpenAI-compatible
- Capacités : streaming, reasoning (thinking)

### Outils IA (Function Calling)

Créez des outils personnalisés en implémentant `AiToolInterface` :

```php
class MaFonctionTool implements AiToolInterface
{
    public function getName(): string { return 'ma_fonction'; }
    public function getDescription(): string { return 'Description pour le LLM'; }
    public function getInputSchema(): array { return [...]; }
    public function execute(array $parameters): mixed { return [...]; }
}
```

Les outils sont automatiquement découverts et disponibles pour le LLM.

## 🧪 Tests

```bash
vendor/bin/phpunit
```

## 🤝 Contribution

Les contributions sont les bienvenues ! Merci de :

1. Fork le projet
2. Créer une branche (`git checkout -b feature/ma-feature`)
3. Commit vos changements (`git commit -m 'Add ma feature'`)
4. Push vers la branche (`git push origin feature/ma-feature`)
5. Ouvrir une Pull Request

## 📝 Changelog

Voir [Changelog](docs/changelog.md) pour l'historique des versions.

## 📄 Licence

PolyForm Noncommercial 1.0.0 - Voir [LICENSE](LICENSE) pour plus de détails.

## 🙏 Crédits

- **Design Chat** : Inspiré de l'interface Google Gemini
- **Icons** : [Lucide Icons](https://lucide.dev/)
- **Framework** : [Symfony](https://symfony.com/)
- **LLM Providers** : [Google Vertex AI](https://cloud.google.com/vertex-ai), [OVH AI Endpoints](https://docs.ovh.com/gb/en/ai-endpoints/)

---

**Made with ❤️ by MakerLab**
