# Brain v3 — Design Document

> Modèle de mémoire Hebbien dynamique pour Synapse Bundle
> Architecture cognitive à 7 aires cérébrales spécialisées
> Status : draft (à coder)
> Cible : version majeure v1.0 ou v2.0 du bundle (breaking)

---

## Table des matières

1. [Vision et métaphore](#1-vision-et-métaphore)
2. [Écueils observés à éviter](#2-écueils-observés-à-éviter)
3. [Convention de nommage SQL](#3-convention-de-nommage-sql)
4. [Les 7 aires cérébrales — schémas spécialisés](#4-les-7-aires-cérébrales--schémas-spécialisés)
5. [Système nerveux périphérique (webhooks I/O)](#5-système-nerveux-périphérique-webhooks-io)
6. [Synapses enrichies — au-delà du Graph RAG](#6-synapses-enrichies--au-delà-du-graph-rag)
7. [Edges d'association vs transduction](#7-edges-dassociation-vs-transduction)
8. [Dynamique des synapses (Hebbien, decay, consolidation)](#8-dynamique-des-synapses-hebbien-decay-consolidation)
9. [Extracteur multi-aires](#9-extracteur-multi-aires)
10. [Interaction LLM avec les synapses](#10-interaction-llm-avec-les-synapses)
11. [Connexion via Module/{Domain}](#11-connexion-via-moduledomain)
12. [Plan d'extension](#12-plan-dextension)
13. [Phases de validation progressive](#13-phases-de-validation-progressive)
14. [Règles de discipline](#14-règles-de-discipline)
15. [Glossaire](#15-glossaire)

---

## 1. Vision et métaphore

**Métaphore directrice :** *"Le cerveau qu'on colle dans un corps."*

Le **Brain** (modèle de mémoire) est un organe greffé dans un corps applicatif (l'application hôte) qui apporte ses propres organes périphériques (Modules métier) et son environnement (sources de données externes). Le bundle Synapse héberge le Brain et le SNP (système nerveux périphérique) qui le relie aux organes.

**Principe central :** une source = un UUID unique = N neurones répartis dans N aires, chaque neurone étant une **lecture distincte** de la source par son aire correspondante. Analogie biologique exacte : un stimulus unique (un visage croisé) active simultanément cortex visuel (formes), hippocampe (déjà vu), néocortex (c'est X), amygdale (émotion), ganglions (le saluer). **Un stimulus, N processings, N traces mémoire.**

**Sélectivité naturelle :** toutes les aires ne s'activent pas pour chaque source. Un PDF documentaire active souvent juste Sensoriel + Encyclopédique. Un email peut activer les 6+ aires. Une note manuelle souvent juste Néocortex. L'oubli par non-activation est sain, pas un bug.

**Inspiration biologique = métaphore opérationnelle assumée**, pas modèle scientifique strict. La biologie inspire le vocabulaire et l'architecture ; elle ne tranche pas les choix techniques quand un compromis se présente.

---

## 2. Écueils observés à éviter

Plusieurs systèmes de mémoire d'agent ont déjà été construits (Mem0, Letta, Zep, Cognee, HippoRAG 2, HeLa-Mem, Kairos — cf. `docs/brain/04-references.md` pour les liens). L'objectif de cette section n'est pas de les concurrencer ni de copier leurs solutions, mais de **lister les limitations qu'ils ont rencontrées** pour ne pas les répéter dans Brain v3.

### Écueils identifiés (ne pas refaire)

- **Un seul poids par edge** (HippoRAG 2 le reconnaît comme limite explicite) : impossible de modéliser à la fois la *force* d'activation et la *fiabilité* de l'information, ni les contradictions entre faits. → Brain v3 sépare `weight`, `confidence`, `polarity`, `relationType`, `evidenceCount` en 5 dimensions distinctes (cf. §6).

- **Decay homogène toutes-aires** (HeLa-Mem) : applique le même taux d'oubli aux faits stables et aux événements ponctuels — soit on perd les souvenirs récents, soit on garde du bruit éternel. → Brain v3 expose un decay configurable par aire (§8).

- **Pas de polarité** (tous) : un fait corroboré et un fait contredit comptent pareil. → Brain v3 introduit `polarity = excitatory / inhibitory` pour les contradictions auditables.

- **Mémoire homogène monolithique** (Mem0, Letta) : tous les souvenirs dans le même bac, scoring uniforme. → Brain v3 spécialise par aire (épisodique/sémantique/encyclopédique/procédural/émotionnel/sensoriel/moteur), 7 schémas distincts.

- **Validation absente sur consolidation** (Mem0, Cognee) : tout ce qui passe l'extraction est consolidé, y compris les hallucinations LLM. → Brain v3 reprend le pattern *validation-gated* de Kairos (jalon 6+).

- **Auditabilité limitée** (la plupart) : difficile pour un user d'inspecter pourquoi tel souvenir a remonté. → Brain v3 expose une UI graphe + trace explicite des chemins de retrieval (jalon 7).

- **PPR sur arêtes signées instable** (HippoRAG 2 ne tente pas, et la littérature le confirme) : pas de propagation d'inhibition fiable sur Personalized PageRank. → Brain v3 ignore la polarité au retrieval jalon 4 (cf. ADR-008 amendé), et ne la réintroduira qu'avec validation empirique au jalon 5+.

- **Topology-induced leakage** (SSGM 2603.11768) : les chemins indirects à travers un graphe partagé peuvent fuir des données entre frontières utilisateurs. → Brain v3 filtre owner à 3 niveaux (synapse, seed, dequeue) — cf. ADR-006 + audit `brain-isolation-paranoid`.

### Inspirations techniques (à digérer, pas à copier)

Les références citées plus haut sont étudiées dans `docs/brain/04-references.md`. La règle (charte §2.5) est de **lire avant d'intégrer** — chaque emprunt à une de ces sources fait l'objet d'un ADR qui justifie l'adoption ou l'écart.

Brain v3 n'est pas une réponse à un marché. C'est un système qui essaie d'**associer des idées**. Si certaines briques ressemblent à des composants existants ailleurs, c'est parce qu'on a appris d'eux — pas parce qu'on les concurrence.

---

## 3. Convention de nommage SQL

Toutes les tables du bundle utilisent un **préfixe configurable + sous-domaine** :

```
{prefix}_brain_*    → structure de raisonnement (cognition)
{prefix}_core_*     → infrastructure opérationnelle (plomberie)
```

**Préfixe par défaut :** `syn_` (court, lisible, évite la répétition `synapse_*_synapse`). Surchargeable côté app hôte via config Symfony :

```yaml
synapse:
    persistence:
        table_prefix: 'acme_'  # → acme_brain_*, acme_core_*
```

**Règle de décision pour placer une nouvelle table :**
- `core` = opérationnel = ce qui fait *tourner* le LLM et l'orchestre (agents, providers, models, presets, token usage, quotas, debug logs, governance, RAG config, workflows runs)
- `brain` = structure de raisonnement = ce qui *raisonne, mémorise, apprend* (neurones par aire, synapses, learning signals, functional networks, audit log de raisonnement, conversation)

Test simple : *"est-ce que ça pense, ça mémorise, ça apprend ?"* → `brain`. Sinon → `core`.

**Rôle du préfixe :** convention technique Symfony bundle = identifie "ces tables appartiennent au bundle, n'y touche pas". Permet au bundle de cohabiter sans collision avec les tables de l'app hôte.

---

## 4. Les 7 aires cérébrales — schémas spécialisés

**Choix structurant :** 7 tables avec schémas distincts, **pas** 1 table polymorphe avec champ `area` + `metadata JSONB`. Raison : les aires cérébrales ont des cytoarchitectures différentes parce qu'elles font des boulots différents. Forcer l'uniformité = perdre la spécialisation.

**Distinction conceptuelle :** 5 aires d'**association** (plasticité Hebbienne, persistance durable) + 2 aires de **transduction** (sensoriel/moteur, neurones de conversion I/O, sans plasticité Hebbienne, persistance transitoire).

### Source commune

```sql
-- Un point d'entrée par stimulus brut, UUID unique
syn_brain_memory_source
  uuid          UUID PRIMARY KEY
  provider      VARCHAR(50)         -- 'gmail' | 'calendar' | 'drive' | 'pipedrive' | 'abby' | 'ringover' | 'manual' | 'webhook_generic' | ...
  external_id   VARCHAR(255) NULL   -- id côté provider (mailId, eventId, dealId)
  raw_payload   JSONB               -- donnée brute reçue
  received_at   TIMESTAMP
  tenant_id     UUID
  owner_id      UUID NULL
```

### Aires d'association (5)

```sql
-- 1. Hippocampe (épisodique) — événement situé dans temps × lieu × acteurs
syn_brain_neuron_episodic
  id                UUID PRIMARY KEY
  source_uuid       UUID FK → syn_brain_memory_source(uuid) ON DELETE CASCADE
  occurred_at       TIMESTAMP
  location          VARCHAR(255) NULL
  actors            TEXT[]
  event_summary     TEXT
  embedding         VECTOR(768)
  sequence_id       UUID NULL       -- pour grouper en conversations/séquences

-- 2. Néocortex (sémantique) — fait stable subject-predicate-value
syn_brain_neuron_semantic
  id                    UUID PRIMARY KEY
  source_uuids          UUID[]      -- un fait peut être corroboré par PLUSIEURS sources
  subject               TEXT
  predicate             TEXT
  value                 TEXT
  confidence            FLOAT
  embedding             VECTOR(768)
  last_corroborated_at  TIMESTAMP

-- 3. Cortex temporal (encyclopédique) — document chunké indexable
syn_brain_neuron_encyclopedic
  id              UUID PRIMARY KEY
  source_uuid     UUID FK → syn_brain_memory_source(uuid) ON DELETE CASCADE
  document_ref    VARCHAR(255)
  chunk_index     INT
  chunk_content   TEXT
  embedding       VECTOR(768)
  doc_metadata    JSONB

-- 4. Ganglions de la base (procédural) — workflow typé
syn_brain_neuron_procedural
  id                UUID PRIMARY KEY
  source_uuid       UUID NULL FK → syn_brain_memory_source(uuid) ON DELETE SET NULL
  name              VARCHAR(255)
  trigger_pattern   JSONB           -- conditions de déclenchement
  steps             JSONB           -- séquence d'étapes typées
  conditions        JSONB           -- pré/post-conditions
  success_rate      FLOAT           -- 0 à 1, mis à jour à chaque exécution
  execution_count   INT
  last_executed_at  TIMESTAMP NULL
  -- pas d'embedding : procédure, pas sens

-- 5. Amygdale (émotionnel) — valence + intensité ciblée sur autre neurone/source
syn_brain_neuron_emotional
  id                    UUID PRIMARY KEY
  source_uuid           UUID NULL
  target_neuron_table   VARCHAR(50)
  target_neuron_id      UUID
  valence               FLOAT       -- -1 (négatif) à +1 (positif)
  intensity             FLOAT       -- 0 à 1
  emotion_type          VARCHAR(50) -- 'urgent' | 'anxiety' | 'satisfaction' | 'tension' | ...
  decay_curve           JSONB       -- paramètres de décroissance
  -- pas d'embedding : marqueur affectif sur cible
```

### Aires de transduction (2)

```sql
-- 6. Cortex sensoriel (ENTRÉE) — input non encore extrait
syn_brain_neuron_sensory
  id              UUID PRIMARY KEY
  source_uuid     UUID FK → syn_brain_memory_source(uuid) ON DELETE CASCADE
  raw_format      VARCHAR(50)       -- 'application/json' | 'text/html' | 'application/pdf' | ...
  raw_data_ref    VARCHAR(255)      -- chemin ou clé objet
  size_bytes      BIGINT
  status          ENUM('pending', 'extracted', 'discarded')
  received_at     TIMESTAMP
  -- pas d'embedding : flux brut transitoire, TTL court
  -- pas de plasticité Hebbienne : neurone de transduction

-- 7. Cortex moteur (SORTIE) — action planifiée ou exécutée
syn_brain_neuron_motor
  id                      UUID PRIMARY KEY
  target_module           VARCHAR(100)    -- nom du Module destinataire
  action_type             VARCHAR(100)    -- ex 'create_invoice', 'send_email', 'trigger_workflow'
  payload                 JSONB
  status                  ENUM('planned', 'sent', 'acknowledged', 'failed')
  triggered_by_neuron_id  UUID            -- traçabilité
  triggered_at            TIMESTAMP
  sent_at                 TIMESTAMP NULL
  response_payload        JSONB NULL
  -- pas d'embedding : commande, pas sens
  -- pas de plasticité Hebbienne : neurone de transduction
```

### Synapses (connexions enrichies)

```sql
-- 8. Synapse — connexion enrichie polymorphe cross-aires
syn_brain_synapse
  id                      UUID PRIMARY KEY
  source_neuron_table     VARCHAR(50)
  source_neuron_id        UUID
  target_neuron_table     VARCHAR(50)
  target_neuron_id        UUID
  weight                  FLOAT DEFAULT 0.1              -- force d'activation, 0 à 1
  polarity                ENUM('excitatory', 'inhibitory') DEFAULT 'excitatory'
  relation_type           ENUM('causal', 'corroborates', 'contradicts', 'composes',
                               'instantiates', 'temporal', 'spatial', 'emotional',
                               'generic') DEFAULT 'generic'
  confidence              FLOAT DEFAULT 0.5              -- fiabilité (≠ force)
  evidence_count          INT DEFAULT 1                  -- nb corroborations
  last_activated_at       TIMESTAMP
  context_id              UUID NULL                      -- functional network
  edge_type               ENUM('association', 'transduction')
```

**FK polymorphes :** intégrité gérée en code PHP via interface `MemoryFragment` implémentée par les 7 entités d'aires. Pas de contrainte SQL stricte (compromis nécessaire pour le polymorphisme cross-aires).

### Vue synthèse

| Aire | Table | Embedding | Type | Sens du flux | Plasticité Hebbienne |
|---|---|---|---|---|---|
| Sensoriel | `syn_brain_neuron_sensory` | Non | Transduction | Entrée | Non |
| Hippocampe | `syn_brain_neuron_episodic` | Oui | Association | Interne | Oui |
| Néocortex | `syn_brain_neuron_semantic` | Oui | Association | Interne | Oui |
| Temporal | `syn_brain_neuron_encyclopedic` | Oui | Association | Interne | Oui |
| Ganglions | `syn_brain_neuron_procedural` | Non | Association | Interne | Oui (sur trigger_pattern) |
| Amygdale | `syn_brain_neuron_emotional` | Non (cible seulement) | Association | Interne | Oui |
| **Moteur** | `syn_brain_neuron_motor` | Non | Transduction | **Sortie** | Non |

4 aires sur 7 ont un embedding ; procédural, sensoriel et moteur n'en ont pas. On ne fait pas une recherche par similarité sur un workflow (on le déclenche par trigger pattern), et on ne cherche pas un input/output brut par similarité (on le consomme en file).

### Tables Brain complémentaires

```sql
syn_brain_learning_signal       -- événements d'apprentissage (co_activation, validated, corrected, pruned)
syn_brain_functional_network    -- modes contextuels (brief deal, support, prospection, ...)
syn_brain_conversation          -- container de séquences épisodiques (chat)
syn_brain_audit_log             -- traçabilité Palantir-style des décisions
```

---

## 5. Système nerveux périphérique (webhooks I/O)

La frontière entre l'organisme et le monde extérieur passe par les **webhooks**. Ils sont la primitive agnostique de transport au niveau noyau ; les objets concrets (ESP32, Gmail, Pipedrive…) sont des entités métier qui vivent dans les Modules.

| Niveau biologique | Niveau code |
|---|---|
| Organes sensoriels (yeux, peau, oreilles, capteurs IoT) | Module métier producteur (`Module/Iot`, `Module/Gmail`, …) |
| **Nerfs afférents** | **Webhook entrant** (normalisé, signé, persisté en `syn_brain_memory_source`) |
| SNC (7 aires + synapses) | Noyau Brain |
| **Nerfs efférents** | **Webhook sortant** (`Brain\OutboundEventDispatcher` lit `syn_brain_neuron_motor` et émet vers URL HTTP cible) |
| Organes effecteurs (muscles, glandes, actuateurs IoT) | Module métier consommateur |

Le SNC ne sait pas que ses inputs viennent d'un ESP32 ou d'un Gmail ; il reçoit des "spikes normalisés" via `syn_brain_memory_source` avec un champ `provider` textuel. Symétriquement, il émet des commandes sortantes sans savoir ce que le module récepteur en fera concrètement.

---

## 6. Synapses enrichies — au-delà du Graph RAG

**Une synapse ne porte PAS qu'un seul poids.** Sinon on est strictement du Graph RAG / Personalized PageRank type HippoRAG, efficace pour le retrieval mais plafonné en raisonnement.

Une synapse Brain porte **5 dimensions distinctes** :

| Dimension | Rôle | Impact |
|---|---|---|
| `weight` (float 0-1) | Force d'activation historique | Scoring du retrieval (intensité du lien) |
| `polarity` (excitatory/inhibitory) | Amplifie OU inhibe le signal | Permet de modéliser contradictions et exclusions |
| `relation_type` (9 types) | Nature sémantique du lien | Permet à l'IA de raisonner typé : cause vs corrélation vs contradiction |
| `confidence` (float 0-1) | Fiabilité (≠ force) | 100 sources sûres ≠ activé 100 fois historiquement |
| `evidence_count` (int) | Nombre de corroborations | Scoring fin du retrieval et décisions de pruning |

**Exemple concret :** *"X préfère le matin"* et *"X est en congé cette semaine"* sont deux faits sémantiques liés au même neurone. Sans polarité, ils s'activent ensemble quand on parle de RDV avec X, le LLM est confus. Avec polarité (le congé **inhibe** "RDV proposable"), le système raisonne correctement.

**Schéma extensible :** à ajouter plus tard si le besoin émerge — `decay_profile` (courbe linéaire/exponentielle/sigmoïde par synapse), `is_symmetric` (A↔B vs A→B), `provenance` JSONB enrichi pour audit détaillé.

---

## 7. Edges d'association vs transduction

Deux régimes coexistent dans `syn_brain_synapse` (distingués par `edge_type`) :

| Aspect | Association (5 aires intérieures entre elles) | Transduction (impliquant sensoriel ou moteur) |
|---|---|---|
| Plasticité Hebbienne | Oui (renforcement par co-activation) | Non (câblage fixe) |
| Decay temporel | Oui (paramétrable par aire) | Non |
| Cycle de vie | Created → renforcé → consolidé → décayé | Created → archivé (audit trail) |
| Rôle | Apprentissage et mémoire | Traçabilité (provenance / causalité) |

**Exemples :**
- Edge association : "X (sémantique) est associé à RDV mardi 14h (épisodique)" — renforcé chaque fois que la conversation parle des deux ensemble
- Edge transduction : "neurone épisodique A a été extrait du buffer sensoriel B" — pure traçabilité, pas de renforcement

---

## 8. Dynamique des synapses (Hebbien, decay, consolidation)

**Plasticité Hebbienne** : *neurons that fire together wire together*. Deux neurones activés ensemble dans une même réflexion → connexion renforcée. Pas activés depuis longtemps → décroît. Convoquée à tort et corrigée → s'affaiblit.

**Decay configurable par aire :**
- Épisodique : rapide (jours/semaines)
- Sémantique : lent (mois/années)
- Encyclopédique : négligeable
- Procédural : lié au `success_rate`
- Émotionnel : `decay_curve` paramétrable
- Sensoriel : oubli quasi immédiat (TTL minutes-heures)
- Moteur : archivé après exécution (audit, pas mémoire active)

**Consolidation** = cron qui transfère/duplique fragments entre aires selon poids accumulés. Épisodique → sémantique = équivalent sommeil REM (souvenir consolidé en fait stable).

**Validation-gated learning** = consolidation d'une synapse seulement si le signal d'apprentissage passe un filtre qualité (cf. Kairos 2026). Étend le pattern user-gated existant (LLM propose, user valide) par un score automatique : `LLM confidence × user rating × historical accuracy`.

---

## 9. Extracteur multi-aires

**Composant central :** service `Brain\MemoryExtractor` qui reçoit une source brute + un prompt LLM paramétrable, et retourne `{aire: contenu_extrait}` typé. Une passe LLM = N neurones créés dans N tables différentes (1 source = jusqu'à 7 neurones).

**Source immutable, neurones jetables** : on peut revectoriser les neurones quand on change d'extracteur (meilleur prompt, autre modèle d'embedding), sans toucher aux sources. Pattern issu de l'expérience Prisma (`RevectorizeSignalsCommand`).

**Cascade suppression :** `ON DELETE CASCADE` sur `source_uuid` retire les neurones associés. RGPD-friendly (droit à l'oubli automatique).

**Convergence mémorielle détectable :** deux sources différentes produisent des neurones similaires dans la même aire (embeddings proches) → fait renforcé (poids ↑), pas dédoublé. Comme quand plusieurs personnes confirment la même information.

---

## 10. Interaction LLM avec les synapses

### Comment les synapses naissent (4 modes à combiner)

| Mode | Mécanisme | Coût | Quand |
|---|---|---|---|
| **A. Hebbien implicite** | 2 neurones convoqués dans la même réflexion → synapse auto avec poids initial | Gratuit (automatique sur chaque retrieval) | Toujours en arrière-plan |
| **B. LLM explicite** | LLM appelle `Brain.linkNeurons(A, B, weight, polarity, type, reason)` via tool | Coût LLM + décision | Quand le LLM "voit" un lien |
| **C. Règle métier** | Event business → `LearningSignal` → synapse spécifique | Gratuit (déterministe) | Sur events spécifiques (deal won, facture finalisée, …) |
| **D. Extraction depuis source** | À l'ingestion, l'extracteur LLM produit neurones **+ liens** entre eux | Coût LLM (déjà payé pour extraction) | À chaque ingestion |

### Paramètres Hebbiens configurables (par aire, surchargeables par functional network)

- Poids initial à la création (ex: 0.1)
- Incrément par co-activation (ex: +0.05)
- Décroissance par jour (ex: -0.01)
- Seuil de pruning (ex: < 0.05 → supprimé)
- Seuil de saillance (ex: > 0.5 = remonte dans retrieval)

### Agency du LLM (3 modes possibles)

| Mode | LLM fait quoi | Trade-off |
|---|---|---|
| **Passif** | Consomme le contexte injecté par spreading activation. Pas de tool pour modifier. | Simple, prévisible, moins coûteux. Mais le LLM ne peut pas "corriger" la mémoire. |
| **Actif** | Tools `Brain.link()`, `Brain.reinforce()`, `Brain.prune()`, `Brain.switchContext()` | Puissant. Mais coûteux, moins prévisible, risque de pollution mémoire. |
| **Hybride (validation-gated)** | LLM **propose** via tool, filtre qualité (score auto) ou user valide. Continuité du pattern `ProposeMemoryTool` existant + Kairos. | Bon compromis. Plus complexe à implémenter. |

Recommandation initiale : **démarrer hybride** pour rester dans la continuité du `ProposeMemoryTool` existant, évaluer ensuite si on monte en agency.

### Spreading activation (mode de retrieval déterministe)

Mécanisme cœur, **sans appel LLM** :

1. User : *"donne-moi le brief du dossier X"*
2. Extraction prompt → neurones candidats : `entity:X`, `Y`, `Z`
3. Pour chacun : récupérer voisins via synapses (poids > seuil), 2-3 sauts max
4. Filtrer par functional network actif (mode "brief") → certains poids amplifiés
5. Filtrer par aire selon le type de question (urgence → amygdale ; faits → sémantique ; chronologie → épisodique)
6. Score combiné (poids synapse × distance saut × score aire × confidence) → top N neurones
7. Injection dans le contexte LLM

**Coût** : 1-2 requêtes SQL (graph traversal) + récupération des contenus → quelques ms. Le LLM intervient seulement avant (extraction prompt) et après (consommation du contexte injecté).

---

## 11. Connexion via Module/{Domain}

L'application hôte connecte ses verticaux via le pattern `Module/{Domain}` éprouvé :

```
src/Module/{Domain}/
├── Entity/{Domain}Config.php           # config per-user/tenant
├── Repository/
├── Service/
│   ├── {Domain}Service.php             # métier domaine
│   ├── Tool/{Action}Tool.php           # tag `synapse.tool`
│   └── Rag/{Domain}RagSourceProvider.php  # implémente le contrat synapse
├── Controller/                         # UI module + admin
└── Message/MessageHandler/             # async via Symfony Messenger
```

Chaque module :
- Require synapse-bundle via composer
- Déclare ses Tools via tag `synapse.tool`
- Déclare ses RagSourceProvider via tag `synapse.rag_source_provider`
- Déclare ses Context Providers via tag `synapse.context_provider`
- Émet ses `Brain\LearningSignal` via bus d'events Symfony quand actions métier
- Aucun fork du bundle, aucune modif du noyau

Le bundle (côté Synapse) reste 100% agnostique : zéro vocabulaire métier dans son code.

---

## 12. Plan d'extension

1. **Config `synapse.persistence.table_prefix`** (défaut `syn_`, surchargeable) — mécanisme Doctrine de réécriture du préfixe
2. `syn_brain_memory_source` (entité commune, table parent)
3. `syn_brain_synapse` (entité connexion polymorphe enrichie avec `edge_type`)
4. Interface `Brain\MemoryFragment` (implémentée par les 7 entités d'aires)
5. Migration `SynapseMessage` → `Brain\Neuron\Episodic` (table `syn_brain_neuron_episodic`)
6. Migration `SynapseVectorMemory` → `Brain\Neuron\Semantic`
7. Migration `SynapseRagDocument` → `Brain\Neuron\Encyclopedic`
8. `Brain\Neuron\Procedural` (nouveau, étend `SynapseWorkflow` alpha)
9. `Brain\Neuron\Emotional` (nouveau)
10. `Brain\Neuron\Sensory` (nouveau, transduction entrée)
11. `Brain\Neuron\Motor` (nouveau, transduction sortie)
12. `Brain\WebhookHandler` agnostique (nerf afférent, persiste en `syn_brain_memory_source`)
13. `Brain\OutboundEventDispatcher` agnostique (nerf efférent, lit `syn_brain_neuron_motor`)
14. `Brain\LearningSignal` (entité événement d'apprentissage)
15. `Brain\MemoryExtractor` service (orchestrateur multi-aires)
16. `Brain\ConsolidationGate` (validation-gated automatique)
17. `Brain\MemoryDecayService` (cron decay par aire, paramètres distincts)
18. `Brain\MemoryAuditLogger` (traçabilité Palantir-style)
19. `Brain\FunctionalNetwork` (entité + service de gestion des contextes)
20. Admin "Graphe de mémoire" (UI visualisation des synapses)
21. Migration Doctrine breaking + scripts data migration pour apps consommatrices
22. Renommage des autres tables du bundle en `syn_core_*` (agent, preset, model, provider, token_usage, spending_limit, debug_log, rag_source, prompt_version, workflow_run)

---

## 13. Phases de validation progressive

Les phases ne sont pas un garde-fou contre l'overengineering — c'est un **chemin d'apprentissage progressif par dogfooding**. À chaque palier, mesurer si la phase suivante apporte mesurablement quelque chose. Si non, on s'arrête là.

- **Jalon 1** : `Module/{Domain}` pilote créé, premiers Tools `synapse.tool` migrés
- **Jalon 2** : ingestion des sources en `syn_brain_memory_source` + extraction par `MemoryExtractor`
- **Jalon 3** : neurones sémantiques + épisodiques peuplés, premier retrieval testable
- **Jalon 4** : synapses Hebbiennes simples (poids seul) + spreading activation, mesurer qualité retrieval
- **Jalon 5** : enrichissement synapses (polarity + relation_type + confidence + evidence_count)
- **Jalon 6** : functional networks contextuels (modes opérationnels)
- **Jalon 7** : aires sensoriel/moteur + audit Palantir UI
- **Jalon 8** : version mature → migration des autres apps consommatrices

---

## 14. Règles de discipline

- **Vocabulaire abstrait dans le noyau** : le code du Brain (et du Core) ne contient jamais de vocabulaire métier ("deal", "client", "patient", "facture", "élève"). Si on en trouve, c'est un trou d'abstraction à combler.
- **Pas de sur-uniformisation** : chaque aire a son schéma propre. La tentation d'une table polymorphe `memory_neuron(area, content, metadata JSONB)` est écartée explicitement.
- **Construire d'abord pour 1 vertical** ; ajouter des modules supplémentaires seulement quand un cas concret émerge. Pas d'abstraction prématurée.
- **Comprendre avant d'intégrer** : lire les papiers Hebbiens (HeLa-Mem, Kairos) et le code Mem0/Cognee/HippoRAG 2 AVANT de décider quoi coder soi-même vs intégrer.
- **Pas d'anthropomorphisation dans la com publique** : un LLM ne "pense" pas, il **structure sa mémoire**, **ajuste les poids**, **rappelle un contexte**. Pas "pense", "comprend", "sent", "décide".
- **Marque externe = Synapse** (nom du bundle, packages composer, repo GitHub). **Préfixe SQL = `syn_`** (configurable). **Sous-domaines** : `brain_` (mémoire) et `core_` (reste). Ne pas mélanger.
- **Mesurer à chaque jalon** : la phase suivante apporte-t-elle mesurablement quelque chose ? Si non, s'arrêter.

---

## 15. Glossaire

| Terme | Définition |
|---|---|
| **Brain** | Modèle de mémoire dynamique à 7 aires cérébrales. Sous-domaine fonctionnel du bundle Synapse. |
| **Core** | Infrastructure opérationnelle du bundle Synapse (agents, providers, accounting, governance). Hors Brain. |
| **Source** | Stimulus brut entrant, identifié par UUID unique. Une source produit N neurones répartis dans N aires. |
| **Neurone** | Lecture d'une source par une aire cérébrale spécifique. 1 source × 7 aires = jusqu'à 7 neurones. |
| **Synapse** | Connexion enrichie pondérée entre 2 neurones (cross-aires possible). 5 dimensions : weight, polarity, relation_type, confidence, evidence_count. |
| **Aire d'association** | Aire cérébrale où s'exerce la plasticité Hebbienne (Hippocampe, Néocortex, Temporal, Ganglions, Amygdale). |
| **Aire de transduction** | Aire de conversion I/O sans plasticité Hebbienne (Sensoriel = entrée, Moteur = sortie). |
| **Learning signal** | Événement d'apprentissage qui ajuste les poids des synapses (co_activation, validated, corrected, pruned). |
| **Functional network** | Mode de travail contextuel transverse aux aires (ex: mode "support", mode "prospection"). Modifie les poids selon le contexte actif. |
| **Validation-gated learning** | Consolidation d'une synapse soumise à un filtre qualité (score auto LLM confidence × user rating × historical accuracy). |
| **Spreading activation** | Mécanisme de retrieval déterministe par propagation d'activation dans le graphe de synapses depuis les neurones convoqués par le prompt. |
| **SNP (Système nerveux périphérique)** | Couche transport des webhooks entrants (afférents) et sortants (efférents) entre le Brain et les organes métier. |
| **Module/{Domain}** | Pattern d'adapter côté application hôte qui connecte un vertical métier au bundle Synapse. |

---

## Références

- HeLa-Mem (arxiv 2604.16839, 2026) — Hebbian Learning and Associative Memory for LLM Agents
- Kairos (OpenReview, 2026) — Validation-Gated Hebbian Learning for Adaptive Agent Memory
- HippoRAG 2 (ICML 2025, arxiv 2502.14802) — From RAG to Memory: Non-Parametric Continual Learning
- Generative Agents (Stanford, Park et al. 2023) — Importance × Recency × Relevance scoring
- Mem0, Letta (ex-MemGPT), Zep, Cognee — frameworks commerciaux et OSS de mémoire d'agents IA
- Tulving (1972) — distinction mémoire épisodique vs sémantique
