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
- 🔧 **Agents & Missions** : Créez des agents spécialisés avec leurs propres instructions (Missions), tons de réponse et outils via `AgentInterface`.
- 💰 **Suivi des Coûts (Accounting)** : Tracking précis des tokens (input/output/thinking), estimation avant requête et gestion multi-devises (EUR/USD).
- 📉 **Quotas & Limites** : Plafonds de dépense configurables par utilisateur, mission ou preset avec fenêtres glissantes et calendaires.
- 🩺 **Synapse Doctor** : Assistant de diagnostic intégré pour automatiser l'installation et la réparation (`php bin/console synapse:doctor`).
- 📡 **Streaming & Auto-titling** : UX fluide avec réponses en temps réel et génération automatique des titres de conversation.
- 🎨 **Administration** : Dashboard analytique moderne, gestion de la mémoire sémantique et monitoring temps réel.

## 🚀 Installation Rapide

**Core** (requis) :

```bash
composer require arnaudmoncondhuy/synapse-core
```

**Admin** et **Chat** (optionnels) :

```bash
composer require arnaudmoncondhuy/synapse-admin arnaudmoncondhuy/synapse-chat
```

### 2. Initialisation Automatique

Utilisez l'assistant de diagnostic pour configurer automatiquement votre projet (entités, security.yaml, routes, configurations) :

```bash
php bin/console synapse:doctor --init
```

> [!NOTE]
> En mode `--init`, Synapse crée un utilisateur de développement par défaut : `admin` / `admin`.


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
    options: ['tone' => 'expert_analyste']
);
echo $result['answer'];
```

## 📖 Documentation

La documentation est générée depuis ce dépôt et publiée sur **[GitHub Pages](https://arnaudmoncondhuy.github.io/synapse-bundle/)**. Elle est organisée en trois sections :

- **[Synapse Core](https://arnaudmoncondhuy.github.io/synapse-bundle/core/)** — Architecture headless, contrats, **Accounting (coûts)**, **Quotas**, **Missions**, RAG, mémoire et CLI (**Synapse Doctor**).
- **[Synapse Admin](https://arnaudmoncondhuy.github.io/synapse-bundle/admin/)** — Interface d'administration **V2**, Dashboard Analytics et monitoring.
- **[Synapse Chat](https://arnaudmoncondhuy.github.io/synapse-bundle/chat/)** — Routes API, composants front, **Auto-titling** et sécurité CSRF.

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

