# Calibration des synapses

> Comment on choisit les bons hyper-paramètres du brain — sans régler à l'œil.

## Pourquoi c'est critique

**La calibration des synapses est ce qui fait la différence entre un brain qui fonctionne et une soupe de neurones.** Tout l'effort des jalons 1-2 (infrastructure) et 3-8 (capacités) peut être ruiné par un mauvais réglage : un seuil cosine trop bas → tout converge vers rien ; un decay trop rapide → le brain oublie tout ; une formule de score plate → le retrieval renvoie n'importe quoi.

Ce n'est pas un sujet à traiter "à la fin" — c'est un fil rouge transverse à tous les jalons à partir du 3. Sous-budget de temps recommandé : **20% du temps de chaque jalon de capacité doit aller à la calibration**.

## Pourquoi un doc dédié

Brain v3 introduit beaucoup de paramètres dont la valeur **n'est pas évidente a priori** :

| Paramètre | Où | Plage probable | Quand calibrer |
|---|---|---|---|
| Seuil cosine convergence | `ConvergenceDetector` | 0.7 – 0.95 | Jalon 3 |
| Profondeur BFS spreading activation | `MemoryRetriever` | 1 – 4 | Jalon 4 |
| Formule de score retrieval | `MemoryRetriever` | linéaire / log / log+α | Jalon 4 |
| Poids initial synapse | `Synapse` create | 0.05 – 0.3 | Jalon 4 |
| Incrément par co-activation | `HebbianReinforcer` | 0.01 – 0.1 | Jalon 4 |
| Seuil pruning weight | `MemoryDecayService` | 0.01 – 0.1 | Jalon 8 |
| Taux decay par aire (épisodique) | `MemoryDecayService` | -0.005 à -0.05/jour | Jalon 8 |
| Taux decay sémantique | idem | -0.0005 à -0.005/jour | Jalon 8 |
| Seuil pondération polarity inhibitory | `MemoryRetriever` | -0.5 à -1.5 | Jalon 5 |
| Seuil validation-gated learning | `ConsolidationGate` | 0.4 – 0.8 | Jalon 8 |

Choisir à l'œil = se mentir. Choisir par **comparaison empirique de jeux de synapses concurrents** = ancrer le brain dans la réalité du corpus.

## Concept : jeu de synapses

Un **jeu de synapses** (aussi appelé *parameter set*) est :

1. Un fichier YAML d'hyper-paramètres versionné dans `tests/Brain/Calibration/Sets/`
2. Un **snapshot reproductible** : MemorySources d'origine + neurones + synapses générés avec ces paramètres
3. Un identifiant et une date

Exemple :

```yaml
# tests/Brain/Calibration/Sets/v1-baseline.yaml
id: v1-baseline
date: 2026-06-XX
parameters:
  convergence:
    cosine_threshold: 0.85
  hebbian:
    initial_weight: 0.1
    co_activation_delta: 0.05
  retrieval:
    bfs_depth: 2
    score_formula: linear
  decay:
    episodic_per_day: -0.02
    semantic_per_day: -0.002
    pruning_threshold: 0.05
corpus_ref: weecom-sample-2026-05
```

## Pattern de calibration

```
┌─ 1. Définir le paramètre à calibrer ─────────────────────────────┐
│  Ex: "seuil cosine pour convergence"                             │
└──────────────────────────────────────────────────────────────────┘
                  ↓
┌─ 2. Identifier la métrique cible ────────────────────────────────┐
│  Ex: "précision convergence sur fixtures versionnées"            │
└──────────────────────────────────────────────────────────────────┘
                  ↓
┌─ 3. Générer ≥3 sets variants ────────────────────────────────────┐
│  Set A: cosine_threshold=0.75                                    │
│  Set B: cosine_threshold=0.85   ← candidat baseline              │
│  Set C: cosine_threshold=0.95                                    │
└──────────────────────────────────────────────────────────────────┘
                  ↓
┌─ 4. Lancer le même bench sur les 3 sets ─────────────────────────┐
│  bin/console brain:bench:convergence --set=A,B,C                 │
│  → tableau métriques par set                                     │
└──────────────────────────────────────────────────────────────────┘
                  ↓
┌─ 5. Choisir le set gagnant + écrire ADR ────────────────────────┐
│  Justifier vs les autres avec chiffres                           │
│  Marquer le set comme "production" dans calibration/README.md    │
└──────────────────────────────────────────────────────────────────┘
```

## Règles de calibration

### Reproductibilité

- **Le corpus source est figé** entre 2 sets — sinon comparaison invalide. Le snapshot `weecom-sample-2026-05` doit être versionné (export JSON, hash sha256 calculé)
- **Le pipeline de generation est déterministe** côté code — seuls les hyper-paramètres varient
- **Si on doit régénérer un set après bugfix dans le code** : on incrémente l'ID (v1.1, v1.2…) et on note la raison

### Métriques unifiées

Toujours mesurer les mêmes métriques entre sets concurrents. Documenter chaque métrique dans `tests/Brain/Calibration/METRICS.md` :
- Nom
- Calcul exact
- Échelle
- Direction (max ou min)
- Métrique seule ou agrégat de plusieurs

### Anti-pattern : "petit ajustement à la marge"

Si tu changes un paramètre `incremental_weight` de 0.05 à 0.06 sans relancer le bench complet, tu fais du tuning à l'œil. Interdit. Soit tu ouvres une nouvelle calibration (set v2), soit tu ne touches pas.

### Anti-pattern : "le set production est implicite"

À tout moment, un set est marqué `production` dans `tests/Brain/Calibration/Sets/PRODUCTION` (symlink ou pointeur). C'est lui qui sert pour les tests de régression. Pas d'ambiguïté.

## Calendrier de calibration par jalon

| Jalon | Paramètres à calibrer | Sets minimum |
|---|---|---|
| 3 | Seuil cosine convergence | 3 |
| 4 | BFS depth + formule score + poids initial | 4 (grid 2×2) |
| 5 | Pondération polarity inhibitory | 3 |
| 6 | Modulateurs par functional network | 1 par mode × 2 variants |
| 8 | Decay par aire + seuil pruning + seuil validation-gated | 5+ (le plus délicat) |

## Outils dédiés

À créer au jalon 3 ou avant :

- `bin/console brain:calibration:generate --set=<id>` — produit un set complet à partir d'un YAML
- `bin/console brain:calibration:compare --sets=A,B,C --metric=<name>` — tableau comparatif
- `bin/console brain:calibration:promote --set=<id>` — marque un set comme production
- `tools/brain-bench/calibration-report.php` — produit un rapport markdown lisible (à coller dans un ADR)

## Lien avec les ADRs

**Chaque calibration tranchée produit un ADR** dans `05-decisions/` :

- Titre : *"ADR-NNN — Calibration jalon X : <paramètre>"*
- Section "Décision" : nom du set retenu + résumé chiffré
- Section "Options" : les sets variants avec leurs métriques
- Section "Conséquences" : impact sur les autres paramètres, dépendances, datage de la prochaine recalibration

## Quand recalibrer

- Changement de modèle d'embedding → re-calibrer tous les seuils cosine
- Changement majeur du prompt d'extraction → re-calibrer convergence + polarity
- Changement de provider LLM → recalibrer formules de score si confidence du LLM intervient
- Tous les **6 mois minimum** sur le set production (drift potentiel du corpus)

---

**Liens** :
- Charte §2.9 (validation expérimentale) : [00-charte.md](00-charte.md)
- Méthodologie §validation qualité : [02-methodologie-sessions.md](02-methodologie-sessions.md#qualite-synapses)
- ADRs : [05-decisions/](05-decisions/)
