---
statut: en cours
ouvert: 2026-05-12
livré: —
---

# Jalon 1 — Fondations

## 1. Capacité d'association visée

**Aucune** (jalon d'infrastructure). À la fin de ce jalon, on peut écrire en BDD deux neurones (n'importe quelle aire) et créer une synapse entre eux. Pas de retrieval, pas de Hebbien, pas d'extraction.

## 2. Test de sortie

```bash
# 1. Migration en place
bin/console doctrine:schema:create --dump-sql  # montre les tables syn_brain_* + syn_core_*

# 2. Préfixe configurable
SYNAPSE_TABLE_PREFIX=demo_ bin/console doctrine:schema:create --dump-sql
# → montre demo_brain_* / demo_core_*

# 3. Démo de bout en bout
bin/console brain:demo:jalon-1
# → crée 1 MemorySource, 1 neurone sémantique, 1 neurone épisodique, 1 synapse entre les deux
# → affiche les 4 lignes BDD avec leurs UUIDs + relations
```

## 3. Pré-requis

- ADR-001 (préfixe table configurable) **validé**
- Branche `brain` opérationnelle (déjà fait, mai 2026)
- Méthodologie figée (ce dossier `docs/brain/`)

## 4. Surface à concevoir

### 4.1 Tables Doctrine — squelettes

Toutes les annotations utilisent le nom **sans préfixe** (le `TablePrefixSubscriber` ajoute `syn_` au runtime).

```php
#[ORM\Entity]
#[ORM\Table(name: 'brain_memory_source')]
final class MemorySource { ... }

#[ORM\Entity]
#[ORM\Table(name: 'brain_neuron_episodic')]
#[ORM\Index(columns: ['source_uuid'])]
#[ORM\Index(columns: ['occurred_at'])]
final class EpisodicNeuron implements MemoryFragment { ... }

#[ORM\Entity]
#[ORM\Table(name: 'brain_neuron_semantic')]
final class SemanticNeuron implements MemoryFragment { ... }

#[ORM\Entity]
#[ORM\Table(name: 'brain_synapse')]
#[ORM\Index(columns: ['source_neuron_table', 'source_neuron_id'])]
#[ORM\Index(columns: ['target_neuron_table', 'target_neuron_id'])]
final class Synapse { ... }
```

Pour ce jalon, seulement **2 aires sur 7** (épisodique + sémantique) — pas besoin des 5 autres avant le jalon 2/3.

### 4.2 Contrat `MemoryFragment`

```php
namespace ArnaudMoncondhuy\SynapseCore\Brain\Contract;

interface MemoryFragment
{
    public function getId(): UuidInterface;
    public function getArea(): BrainArea;          // enum: EPISODIC, SEMANTIC, ENCYCLOPEDIC, ...
    public function getTableName(): string;        // pour Synapse polymorphique
    public function getSourceUuid(): ?UuidInterface;
}
```

### 4.3 Enums

- `BrainArea` (EPISODIC, SEMANTIC, ENCYCLOPEDIC, PROCEDURAL, EMOTIONAL, SENSORY, MOTOR)
- `SynapsePolarity` (EXCITATORY, INHIBITORY)
- `SynapseRelationType` (CAUSAL, CORROBORATES, CONTRADICTS, COMPOSES, INSTANTIATES, TEMPORAL, SPATIAL, EMOTIONAL, GENERIC)
- `SynapseEdgeType` (ASSOCIATION, TRANSDUCTION)

### 4.4 Subscriber Doctrine `TablePrefixSubscriber`

Cf. [ADR-001](../05-decisions/001-prefixe-table-configurable.md) pour la spec détaillée.

### 4.5 Config DI

```yaml
# config/packages/synapse.yaml (racine `synapse`, alignée sur Configuration.php du bundle)
synapse:
  persistence:
    table_prefix: 'syn_'  # par défaut
```

### 4.6 Command de démo

`bin/console brain:demo:jalon-1` — script idempotent qui :
1. Crée une `MemorySource` (provider='manual', payload synthétique)
2. Crée un `EpisodicNeuron` lié
3. Crée un `SemanticNeuron` lié à la même source
4. Crée une `Synapse` entre les deux (weight=0.5, polarity=EXCITATORY, type=CORROBORATES)
5. Affiche le tout au format JSON

## 5. Étapes d'implémentation

| # | Étape | Test associé | Commit |
|---|---|---|---|
| 1 | ADR-001 validé par user | — | `docs(brain): valide ADR-001 préfixe table` |
| 2 | Enums Brain (`BrainArea`, `SynapsePolarity`, `SynapseRelationType`, `SynapseEdgeType`) | `BrainAreaTest` | `feat(brain): ajoute enums Brain v3 (area, polarity, relation_type, edge_type)` |
| 3 | Interface `MemoryFragment` + tests trait | `MemoryFragmentTest` | `feat(brain): introduit contrat MemoryFragment` |
| 4 | Entité `MemorySource` + repository | `MemorySourceRepositoryTest` | `feat(brain): ajoute entité MemorySource (point d'entrée stimuli)` |
| 5 | Entité `EpisodicNeuron` + repository | `EpisodicNeuronTest` | `feat(brain): ajoute neurone épisodique (hippocampe)` |
| 6 | Entité `SemanticNeuron` + repository | `SemanticNeuronTest` | `feat(brain): ajoute neurone sémantique (néocortex)` |
| 7 | Entité `Synapse` + repository (FK polymorphe + index) | `SynapseRepositoryTest` | `feat(brain): ajoute synapse polymorphe 5 dimensions` |
| 8 | `TablePrefixSubscriber` + binding DI + tests préfixe défaut & custom | `TablePrefixSubscriberTest` | `feat(brain): ajoute préfixe table configurable (ADR-001)` |
| 9 | Migrations doctrine SQL pour apps hôtes (`migrations-brain-v3/jalon-1/`) | — (smoke test sur basile) | `feat(brain): scripts migration jalon 1 pour apps hôtes` |
| 10 | Command `brain:demo:jalon-1` | démo exécutée à la main | `feat(brain): ajoute brain:demo:jalon-1 (démo bout en bout)` |
| 11 | Bilan rempli + MEMORY.md mis à jour | — | `docs(brain): bilan jalon 1` |

Granularité : 1 commit par étape (cf. [02-methodologie-sessions.md](../02-methodologie-sessions.md#granularité-des-commits)).

## 6. Tests & dogfooding

### Tests PHPUnit (`composer test`)

- Tests unitaires pour chaque entité et chaque enum (cf. tableau étapes)
- Test d'intégration `TablePrefixSubscriberTest` : préfixe défaut + custom, vérifie que `EntityManager::getClassMetadata()` retourne les bons noms de table
- Test fonctionnel : `brain:demo:jalon-1` ne plante pas et produit 4 lignes en BDD

### Dogfooding

Pas de validation qualité de synapse à ce stade (rien à mesurer — la synapse créée est artificielle). On vérifie juste que :

- Les migrations passent sur basile (app hôte)
- La commande `brain:demo:jalon-1` produit bien 4 lignes côté `syn_brain_*`
- Aucune ancienne table `synapse_*` n'est créée

## 7. Décisions ouvertes (à transformer en ADRs si tranchées pendant le jalon)

| # | Question | Statut |
|---|---|---|
| Q1 | DoctrineMigrationsBundle dans synapse-bundle ou app hôte ? | ouvert |
| Q2 | Stratégie de migration data depuis `synapse_*` existant : script SQL ou commande Doctrine ? | ouvert |
| Q3 | Faut-il faire vivre temporairement les anciennes tables `synapse_*` en parallèle ? | tranché en avance → **non** (cf. charte §3 : pas de compat layer) |
| Q4 | Quel UUID generator : Doctrine `uuid_binary_ordered_time` ou `ramsey/uuid` v7 ? | à trancher à l'étape 2 |

## 8. Hors-scope du jalon

- Les 5 autres aires (encyclopédique, procédural, émotionnel, sensoriel, moteur) — viennent au jalon 2 ou plus tard
- MemoryExtractor — jalon 2
- Spreading activation — jalon 4
- Webhook handler (SNP) — jalon 7
- UI graphe — jalon 7

**Tentation à résister** : créer toutes les 7 aires d'un coup parce que "tant qu'à y être". Non. Charte §2.4 : *construire pour 1, abstraire pour N*. Tant qu'on n'a pas extrait quoi que ce soit (jalon 2), créer 7 tables vides est du noise.

## 9. Bilan

*À remplir à la fin du jalon. Format minimal :*

- **Capacité livrée** : oui/non, démontrée par `bin/console brain:demo:jalon-1`
- **Coût** : N sessions, X LOC, Y commits, Z migrations
- **Surprises** : ce qui n'était pas anticipé
- **ADRs créés pendant le jalon** : liste
- **Go/no-go jalon 2** : avec justification
