---
status: accepté
date: 2026-05-13
jalon: 4
---

# ADR-008 — Formule de score pour le spreading activation : linéaire avec decay exponentiel

## Statut

`accepté` — décidé au démarrage du jalon 4. À recalibrer si bench retrieval (étape 11) montre une dérive.

## Contexte

Quand le `SpreadingActivation` propage depuis un seed vers ses voisins, chaque neurone touché reçoit un score. Comment calculer ce score ?

Facteurs à combiner :
- `seedScore` : similarité cosine initiale entre la requête et le seed (0-1)
- `synapse.weight` : poids Hebbien de la synapse traversée (0-1)
- `synapse.confidence` : fiabilité de la synapse (0-1, distinct du poids)
- `depth` : nombre de sauts depuis le seed (0, 1, 2, ...)

Une bonne formule doit :
1. Donner des scores plus élevés aux neurones **proches** (depth bas)
2. Pénaliser les synapses **faibles** ou **incertaines**
3. Garder des valeurs dans une plage interprétable (0-1)
4. Être **reproductible** (déterministe)
5. Être **rapide** à calculer (BFS à 2 sauts peut toucher 500 neurones)

## Options considérées

### Option A — Multiplicatif simple

```
score(target) = seedScore × synapse.weight × synapse.confidence
```

Aucune pénalité explicite par profondeur (mais implicite : chaque saut multiplie par <1).

**Inconvénients** :
- Si `seedScore=0.7`, `weight=0.9`, `confidence=0.9`, on a déjà 0.567. À 2 sauts on tombe à ~0.32 si tous les facteurs sont à 0.9. Trop punitif si la chaîne est longue ?
- Pas de contrôle séparé sur le decay

### Option B — Linéaire avec decay exponentiel explicite

```
score(target, depth) = seedScore × synapse.weight × synapse.confidence × decayBase^depth
```

Où `decayBase` est un paramètre (ex: 0.7) qui contrôle indépendamment la pénalité par saut.

**Avantages** :
- Decay contrôlable séparément (pas de double comptage)
- À depth=0 : decay^0 = 1 (le seed n'est pas pénalisé)
- À depth=1 : decay = 0.7 (perte de 30%)
- À depth=2 : decay² = 0.49 (la moitié restante)
- Lisible, mesurable, paramétrable

**Choix de `decayBase`** : 0.7 — empirique. Plus bas = décroissance plus rapide (favorise les proches). Plus haut = moins de pénalité distance.

### Option C — Logarithmique

```
score(target, depth) = log(seedScore + 1) × log(weight + 1) × ... × decayBase^depth
```

Lisse les valeurs faibles (rend moins critique le seedScore très bas), mais brise l'intuition multiplicative.

**Rejeté** : complique sans gain démontré. Si besoin de lissage, on aura toute la latitude au jalon 6 (functional networks pondèrent).

### Option D — Apprentissable (RankNet-like)

Apprendre les poids des facteurs via gradient descent sur un dataset d'évaluation.

**Rejeté** : hors-scope jalon 4. Énorme overhead pour un gain incertain à ce stade.

## Décision

**Option B retenue : linéaire avec decay exponentiel explicite.**

Formule :

```
score(target, depth) = seedScore × synapse.weight × synapse.confidence × decay^depth
```

avec `decay = 0.7` par défaut (constant exposé via `SpreadingActivation::DEFAULT_DECAY_PER_HOP`).

## Conséquences

- **Code** :
  ```php
  final readonly class SpreadingActivation
  {
      public const DEFAULT_DECAY_PER_HOP = 0.7;

      private float $decayPerHop;

      public function __construct(float $decayPerHop = self::DEFAULT_DECAY_PER_HOP)
      {
          $this->decayPerHop = $decayPerHop;
      }

      // Dans la boucle BFS :
      $newScore = $parentScore * $synapse->getWeight() * $synapse->getConfidence();
      $newScore *= ($this->decayPerHop ** ($depth + 1));
  }
  ```

- **Tests** : `SpreadingActivationTest` doit asserter sur les scores attendus pour un graphe synthétique :
  - seed à score 1.0 (depth=0) → reste 1.0
  - voisin direct via synapse(w=0.9, c=0.9) → 1.0 × 0.9 × 0.9 × 0.7^1 ≈ 0.567
  - voisin-du-voisin idem → 0.567 × 0.9 × 0.9 × 0.7^1 ≈ 0.321

- **Bench retrieval (étape 11)** : si F1 hebbian est seulement marginalement meilleur que baseline vector, envisager :
  - Ajuster `decayPerHop` (0.5 = plus agressif, 0.85 = plus permissif)
  - Ajouter un terme `confidence^α` avec α exposant
  - Mesurer 2-3 variantes

- **ADRs à suivre** :
  - Jalon 6 (functional networks) : la formule sera enrichie d'un facteur de pondération contextuelle par aire (mode "focus" amplifie épisodique, mode "discovery" amplifie encyclopédique)
  - Si bench montre besoin de re-calibrer : ADR-008.v2 ou amendement

## Notes

- **Pas de polarity au jalon 4** : ADR-005 et le design §6 prévoient `polarity = inhibitory` qui devrait *réduire* le score. Reporté au jalon 5 (premier jalon où la polarity est exploitée par les LLM extractors)
- Formule **séparable** : on peut tester chaque facteur indépendamment (mettre confidence=1 pour ne tester que weight, etc.) — utile pour le debug

---

*Décidé au démarrage du jalon 4 (2026-05-13).*
