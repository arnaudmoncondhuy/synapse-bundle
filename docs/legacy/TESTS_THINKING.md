# Tests pour Thinking Config Natif

## Configuration de Test

### Dans l'application (Intranet)

Créer/modifier `config/packages/synapse.yaml` :

```yaml
synapse:
    api_key: '%env(GEMINI_API_KEY)%'
    model: 'gemini-2.5-flash'

    thinking:
        enabled: true
        budget: 2048
```

---

## Test 1 : Thinking Activé

### Configuration

```yaml
synapse:
    thinking:
        enabled: true
        budget: 1024
```

### Procédure

1. Lancer l'application
2. Ouvrir l'assistant (interface chat)
3. Poser une question : "Bonjour, peux-tu m'expliquer comment réserver un véhicule ?"
4. **Activer le mode debug** (ajouter `?debug=1` dans l'URL ou via l'interface)

### Résultat Attendu

**Dans le debug (page debug ou console)** :
- ✅ Voir un bloc "🧠 Réflexion (CoT)" rempli
- ✅ Le contenu est structuré (pas de balises `<thinking>` visibles dans le bloc)
- ✅ La réflexion est cohérente avec la question

**Dans la réponse visible** :
- ✅ Pas de balises `<thinking>` affichées
- ✅ Réponse propre en Markdown
- ✅ URLs formatées comme `[texte](url)`

**Dans le prompt système (debug)** :
- ✅ Le prompt NE contient PAS les instructions `<thinking>` manuelles
- ✅ Le prompt contient : "Le système capture automatiquement ton processus de réflexion"

---

## Test 2 : Thinking Désactivé (Mode Legacy)

### Configuration

```yaml
synapse:
    thinking:
        enabled: false
```

### Procédure

1. Modifier la config
2. Vider le cache : `php bin/console cache:clear`
3. Relancer l'application
4. Poser la même question avec debug activé

### Résultat Attendu

**Dans le prompt système (debug)** :
- ✅ Le prompt contient les instructions `<thinking>` manuelles complètes
- ✅ Le prompt contient : "Tu DOIS commencer CHAQUE réponse par une réflexion"

**Dans le debug** :
- ✅ Le bloc réflexion est toujours présent
- ✅ Il contient les balises `<thinking>...</thinking>` parsées

**Comportement** :
- ✅ Identique au fonctionnement avant l'implémentation

---

## Test 3 : Budget Variable

### Configuration Courte

```yaml
synapse:
    thinking:
        enabled: true
        budget: 512  # Réflexion courte
```

### Configuration Longue

```yaml
synapse:
    thinking:
        enabled: true
        budget: 8192  # Réflexion longue
```

### Procédure

1. Tester avec budget = 512
2. Poser une question complexe : "Peux-tu comparer les avantages et inconvénients de la réservation de véhicules pour les sorties pédagogiques vs les stages ?"
3. Observer la longueur du thinking dans le debug
4. Changer pour budget = 8192
5. Poser la même question
6. Comparer les longueurs

### Résultat Attendu

- ✅ Budget 512 : réflexion plus courte (~200-400 mots)
- ✅ Budget 8192 : réflexion plus longue et détaillée (~800-1500 mots)
- ✅ Pas d'erreur API
- ✅ Réponses toujours cohérentes

---

## Test 4 : Budget Zéro (Désactivation si supporté)

### Configuration

```yaml
synapse:
    model: 'gemini-2.5-flash'  # Important : flash supporte budget=0
    thinking:
        enabled: true
        budget: 0
```

### Procédure

1. Modifier la config
2. Poser une question simple : "Bonjour !"

### Résultat Attendu

- ✅ Pas d'erreur API
- ✅ Le debug ne contient PAS de bloc réflexion (ou vide)
- ✅ Réponse directe sans thinking

**Note** : Si le modèle est `gemini-2.5-flash-lite` ou `gemini-2.5-pro`, budget=0 n'est pas supporté et l'API retournera une erreur.

---

## Test 5 : API Payload Inspection

### Procédure

1. Activer thinking avec budget = 1024
2. Dans le debug, vérifier le payload envoyé à l'API

### Résultat Attendu

Le payload doit contenir :

```json
{
  "system_instruction": { ... },
  "contents": [ ... ],
  "generationConfig": {
    "thinkingConfig": {
      "thinkingBudget": 1024
    }
  },
  "tools": [ ... ]
}
```

---

## Test 6 : Compatibilité Modèles

### Test avec gemini-2.5-flash

```yaml
synapse:
    model: 'gemini-2.5-flash'
    thinking:
        enabled: true
        budget: 2048
```

**Résultat** : ✅ Doit fonctionner (budget range: 0-24576)

### Test avec gemini-2.5-flash-lite

```yaml
synapse:
    model: 'gemini-2.5-flash-lite'
    thinking:
        enabled: true
        budget: 2048
```

**Résultat** : ✅ Doit fonctionner (budget range: 512-24576)

### Test avec modèle ne supportant pas thinking

```yaml
synapse:
    model: 'gemini-1.5-flash'  # Vieux modèle
    thinking:
        enabled: true
```

**Résultat** : ⚠️ L'API peut ignorer `thinkingConfig` ou retourner une erreur. Si erreur, désactiver thinking pour ce modèle.

---

## Test 7 : Rétro-compatibilité

### Procédure

1. **NE PAS** ajouter la config `thinking` dans synapse.yaml
2. Lancer l'application

### Résultat Attendu

- ✅ Valeurs par défaut appliquées : `enabled: true`, `budget: 1024`
- ✅ L'application fonctionne normalement
- ✅ Le thinking natif est activé par défaut

---

## Checklist de Validation

Avant de considérer l'implémentation terminée :

- [ ] Test 1 réussi (thinking activé)
- [ ] Test 2 réussi (thinking désactivé)
- [ ] Test 3 réussi (budget variable)
- [ ] Test 4 réussi (budget zéro si modèle compatible)
- [ ] Test 5 réussi (payload correct)
- [ ] Test 6 réussi (compatibilité modèles)
- [ ] Test 7 réussi (rétro-compatibilité)
- [ ] Pas d'erreur dans les logs
- [ ] Debug affiche correctement le thinking
- [ ] Prompt adapté selon le mode

---

## Dépannage

### Erreur : "thinkingBudget out of range"

**Cause** : Le budget est en dehors de la plage supportée par le modèle.

**Solution** : Vérifier les limites par modèle :
- `gemini-2.5-flash` : 0-24576
- `gemini-2.5-flash-lite` : 512-24576
- `gemini-2.5-pro` : 128-32768

### Le thinking n'apparaît pas dans le debug

**Cause** : Soit thinking désactivé, soit modèle ne le supporte pas.

**Solution** :
1. Vérifier `synapse.thinking.enabled: true`
2. Vérifier le modèle (doit être 2.5+)
3. Vider le cache : `php bin/console cache:clear`

### Le prompt contient toujours les instructions manuelles

**Cause** : Le paramètre `nativeThinkingEnabled` n'est pas passé correctement.

**Solution** :
1. Vérifier `config/services.yaml` : `$nativeThinkingEnabled: '%synapse.thinking.enabled%'`
2. Vider le cache
3. Vérifier que le paramètre est bien chargé dans `SynapseExtension.php`

---

## Métriques de Performance

### Impact sur Latence

- **Thinking activé (budget 1024)** : +200-500ms selon complexité
- **Thinking activé (budget 4096)** : +500-1500ms
- **Thinking désactivé** : Latence de base (~800-1200ms)

### Impact sur Coûts

Le thinking consomme des tokens supplémentaires :
- Budget 1024 ≈ +1024 tokens input
- Budget 4096 ≈ +4096 tokens input

**Important** : Les tokens de thinking sont comptabilisés comme input tokens (0.038 USD / 1M pour gemini-2.5-flash sur Vertex).

---

## Validation Finale

Une fois tous les tests réussis, créer un commit avec :

```bash
git add .
git commit -m "feat: add native thinking config support

- Add thinkingConfig support in GeminiClient
- Add thinking.enabled and thinking.budget config options
- Add native thinking prompt (TECHNICAL_PROMPT_NATIVE)
- Update services wiring for thinking parameters
- Maintain backward compatibility (defaults to enabled)

Supports gemini-2.5-flash and compatible models.
Budget range: 0-24576 tokens (model-dependent).
"
```
