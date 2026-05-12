---
statut: en cours
ouvert: 2026-05-12
livré: —
---

# Jalon 3 — Ingestion multi-aires + convergence

> Plan validé par le user 2026-05-13. En cours d'exécution.

## Progression (mise à jour pendant l'exécution)

*Fil de reprise en cas de compactage de contexte. Inversions par rapport au plan §5 signalées explicitement.*

- [x] Étape 1 : docker compose test PostgreSQL + pgvector (`tests/Integration/Brain/`)
- [x] Étape 2 : ADR-005 (cosine, placeholder calibration) + ADR-006 (isolation user, accepté)
- [x] Étape 3 : `MultiAreaExtractorInterface` + tests
- [x] Étape 4 : Resources prompts multi-aire (extract-multi-area.md + schema avec 4 aires)
- [x] Étape 5 (faite avant 4 — voir note ci-après) : `ProceduralNeuron` + repository + tests + migration SQL jalon-3/001
- [x] Étape 6 : Garde-fou isolation user dans `Synapse::__construct` + migration SQL jalon-3/002
- [x] Étape 7 : `OnePassMultiAreaExtractor` (classe standalone, implémente `MultiAreaExtractorInterface`)
- [x] Étape 8 : `ConvergenceDetector` + tests (similarité cosine, garde-fou isolation user, synapse auto, log warning cross-user)
- [x] Étape 9 : `MemoryExtractor::extractAll()` + tests multi-aires + DI explicite multi-area extractor
- [x] Étape 12 : `brain:ingest:test --multi-area`
- [x] **Audits sous-agents + fixes mineurs** : anti-anthropomorphisation, log warning, HEBBIAN_LEARNING_RATE const, DI explicite OnePassMultiAreaExtractor, PHPDoc named-args-only sur Synapse
- [ ] **Étape 10** ⏸️ *reportée* : Annotations en aveugle 15-20 paires sources weecom + fixtures `tests/Brain/Quality/Fixtures/convergence-v1/` — nécessite session dédiée avec LLM réel (credentials weecom) + BDD Postgres+pgvector active
- [ ] **Étape 11** ⏸️ *reportée* : Outil `brain:bench:convergence` + calibration 3 sets cosine + ADR-005 finalisé chiffré — dépend de l'étape 10
- [ ] **Étape 13** : Bilan jalon 3 final (après livraison étapes 10-11)

**État au 2026-05-13 :** 10/13 étapes livrées (toute la couche code/infra/contrats). Les 3 étapes restantes (10-11-13) constituent la **validation expérimentale qualité** prévue par la charte §2.9. Reportées à une session dédiée car nécessitant LLM réel et travail d'annotation manuelle.

**Note inversion étapes 4/5 :** ProceduralNeuron créé avant le prompt multi-aire pour que celui-ci puisse référencer les 4 aires (Semantic + Episodic + Encyclopedic + Procedural) d'emblée. Pas d'impact fonctionnel.

## 1. Capacité d'association visée

**Convergence mémorielle** (design §9). Deux sources différentes produisent des neurones similaires dans la même aire (embeddings proches) → détectés et marqués comme corroborants. Premier jalon où le brain produit des **synapses automatiquement** sur la base d'une mesure objective.

Pré-conditions structurantes pour cette capacité :
1. Mode **"1 passe unique"** multi-aires opérationnel (décision orientée du jalon 2)
2. Garde-fou **isolation user** sur les synapses (cf. `feedback-brain-user-isolation`)
3. Validation expérimentale **obligatoire** (charte §2.9, premier jalon concerné)

## 2. Test de sortie

```bash
# Ingestion d'une source qui active plusieurs aires en une passe
bin/console brain:ingest:test --source-id=<uuid> --multi-area
# → produit ≥2 neurones répartis dans ≥2 aires différentes

# Bench de convergence sur fixtures versionnées
bin/console brain:bench:convergence --set=v1-baseline
# → précision convergence ≥ 0.7, rappel ≥ 0.6 (fixtures annotées)

# Garde-fou isolation user (vérification dédiée)
bin/console brain:bench:user-isolation
# → 100% des synapses cross-user sont rejetées ou logguées
```

## 3. Pré-requis

- ✅ Jalon 2 livré (extracteurs mono-aire, refacto AbstractLlmExtractor et MemoryFragmentSerializer)
- ⏳ BDD test PostgreSQL + pgvector (cf. `feedback-brain-test-db-postgres-vector`)
- ⏳ Premier set de fixtures annotées manuellement dans `tests/Brain/Quality/Fixtures/convergence-v1/`

## 4. Surface à concevoir

### 4.1 Nouveau contrat multi-aires

```php
namespace ArnaudMoncondhuy\SynapseCore\Brain\Service\Extractor;

interface MultiAreaExtractorInterface
{
    /**
     * Une seule passe LLM produit des neurones dans plusieurs aires.
     *
     * @return list<ExtractionResult>  Un résultat par aire (vide si rien d'extrait pour cette aire)
     */
    public function extractAll(MemorySource $source): array;
}
```

### 4.2 Implémentation `OnePassMultiAreaExtractor`

Hérite de `AbstractLlmExtractor` mais avec un nouveau prompt et un nouveau schema :

- `Resources/brain/prompts/extract-multi-area.md` : prompt qui décrit les 7 aires + sélectivité naturelle ("vide autorisé par aire")
- `Resources/brain/prompts/extract-multi-area.schema.json` : objet avec une clé par aire (`facts: [...]`, `episode: ... | null`, `procedural: ... | null`, etc.)
- `buildNeurons()` parse l'objet retourné et construit les neurones de chaque aire

L'extracteur retourne **plusieurs** `ExtractionResult` (un par aire). MemoryExtractor expose une nouvelle méthode `extractAll(source)` qui appelle le multi-area extractor en priorité.

Les extracteurs mono-aire du jalon 2 restent disponibles pour les apps hôtes qui préfèrent contrôler le routage par aire.

### 4.3 Nouvelle aire activée : `ProceduralNeuron` (ganglions de la base)

Workflow détecté dans la source si présent. Champs : `name`, `triggerPattern` (JSONB), `steps` (JSONB), `conditions`, `successRate`, `executionCount`.

Pas d'embedding (recherche par trigger pattern, pas par similarité).

### 4.4 `Brain\Service\ConvergenceDetector`

```php
final readonly class ConvergenceDetector
{
    /**
     * Cherche des neurones existants sémantiquement proches du candidat.
     * Si trouvés, crée des synapses CORROBORATES auto.
     *
     * @return list<Synapse>  Synapses créées (peut être vide)
     */
    public function detectAndLink(MemoryFragment $candidate, float $cosineThreshold = 0.85): array;
}
```

Algorithme (jalon 3 mono-aire) :
1. Récupère les neurones existants de la **même aire** que `$candidate`
2. Filtre par **owner_id** (isolation user — cf. §4.6)
3. Calcule similarité cosine entre embeddings
4. Pour ceux qui dépassent `$cosineThreshold` → crée une `Synapse` CORROBORATES (edge_type=ASSOCIATION) auto + incrémente `evidenceCount` côté `SemanticNeuron` si même fait

### 4.5 Calibration du seuil cosine (ADR-005)

À mesurer empiriquement (cf. [07-calibration.md](../07-calibration.md)) :
- 3 sets : `v1-cosine-075`, `v1-cosine-085`, `v1-cosine-095`
- Métriques : précision, rappel, F1 sur fixtures annotées
- ADR-005 sera écrit au moment du choix avec les chiffres dans la section "Décision"

### 4.6 Garde-fou isolation user (ADR-006)

**Bloquant** pour la création de Synapse (cf. `feedback-brain-user-isolation`).

Règle d'admissibilité :
| Source neuron owner | Target neuron owner | Admissible |
|---|---|---|
| user X | user X | ✅ |
| user X | open (null) | ✅ |
| open (null) | open (null) | ✅ |
| user X | user Y (X ≠ Y) | ❌ exception |

Implémentation choisie (à confirmer par ADR-006) : **assertion en code** dans `Synapse::__construct` qui throw si les owners diffèrent et sont tous deux non-null. La FK polymorphe rend un trigger SQL plus complexe — l'assertion en code suffit si **toute** création de Synapse passe par le constructeur (à vérifier).

### 4.7 Outil bench `brain:bench:convergence`

```bash
bin/console brain:bench:convergence --set=v1-baseline --output=report.md
```

Charge le set (fichier YAML), ingère le corpus de fixtures versionné, exécute la convergence, compare aux annotations manuelles, calcule précision/rappel/F1, produit un rapport markdown.

### 4.8 Fixtures de régression

`tests/Brain/Quality/Fixtures/convergence-v1/` :
- `corpus.jsonl` : 15-20 MemorySource versionnées (extraites de weecom, anonymisées si besoin)
- `expected-convergences.json` : annotations manuelles (paires de neurones attendues comme convergentes)
- `metrics-baseline.json` : seuils minimums (précision, rappel, F1) pour ne pas régresser

## 5. Étapes d'implémentation

| # | Étape | Test associé | Commit |
|---|---|---|---|
| 1 | Plan détaillé + ADR-005 (placeholder calibration) + ADR-006 (isolation user) | — | `docs(brain): plan jalon 3 + ADR-005/006` |
| 2 | Contrat `MultiAreaExtractorInterface` + DTO | `MultiAreaExtractorInterfaceTest` | `feat(brain): contrat MultiAreaExtractorInterface` |
| 3 | Resources prompts multi-aire | — | `feat(brain): prompts extracteur multi-aires` |
| 4 | `OnePassMultiAreaExtractor` + tests (3 aires actives) | `OnePassMultiAreaExtractorTest` | `feat(brain): OnePassMultiAreaExtractor (1 passe LLM)` |
| 5 | Entité `ProceduralNeuron` + repository + tests + migration SQL | `ProceduralNeuronTest` | `feat(brain): ProceduralNeuron (ganglions de la base)` |
| 6 | `Synapse::__construct` : garde-fou isolation user + tests | `SynapseUserIsolationTest` | `feat(brain): garde-fou isolation user sur Synapse` |
| 7 | `ConvergenceDetector` + tests (similarité cosine pure, mocks BDD) | `ConvergenceDetectorTest` | `feat(brain): ConvergenceDetector (similarité cosine + synapse auto)` |
| 8 | `MemoryExtractor::extractAll` + tests | `MemoryExtractorMultiAreaTest` | `feat(brain): MemoryExtractor.extractAll dispatch multi-aires` |
| 9 | Fixtures de qualité + outil `brain:bench:convergence` | smoke test manuel | `feat(brain): fixtures + bench convergence v1` |
| 10 | Calibration : générer 3 sets, comparer, écrire ADR-005 final | bench rejouable | `feat(brain): calibration cosine v1 (seuil 0.X retenu)` |
| 11 | Mise à jour `brain:ingest:test --multi-area` | smoke test | `feat(brain): brain:ingest:test --multi-area` |
| 12 | Audits + bilan | — | `docs(brain): bilan jalon 3` |

## 6. Tests & dogfooding

### Tests PHPUnit

Tous mocks/stubs comme au jalon 2 (ChatService, repositories Doctrine), pas de BDD réelle pour l'unit testing.

### **Validation qualité synapses (charte §2.9 — premier jalon concerné)**

- **Échantillon** : 15-20 sources réelles weecom annotées (cf. §4.8)
- **Métriques minimales** :
  - Précision convergence ≥ 0.7
  - Rappel convergence ≥ 0.6
  - F1 ≥ 0.65
- **Régression bloquante** : si F1 baisse vs jalon précédent, c'est bloquant (cf. méthodologie §validation-qualité)

### **Tests d'intégration BDD** (premiers du chantier)

Nouveau : `tests/Integration/Brain/`. Nécessite PostgreSQL + pgvector lancé localement (Docker compose). Marqués `@group integration`. Pas dans `composer test` par défaut, mais dans `composer test:integration` à créer.

## 7. Décisions ouvertes

- **ADR-005** : seuil cosine retenu pour la convergence (à calibrer avant ADR finalisé)
- **ADR-006** : garde-fou isolation user — assertion code uniquement, ou code + trigger SQL ?
- **ADR-007 (potentiel)** : si la calibration montre que le seuil cosine seul est insuffisant, faut-il ajouter un filtre LLM-as-judge pour la convergence (coûteux mais précis) ? Reporté tant que les chiffres ne le justifient pas.

## 8. Hors-scope

- Spreading activation (jalon 4)
- Polarity et relation_type fines au-delà du CORROBORATES auto (jalon 5)
- Functional networks (jalon 6)
- Aire Emotional / Sensory / Motor (jalon 7)

## 9. Bilan

*À remplir à la fin du jalon. Sections obligatoires : capacité livrée, coût, surprises, ADRs créés, audits, validation qualité (précision/rappel/F1 mesurés sur fixtures), go/no-go jalon 4.*
