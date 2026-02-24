# Configuration

Ce document documente toutes les options de configuration du bundle SynapseBundle via `synapse.yaml`.

## Configuration minimale

```yaml
# config/packages/synapse.yaml
synapse:
    persistence:
        enabled: true
        conversation_class: App\Entity\Conversation
        message_class: App\Entity\Message

    admin:
        enabled: true
```

## Référence complète

### Clé racine : `synapse`

#### `persistence`

Gère la persistance de l'historique des conversations.

| Clé | Type | Défaut | Description |
|---|---|---|---|
| `enabled` | bool | `false` | Activer la persistance |
| `conversation_class` | string | `null` | **Requis** : FQCN de votre entité `Conversation` (ex: `App\Entity\Conversation`) |
| `message_class` | string | `null` | **Requis** : FQCN de votre entité `Message` (ex: `App\Entity\Message`) |
| `conversation_repository` | string | `null` | **Optionnel** : FQCN du repository `SynapseConversationRepository` (auto-détecté sinon) |
| `message_repository` | string | `null` | **Optionnel** : FQCN du repository `SynapseMessageRepository` (auto-détecté sinon) |

**Exemple :**
```yaml
synapse:
    persistence:
        enabled: true
        conversation_class: App\Entity\Conversation
        message_class: App\Entity\Message
```

---

#### `encryption`

Chiffrement XSalsa20-Poly1305 des messages et credentials via libsodium.

| Clé | Type | Défaut | Description |
|---|---|---|---|
| `enabled` | bool | `false` | Activer le chiffrement |
| `key` | string | `null` | Clé 32 bytes en base64. Support `%env(SYNAPSE_ENCRYPTION_KEY)%`. Générer via `LibsodiumEncryptionService::generateKey()` |

**Format de la clé :**
```bash
# Générer une clé (depuis un controller ou CLI)
php -r "echo bin2hex(sodium_crypto_secretbox_keygen());"
```

Stocker la clé dans `.env.local` :
```env
SYNAPSE_ENCRYPTION_KEY=base64:your_32_byte_key_here
```

Configuration :
```yaml
synapse:
    encryption:
        enabled: true
        key: '%env(SYNAPSE_ENCRYPTION_KEY)%'
```

---

#### `token_tracking`

Suivi de la consommation de tokens et calcul des coûts par modèle.

| Clé | Type | Défaut | Description |
|---|---|---|---|
| `enabled` | bool | `false` | Activer le tracking |
| `pricing` | array | `{}` | Dictionnaire `model_id => {input: price, output: price}` ($/1M tokens) |

**Exemple :**
```yaml
synapse:
    token_tracking:
        enabled: true
        pricing:
            gemini-3.1-pro:
                input: 2.00
                output: 12.00
            gemini-3-flash:
                input: 0.50
                output: 3.00
            gemini-2.5-flash:
                input: 0.30
                output: 2.50
```

---

#### `retention`

Politique de rétention RGPD des conversations (suppression automatique).

| Clé | Type | Défaut | Description |
|---|---|---|---|
| `days` | int | `30` | Nombre de jours avant suppression (minimum: 1) |

```yaml
synapse:
    retention:
        days: 90
```

Les conversations plus anciennes que `days` jours sont purgées via la commande `synapse:purge`.

---

#### `security`

Contrôle d'accès et rôles Symfony.

| Clé | Type | Défaut | Description |
|---|---|---|---|
| `permission_checker` | string | `'default'` | Service de contrôle d'accès : `'default'`, `'none'`, ou FQCN d'un service custom implémentant `PermissionCheckerInterface` |
| `admin_role` | string | `'ROLE_ADMIN'` | Rôle Symfony requis pour l'interface admin |

```yaml
synapse:
    security:
        permission_checker: 'default'
        admin_role: 'ROLE_SYNAPSE_ADMIN'
```

---

#### `context`

Fournisseur de contexte initial pour les prompts.

| Clé | Type | Défaut | Description |
|---|---|---|---|
| `provider` | string | `'default'` | Fournisseur de contexte : `'default'`, `'user_aware'`, ou FQCN d'un service custom implémentant `ContextProviderInterface` |
| `language` | string | `'fr'` | Langue des prompts système : `'fr'` ou `'en'` |
| `base_identity` | string | `null` | **Optionnel** : surcharge de l'identité de base (défaut: construction automatique) |

```yaml
synapse:
    context:
        provider: 'user_aware'
        language: 'fr'
        base_identity: 'AppClient'
```

---

#### `admin`

Configuration de l'interface d'administration.

| Clé | Type | Défaut | Description |
|---|---|---|---|
| `enabled` | bool | `false` | Activer l'interface admin `/synapse/admin` |
| `route_prefix` | string | `'/synapse/admin'` | Préfixe des routes admin |
| `default_color` | string | `'#8b5cf6'` | Couleur primaire du thème admin (code hex) |
| `default_icon` | string | `'robot'` | Icône par défaut (nom Lucide Icons) |

```yaml
synapse:
    admin:
        enabled: true
        route_prefix: '/ia/admin'
        default_color: '#e63946'
        default_icon: 'cpu'
```

---

#### `ui`

Configuration de l'interface utilisateur du widget chat.

| Clé | Type | Défaut | Description |
|---|---|---|---|
| `sidebar_enabled` | bool | `true` | Afficher la sidebar avec l'historique |
| `layout_mode` | string | `'standalone'` | Mode d'affichage : `'standalone'` (complet) ou `'module'` (intégration) |

```yaml
synapse:
    ui:
        sidebar_enabled: true
        layout_mode: 'standalone'
```

---

#### `personas_path`

Chemin vers un fichier JSON custom de personnalités IA.

| Clé | Type | Défaut | Description |
|---|---|---|---|
| `personas_path` | string | `null` | Chemin absolu ou relatif à `%kernel.project_dir%` vers un `personas.json` custom. Si `null`, utilise le fichier fourni par le bundle |

```yaml
synapse:
    personas_path: '%kernel.project_dir%/config/personas.json'
```

**Structure d'un `personas.json` :**
```json
{
    "juridique": {
        "name": "Juriste Expert",
        "emoji": "⚖️",
        "system_prompt": "Tu es un expert en droit français. Réponds avec précision..."
    },
    "marketing": {
        "name": "Spécialiste Marketing",
        "emoji": "📢",
        "system_prompt": "Tu es un expert en stratégie marketing digital..."
    }
}
```

---

## Configuration des Providers (via l'admin)

Les providers LLM et leurs credentials sont gérés via l'interface admin : `/synapse/admin/providers`

### Provider : Gemini (Google Vertex AI)

**Credentials à configurer :**

| Champ | Type | Description |
|---|---|---|
| `project_id` | string | ID du projet GCP (ex: `my-project-123`) |
| `region` | string | Région Vertex AI (voir liste ci-dessous) |
| `service_account_json` | JSON string | Contenu complet du fichier JSON de la clé de service GCP |

**Régions disponibles :**
- `europe-west1` (Belgique) — recommandé pour EU
- `europe-west4` (Pays-Bas)
- `us-central1` (Iowa)
- `us-east1` (Caroline du Sud)
- `asia-east1` (Taïwan)
- `asia-northeast1` (Tokyo)

**Obtenir la clé de service :**

1. Google Cloud Console → projet → Service accounts
2. Créer un compte de service ou sélectionner un existant
3. Onglet Keys → Add Key → Create new key → JSON
4. Télécharger le fichier JSON et copier son contenu en entier

**Sécurité :** Les credentials sont chiffrés automatiquement en base de données (si `encryption.enabled: true`).

### Provider : OVH AI Endpoints

**Credentials à configurer :**

| Champ | Type | Description |
|---|---|---|
| `api_key` | string | Bearer token d'authentification OVH |
| `endpoint` | string | Endpoint API (défaut: `https://oai.endpoints.kepler.ai.cloud.ovh.net/v1`) |

**Obtenir la clé API :**

1. OVH Manager → AI Endpoints
2. Copier le Bearer token d'authentification
3. Configurer l'endpoint approprié pour votre région

**Sécurité :** Comme Gemini, les credentials sont chiffrés si `encryption.enabled: true`.

---

## Variables d'environnement

### Variables principales

```env
# Chiffrement des messages et credentials (optionnel)
SYNAPSE_ENCRYPTION_KEY=base64:your_32_byte_key_here

# Rôle admin (par défaut ROLE_ADMIN)
SYNAPSE_ADMIN_ROLE=ROLE_SYNAPSE_ADMIN

# Langage des prompts (fr ou en)
SYNAPSE_CONTEXT_LANGUAGE=fr

# Rétention RGPD en jours
SYNAPSE_RETENTION_DAYS=30
```

---

## Exemple : Configuration complète (Doctrine + Encryption)

```yaml
# config/packages/synapse.yaml
synapse:
    # ── Persistance Doctrine ──────────────────────────────────────────
    persistence:
        enabled: true
        conversation_class: App\Entity\Conversation
        message_class: App\Entity\Message

    # ── Chiffrement ───────────────────────────────────────────────────
    encryption:
        enabled: true
        key: '%env(SYNAPSE_ENCRYPTION_KEY)%'

    # ── Token tracking ────────────────────────────────────────────────
    token_tracking:
        enabled: true
        pricing:
            gemini-3.1-pro: {input: 2.00, output: 12.00}
            gemini-3-flash: {input: 0.50, output: 3.00}
            gemini-2.5-flash: {input: 0.30, output: 2.50}

    # ── Rétention RGPD ────────────────────────────────────────────────
    retention:
        days: 90

    # ── Sécurité ──────────────────────────────────────────────────────
    security:
        permission_checker: 'default'
        admin_role: 'ROLE_ADMIN'

    # ── Contexte ──────────────────────────────────────────────────────
    context:
        provider: 'user_aware'
        language: 'fr'
        base_identity: null

    # ── Interface Admin ───────────────────────────────────────────────
    admin:
        enabled: true
        route_prefix: '/synapse/admin'
        default_color: '#8b5cf6'
        default_icon: 'robot'

    # ── UI ────────────────────────────────────────────────────────────
    ui:
        sidebar_enabled: true
        layout_mode: 'standalone'

    # ── Personas custom ───────────────────────────────────────────────
    personas_path: '%kernel.project_dir%/config/personas.json'
```

Fichier `.env.local` :
```env
SYNAPSE_ENCRYPTION_KEY=base64:0x1a2b3c4d5e6f7a8b9c0d1e2f3a4b5c6d7e8f9a0b1c2d3e4f5a6b7c8d9e0f1
```

---

## Exemple : Configuration minimale (Session, pas de chiffrement)

```yaml
# config/packages/synapse.yaml
synapse:
    persistence:
        enabled: false

    admin:
        enabled: true
```

---

## Validation et test

**Vérifier la configuration :**
```bash
php bin/console config:dump synapse
```

**Tester un preset :**
```
Admin → Presets → Cliquer sur le preset → Test
```

Un rapport détaillé indique si le preset fonctionne et conforme les critères du bundle.

---

## Voir aussi

- [Usage](usage.md) — Utiliser ChatService, créer des outils
- [Intégration des vues](views.md) — Templates Twig et personnalisation CSS
- [Changelog](changelog.md) — Historique des versions
