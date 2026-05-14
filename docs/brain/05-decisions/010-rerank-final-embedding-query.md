---
status: accepté
date: 2026-05-14
jalon: 4
---

# ADR-010 — Re-rank final contre embedding query (constante 0.6)

## Statut

`accepté` — décidé en cours de jalon 4 (étape 6, MemoryRetriever) pour combler une dérive sémantique du spreading multi-hop. À recalibrer empiriquement à l'étape 11 (bench retrieval).

## Contexte

Le `SpreadingActivation` (jalon 4) propage un score multiplicatif depuis les seeds via le graphe de synapses. Plus on s'éloigne du seed, plus le score d'arrivée reflète **la chaîne traversée**, pas la **pertinence du neurone d'arrivée par rapport à la query**.

EcphoryRAG (arxiv 2510.08958, revue littérature passe 2 ADR-008) signale ce problème comme "dérive sémantique du spreading multi-hop" : un neurone atteint via 2-3 hops peut être structurellement bien connecté mais sémantiquement éloigné de ce que demande la query.

Solution proposée par le paper : **re-rank final** des neurones du résultat contre l'embedding de la query. Combine score structurel (BFS) et score sémantique (cosine).

## Options considérées

### Option A — Pas de re-rank
Garder le score BFS pur. Risque de dérive sémantique sur les résultats à depth ≥ 2.

**Rejeté** : confirmé comme problème par EcphoryRAG, et notre formule multiplicative s'effondre vite donc on a justement besoin que le rang final soit corrélé à la pertinence d'arrivée.

### Option B — Re-rank multiplicatif
`final = BFS × cosine(neuron, query)`

Si cosine = 0.5, on divise par 2 le score BFS. Le rang dépend autant du structurel que du sémantique.

**Rejeté** : multiplicatif s'effondre encore plus. Si BFS=0.3 et cosine=0.5, final=0.15. Trop punitif.

### Option C — Re-rank additif normalisé
`final = α × BFS + (1-α) × cosine(neuron, query)`

Combine linéairement les deux signaux avec un poids α qui contrôle le trade-off.

**Avantages** :
- Échelle préservée (entre 0 et 1)
- α paramétrable, calibrable empiriquement
- Cohérent avec ce que fait Generative Agents pour Importance × Recency × Relevance (additif normalisé, cf. revue passe 1)
- Lisible : un neurone à BFS=0.3 mais cosine=0.9 obtient `0.6×0.3 + 0.4×0.9 = 0.54` — le sémantique le sauve

### Option D — Re-rank par re-classement uniquement
Garder BFS pour le filtrage (seuil minScore), puis re-trier par cosine pur.

**Rejeté** : on perdrait le signal structurel utile. Un neurone récemment renforcé (high BFS via recency) a une vraie raison d'être proposé même s'il n'est pas littéralement le plus proche en cosine.

## Décision

**Option C retenue** : `final = RERANK_PATH_WEIGHT × BFS + (1 - RERANK_PATH_WEIGHT) × cosine(neuron, query)` avec `RERANK_PATH_WEIGHT = 0.6` par défaut.

Justification du **0.6** :
- Privilégie légèrement le BFS (60%) car c'est notre signal différenciant vs un retrieval vector pur (sinon Brain n'apporte rien de plus qu'un cosine RAG classique)
- Mais laisse 40% au cosine pour rattraper la dérive sémantique
- Valeur de départ raisonnable, **à calibrer à l'étape 11** sur le corpus weecom

Cette constante est exposée en `MemoryRetriever::RERANK_PATH_WEIGHT` pour audit et test ablation.

## Conséquences

- **Code** : `MemoryRetriever::rerankAgainstQuery()` applique la formule
- **Coût** : 1 appel embedding supplémentaire par query (l'embedding de la query). Acceptable pour jalon 4 (mémoïsation possible jalon 5+ via wrapper `SeedExtractionResult`)
- **Edge cases** :
  - Neurone sans embedding (procédural) → garde score BFS tel quel
  - Query embedding échoue → skip re-rank, garde score BFS tel quel
  - cosine < 0 → clamp à 0 (un neurone "anti-corrélé" ne booste pas, mais ne pénalise pas non plus le BFS)
- **Bench (étape 11)** : tester 3 valeurs de `RERANK_PATH_WEIGHT` (0.4, 0.6, 0.8) sur fixtures retrieval-v1, retenir celle qui maximise F1@10

## Notes

- L'option C peut être amendée si bench montre besoin d'aller en additif pondéré par d'autres facteurs (Importance, Recency cf. Generative Agents)
- À surveiller : le re-rank double-compte la recency (déjà dans BFS via `recency_factor` ADR-008) et peut renforcer un biais de récence si on n'y prend pas garde

---

*Décidé le 2026-05-14 en cours de jalon 4 (étape 6 MemoryRetriever), suite à audit `brain-charter-auditor` qui a flaggé l'absence d'ADR pour `RERANK_PATH_WEIGHT = 0.6` (charte §2.10 : pas de réglage à l'œil).*
