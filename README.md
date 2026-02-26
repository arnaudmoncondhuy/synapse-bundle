# 🧠 Synapse

**L'intelligence artificielle, intégrée nativement dans Symfony.**

Synapse est un écosystème de bundles Symfony pour déployer des agents IA autonomes, des chatbots et des outils de raisonnement dans vos applications. Le projet est organisé en **monorepo** avec trois packages : **Core** (orchestration headless), **Admin** (interface d'administration), **Chat** (UI et API chat).

---

<p align="center">
  <a href="https://arnaudmoncondhuy.github.io/synapse-bundle/"><strong>Explorer la Documentation »</strong></a>
</p>

---

## ✨ Points Forts

- 🤖 **Agnosticisme LLM** : Standardisation sur le format OpenAI pour passer de Gemini à OVH ou OpenAI sans changer une ligne de code.
- 🔧 **Agents Autonomes** : Créez des agents spécialisés avec leurs propres instructions, outils et configurations LLM via `AgentInterface`.
- � **Function Calling** : Système de plugins ultra-simple pour permettre à l'IA d'interagir avec vos services via `AiToolInterface`.
- 📡 **Streaming Natif** : UX fluide avec des réponses en temps réel (NDJSON).
- � **Coffre-fort Intégré** : Chiffrement AES-256 de bout en bout des messages et des clés API via `libsodium`.
- 🎨 **Admin Interface Premium** : Dashboard analytique, gestion des consommations (tokens/coûts), presets et debug logs en temps réel.
- � **Contextualisation Infinie** : Gestion intelligente de l'historique et injection de contexte dynamique.

## 🚀 Installation Rapide

### 1. Packages

**Core** (requis) :

```bash
composer require arnaudmoncondhuy/synapse-core
```

**Admin** et **Chat** (optionnels) :

```bash
composer require arnaudmoncondhuy/synapse-admin arnaudmoncondhuy/synapse-chat
```

### 2. Configuration minimale (Core)

```yaml
# config/packages/synapse_core.yaml (ou synapse.yaml selon votre config)
synapse_core:
  persistence:
    enabled: true
    conversation_class: App\Entity\Conversation
    message_class: App\Entity\Message
```

## 📖 Utilisation

### Composant Chat (avec synapse-chat)

```twig
{{ include('@Synapse/chat/component.html.twig') }}
```

### Service Chat (usage programmatique, Core)

```php
$result = $chatService->ask(
    message: "Analyse ce rapport trimestriel",
    options: ['persona' => 'expert_analyste']
);
echo $result['answer'];
```

## 📚 Documentation

La documentation est générée depuis ce dépôt et publiée sur **[GitHub Pages](https://arnaudmoncondhuy.github.io/synapse-bundle/)**. Elle est organisée en trois sections :

- **[Synapse Core](https://arnaudmoncondhuy.github.io/synapse-bundle/core/)** — Installation, configuration, guides (outils IA, personas, RAG, mémoire), référence technique (contrats, événements, CLI).
- **[Synapse Admin](https://arnaudmoncondhuy.github.io/synapse-bundle/admin/)** — Interface d'administration.
- **[Synapse Chat](https://arnaudmoncondhuy.github.io/synapse-bundle/chat/)** — Routes API, CSRF, intégration du composant chat.

## 🏗️ Architecture

- **Synapse Core** : Contrats (LLM, Vector Store, Formatters), orchestration, persistance Doctrine, événements.
- **Synapse Admin** : Contrôleurs et vues Twig pour la gestion des providers, presets et conversations.
- **Synapse Chat** : API HTTP (chat, reset, CSRF) et composant Stimulus/Twig pour l'UI de chat.

## 🧪 Tests et Fiabilité

Le bundle est testé pour garantir la stabilité des échanges :

```bash
vendor/bin/phpunit
```

---

## 📄 Licence
PolyForm Noncommercial 1.0.0 - Voir [LICENSE](LICENSE) pour plus de détails.

## 🙏 Crédits
- **Design** : Inspiré par l'écosystème Google Gemini.
- **Framework** : Propulsé par Symfony.
- **Moteur** : Compatible Vertex AI, OVHcloud AI Endpoints et OpenAI.

---
**Développé avec ❤️ par [MakerLab](https://github.com/arnaudmoncondhuy)**

