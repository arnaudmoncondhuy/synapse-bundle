# Synapse Admin

> Administration Synapse — Interface d'administration pour la gestion des présets IA, fournisseurs, missions, conversations et mémoires.

Interface d'administration complète pour gérer **Synapse Core** : configuration des providers LLM, création de presets, historique conversationnel, gestion de mémoires, et analytics.

**Dépend de** : `arnaudmoncondhuy/synapse-core`

## Installation

```bash
composer require arnaudmoncondhuy/synapse-admin:^0.1
```

## Caractéristiques

### 🎛️ Administration Synapse (Interface moderne)
- **Dashboard** - Vue d'ensemble de l'utilisation
- **Providers** - Configuration des APIs LLM (Gemini, OVH, OpenAI)
  - Gestion des credentials chiffrés
  - Test de connexion
  - Sélection du modèle par provider

- **Presets** - Création et test de configurations IA
  - Paramètres de génération (température, max_tokens, etc.)
  - Paramètres de sécurité (Gemini Safety Settings)
  - Thinking/Extended Thinking support
  - Test en live avec mock data

- **Conversation** - Historique et analyse
  - Historique des conversations
  - Paramètres conversationnels
  - Outils utilisés

- **Mémoire** - Gestion sémantique
  - Configuration des Vector Stores
  - Embeddings management
  - Chunking strategy

- **Sécurité** - Audit et accès
  - API Keys management
  - Audit logs
  - GDPR tools

- **Système** - Monitoring
  - About & Versions
  - Health check
  - Debug logs

### 🎨 Admin V1 (Support rétro-compatible)
- Interface classique Symfony
- Tous les formulaires de configuration
- Analytics détaillées

### 🔒 Sécurité
- `AdminSecurityTrait` - Vérification des droits via `DefaultPermissionChecker`
- Intégration Symfony Security
- Chiffrement des credentials sensibles

### 📊 Analytics & Quotas
- **Dashboard Principal** - Vue d'ensemble de la consommation (tokens & coûts)
- **Monitoring d'Usage** - Historique détaillé des appels LLM par module
- **Gestion des Quotas** - Interface pour définir les limites de dépense (Spending Limits)
- **Modèles & Tarifs** - Configuration des prix par million de tokens

### 🎛️ Architecture Administration Synapse
- **Hybrid HTML/JSON form pattern** - Formulaires dynamiques s'adaptant au provider LLM
- **Live preset testing** - Test des presets avec streaming en temps réel dans l'admin
- **Gestion des Agents** - Interface de configuration des agents (system prompt, preset, ton et outils)

## Configuration

**config/bundles.php** - Ajouter le bundle :
```php
ArnaudMoncondhuy\SynapseAdmin\SynapseAdminBundle::class => ['all' => true],
```

**config/routes.yaml** - Enregistrer les routes :
```yaml
synapse_admin:
    resource: '@SynapseAdminBundle/config/routes.yaml'
    prefix: /synapse/admin
```

**config/packages/security.yaml** - Protéger les routes admin :
```yaml
access_control:
    - { path: ^/synapse/admin, roles: ROLE_ADMIN }
    - { path: ^/synapse, roles: ROLE_ADMIN } # Chat admin aussi
```

## Routes disponibles

### Administration Synapse Dashboard
- `GET /synapse/admin` - Dashboard principal
- `GET /synapse/admin/intelligence/configuration-llm` - Configuration LLM (onglets : fournisseurs, modeles, presets)
- Presets (CRUD) : `GET/POST .../presets/new`, `GET/POST .../presets/{id}/edit`, etc.
- Modèles (actions) : `POST .../modeles/{modelId}/toggle`, `POST .../modeles/{modelId}/pricing`
- `GET /synapse/admin/conversation/history` - Historique conversations
- `GET /synapse/admin/memoire/embeddings` - Gestion embeddings
- `GET /synapse/admin/securite/api_keys` - API Keys
- `GET /synapse/admin/securite/audit` - Logs d'audit
- `GET /synapse/admin/systeme/health` - Health check
- `GET /synapse/admin/systeme/debug` - Debug info

### Admin V1 (Rétro-compatible)
- `GET /synapse/admin/dashboard`
- `GET /synapse/admin/providers`
- `GET /synapse/admin/presets`
- Etc.

## Twig Namespaces

Les templates sont accessibles via `@Synapse` :

```twig
{% include '@Synapse/admin/layout/base.html.twig' %}
{% include '@Synapse/admin/layout.html.twig' %}
```

## Structure des dépendances

```
synapse-admin
  ├── arnaudmoncondhuy/synapse-core
  ├── symfony/twig-bundle
  ├── symfony/asset-mapper
  ├── symfony/stimulus-bundle
  └── symfony/asset
```

## Intégration avec Synapse Core

Le bundle auto-découvre les contrôleurs et services du core :
- Services de configuration via `DatabaseConfigProvider`
- Formulaires de preset/provider
- Gestion des outils enregistrés

## Internationalisation

Le bundle supporte nativement le multilingue.
- **Domaine de traduction** : `synapse_admin`
- **Langues supportées** : FR (par défaut). Extension possible via les fichiers de traduction Symfony.

---

## Licence

## Support

- 📖 [Documentation Admin](https://arnaudmoncondhuy.github.io/synapse-bundle/admin/)
- 🐛 [Issues](https://github.com/arnaudmoncondhuy/synapse-bundle/issues)

## Auteur

[Arnaud Moncondhuy](https://github.com/arnaudmoncondhuy)
