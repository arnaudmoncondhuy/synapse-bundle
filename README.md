# 🧠 SynapseBundle

**L'intelligence artificielle, intégrée nativement dans Symfony.**

SynapseBundle est une solution industrielle pour déployer des agents IA autonomes, des chatbots et des outils de raisonnement complexes dans vos applications Symfony. Conçu pour l'agnosticisme et la sécurité, il supporte les meilleurs modèles du marché (Google Gemini, OVH AI Endpoints, OpenAI) avec une interface d'administration "Premium" prête à l'emploi.

---

<p align="center">
  <a href="https://arnaudmoncondhuy.github.io/synapse-bundle/"><strong>Explorer la Documentation Officielle »</strong></a>
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

### 1. Téléchargement

```bash
composer require arnaudmoncondhuy/synapse-bundle
```

### 2. Configuration Minimale

```yaml
# config/packages/synapse.yaml
synapse:
    persistence:
        enabled: true
        conversation_class: App\Entity\Conversation
        message_class: App\Entity\Message
```

## 📖 Comment l'utiliser ?

### Le composant Chat (Plug-and-Play)
Intégrez une interface de chat complète inspirée de Gemini en une seule ligne :

```twig
{{ include('@Synapse/chat/component.html.twig') }}
```

### Le service Chat (Usage Programmatique)
Prenez le contrôle total de l'IA dans vos services :

```php
$result = $chatService->ask(
    message: "Analyse ce rapport trimestriel",
    options: ['persona' => 'expert_analyste']
);

echo $result['answer'];
```

## 📚 Ressources et Documentation

Pour exploiter tout le potentiel de SynapseBundle, consultez notre **[Documentation Officielle](https://arnaudmoncondhuy.github.io/synapse-bundle/)** :

- � **[Guide d'Installation](https://arnaudmoncondhuy.github.io/synapse-bundle/getting-started/installation/)**
- ⚙️ **[Référence de Configuration](https://arnaudmoncondhuy.github.io/synapse-bundle/guides/configuration/)**
- 👮 **[Interface d'Administration](https://arnaudmoncondhuy.github.io/synapse-bundle/admin/interface/)**
- 🏗 **[Créer des Outils IA](https://arnaudmoncondhuy.github.io/synapse-bundle/guides/ai-tools/)**
- 🔌 **[Référence des Contrats/Interfaces](https://arnaudmoncondhuy.github.io/synapse-bundle/reference/contracts/overview/)**

## 🏗️ Architecture Technique

Synapse suit une architecture en couches pour garantir la séparation des responsabilités :
- **Couche Contrats** : Interfaces strictes pour les clients LLM, Vector Stores et Formatters.
- **Couche Core** : Managers de conversations et orchestration des événements.
- **Couche Admin** : Contrôleurs et vues Twig isolés pour la gestion métier.

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

