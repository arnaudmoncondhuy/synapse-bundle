# Changelog

Toutes les modifications importantes de SynapseBundle sont documentées dans ce fichier.

Les modifications importantes sont classées par catégorie :
- **Features** : Nouvelles fonctionnalités
- **Fixes** : Corrections de bugs
- **Refactor** : Refactorisations de code
- **Security** : Améliorations de sécurité
- **Docs** : Mises à jour de documentation

---

## [Non classé] — Développement actuel

### Features

#### Standardisation sur le format OpenAI (LLM-Agnosticism)
- **Refactorisation majeure** : ChatService utilise maintenant OpenAI Chat Completions comme format interne standard
- **Impact** : Bundle maintenant 100% LLM-agnostique (prêt pour Mistral, Claude, Ollama, etc.)
- **Changement de format** : Message système intégré comme premier élément de `contents` array
  - **Avant** : `systemInstruction` (string) + `contents` (array) séparés
  - **Après** : Tous les messages dans `contents` avec `role: 'system'` en tête (format OpenAI)
- **Clients LLM** : Chacun devient un simple "traducteur"
  - GeminiClient : Extrait système de contents, convertit OpenAI→Gemini, traduit les catégories de sécurité
  - OvhAiClient : Passthrough pur (déjà compatible OpenAI)
  - Nouveaux providers : Implémentation simple en 2-3 heures (juste la couche de conversion)
- **ChatService** : Zéro logique spécifique au provider (sécurité, safety settings, etc. gérés par les clients)
- **Chunk format** : Changement de `blocked_category` → `blocked_reason` (raison lisible)
- **Sécurité Gemini** : Toujours fonctionnelle, traduction déplacée du centre à la périphérie

**Pour les développeurs créant des clients personnalisés** : Voir [IMPLEMENTATION_GUIDE.md](../IMPLEMENTATION_GUIDE.md)

#### Chiffrement des credentials des providers LLM
- Implémentation du chiffrement XSalsa20-Poly1305 pour les credentials (API keys, service account JSON)
- Chiffrement automatique lors de la sauvegarde via l'interface admin
- Déchiffrement transparent lors du chargement en mémoire
- Support `encryption.enabled: true/false` dans la configuration
- Clés sensibles encryptées : `api_key`, `service_account_json`, `private_key`
- Format de stockage : `base64(nonce_24bytes + ciphertext)` en BDD
- Migration progressive : détection automatique des credentials non chiffrés lors de la sauvegarde

#### Vidage des logs de debug
- Nouvelle fonctionnalité dans l'interface admin : vidage complet des logs de debug
- Contrôle d'accès : accessible aux administrateurs uniquement
- Endpoint dédié pour la gestion des logs

#### Support multi-providers
- Google Vertex AI (Gemini 2.5+, 2.0-pro, etc.)
- OVH AI Endpoints (OpenAI-compatible)
- Interface admin pour gérer les credentials et tester la connexion

#### Interface d'administration complète
- Dashboard avec KPIs (conversations, utilisateurs, tokens, coûts)
- Analytics avec graphiques (usage par modèle, tendances temporelles)
- Gestion des presets LLM (création, édition, test)
- Gestion des providers (credentials chiffrés)
- Catalogue des modèles disponibles
- Paramètres globaux (rétention RGPD, langue, prompt système)
- Logs de debug complets (requête/réponse LLM, tokens, safety ratings)

#### Personas IA
- Support des personnalités IA prédéfinies
- Fichier JSON configurable (`personas_path`)
- Chaque persona : nom, emoji, prompt système custom

#### Context Caching (Gemini)
- Support du caching de contexte pour optimiser les coûts
- ~90% d'économie sur les tokens de contexte réutilisés

#### Thinking Mode natif
- Support du raisonnement Chain-of-Thought (Gemini 2.5+)
- Configuration : `thinking.enabled`, `thinking.budget`

#### Token tracking
- Suivi de la consommation de tokens par modèle
- Calcul automatique des coûts basé sur la pricing
- Analytics : coûts par modèle, par utilisateur, par période

### Refactoring

#### Architecture domain-driven
- Réorganisation complète du code source en domaines :
  - `Core/` : logique métier, orchestration LLM
  - `Admin/` : contrôleurs et UI administration
  - `Storage/` : persistance Doctrine, entités, repositories
  - `Security/` : chiffrement, permissions
  - `Contract/` : interfaces publiques (API du bundle)
  - `Shared/` : code réutilisable (enums, models, tools, utils)
  - `Infrastructure/` : DI, commandes CLI, ressources/views

#### Chargement des configurations modèles
- Priorisation : dossier `Infrastructure/config/models/` en premier, fallback sur `Core/`
- Permet une meilleure organisation des fichiers de configuration

#### Registration de DebugController
- Enregistrement explicite comme service Symfony
- Correction : était manquant dans la configuration admin

### Security

#### Chiffrement des messages
- Messages de conversation chiffrés en BDD (XSalsa20-Poly1305)
- Déchiffrement automatique lors de la lecture
- Transparent pour l'utilisateur/développeur

#### Chiffrement des credentials
- Credentials des providers chiffrés (décrit ci-dessus)
- Migration progressive des credentials existants

#### Contrôle d'accès
- Interface admin protégée par rôle Symfony (`ROLE_ADMIN`)
- Vérification des permissions à chaque action

### Docs

#### Refonte complète de la documentation
- README.md : rewritten avec 2 providers (Gemini + OVH), vraies options de config
- **docs/configuration.md** : référence complète de `synapse.yaml`
- **docs/usage.md** : guide d'utilisation avancée (ChatService, outils, events)
- **docs/views.md** : intégration Twig, layouts, personnalisation CSS
- **docs/changelog.md** : ce fichier

---

## Notes de migration

### ⚠️ Breaking Changes - Standardisation OpenAI

**Si vous avez créé un client LLM personnalisé**, vous devez mettre à jour ses signatures :

#### 1. Signatures des méthodes (LlmClientInterface)

**AVANT** :
```php
public function generateContent(
    string $systemInstruction,
    array $contents,
    array $tools = [],
    ?string $model = null,
    ?array $thinkingConfigOverride = null,
    array &$debugOut = [],
): array;
```

**APRÈS** :
```php
public function generateContent(
    array $contents,  // ← Contient le système en [0]
    array $tools = [],
    ?string $model = null,
    ?array $thinkingConfigOverride = null,
    array &$debugOut = [],
): array;
```

#### 2. Format des messages (OpenAI canonical)

**AVANT** : systèmeInstruction séparé + contents
```php
$systemInstruction = "You are helpful";
$contents = [
    ['role' => 'user', 'content' => '...'],
    ['role' => 'assistant', 'content' => '...'],
];
```

**APRÈS** : Tout dans contents, système en premier
```php
$contents = [
    ['role' => 'system', 'content' => 'You are helpful'],    // ← PREMIER
    ['role' => 'user', 'content' => '...'],
    ['role' => 'assistant', 'content' => '...'],
];
```

#### 3. Format du chunk retourné

**AVANT** : `blocked_category` (enum Gemini-spécifique)
```php
return [
    'blocked' => true,
    'blocked_category' => 'HARM_CATEGORY_HATE_SPEECH',  // ← Constante Gemini
];
```

**APRÈS** : `blocked_reason` (string lisible, provider-agnostique)
```php
return [
    'blocked' => true,
    'blocked_reason' => 'discours haineux',  // ← String lisible
];
```

#### 4. Migration simple (exemple)

Si vous aviez un client personnalisé, voici le pattern :

```php
class MyLLMClient implements LlmClientInterface {
    public function generateContent(
        array $contents,  // ← Nouvelle signature
        array $tools = [],
        ?string $model = null,
        ?array $thinkingConfigOverride = null,
        array &$debugOut = [],
    ): array {
        // 1. Extraire le système si présent
        $systemMessage = '';
        $contentsWithoutSystem = $contents;

        if (!empty($contents) && $contents[0]['role'] === 'system') {
            $systemMessage = $contents[0]['content'];
            $contentsWithoutSystem = array_slice($contents, 1);
        }

        // 2. Convertir au format de votre provider
        $providerMessages = $this->toProviderFormat($contentsWithoutSystem);

        // 3. Appeler votre API (avec ou sans système selon le provider)
        $response = $this->callApi($systemMessage, $providerMessages, ...);

        // 4. Normaliser la réponse
        return $this->normalizeResponse($response);
    }
}
```

**Voir** : [IMPLEMENTATION_GUIDE.md](../IMPLEMENTATION_GUIDE.md) pour un guide complet.

### ✅ Pas de changement requis pour
- Configuration (synapse.yaml, presets, safety_settings)
- Base de données (schéma inchangé)
- Interface admin (UI inchangée)
- Utilisation de ChatService (signatures publiques inchangées)
- Conversations existantes (compatibilité totale)

### Pour utiliser le chiffrement

### Pour utiliser le chiffrement
1. Générer une clé : `php -r "echo bin2hex(sodium_crypto_secretbox_keygen());"`
2. Ajouter à `.env.local` : `SYNAPSE_ENCRYPTION_KEY=base64:...`
3. Activer dans `synapse.yaml` :
   ```yaml
   encryption:
       enabled: true
       key: '%env(SYNAPSE_ENCRYPTION_KEY)%'
   ```
4. Les credentials existants seront chiffrés automatiquement lors de la prochaine sauvegarde

---

## Version future envisagée

- [ ] Support d'autres providers LLM (OpenAI, Anthropic Claude, etc.)
- [ ] Téléchargement des logs de debug
- [ ] API publique pour les modules tiers
- [ ] Webhooks pour les événements importants
- [ ] Système de plugins

---

## Liens utiles

- [Configuration](configuration.md) — Documentation complète `synapse.yaml`
- [Usage](usage.md) — Utilisation ChatService, outils, events
- [Intégration des vues](views.md) — Templates Twig, personnalisation CSS
- [README](../README.md) — Vue d'ensemble du bundle
