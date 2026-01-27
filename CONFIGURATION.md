# Configuration Avancée - SynapseBundle

Documentation complète des paramètres avancés de configuration pour l'intégration Gemini via Vertex AI.

## Table des matières

1. [Configuration de Base](#configuration-de-base)
2. [Filtres de Sécurité (Safety Settings)](#filtres-de-sécurité-safety-settings)
3. [Configuration de Génération (Generation Config)](#configuration-de-génération-generation-config)
4. [Context Caching](#context-caching)
5. [Exemples Complets](#exemples-complets)

---

## Configuration de Base

### Modèle et Authentification

```yaml
synapse:
    # Modèle Gemini à utiliser (tous les modèles Vertex AI supportés)
    model: 'gemini-2.5-flash'

    # Configuration Vertex AI
    vertex:
        project_id: 'your-gcp-project-id'
        region: 'europe-west1'
        service_account_json: '%kernel.project_dir%/config/secrets/gcp-service-account.json'

    # Réflexion native (CoT - Chain of Thought)
    thinking:
        enabled: true
        budget: 2048  # Tokens alloués pour la réflexion interne
```

---

## Filtres de Sécurité (Safety Settings)

### Description

Les **filtres de sécurité** permettent de contrôler le contenu généré par le modèle. Ils sont particulièrement utiles dans un environnement scolaire pour bloquer les contenus inadaptés.

Vertex AI propose 4 catégories de contenu à filtrer :

| Catégorie | Description |
|-----------|-------------|
| `hate_speech` | Discours haineux, discrimination |
| `dangerous_content` | Contenu dangereux (instructions d'armes, drogues, etc.) |
| `harassment` | Harcèlement, contenu abusif |
| `sexually_explicit` | Contenu sexuel explicite |

### Seuils de Blocage

| Seuil | Signification |
|-------|---------------|
| `BLOCK_NONE` | Aucun blocage |
| `BLOCK_ONLY_HIGH` | Bloque seulement les probabilités très élevées |
| `BLOCK_MEDIUM_AND_ABOVE` | **RECOMMANDÉ pour les établissements** - Bloque moyen, élevé et très élevé |
| `BLOCK_LOW_AND_ABOVE` | Très restrictif - Bloque même les faibles probabilités |

### Configuration

```yaml
synapse:
    safety_settings:
        # Activer les filtres de sécurité
        enabled: true

        # Seuil par défaut pour toutes les catégories
        default_threshold: 'BLOCK_MEDIUM_AND_ABOVE'

        # Seuils spécifiques par catégorie (optionnel)
        # Si non spécifié, utilise le seuil par défaut
        thresholds:
            hate_speech: 'BLOCK_MEDIUM_AND_ABOVE'
            dangerous_content: 'BLOCK_MEDIUM_AND_ABOVE'
            harassment: 'BLOCK_MEDIUM_AND_ABOVE'
            sexually_explicit: 'BLOCK_MEDIUM_AND_ABOVE'
```

### Exemple pour un Lycée

```yaml
synapse:
    safety_settings:
        enabled: true
        default_threshold: 'BLOCK_MEDIUM_AND_ABOVE'
        thresholds:
            hate_speech: 'BLOCK_LOW_AND_ABOVE'        # Plus strict
            dangerous_content: 'BLOCK_MEDIUM_AND_ABOVE'
            harassment: 'BLOCK_MEDIUM_AND_ABOVE'
            sexually_explicit: 'BLOCK_LOW_AND_ABOVE'   # Plus strict
```

### Comportement

Quand un filtre de sécurité bloque le contenu :
- La réponse est vide ou minimale
- Un message d'erreur est retourné
- Aucune réflexion (thinking) n'est exposée si elle contient le contenu bloqué

---

## Configuration de Génération (Generation Config)

### Description

Les **paramètres de génération** contrôlent le comportement du modèle lors de la génération de réponses.

### Paramètres

| Paramètre | Plage | Défaut | Description |
|-----------|-------|--------|-------------|
| `temperature` | 0.0 - 2.0 | 1.0 | Contrôle la créativité. 0.0 = déterministe, 2.0 = très créatif |
| `top_p` | 0.0 - 1.0 | 0.95 | Nucleus sampling : probabilité cumulative des tokens considérés |
| `top_k` | ≥ 1 | 40 | Nombre de tokens avec la plus haute probabilité à considérer |
| `max_output_tokens` | ≥ 1 | null* | Limite maximale de tokens générés (null = défaut du modèle) |
| `stop_sequences` | Array | [] | Séquences qui arrêtent la génération (ex: `["\\n\\n"]`) |

*null signifie que le modèle utilise sa limite par défaut (ex: 8000 tokens pour gemini-2.5-flash)

### Configuration

```yaml
synapse:
    generation_config:
        # Température : 0.0 (déterministe) à 2.0 (créatif)
        temperature: 1.0

        # Nucleus sampling (0.0 à 1.0)
        top_p: 0.95

        # Top-K (nombre minimum 1)
        top_k: 40

        # Limite de tokens de sortie
        max_output_tokens: null  # null = par défaut du modèle

        # Séquences d'arrêt personnalisées
        stop_sequences: []
```

### Cas d'Usage Courants

#### 1. Réponses Déterministes (Exercices d'Analyse)

```yaml
synapse:
    generation_config:
        temperature: 0.2          # Faible créativité
        top_p: 0.9
        top_k: 20
        max_output_tokens: 2000   # Limite les bavardages
```

#### 2. Réponses Créatives (Brainstorming)

```yaml
synapse:
    generation_config:
        temperature: 1.5          # Créatif
        top_p: 0.98
        top_k: 50
        max_output_tokens: 4000
```

#### 3. Résumés Courts

```yaml
synapse:
    generation_config:
        temperature: 0.8
        top_p: 0.95
        top_k: 40
        max_output_tokens: 500    # Force la concision
        stop_sequences:           # Arrête après 2 retours à la ligne
            - "\n\n"
```

#### 4. Explications Pédagogiques

```yaml
synapse:
    generation_config:
        temperature: 0.7
        top_p: 0.9
        top_k: 30
        max_output_tokens: 3000
        stop_sequences: []        # Pas d'arrêt forcé
```

---

## Context Caching

### Description

Le **Context Caching** (mise en cache de contexte) permet de :
- **Réduire les coûts** : 90% de réduction sur les tokens en cache
- **Accélérer les réponses** : Les contenus en cache sont traités plus rapidement
- **Réutiliser du contexte** : Parfait pour les documents volumineux, procédures, etc.

### Cas d'Usage au Lycée

- 📚 **Procédures scolaires** : Règlement intérieur, protocoles
- 📖 **Documents volumineux** : Traités en cache pour analyse répétée
- 🔬 **Énoncés d'exercices** : Réutiliser le même énoncé pour plusieurs questions
- 📋 **Ressources pédagogiques** : Chapitre de manuel pour plusieurs discussions

### Configuration

```yaml
synapse:
    context_caching:
        # Activer la fonctionnalité
        enabled: false

        # ID du contenu en cache (créé via l'API Vertex AI)
        cached_content_id: null
```

### Créer un Cache

Le caching nécessite une étape préalable : créer le cache via l'API Vertex AI.

#### Exemple : Cacher un Document

```php
// Dans votre contrôleur ou service
$geminiClient->cacheContent(
    systemInstruction: "Tu es un assistant pédagogique.",
    cachedContent: "Contenu volumineux à cacher (ex: chapitre complet)",
    timeToLive: 3600  // 1 heure
);
// Retourne: ['cachedContent' => 'projects/.../cachedContents/xyz123...']
```

Utilisez ensuite l'ID retourné dans la configuration :

```yaml
synapse:
    context_caching:
        enabled: true
        cached_content_id: 'projects/your-project/locations/europe-west1/cachedContents/xyz123...'
```

### Limites et Contraintes

| Aspect | Détail |
|--------|--------|
| **Minimum** | 2,048 tokens |
| **Maximum** | Jusqu'à la limite du modèle (Gemini 2.5 Pro : 1M+ tokens) |
| **TTL** | 1 heure (renouvellement automatique à chaque accès) |
| **Coût** | 5 × moins cher que les tokens normaux |
| **Durée** | Disponible 1 heure après création |

### Exemple Complet

```yaml
synapse:
    model: 'gemini-2.5-flash'

    vertex:
        project_id: 'intranet-lycee-485610'
        region: 'europe-west1'
        service_account_json: '%kernel.project_dir%/config/secrets/gcp-service-account.json'

    thinking:
        enabled: true
        budget: 2048

    # Cacher le règlement intérieur pour analyse répétée
    context_caching:
        enabled: true
        cached_content_id: 'projects/intranet-lycee-485610/locations/europe-west1/cachedContents/abc123def456'
```

---

## Exemples Complets

### Configuration Minimale (Production)

```yaml
synapse:
    model: 'gemini-2.5-flash'

    vertex:
        project_id: 'your-gcp-project'
        region: 'europe-west1'
        service_account_json: '%kernel.project_dir%/config/secrets/gcp-service-account.json'

    thinking:
        enabled: true
        budget: 2048
```

### Configuration Lycée (Sécurisée)

```yaml
synapse:
    model: 'gemini-2.5-flash'

    vertex:
        project_id: 'intranet-lycee-485610'
        region: 'europe-west1'
        service_account_json: '%kernel.project_dir%/config/secrets/gcp-service-account.json'

    thinking:
        enabled: true
        budget: 2048

    # Filtres de sécurité activés
    safety_settings:
        enabled: true
        default_threshold: 'BLOCK_MEDIUM_AND_ABOVE'

    # Génération équilibrée
    generation_config:
        temperature: 1.0
        top_p: 0.95
        top_k: 40
        max_output_tokens: null
```

### Configuration Avancée (Tous les Paramètres)

```yaml
synapse:
    model: 'gemini-2.5-flash'

    vertex:
        project_id: 'intranet-lycee-485610'
        region: 'europe-west1'
        service_account_json: '%kernel.project_dir%/config/secrets/gcp-service-account.json'

    thinking:
        enabled: true
        budget: 2048

    safety_settings:
        enabled: true
        default_threshold: 'BLOCK_MEDIUM_AND_ABOVE'
        thresholds:
            hate_speech: 'BLOCK_LOW_AND_ABOVE'
            dangerous_content: 'BLOCK_MEDIUM_AND_ABOVE'
            harassment: 'BLOCK_MEDIUM_AND_ABOVE'
            sexually_explicit: 'BLOCK_LOW_AND_ABOVE'

    generation_config:
        temperature: 0.8
        top_p: 0.9
        top_k: 30
        max_output_tokens: 3000
        stop_sequences:
            - "\n\nQuestion :"

    context_caching:
        enabled: true
        cached_content_id: 'projects/intranet-lycee-485610/locations/europe-west1/cachedContents/abc123'
```

---

## Notes Importantes

### Performance

- **Safety Settings** : Ajoute ~10-20ms à chaque requête (acceptable)
- **Context Caching** : Économise 10-20% de latence sur requêtes répétées
- **Generation Config** : Les limites `max_output_tokens` réduisent la latence

### Coûts Google Cloud

- **Tokens normaux** : Prix standard par modèle
- **Tokens en cache** : 90% moins chers (~5× réduction effective avec overhead)
- **Safety Processing** : Inclus dans le prix (pas de surcoût)

### Sécurité au Lycée

Recommandations :
1. Toujours activer `safety_settings` avec `BLOCK_MEDIUM_AND_ABOVE`
2. Pour du contenu sensible, utiliser `BLOCK_LOW_AND_ABOVE`
3. Tester avec des cas limites avant déploiement
4. Monitorer les réponses bloquées dans les logs

---

## Dépannage

### Les filtres de sécurité bloquent des réponses légitimes

➜ Diminuer le seuil de blocage par catégorie :
```yaml
safety_settings:
    thresholds:
        hate_speech: 'BLOCK_MEDIUM_AND_ABOVE'  # au lieu de BLOCK_LOW_AND_ABOVE
```

### Le context caching n'est pas utilisé

➜ Vérifier que :
1. `context_caching.enabled: true`
2. `cached_content_id` est défini et valide (commence par `projects/`)
3. Le contenu en cache n'a pas expiré (1 heure max)

### Les réponses sont trop courtes ou coupées

➜ Augmenter `max_output_tokens` ou le mettre à `null` :
```yaml
generation_config:
    max_output_tokens: null  # Utilise le maximum du modèle
```

---

**Dernière mise à jour** : 2026-01-27
**Version Bundle** : Compatible avec Gemini 2.5 Flash / Pro via Vertex AI
