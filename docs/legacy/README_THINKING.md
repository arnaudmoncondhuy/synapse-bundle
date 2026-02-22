# Thinking Config Natif - Synapse Bundle

## Qu'est-ce que c'est ?

Le mode **Thinking Natif** active la capacité de réflexion interne de Gemini via l'API `thinkingConfig`.

**Bénéfices** :
- 🧠 Debug structuré (champ `thought: true` au lieu de regex)
- ✂️ Prompt simplifié (-80% de taille)
- 🎛️ Contrôle du budget de réflexion (512-24576 tokens)
- 🔍 Meilleure fiabilité du parsing

---

## Configuration

### Configuration Minimale

```yaml
# config/packages/synapse.yaml
synapse:
    api_key: '%env(GEMINI_API_KEY)%'
    model: 'gemini-2.5-flash-lite'
```

Le thinking est **activé par défaut** avec un budget de 1024 tokens.

### Configuration Personnalisée

```yaml
synapse:
    api_key: '%env(GEMINI_API_KEY)%'
    model: 'gemini-2.5-flash-lite'

    thinking:
        enabled: true
        budget: 2048  # 512-24576 selon le modèle
```

### Désactiver le Thinking

```yaml
synapse:
    thinking:
        enabled: false  # Pas de réflexion interne
```

---

## Compatibilité Modèles

| Modèle | Thinking | Budget Min | Budget Max | Budget=0 OK ? |
|--------|----------|-----------|-----------|---------------|
| `gemini-2.5-flash` | ✅ | 0 | 24576 | ✅ |
| `gemini-2.5-flash-lite` | ✅ | 512 | 24576 | ❌ |
| `gemini-2.5-pro` | ✅ | 128 | 32768 | ❌ |
| `gemini-1.5-*` | ❌ | - | - | - |

---

## Debug

### Activer le Debug

Ajouter `?debug=1` à l'URL de l'assistant, ou activer via l'interface.

### Ce que tu verras

**Bloc "🧠 Réflexion (CoT)"** :
```
L'utilisateur demande la disponibilité des véhicules.
Je dois vérifier les outils disponibles et exécuter
l'outil de vérification sans poser de questions.
```

**Prompt Système** :
```
### CADRE TECHNIQUE DE RÉPONSE
Le système capture automatiquement ton processus
de réflexion interne via thinkingConfig.
```

---

## Payload API

Le bundle envoie automatiquement :

```json
{
  "system_instruction": {...},
  "contents": [...],
  "generationConfig": {
    "thinkingConfig": {
      "thinkingBudget": 2048
    }
  },
  "tools": [...]
}
```

---

## Exemples de Configuration

### Production (Optimisé Coût)

```yaml
synapse:
    model: 'gemini-2.5-flash-lite'
    thinking:
        enabled: true
        budget: 1024  # Minimal pour réduire les coûts
```

### Développement (Debug Maximal)

```yaml
synapse:
    model: 'gemini-2.5-flash'
    thinking:
        enabled: true
        budget: 8192  # Maximum pour comprendre le raisonnement
```

### Performance (Sans Thinking)

```yaml
synapse:
    model: 'gemini-2.5-flash'
    thinking:
        enabled: false  # Latence minimale
```

---

## Impact Performance

| Budget | Latence Ajoutée | Coût Tokens Supplémentaires |
|--------|-----------------|----------------------------|
| 1024 | +200-500ms | ~1024 input tokens |
| 2048 | +300-700ms | ~2048 input tokens |
| 4096 | +500-1000ms | ~4096 input tokens |
| 8192 | +800-1500ms | ~8192 input tokens |

**Note** : Les tokens de thinking sont comptés comme input tokens.

---

## Développement Local

### Modifier le Bundle

1. Éditer les fichiers dans `C:\MakerLab\www\synapse-bundle\`
2. Copier vers le vendor de l'Intranet :

```bash
cp C:\MakerLab\www\synapse-bundle\src\Service\*.php c:\MakerLab\Lycee\Intranet\vendor\arnaudmoncondhuy\synapse-bundle\src\Service\
```

3. Vider le cache :

```bash
cd c:\MakerLab\Lycee\Intranet
php bin/console cache:clear
```

### Ou Utiliser Path Repository (Recommandé)

Modifier `composer.json` de l'Intranet :

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "C:/MakerLab/www/synapse-bundle"
        }
    ],
    "require": {
        "arnaudmoncondhuy/synapse-bundle": "@dev"
    }
}
```

Puis :

```bash
composer update arnaudmoncondhuy/synapse-bundle
```

---

## Architecture

### Fichiers Modifiés

| Fichier | Rôle |
|---------|------|
| `Configuration.php` | Définit la config `thinking.enabled` et `thinking.budget` |
| `SynapseExtension.php` | Charge les paramètres |
| `GeminiClient.php` | Injecte `thinkingConfig` dans le payload API |
| `PromptBuilder.php` | Utilise un prompt simplifié (plus de `<thinking>` manuelles) |
| `services.yaml` | Wire les paramètres |

### Flux de Données

```
Config YAML
    ↓
SynapseExtension (charge paramètres)
    ↓
GeminiClient (construit payload avec thinkingConfig)
    ↓
API Gemini (renvoie thought:true)
    ↓
ChatService (parse et wrap dans <thinking>)
    ↓
Debug Twig (affiche le bloc réflexion)
```

---

## FAQ

### Le thinking ne s'affiche pas dans le debug ?

1. Vérifier que `thinking.enabled: true`
2. Vérifier le modèle (doit être 2.5+)
3. Vider le cache : `php bin/console cache:clear`

### Erreur "thinkingBudget out of range" ?

Le budget est en dehors de la plage supportée par le modèle.
Voir tableau de compatibilité ci-dessus.

### Le thinking consomme trop de tokens ?

Réduire le budget :

```yaml
thinking:
    budget: 512  # Minimum pour flash-lite
```

Ou désactiver :

```yaml
thinking:
    enabled: false
```

---

## Changelog

### v1.1.0 (2026-01-27)

- ✅ Ajout du support `thinkingConfig` natif
- ✅ Simplification du prompt technique (-80%)
- ✅ Support des budgets configurables
- ✅ Mode thinking activé par défaut

---

## Support

Pour toute question : contact@arnaudmoncondhuy.fr
