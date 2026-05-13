---
statut: en cours
ouvert: 2026-05-13
livré: —
---

# Jalon 4 — Retrieval Hebbien simple (spreading activation)

> Plan détaillé. Validé par démarrage du jalon le 2026-05-13.

## Progression (mise à jour pendant l'exécution)

*Fil de reprise en cas de compactage de contexte.*

- [x] Étape 1 : plan détaillé (ce document) + ADR-007 (amendé score cumulé) + ADR-008 (decay exponentiel) + **ADR-009** (saturation soft + events anti-emballement) ajouté en cours
- [x] Étape 2 : DTOs `RetrievalQuery` + `RetrievalResult` + `ScoredNeuron` + 14 tests
- [x] Étape 3 : `NeuronResolver` (dispatch par aire) — créé, en attente de commit
- [x] Étape 4 : `SeedExtractor` (embedding query + lookup similarité Vertex) — créé, en attente de commit
- [x] Étape 7 (faite avant 5-6) : `SynapseReinforcedEvent` + `HebbianReinforcer` + 9 tests — **saturation soft validée** par ADR-009
- [ ] Étape 5 : `SpreadingActivation` — BFS bornée par **score cumulé** (pas profondeur fixe, cf. ADR-007 amendé), hard cap 5 sauts
- [ ] Étape 6 : `MemoryRetriever` (orchestrateur SeedExtractor + SpreadingActivation + HebbianReinforcer)
- [ ] Étape 8 : Command `brain:query` (test sortie CLI)
- [ ] Étape 9 : `BrainContextSubscriber` (ENRICH pipeline)
- [ ] Étape 10 : Fixtures `retrieval-v1/`
- [ ] Étape 11 : Outil `brain:bench:retrieval`
- [ ] Étape 12 : Audits + bilan

**Notes de progression (anti-compactage)** :
- ADR-007 amendé en étape 2 sur proposition user (score cumulé > profondeur fixe)
- ADR-009 ajouté en étape 7 sur question user (anti-emballement → saturation soft + events)
- Étape 7 (HebbianReinforcer) traitée avant 5-6 car indépendante du SpreadingActivation et nécessaire pour MemoryRetriever
- Mémoires user ajoutées : `feedback_brain_event_driven_synapse_mutations`, `project_brain_v3_deep_test_in_weecom`, `feedback_brain_complexity_mit_level`, `project_brain_v3_corpus_could_exceed_weecom`
- Tests : 213 Brain tests OK, PHPStan 0 erreur, CS clean
- **Reprise** : continuer avec étape 5 (SpreadingActivation) — algorithme dans le plan §4.3, hard cap 5 sauts, decay 0.7^depth (ADR-008), arrêt si score < query.minScore

### ⚠️ Avant de coder SpreadingActivation — RELIRE OBLIGATOIREMENT

User a explicitement rappelé (2026-05-13) : *"la lecture fournie dans brain-v3-design.md peut peut-être éviter des erreurs que d'autres ont faites."* — le design figé contient des subtilités issues de la littérature (HeLa-Mem, Kairos, HippoRAG 2) qu'on risque de manquer si on code "à l'instinct".

**Sections à relire avant de coder SpreadingActivation** :
- `docs/brain-v3-design.md` §8 (Dynamique des synapses) — Hebbien classique, decay, consolidation, validation-gated
- `docs/brain-v3-design.md` §10 (Interaction LLM) — 4 modes de naissance des synapses (A=Hebbien implicite, B=LLM explicite, C=règle métier, D=extraction), spreading activation déterministe sans LLM
- Réfs §5 du design : *neurons that fire together wire together*, Kairos validation-gated, Tulving sémantique vs épisodique
- Charte §2.5 (« Comprendre avant d'intégrer ») : lire HeLa-Mem + Kairos OU au moins relire ce que le design en a tiré, AVANT de coder

**Pièges anticipés à éviter** :
1. Spreading activation ≠ retrieval vector. Le retrieval pur cosine est la **baseline**, pas la solution
2. Hebbien implicite doit être *gratuit* sur chaque retrieval (mode A design §10) — c'est ce que HebbianReinforcer fait après le retrieval
3. Le contexte LLM consomme **passivement** le retrieval (mode passif §10) — ne pas confondre avec un LLM qui modifie la mémoire pendant
4. Functional networks (mode contextuel) sont **hors-scope jalon 4** (jalon 6) — ne pas pré-câbler ici
5. Confidence ≠ weight (design §6) — Synapse porte les 2, le score les multiplie séparément

## 1. Capacité d'association visée

**Retrieval enrichi par spreading activation.** À partir d'une requête en langage naturel :

1. Extraction de **seeds** (neurones initialement candidats par similarité vector)
2. **Propagation** sur le graphe de synapses jusqu'à 2 sauts
3. **Scoring combiné** (poids synapse × distance × confidence × score initial seed)
4. Retour du **top-N** neurones, leur contexte, leurs synapses

C'est le **premier jalon où le brain remonte naturellement les associations** à partir d'une question, en utilisant son graphe Hebbien comme un humain remonterait ses souvenirs liés.

## 2. Test de sortie

```bash
# Requête en langage naturel
bin/console brain:query "problème ordinateur récent"
# → top 10 neurones avec scores, debug détaillé (seeds, sauts, synapses traversées)

# Bench comparatif sur fixtures annotées
bin/console brain:bench:retrieval --set=v1
# → F1@10 hebbian ≥ baseline vector simple sur 10 requêtes annotées
# → Recall@5 ≥ 0.6, Precision@5 ≥ 0.7
```

## 3. Pré-requis

- ✅ Jalon 3 livré (Synapses CORROBORATES auto via ConvergenceDetector)
- ✅ Garde-fou isolation user (ADR-006) — pour filtrer les voisins du graphe
- ✅ Corpus + embeddings réels disponibles (jalon 3 a calibré 20 sources)
- ⏳ ADR-007 (BFS depth) — à trancher
- ⏳ ADR-008 (formule de score) — à trancher

## 4. Surface à concevoir

### 4.1 DTOs

```php
final readonly class RetrievalQuery
{
    public function __construct(
        public string $text,            // requête en langage naturel
        public ?Uuid $ownerId,          // filtre isolation user (null = couche open)
        public int $topN = 10,          // nombre max de résultats
        public int $maxDepth = 2,       // profondeur BFS (cf. ADR-007)
        public float $minScore = 0.0,   // seuil de score minimal
    ) {}
}

final readonly class RetrievalResult
{
    /** @param list<ScoredNeuron> $neurons */
    public function __construct(
        public array $neurons,           // triés par score décroissant
        public array $debug = [],        // seeds, traversals, timing
    ) {}
}

final readonly class ScoredNeuron
{
    public function __construct(
        public MemoryFragment $neuron,
        public float $score,
        public int $depth,               // distance depuis le seed
        public ?Uuid $reachedVia,        // ID de la synapse traversée (ou null si seed)
    ) {}
}
```

### 4.2 `SeedExtractor`

Produit les neurones de départ (seeds) :
1. Génère l'embedding de la requête via `EmbeddingService` (purpose='brain_retrieval')
2. Cherche les neurones existants avec similarité cosine ≥ seuil bas (~0.4 — plus laxe que la convergence)
3. Filtre par owner (ADR-006)
4. Retourne top-K (K=5) seeds avec leur score initial = similarité cosine

Cherche **dans toutes les aires embeddables** (Episodic, Semantic, Encyclopedic), pas seulement une.

### 4.3 `SpreadingActivation`

Algorithme BFS bornée :

```
seeds = SeedExtractor.extract(query)
visited = {}     // neuron_id → ScoredNeuron
queue = [(seed, depth=0, parentScore=cosine) for seed in seeds]

while queue not empty:
    (current, depth, parentScore) = queue.dequeue()
    if current.id in visited:
        continue
    visited[current.id] = ScoredNeuron(current, parentScore, depth, ...)

    if depth >= maxDepth:
        continue

    outgoing = SynapseRepo.findOutgoing(current)
    for synapse in outgoing:
        if not isAdmissibleForOwner(synapse, queryOwner):
            continue
        targetNeuron = resolveNeuron(synapse.targetArea, synapse.targetId)
        if targetNeuron is null:
            continue

        // Score combiné (formule ADR-008)
        newScore = parentScore * synapse.weight * synapse.confidence * decayByDepth(depth+1)

        queue.enqueue((targetNeuron, depth+1, newScore))

return sortByScore(visited).take(topN)
```

### 4.4 `MemoryRetriever`

```php
final class MemoryRetriever
{
    public function retrieve(RetrievalQuery $q): RetrievalResult;
}
```

Orchestrateur : SeedExtractor → SpreadingActivation → tri → top-N.

### 4.5 `HebbianReinforcer`

Quand un retrieval est utilisé (un agent y répond), les synapses traversées entre neurones co-activés voient leur poids incrémenté légèrement. Pattern Hebbien classique : *fire together, wire together*.

```php
public function reinforce(RetrievalResult $result, float $delta = 0.05): void;
```

- Pour chaque paire de neurones dans le résultat : si une synapse existe entre eux, incrémenter son poids (clamp [0, 1])
- Pour chaque paire qui n'a pas de synapse mais qui co-apparaissent : **NE PAS** créer de synapse ici (le ConvergenceDetector du jalon 3 s'en charge à l'ingestion, pas au retrieval)

### 4.6 Resolution polymorphe des neurones

Problème : `Synapse` stocke `(area, neuron_id)`. Pour obtenir le neurone effectif, il faut un service de résolution :

```php
final readonly class NeuronResolver
{
    public function resolve(BrainArea $area, Uuid $neuronId): ?MemoryFragment;
}
```

Dispatch par aire vers le bon repository.

### 4.7 Command `brain:query`

```bash
bin/console brain:query "ma requête" [--owner=<uuid>] [--top=10] [--depth=2]
```

Sort un tableau avec :
- Score
- Aire
- UUID neurone
- Extrait textuel
- Synapse traversée (UUID) + son poids

### 4.8 `BrainContextSubscriber`

Subscriber sur `PromptEnrichEvent` (cf. `packages/core/docs/explanation/architecture.md` §"Phase 2 ENRICH") :

1. Récupère la dernière requête utilisateur
2. Lance `MemoryRetriever::retrieve()`
3. Injecte les neurones top-N dans le contexte LLM (format markdown lisible)
4. Remplace progressivement `MemoryContextSubscriber` + `RagContextSubscriber`

Priorité subscriber : avant `ContextTruncationSubscriber` (pour que le truncate puisse gérer la taille si nécessaire).

## 5. Étapes d'implémentation

| # | Étape | Test associé | Commit |
|---|---|---|---|
| 1 | Plan + ADR-007 (BFS depth) + ADR-008 (formule score) | — | `docs(brain): plan jalon 4 + ADR-007/008` |
| 2 | DTOs RetrievalQuery + RetrievalResult + ScoredNeuron | tests basiques | `feat(brain): DTOs retrieval` |
| 3 | NeuronResolver (dispatch par aire) | unit | `feat(brain): NeuronResolver polymorphe` |
| 4 | SeedExtractor (embedding query + lookup similarité bas) | unit avec stubs | `feat(brain): SeedExtractor` |
| 5 | SpreadingActivation (BFS bornée + score combiné) | unit (graphe synthétique) | `feat(brain): SpreadingActivation BFS` |
| 6 | MemoryRetriever (orchestrateur) | unit | `feat(brain): MemoryRetriever` |
| 7 | HebbianReinforcer (renforcement co-activation) | unit | `feat(brain): HebbianReinforcer` |
| 8 | Command `brain:query` | smoke manual | `feat(brain): command brain:query` |
| 9 | BrainContextSubscriber (intégration pipeline ENRICH) | unit + intégration | `feat(brain): BrainContextSubscriber (ENRICH)` |
| 10 | Fixtures retrieval-v1 (10 requêtes annotées) | — | `feat(brain): fixtures retrieval-v1` |
| 11 | Outil `brain:bench:retrieval` + bench baseline vs hebbian | bench rejouable | `feat(brain): bench retrieval` |
| 12 | Audits + fixes + bilan | — | `docs(brain): bilan jalon 4` |

## 6. Tests & dogfooding

### Tests PHPUnit
- `RetrievalQuery/ResultTest` : immutabilité, validations
- `SeedExtractorTest` : stub EmbeddingService + repository, vérifie filtrage owner
- `SpreadingActivationTest` : graphe synthétique 5 neurones + 4 synapses, vérifie ordre BFS et scores
- `MemoryRetrieverTest` : end-to-end avec stubs
- `HebbianReinforcerTest` : delta + clamp [0,1]
- `NeuronResolverTest` : dispatch par aire

### Validation qualité (charte §2.9)

Sur fixtures `retrieval-v1/` :
- 10 requêtes en langage naturel ("problème ordinateur récent", "facturation imprimante", "événement Alice", etc.)
- Pour chaque : annotation des 3-5 neurones attendus dans le top-10
- Métriques :
  - **Recall@5** : combien des neurones attendus sont dans le top-5
  - **Precision@5** : combien des top-5 sont des neurones pertinents
  - **F1@10** : harmonique sur top-10
- **Comparaison baseline** : retrieval par similarité vector pure (sans spreading) vs retrieval Hebbian

Objectif : F1@10 hebbian ≥ baseline vector + 0.10 (10 points d'avance sur la base).

Si l'écart est faible (< 0.05) → le spreading activation n'apporte pas grand-chose à 1 saut. Réfléchir à la valeur de cette dimension (peut-être que le jalon 5 — polarity — aura plus d'impact).

## 7. Décisions ouvertes

- **ADR-007** : profondeur BFS — 2 ou 3 sauts par défaut ? Configurable ?
  - 2 sauts = explosion combinatoire raisonnable (~100-500 neurones touchés), suffisant pour la majorité des associations
  - 3 sauts = plus profond mais risque de bruit (associations lointaines)
  - Décision orientée : **2 sauts par défaut, configurable** via `RetrievalQuery::$maxDepth`
- **ADR-008** : formule de score combiné — linéaire ou log ?
  - Linéaire : `parentScore × synapse.weight × synapse.confidence × decay^depth`
  - Log : `log(parentScore + 1) × log(weight + 1) × ...` (lisse les valeurs faibles)
  - Décision orientée : **linéaire** au jalon 4, décroissance exponentielle par saut (`decay = 0.7^depth`). Re-calibrer au jalon 6 (functional networks pondèrent)
- **ADR-009 (potentiel)** : reinforcement automatique de toutes les synapses d'un retrieval, ou seulement quand l'agent valide la réponse ?
  - Décision orientée : **automatique au jalon 4** (mode A du design §10). Validation-gated reporté au jalon 8

## 8. Hors-scope

- **Polarity inhibitoire** dans le score → jalon 5
- **Functional networks** pour pondérer le score selon le contexte → jalon 6
- **Validation-gated learning** (reinforcement conditionné) → jalon 8
- **Recherche par trigger pattern** sur Procedural → reportée (les ganglions ne participent pas au spreading activation classique)
- **Aires Emotional/Sensory/Motor** → pas activées au jalon 4

## 9. Bilan

*À remplir à la fin du jalon.*
