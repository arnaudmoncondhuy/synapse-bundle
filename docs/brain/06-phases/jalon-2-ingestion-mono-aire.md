---
statut: livré
ouvert: 2026-05-12
livré: 2026-05-12
---

# Jalon 2 — Ingestion mono-aire

> Plan détaillé après affinement nocturne 2026-05-12.

## 1. Capacité d'association visée

**Aucune encore** (toujours infra). Mais on franchit un cap qualitatif : on a maintenant un **MemoryExtractor** qui prend une `MemorySource` brute et produit des neurones via appel LLM. Le bundle dispose désormais d'une boucle d'ingestion qui transforme une source brute en neurones typés par aire.

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

- [x] Étape 1 : plan + ADRs (ADR-003 et ADR-004)
- [x] Étape 2 : EncyclopedicNeuron
- [x] Étape 3 : NeuronExtractorInterface + ExtractionResult + ExtractionFailedException
- [x] Étape 4 : Resources prompts
- [x] Étape 5 : SemanticExtractor
- [x] Étape 6 : EpisodicExtractor
- [x] Étape 7 : EncyclopedicExtractor
- [x] Étape 8 : MemoryExtractor + DI wiring
- [x] Étape 9 : Command brain:ingest:test
- [x] Étape 10 : Migration SQL
- [x] Étape 11 : Outil extract-corpus + validation manuelle sur weecom
- [x] Étape 12 : Audits + 6 fixes mineurs commités

## 10. Bilan (livré 2026-05-12)

### Capacité livrée

**Oui** — le bundle dispose désormais d'une boucle d'ingestion fonctionnelle :

1. Une `MemorySource` brute (provider + payload JSON) est consommée par `MemoryExtractor::extract($source, $area)`
2. L'extracteur compétent est dispatché (Semantic / Episodic / Encyclopedic)
3. Pour les 2 premiers, 1 appel LLM avec structured output produit les neurones typés
4. Pour le 3ème, chunking + embedding produit des chunks vectorisés
5. Retour normalisé via `ExtractionResult` (neurones + debug pour audit)

L'outil `tools/brain-bench/extract-corpus.php` permet de transformer un corpus réel weecom (lecture seule) en `MemorySource` ingestibles — testé manuellement, 2 notes Pipedrive correctement adaptées.

La command `bin/console brain:ingest:test --source-id=<uuid> --area=semantic` permet le test de sortie end-to-end côté app hôte (avec config LLM réelle).

### Coût

- **1 session active**, ~3h en autonomie nocturne
- **~3 500 LOC** ajoutées (1 entité + 4 services + 3 DTOs + 4 ressources prompts + 1 command + 1 outil bench + tests + 2 ADRs + plan + migration)
- **13 commits** : plan/ADRs + 10 étapes + 1 commit fixes audits
- **34 tests Brain ajoutés** (jalon 2) → **127 tests Brain total** (vs 77 fin jalon 1), **238 assertions**
- **check.sh complet OK** sur 1011 tests bundle (PHPStan, CS-Fixer, PHPUnit, YAML, Twig, Deptrac)

### Surprises

- **`Doctrine\ORM\Events::loadClassMetadata`** : pas une surprise mais une confirmation — `AsDoctrineListener` du jalon 1 est bien la bonne approche pour la suite (le jalon 2 n'a pas eu besoin d'un autre subscriber Doctrine)
- **Sélectivité naturelle dans les prompts** : ajouter explicitement *"tu as le droit et le devoir de retourner [] / null si rien d'extractible"* a demandé une formulation soignée. Le test côté qualité (jalon 3) montrera si le LLM respecte effectivement cette consigne
- **PurposeMap d'EmbeddingUsageListener** : artefact de l'ancienne archi RAG, dû ajouter `'brain_encyclopedic'` au mapping après l'audit. Pas anticipé dans le plan initial
- **L'IDE diagnostique en retard sur l'autoload** (déjà vu jalon 1) — bruit visuel sans impact réel, PHPStan et PHPUnit donnent l'image fiable

### ADRs créés ou validés pendant le jalon

- [ADR-003](../05-decisions/003-extracteur-llm-structured-output.md) — extracteur LLM via structured output (JSON schema) — **accepté**
- [ADR-004](../05-decisions/004-prompts-en-resources-files.md) — prompts versionnés dans `Resources/brain/prompts/` — **accepté**

### Audits post-jalon

- **brain-charter-auditor** : 0 bloquant, 0 majeur, 3 mineurs (anthropo "lire", label `rag_indexation` transitoire, convention `webhook_pipedrive_*`)
- **brain-code-reviewer** : 0 bloquant, 0 majeur, 9 mineurs

**Fixes appliqués dans ce jalon** :
- Clamp confidence dans [0, 1]
- `rag_indexation` → `brain_encyclopedic` + mapping accounting
- `match` default explicit dans extract-corpus
- Commentaire FK source_uuid sur EncyclopedicNeuron
- PHPDoc explicite pour `receivedAt` omis dans SemanticExtractor
- Reformulation "le brain lit" → "le bundle dispose d'une boucle d'ingestion"

**Reportés au début du jalon 3** (à traiter avant l'orchestrateur multi-aires) :
- Factorisation `AbstractLlmExtractor` (élimine la duplication `loadPrompt`/`loadSchema` entre SemanticExtractor et EpisodicExtractor)
- Cache `supportedAreas()` dans MemoryExtractor
- Warning sur collision d'extracteurs (deux extracteurs pour la même aire)
- Visitor `MemoryFragmentSerializer` (au lieu de la reflection dans BrainIngestTestCommand)
- Documentation de la convention `webhook_<provider>_<type>` côté adaptateur

### Apprentissages mémorisés pour la suite

Pas de nouvelles mémoires durables créées pendant le jalon 2 — les apprentissages restent dans le bilan et seront convertis en règles si récurrents.

### Go / no-go jalon 3

**Go**, avec deux pré-requis :

1. **Refactor du jalon 2** au début du jalon 3 (les 5 points "reportés" ci-dessus). Sans cette refacto, l'orchestrateur multi-aires "1 passe unique" du jalon 3 va dupliquer du code et complexifier inutilement.
2. **BDD de test PostgreSQL + pgvector** opérationnelle (cf. mémoire `feedback-brain-test-db-postgres-vector`). Au jalon 3 on commence à mesurer la qualité des synapses → besoin de persistance réelle pour les tests d'intégration.

Le jalon 3 est ambitieux (convergence mémorielle, premier vrai test de qualité). À découper en sous-jalons si l'on dépasse 3 sessions actives sans livraison (règle rétrospective de la méthodologie).
