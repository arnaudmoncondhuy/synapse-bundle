---
status: accepté
date: 2026-05-14
jalon: 4
---

# ADR-011 — Hebbien implicite : renforcement au retrieval (mode A design §10)

## Statut

`accepté` — décidé en cours de jalon 4 (étape 6 MemoryRetriever). Active explicitement le mode A "Hebbien implicite" décrit dans le design §10 mais non encore tracé.

## Contexte

Le design figé `docs/brain-v3-design.md` §10 décrit 4 modes de naissance/évolution des synapses :

| Mode | Mécanisme | Coût |
|---|---|---|
| **A. Hebbien implicite** | 2 neurones convoqués dans la même réflexion → synapse renforcée auto | Gratuit (sur chaque retrieval) |
| **B. LLM explicite** | LLM appelle `Brain.linkNeurons()` via tool | Coût LLM + décision |
| **C. Règle métier** | Event business → `LearningSignal` → synapse | Gratuit (déterministe) |
| **D. Extraction** | Extracteur LLM produit neurones + liens | Coût LLM (déjà payé) |

Pour le jalon 4, on active **uniquement le mode A** (modes B et D existent déjà via `ProposeMemoryTool` et `OnePassMultiAreaExtractor`, mode C est jalon ultérieur).

Le mode A pose des questions :
1. **Quand** renforcer ? Pour chaque paire de neurones du résultat ? Seulement les chemins traversés ?
2. **De combien** ? `δ` du `HebbianReinforcer` (déjà 0.05 par défaut, ADR-009)
3. **Pour quelle "co-activation"** ? Le retrieval seul, ou seulement quand un agent a réellement utilisé le résultat dans une réponse ?

## Options considérées

### Option A — Renforcer toutes les paires de neurones du résultat
O(N²) sur les N neurones du résultat. Pour topN=10, 90 paires. Pour chaque paire, lookup synapse + reinforce si trouvée.

**Inconvénients** :
- 90 lookups SQL par retrieval (ou jointure complexe)
- Renforce des paires qui ne sont **pas réellement co-activées** par un chemin (juste co-présentes dans le résultat)
- Sémantique douteuse : 2 neurones dans le top-N ne sont pas forcément "fired together" structurellement

### Option B — Renforcer uniquement les synapses **effectivement traversées**
Pendant le BFS, on a tracé `reachedVia` (UUID synapse) sur chaque `ScoredNeuron`. On renforce ces synapses-là, et seulement elles.

**Avantages** :
- O(N) — aucun lookup supplémentaire vu que `reachedVia` est déjà disponible
- Sémantique propre : on renforce le **chemin** qu'on a réellement parcouru, pas des paires arbitraires
- Le seed n'est jamais renforcé (pas de synapse traversée pour y arriver) — cohérent avec "Hebbien = qui s'allument ensemble", or le seed s'allume seul (cosine query)

**Inconvénients** :
- Pas de renforcement des paires hors-chemin (mais elles n'ont pas non plus de synapse à renforcer, donc question moot)

### Option C — Renforcer après usage effectif (validation-gated, cf. Kairos)
Attendre qu'un agent ait réellement utilisé les neurones dans sa réponse, puis renforcer rétroactivement. Nécessite un mécanisme de feedback (LLM judge sur la réponse, ou user upvote).

**Rejeté pour jalon 4** : trop complexe, prévu jalon 6+ (validation-gated learning, cf. design §8).

### Option D — Pas de renforcement au retrieval
Réserver le renforcement aux modes B/C/D. Le retrieval est passif.

**Rejeté** : c'est exactement le mode A "Hebbien implicite" du design §10. Sans lui, on n'a pas de plasticité automatique — Brain devient un graphe statique modifiable uniquement par actions explicites. Perd l'analogie biologique principale ("fire together, wire together").

## Décision

**Option B retenue** : `MemoryRetriever::reinforceTraversedSynapses()` parcourt le résultat et appelle `HebbianReinforcer::reinforce($synapse, 'retrieval_co_activation')` pour chaque `ScoredNeuron::reachedVia` non-null.

Cause utilisée pour l'event `SynapseReinforcedEvent` : `'retrieval_co_activation'` (distincte de `'hebbian_co_activation'` par défaut, pour permettre des listeners spécifiques au retrieval).

## Conséquences

- **Code** : `MemoryRetriever::reinforceTraversedSynapses()` itère sur `$result->neurons` et reinforce chaque synapse via `reachedVia`
- **Coût** : 1 SQL `find()` par synapse à renforcer + 1 `setWeight()` + 1 event dispatch. Pour topN=10, max 10 lookups
- **Tracking** : event `SynapseReinforcedEvent` avec `cause='retrieval_co_activation'` permet d'auditer ou de désactiver le mode A par listener (ex: en mode "lecture seule")
- **Anti-emballement** : la saturation soft `w + δ(1-w)` (ADR-009) garantit que le poids ne dépasse pas 1.0. Pas besoin de validation-gated dans le mode A pour jalon 4
- **Implicite "gratuit"** (design §10) : O(N) avec N=topN=10 max → coût négligeable
- **Reproductibilité bench** : si on lance plusieurs queries pour calibration (étape 11), les renforcements modifient l'état entre 2 queries. Solution : flag `--no-reinforce` à ajouter sur `brain:query` pour les benchs (TODO étape 11)

## Notes

- Le mode B (LLM explicite via tool `Brain.linkNeurons`) est jalon 5+
- Le mode C (règle métier via event `LearningSignal`) est jalon 7+
- Le mode D (extraction) est déjà actif depuis jalon 3 (`ConvergenceDetector` crée des synapses lors de l'ingestion)
- Migration ultérieure possible vers Option C (validation-gated) au jalon 8 — l'event-driven d'ADR-009 permet d'ajouter un listener qui annule un reinforcement si un score qualité automatique est trop bas

---

*Décidé le 2026-05-14 en cours de jalon 4 (étape 6 MemoryRetriever), suite à audit `brain-charter-auditor` qui a flaggé l'absence d'ADR formel pour activer le mode A.*
