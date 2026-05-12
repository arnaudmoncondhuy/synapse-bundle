---
statut: en cours
ouvert: 2026-05-12
livré: —
---

# Jalon 2 — Ingestion mono-aire

> Plan détaillé après affinement nocturne 2026-05-12.

## 1. Capacité d'association visée

**Aucune encore** (toujours infra). Mais on franchit un cap qualitatif : on a maintenant un **MemoryExtractor** qui prend une `MemorySource` brute et produit des neurones via appel LLM. Le brain commence à *lire* le monde extérieur.

## 2. Test de sortie

```bash
# Ingestion sémantique (extrait des faits subject-predicate-value)
bin/console brain:ingest:test --source-id=<uuid> --area=semantic
# → N neurones sémantiques persistés, liés à la source d'origine

# Ingestion épisodique (extrait un événement situé)
bin/console brain:ingest:test --source-id=<uuid> --area=episodic
# → 1 neurone épisodique persisté (ou 0 si la source ne contient pas d'événement)

# Ingestion encyclopédique (chunke un document)
bin/console brain:ingest:test --source-id=<uuid> --area=encyclopedic
# → N neurones encyclopédiques (chunks vectorisés), liés à la source

# Tests unitaires
composer test -- --filter='Brain\\(Extractor|Service\\\\MemoryExtractor)'
# → tous verts, ChatService mocké
```

La command nécessite une **vraie configuration LLM** côté app hôte (provider/preset). Les tests unitaires utilisent un stub de `ChatService`.

## 3. Pré-requis

- ✅ Jalon 1 livré
- [ADR-003](../05-decisions/003-extracteur-llm-structured-output.md) — choix structured output via ChatService
- [ADR-004](../05-decisions/004-prompts-en-resources-files.md) — prompts versionnés dans `Resources/brain/prompts/`

## 4. Surface à concevoir

### 4.1 Nouvelle entité (3ème aire activée)

`Storage/Entity/Brain/Neuron/EncyclopedicNeuron` (cortex temporal) — document chunké. Table `brain_neuron_encyclopedic` avec `chunk_index`, `chunk_content`, `embedding`, `document_ref`, `doc_metadata`. Absorbe la sémantique de l'actuel `SynapseRagDocument` (qui sera retiré au jalon 8 quand toutes les apps consommatrices auront migré).

### 4.2 Contrats et DTOs (Brain/Extractor)

```php
namespace ArnaudMoncondhuy\SynapseCore\Brain\Service\Extractor;

interface NeuronExtractorInterface
{
    /**
     * Aires que cet extracteur peut produire.
     * @return list<BrainArea>
     */
    public function supportedAreas(): array;

    public function extract(MemorySource $source, BrainArea $targetArea): ExtractionResult;
}

final readonly class ExtractionResult
{
    /**
     * @param list<MemoryFragment> $neurons  neurones extraits (peut être vide)
     * @param array<string, mixed> $debug     trace pour audit / debug
     */
    public function __construct(
        public BrainArea $area,
        public array $neurons,
        public array $debug = [],
    ) {}
}
```

### 4.3 Trois extracteurs spécialisés (mono-aire)

Au jalon 2, **3 extracteurs séparés** (pas encore l'orchestrateur "1 passe unique" du jalon 3). Chacun fait 1 appel LLM avec un prompt et un structured_output dédiés :

- `SemanticExtractor` → produit `list<SemanticNeuron>` (faits subject-predicate-value)
- `EpisodicExtractor` → produit `list<EpisodicNeuron>` (souvent 1, parfois 0)
- `EncyclopedicExtractor` → ne fait **pas** d'appel LLM extraction (utilise `ChunkingService` + `EmbeddingService` existants pour découper un document en chunks vectorisés). C'est l'absorption naturelle du RAG.

### 4.4 Orchestrateur

`Brain\Service\MemoryExtractor` — dispatch vers l'extracteur de l'aire demandée. Au jalon 2 : 1 aire à la fois. Au jalon 3 : on remplacera ce dispatch par un seul extracteur multi-aires (1 passe unique).

```php
$extractor = $this->memoryExtractor->extract($source, BrainArea::Semantic);
// → ExtractionResult avec list<SemanticNeuron>
```

### 4.5 Prompts versionnés

`packages/core/src/Resources/brain/prompts/` :
- `extract-semantic.md` : prompt pour SemanticExtractor + JSON schema attendu
- `extract-episodic.md` : prompt pour EpisodicExtractor + JSON schema

Chaque fichier contient : description rôle + format de sortie (JSON schema embedded), exemples (few-shot court), instruction explicite "tu as le droit de retourner un tableau vide si la source ne contient rien de pertinent" (anti biais de complétion).

### 4.6 Command de test

`Brain\Command\BrainIngestTestCommand` (`brain:ingest:test`) qui :
1. Charge une `MemorySource` par UUID
2. Appelle `MemoryExtractor::extract($source, $area)`
3. Affiche le résultat + persiste si `--persist` (défaut: dry-run)

## 5. Étapes d'implémentation

| # | Étape | Test associé | Commit |
|---|---|---|---|
| 1 | ADR-003 et ADR-004 + plan affiné | — | `docs(brain): plan jalon 2 + ADR-003/004` |
| 2 | Entité `EncyclopedicNeuron` + repository + tests | `EncyclopedicNeuronTest` | `feat(brain): ajoute neurone encyclopédique (cortex temporal)` |
| 3 | DTO `ExtractionResult` + interface `NeuronExtractorInterface` + tests | `ExtractionResultTest` | `feat(brain): contrat NeuronExtractorInterface + DTO ExtractionResult` |
| 4 | Resources prompts (`extract-semantic.md`, `extract-episodic.md`) | — | `feat(brain): prompts extracteurs sémantique + épisodique` |
| 5 | `SemanticExtractor` + tests (ChatService stubbé) | `SemanticExtractorTest` | `feat(brain): SemanticExtractor (extraction faits via LLM)` |
| 6 | `EpisodicExtractor` + tests (ChatService stubbé) | `EpisodicExtractorTest` | `feat(brain): EpisodicExtractor (extraction événement via LLM)` |
| 7 | `EncyclopedicExtractor` + tests (ChunkingService + EmbeddingService) | `EncyclopedicExtractorTest` | `feat(brain): EncyclopedicExtractor (chunking + embedding)` |
| 8 | `MemoryExtractor` orchestrateur + tests | `MemoryExtractorTest` | `feat(brain): MemoryExtractor (orchestrateur mono-aire)` |
| 9 | Command `brain:ingest:test` | smoke test à la main | `feat(brain): command brain:ingest:test` |
| 10 | Migration SQL `migrations-brain-v3/jalon-2/` | — | `feat(brain): migration jalon 2 (encyclopedic neuron)` |
| 11 | Outil `tools/brain-bench/extract-corpus.php` (lecture weecom) | — | `feat(brain): outil bench extract-corpus weecom` |
| 12 | Audits + corrections + bilan | — | `docs(brain): bilan jalon 2 + ...` |

## 6. Tests & dogfooding

### Tests PHPUnit

- Tests unitaires pour chaque extracteur, ChatService mocké via `createStub` qui retourne un `structured_output` synthétique
- Tests d'intégration : aucun au jalon 2 (besoin d'une vraie clé LLM, hors scope unitaire). Marqués `@group integration-llm` si on en écrit, skippés par défaut

### Dogfooding manuel

- `bin/console brain:ingest:test` sur une source synthétique manuelle pour valider end-to-end (le user lance avec sa config LLM)
- Pas de mesure de qualité quantitative au jalon 2 (premier vrai mesure : jalon 3, charte §2.9)

## 7. Décisions tranchées (cf. ADRs)

| Question | Réponse | ADR |
|---|---|---|
| Modèle LLM extracteur ? | Laissé au choix de l'app hôte (preset configurable). On utilise `ChatService::ask` qui résout le preset au runtime | n/a (déjà l'architecture) |
| Prompt en code ou en BDD ? | **En fichiers `Resources/brain/prompts/`** au jalon 2. BDD reportée à un jalon ultérieur si A/B testing | [ADR-004](../05-decisions/004-prompts-en-resources-files.md) |
| Format de sortie LLM ? | **Structured output (JSON schema)** via option `structured_output` de ChatService::ask | [ADR-003](../05-decisions/003-extracteur-llm-structured-output.md) |
| Stratégie d'appel LLM ? | 3 extracteurs spécialisés au jalon 2 (mono-aire). 1 passe unique multi-aires au jalon 3 (déjà acté dans le plan jalon 2 par le user) | n/a |
| Gestion d'erreur LLM ? | Exception typée `ExtractionFailedException`. La source brute reste immutable, l'extraction peut être rejouée plus tard (RevectorizeCommand pattern) | n/a |

## 8. Hors-scope

- Multi-aires en une seule passe LLM → **jalon 3** (avec orchestration 1 passe unique + convergence mémorielle)
- Procédural / Émotionnel / Sensoriel / Moteur → **jalon 3+** (les 5 aires restantes après les 3 du jalon 2)
- Spreading activation → **jalon 4**
- Validation qualité quantitative → **dès jalon 3** (premier qui produit des associations)
- Calibration des hyper-paramètres extracteurs (température, top_p) → **jalon 3** (premier vrai cas où ça compte)

## 9. Progression (mise à jour pendant l'exécution)

*Cette section sert de fil de reprise en cas de compactage de contexte.*

- [ ] Étape 1 : plan + ADRs
- [ ] Étape 2 : EncyclopedicNeuron
- [ ] Étape 3 : NeuronExtractorInterface + ExtractionResult
- [ ] Étape 4 : Resources prompts
- [ ] Étape 5 : SemanticExtractor
- [ ] Étape 6 : EpisodicExtractor
- [ ] Étape 7 : EncyclopedicExtractor
- [ ] Étape 8 : MemoryExtractor
- [ ] Étape 9 : Command brain:ingest:test
- [ ] Étape 10 : Migration SQL
- [ ] Étape 11 : Outil extract-corpus
- [ ] Étape 12 : Audits + bilan

## 10. Bilan

*À remplir à la fin du jalon. Sections obligatoires : capacité livrée, coût, surprises, ADRs créés, audits, go/no-go jalon 3.*
