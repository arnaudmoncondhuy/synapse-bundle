# Rapport de session nocturne — 2026-05-12

> Session autonome reprenant après le départ du user vers 21h. Objectif : avancer
> le jalon 2 (et au-delà si possible) sans bloquer sur des questions.

## TL;DR

- ✅ **Jalon 2 (Ingestion mono-aire) livré** — 13 commits + 6 fixes audits + 3 refactos pré-jalon 3
- ✅ **Plan détaillé du jalon 3** préparé (12 étapes, fixtures de qualité prévues, garde-fou isolation user formalisé)
- ⏸️ **Pas démarré l'implémentation du jalon 3** — nécessite ta validation du plan + BDD test PostgreSQL+pgvector
- 📊 **137 tests Brain, 267 assertions, check.sh OK** (CS-Fixer, PHPStan, PHPUnit, YAML, Twig, Deptrac)
- 🚫 **Pas de push** (AGENTS.md — ton feu vert requis)

## État du repo

Branche `brain`, **34 commits non poussés** depuis `origin/brain`. Tout est local.

```
git log --oneline brain ^origin/brain
```

Récap commits depuis ton départ :

```
6d92573 refactor(brain): MemoryExtractor — warning collision + cache supportedAreas
44466a3 refactor(brain): MemoryFragmentSerializer typé remplace reflection
5a21710 refactor(brain): AbstractLlmExtractor factorise SemanticExtractor + EpisodicExtractor
bacaa08 docs(brain): bilan jalon 2 livré
f42c6e0 fix(brain): mineurs audits jalon 2 (clamp confidence, mapping accounting, etc.)
a3407c6 feat(brain): outil bench extract-corpus weecom
e310cdf feat(brain): migration jalon 2 (encyclopedic neuron)
2c26ae0 feat(brain): command brain:ingest:test
9f4d695 feat(brain): MemoryExtractor (orchestrateur mono-aire)
86a98be feat(brain): EncyclopedicExtractor (chunking + embedding)
153ba04 feat(brain): EpisodicExtractor (extraction événement via LLM)
93de8c3 feat(brain): SemanticExtractor (extraction faits via LLM)
baa8080 feat(brain): prompts extracteurs sémantique + épisodique
ab4dd02 feat(brain): contrat NeuronExtractorInterface + DTO ExtractionResult
c63d3f3 feat(brain): ajoute neurone encyclopédique (cortex temporal)
1cfdd1e docs(brain): plan jalon 2 détaillé + ADR-003/004
```

## Ce qui a été livré au jalon 2

### Code

- **`EncyclopedicNeuron`** (3ème aire — cortex temporal) + repository + tests
- **3 extracteurs** :
  - `SemanticExtractor` : LLM + structured output → faits subject-predicate-value
  - `EpisodicExtractor` : LLM + structured output → événement situé (0 ou 1)
  - `EncyclopedicExtractor` : pas de LLM extraction, chunking + embedding réutilisés
- **`MemoryExtractor`** : orchestrateur mono-aire, dispatch par aire via `!tagged_iterator`
- **`AbstractLlmExtractor`** (refacto pré-jalon 3) : squelette commun pour `Semantic` + `Episodic`
- **`MemoryFragmentSerializer`** (refacto pré-jalon 3) : visiteur typé remplaçant la reflection
- **`ExtractionFailedException`** : exception typée portant source + area
- **`brain:ingest:test`** : command de test de sortie (avec `--persist` optionnel)
- **`tools/brain-bench/extract-corpus.php`** : adaptateur côté hôte qui transforme les JSON Pipedrive en `MemorySource` agnostiques

### Resources

- `Resources/brain/prompts/extract-semantic.md` + `.schema.json`
- `Resources/brain/prompts/extract-episodic.md` + `.schema.json`
- Vocabulaire **strictement agnostique** (charte §2.1) avec instruction explicite de sélectivité ("droit et devoir de retourner [] / null si rien d'extractible")

### Migration

- `migrations-brain-v3/jalon-2/001_brain_jalon_2_encyclopedic.sql` (PostgreSQL + JSONB)

### Documentation

- Plan détaillé `jalon-2-ingestion-mono-aire.md` (statut "livré")
- 2 ADRs validés : ADR-003 (structured output) + ADR-004 (prompts en Resources)
- Bilan complet du jalon 2 (capacité, coût, ADRs, audits, apprentissages, go/no-go jalon 3)

### Tests

Passage de **77 → 137 tests Brain** (+60 tests, +145 assertions). Coverage des chemins nominaux + dégradés (LLM ko, JSON malformé, types invalides, collisions, isolation, etc.).

## Audits faits

J'ai exécuté les 2 sous-agents `brain-charter-auditor` et `brain-code-reviewer` via `general-purpose` (les sous-agents `.claude/agents/brain-*.md` ne sont chargés qu'au démarrage de session).

**Résultats** : 0 bloquant, 0 majeur, 12 mineurs.

**Fixes appliqués cette nuit** :
1. Vocabulaire métier dans PHPDoc (charter)
2. Framing concurrentiel sur SynapsePolarity (charter)
3. Clamp `confidence` dans [0, 1] (code reviewer)
4. `rag_indexation` → `brain_encyclopedic` + mapping `EmbeddingUsageListener` (code reviewer)
5. `match` default explicit dans extract-corpus.php (code reviewer)
6. Commentaire FK source_uuid sur EncyclopedicNeuron (cohérence intra-jalon)
7. PHPDoc explicite pour `receivedAt` omis dans SemanticExtractor
8. Reformulation "le brain commence à lire" → "le bundle dispose d'une boucle"

**Refactos pré-jalon 3 appliquées cette nuit** (audit code reviewer points a, d, e, f) :
9. `AbstractLlmExtractor` factorise les 2 extracteurs LLM (-26% à -35% LOC)
10. `MemoryFragmentSerializer` visiteur typé remplace la reflection
11. `MemoryExtractor` : warning collision + cache `supportedAreas()`

**Reporté au jalon 3** :
- Convention `webhook_pipedrive_*` à aligner ou documenter (charter mineur)

## Décisions prises seul (à valider / challenger demain)

1. **Mode mono-aire au jalon 2** (3 extracteurs séparés au lieu de l'orchestrateur "1 passe unique") — ce sera le jalon 3. J'ai gardé l'option ouverte via `MultiAreaExtractorInterface` à ajouter au jalon 3.
2. **PostgreSQL test DB** non montée cette nuit — je n'avais pas besoin pour le jalon 2 (tests unitaires uniquement). Le jalon 3 en aura besoin (premier jalon avec qualité mesurée).
3. **Format payload `MemorySource`** : `text`, `document_ref`, `metadata` comme conventions douces (pas dans le contrat de `MemorySource`, juste dans la doc de `EncyclopedicExtractor`). À durcir au jalon 3 si on veut un contrat typé.

## Préparation jalon 3 (à valider demain)

J'ai détaillé le plan dans [06-phases/jalon-3-ingestion-multi-aires.md](06-phases/jalon-3-ingestion-multi-aires.md). Statut : "à valider (plan détaillé)".

**Surface visée** :
- Mode "1 passe unique" multi-aires (`OnePassMultiAreaExtractor`)
- `ProceduralNeuron` (4ème aire activée — ganglions)
- `ConvergenceDetector` (similarité cosine + synapse CORROBORATES auto)
- Garde-fou isolation user dans `Synapse::__construct`
- Outil `brain:bench:convergence` + fixtures de qualité versionnées
- **Première vraie validation qualité** (charte §2.9)
- **Première calibration** (charte §2.10) : 3 sets de seuil cosine comparés

**Pré-requis avant lancement** :
1. Ta validation du plan jalon 3
2. PostgreSQL + pgvector lancés (Docker compose ?). Si tu veux, je peux préparer un `docker/compose.test.yaml` au début de la session de demain.
3. Annotation manuelle de l'échantillon weecom pour les fixtures (15-20 sources annotées) — c'est toi qui dois faire les annotations, je peux juste te préparer un format / outil pour faciliter

## Reprise demain

Quand tu reviens, lance :

```bash
git log --oneline brain ^origin/brain | head -20
```

Pour voir tous les commits de la nuit. Puis lis :
1. [Bilan jalon 2](06-phases/jalon-2-ingestion-mono-aire.md#10-bilan-livré-2026-05-12)
2. [Plan jalon 3 détaillé](06-phases/jalon-3-ingestion-multi-aires.md)

Décisions attendues :
- ✅ ou ❌ sur le plan jalon 3
- BDD test : on monte un docker compose dédié ? ou tu en as déjà un que je peux réutiliser ?
- On annote l'échantillon weecom ensemble (paire-programming) ou tu le fais en off ?
- Push sur `origin/brain` quand tu valides ?

## Quelques chiffres

| Métrique | Jalon 1 (fin) | Jalon 2 (fin) | Delta |
|---|---|---|---|
| Tests Brain | 77 | 137 | +60 |
| Assertions Brain | 121 | 267 | +146 |
| Tests bundle total | 1011 | 1021 | +10 |
| Commits sur branche brain | 17 | 34 | +17 |
| LOC ajoutées (estim.) | ~2350 | ~5800 | +3450 |
| ADRs acceptés | 2 | 4 | +2 (ADR-003 + ADR-004) |
| Aires Brain activées | 2 | 3 | +1 (Encyclopedic) |
| Extracteurs | 0 | 3 | +3 |

`check.sh` complet OK sur les 12 étapes (CS-Fixer, PHPStan, PHPUnit, YAML, Twig, Deptrac).

---

*Rapport généré à la fin de la session autonome. Bon réveil ! 🌅*
