---
status: accepté (amendé 2026-05-13 après revue de littérature)
date: 2026-05-13
jalon: 4
---

# ADR-008 — Formule de score pour le spreading activation : linéaire avec decay exponentiel

## Statut

`accepté (amendé)` — décidé au démarrage du jalon 4 puis amendé le même jour suite à une revue de littérature ciblée (HippoRAG 2, A-MEM, HeLa-Mem, Generative Agents, SSGM).

Le décor initial reste valide : la formule **multiplicative `seedScore × weight × confidence × decay^depth`** est conservée pour le `path_score`. L'amendement ajoute :

1. Un **facteur recency** multiplicatif (insight Park et al. 2023 + Zhu et al. 2026)
2. Un **cap de fan-out par hop** anti-hub (insight SSGM 2026)
3. La **confirmation** du retrait de la polarity au jalon 4 (aucun des 5 papers ne valide la propagation d'inhibition)

À recalibrer à l'étape 11 (bench retrieval) si nécessaire.

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

## Amendement 2026-05-13 — Insights revue de littérature (passe 1)

Revue ciblée juste avant l'écriture de `SpreadingActivation` : 5 papers lus en profondeur (HippoRAG 2, A-MEM, HeLa-Mem, Generative Agents, SSGM). Cf. `docs/brain/04-references.md`.

### Ce qui change

#### 1. Ajout d'un facteur recency

**Constat** : Generative Agents (Park et al. 2023) **et** HeLa-Mem (Zhu et al. 2026) intègrent un decay temporel `0.995^t`. Sans ce facteur, les vieilles synapses dominent à l'échelle des mois — un défaut connu pointé par SSGM. Notre formule initiale ignorait totalement la temporalité.

**Décision** : on multiplie par `recency_factor = RECENCY_DECAY^daysSinceActivation` avec `RECENCY_DECAY = 0.995` (constante alignée Park et al. + Zhu et al.).

- Synapse activée aujourd'hui → `1.0`
- Il y a 30j → `0.86`
- Il y a 90j → `0.64`
- Il y a 365j → `0.16`

**Formule complète** :
```
path_score = seedScore × weight × confidence × DEPTH_DECAY^depth
recency_factor = RECENCY_DECAY^daysSinceActivation
score = path_score × recency_factor
```

Source de `lastActivatedAt` : déjà présent sur `Synapse` (mis à jour à chaque `recordCorroboration()` et à chaque reinforcement Hebbien jalon 4 étape 6).

**À recalibrer** : si bench montre que la recency écrase le path score à long terme (>1 an), envisager passage en additif `score = path + α × recency` (insight Park et al.).

#### 2. Cap de fan-out par hop (anti-hub)

**Constat** : SSGM (2603.11768) signale que les *hub neurons* (nœuds à très haut degree) absorbent disproportionnellement le BFS. Notre formule pure peut traverser 500 voisins depuis un seul neurone hub, polluant le résultat. Aucun des autres papers ne gère vraiment le problème (HeLa-Mem détecte les hubs par seuil mais ne renormalise pas).

**Décision** : on plafonne le fan-out par hop à `MAX_FANOUT_PER_HOP = 20` synapses, triées par `weight × confidence` décroissant. Les 20 plus fortes connexions du hub gagnent, les autres sont silencieusement ignorées au retrieval (pas supprimées en base — elles restent disponibles pour les jalons ultérieurs).

**Trade-off** : on peut rater une connexion faible mais informative. Mitigation : `evidence_count` et `confidence` jouent dans le tri.

#### 3. Polarity inhibitory : retrait confirmé du jalon 4

**Constat** : aucun des 5 papers ne propage l'inhibition sur plusieurs sauts. PPR sur arêtes signées est connu pour être instable (la masse peut diverger). À 3+ hops avec inversion de signe, on obtient des amplifications négatives non-sensées.

**Décision** : `SpreadingActivation::computeNewScore` ignore `polarity` au jalon 4. Reporté à jalon 5+ avec validation empirique dédiée (un test : créer un graphe avec polarity mixte, mesurer F1 avec et sans propagation d'inhibition).

#### 4. Ce qui est **différé** mais noté

- **Importance/relevance d'arrivée** (Generative Agents score_base, HeLa-Mem) : coût embedding × N voisins par hop. À examiner jalon 5 si le bench montre que les voisins de mauvaise qualité polluent le top-K.
- **Validation gate de cohérence logique** (SSGM Eq.6) : trop lourd pour jalon 4. À considérer jalon 6+.
- **Decay temporel des synapses elles-mêmes** (HeLa-Mem `λ=0.995` par retrieval) : nécessite un cron de maintenance. Différé jalon 8 (Maintenance & consolidation).
- **LTP/LTD asymétrie Hebbienne** (Kairos 2025/2026) : recherche active. Pas avant validation extensive sur weecom.

### Ce qui reste inchangé

- Multiplicatif (pas additif) pour le path_score. Justification : plus simple à débugger et à interpréter. À basculer en additif si calibration empirique le démontre.
- `DEPTH_DECAY = 0.7` — défendable (zone Collins & Loftus 1975 / ACT-R 0.5).
- Hard cap profondeur 5 (sécurité), critère d'arrêt principal = `minScore` (ADR-007 amendé).

### Constantes après passe 1

| Constante | Valeur passe 1 | Source |
|---|---|---|
| `DEPTH_DECAY` | 0.7 | Collins & Loftus 1975, ACT-R ~0.5 — zone défendable |
| `RECENCY_DECAY` | 0.995 / jour | Park et al. 2023, Zhu et al. 2026 |
| `HARD_MAX_DEPTH` | 5 | Sécurité runaway, critère réel = `minScore` |
| `MAX_FANOUT_PER_HOP` | 20 | Anti-hub (SSGM 2026) |
| `RetrievalQuery::minScore` default | 0.1 | En dessous = bruit |

---

## Amendement 2026-05-13 — Insights revue de littérature (passe 2 : OSS code + classics + 2024-2026)

Passe 2 ciblée parce que la passe 1 était une seule lecture rapide de 5 papers. Cette passe 2 = 3 agents en parallèle :

1. Code OSS de retrieval réel : mem0, Letta, Cognee, A-MEM, HippoRAG, LightRAG
2. Fondations Hebbien classiques : règle d'Oja, BCM, Hopfield, Collins & Loftus, ACT-R, STDP, Ebbinghaus
3. Papers récents 2024-2026 : SA-RAG (`2512.15922`), CatRAG (`2602.01965`), EcphoryRAG (`2510.08958`), LiCoMemory (`2511.01448`), GraphRAG, LightRAG paper, GraphReader, surveys

### Convergences cross-source actionnables maintenant

#### 1. Hard cap profondeur : 5 → 3

**Sources convergentes** : EcphoryRAG (depth=2 optimal, plateau prouvé), SA-RAG (n=3-4), SCG-MEM (peak hop-1 ou hop-2), MemNN historique (3-4 saturation), A-MEM (hop=1), LightRAG (hop=1).

**Implication** : au-delà de 3 hops, gain mesurable nul ou négatif dans **toutes les ablations 2025-2026**. Notre `HARD_MAX_DEPTH=5` était over-engineered. On passe à 3 par défaut.

#### 2. Ajout d'une pénalité hub explicite au scoring

**Sources convergentes** : mem0 (`memory_count_weight = 1/(1 + 0.001·(degree-1)²)`), HippoRAG (`/len(ent_node_to_chunk_ids)`), CatRAG (Static Graph Fallacy = critique frontale du PPR sans anti-hub), SSGM (topology-induced leakage), Hebbien classiques (rich-get-richer / preferential attachment).

**Constat** : notre `MAX_FANOUT_PER_HOP=20` rationne mais ne dit pas au scoring qu'un neurone à 100 voisins est moins informatif qu'un à 5 voisins. Sans pénalité explicite, un hub absorbe les retrievals.

**Décision** : on ajoute `hubFactor = 1 / (1 + HUB_PENALTY_ALPHA·(degree-1)²)` avec `HUB_PENALTY_ALPHA = 0.001` (formule mem0). Appliqué au scoring lors du dequeue d'un neurone. Le score final stocké et propagé inclut la pénalité.

**Formule complète mise à jour** :
```
arriving      = score qui arrive depuis le synapse parent
hub_factor    = 1 / (1 + α·(outDegree-1)²)
final(neuron) = arriving × hub_factor
propagated(d) = final × weight × confidence × DEPTH_DECAY^d × recency_factor
```

Le **`final`** est ce qui est stocké dans `visited` ET propagé aux descendants (philosophie : un hub bruité contamine moins, mais ne contamine pas non plus disproportionnellement les chemins valides qui passent par lui).

### Insights instructifs mais non-bloquants

1. **Aucun framework OSS ne fait de decay temporel synapse**. Notre `0.995^days` est unique. Possible signal d'innovation ; possible signal qu'on suit Park & Zhu là où eux ne testent pas. **À mesurer empiriquement** sur weecom. Si délétère, on retire.

2. **Multiplicatif s'effondre** (confirmation cross-source). Mais l'effondrement est précisément ce qui rend `minScore` efficace comme critère d'arrêt. Trade-off accepté.

3. **Saturation soft `w + δ(1-w)` ≠ Oja ni BCM** : c'est un plafonnement per-edge cosmétique, pas une normalisation par compétition. Trade-off accepté.

4. **Pas de mécanisme d'affaiblissement actif** (LTD/BCM). Synapse ne fait que monter → bruit s'accumule. **Critique pour jalon 5+ (consolidation)**, pas pour jalon 4 (retrieval).

5. **SA-RAG (arxiv 2512.15922)** est exactement notre approche, ré-actualisée 2026, avec validation MuSiQue 67-87%. Confirme l'architecture, pas de pivot.

### Différé à jalons ultérieurs

| Insight | Source | Jalon cible |
|---|---|---|
| Re-rank final contre embedding query | EcphoryRAG | Jalon 4 — **dans MemoryRetriever**, pas SpreadingActivation |
| Query-conditioning des poids d'edge | CatRAG Dynamic Edge Weighting | Jalon 5+ (coût LLM) |
| Passages comme nodes | HippoRAG 2 (+12.5%) | Jalon 5+ (refonte architecture) |
| Validation gate de cohérence logique | SSGM Eq.6 | Jalon 6+ (lourd) |
| Decay temporel des synapses | HeLa-Mem `λ=0.995` par retrieval | Jalon 8 (consolidation cron) |
| LTP/LTD asymétrie + BCM seuil glissant | Kairos 2025/26 + Bienenstock 1982 | Jalon 8+ (recherche active) |
| Polarity inhibitory | Design §6 | Jalon 5+ (validation empirique requise) |
| Agent-driven navigation | GraphReader | Hors-scope (Brain reste déterministe) |
| Entity aliasing | Consensus survey 2026 | Jalon 8 (consolidation) |

### Métriques cibles (calibration jalon 4)

D'après LiCoMemory (LongMemEval 2026) : 73.8% accuracy, 76.6% recall avec GPT-4o-mini.

**Objectif réaliste sur corpus weecom** :
- recall@5 ≥ 0.6 — conservateur (LiCoMemory : 0.77)
- F1@10 > baseline vector simple
- Distribution depth des hits : ≥ 70% à depth 1, ≤ 25% à depth 2, < 5% à depth 3 — sinon le multi-hop ne sert pas

### Constantes finales (jalon 4, après passe 2)

| Constante | Valeur | Source |
|---|---|---|
| `DEPTH_DECAY` | 0.7 | Collins & Loftus 1975, ACT-R ~0.5 — zone défendable |
| `RECENCY_DECAY` | 0.995 / jour | Park et al. 2023, Zhu et al. 2026 — **à valider empiriquement** |
| `HARD_MAX_DEPTH` | **3** (↓ de 5) | Convergence EcphoryRAG/SA-RAG/SCG-MEM/MemNN |
| `MAX_FANOUT_PER_HOP` | 20 | Anti-hub niveau 1 (rationnement) |
| `HUB_PENALTY_ALPHA` | **0.001** (nouveau) | Anti-hub niveau 2 (scoring) — formule mem0 |
| `RetrievalQuery::maxDepth` default | **3** (↓ de 5) | Aligné HARD_MAX_DEPTH |
| `RetrievalQuery::minScore` default | 0.1 | En dessous = bruit |

---

*Décidé au démarrage du jalon 4 (2026-05-13).*
*Amendé passe 1 le même jour après revue 5 papers.*
*Amendé passe 2 le même jour après revue OSS + classics + papers 2024-2026 — 3 agents en parallèle, ~14 sources additionnelles.*
