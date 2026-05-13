# Charte du chantier Brain v3

> Les principes non négociables. À relire avant chaque session.

## 1. Intention

**Un brain qui sait associer des idées.**

Quatre formes d'association comptent, sans hiérarchie de valeur (priorité = facilité d'implémentation/test) :

| Forme | Servi par |
|---|---|
| **Retrieval enrichi** — trouver l'info pertinente même non requêtée explicitement | Spreading activation, embeddings, evidence_count |
| **Contradictions / nuances** — détecter quand 2 faits se contredisent ou se tempèrent | Polarity (excitatory/inhibitory) sur synapses |
| **Créativité / liens non-évidents** — faire émerger des associations que personne n'avait posées | Cross-aires (épisodique × sémantique × amygdale), convergence mémorielle |
| **Raisonnement typé** — causal, temporel, spatial, etc., pas juste un score de similarité | `relation_type` à 9 valeurs explicites |

Le brain n'est livré que quand il **associe**, pas quand il "stocke et retrouve". Le retrieval est un moyen, pas la finalité.

## 2. Discipline (issues du design doc §14 et des recadrages user)

### 2.1 Agnosticisme strict du noyau

**Aucun vocabulaire métier dans `src/Brain/`.** Pas de "deal", pas de "client", pas de "patient", pas de "facture", pas de "élève". Si un nom de classe, méthode, table, champ contient un terme métier → c'est un trou d'abstraction à combler avant de continuer.

> *Why* — Brain doit être connectable à n'importe quoi (apps perso multiples, OSS éventuel). Weecom sert de **corpus de test**, jamais de modèle architectural. Idem lycee_intranet, basile, etc.

**Accès weecom pour dogfooding** : on a le droit de lire les données réelles weecom (fichiers JSON, base PostgreSQL locale) pour benchmarker les performances et la qualité du Brain. **Strictement lecture seule** — jamais d'écriture dans weecom. Les outils d'accès (par ex. `tools/brain-bench/`) sont des **adaptateurs côté hôte** (pattern `Module/{Domain}/`), pas du code noyau.

### 2.2 Pas de framing concurrentiel

On ne se positionne pas vs Mem0/Letta/Zep/Cognee/HippoRAG/HeLa-Mem/Kairos. Les références servent **uniquement d'inspiration technique** et de vérification ("est-ce que ce qu'on conçoit existe déjà mieux ailleurs ?"). Pas de tableau "Brain est meilleur parce que…" dans la doc.

### 2.3 Pas de sur-uniformisation

Les 7 aires ont 7 schémas différents. La tentation d'une table polymorphe `memory_neuron(area, content, metadata JSONB)` est **écartée explicitement**. Spécialisation = expressivité.

### 2.4 Construire pour 1, abstraire pour N

Pas d'abstraction prématurée. Implémenter d'abord pour un cas concret (corpus weecom), abstraire seulement quand un deuxième usage prouve la généricité. Trois usages similaires valent mieux qu'une abstraction inventée.

### 2.5 Comprendre avant d'intégrer

Avant d'écrire un composant qui ressemble à HippoRAG / HeLa-Mem / Kairos, lire le paper et/ou le code OSS. Décider en connaissance de cause : intégrer, ré-implémenter, écarter. Documenter dans un ADR.

### 2.6 Pas d'anthropomorphisation

Un LLM ne "pense", ne "comprend", ne "sent", ne "décide" pas. Dans la doc et l'UI, on dit : *structurer sa mémoire*, *ajuster les poids*, *rappeler un contexte*, *proposer une association*. La métaphore biologique est opérationnelle (vocabulaire d'archi), pas scientifique.

### 2.7 Marque externe vs préfixe SQL

- Marque externe : **Synapse** (nom du bundle, packages composer, repo GitHub)
- Préfixe SQL : `syn_` par défaut, **configurable** (`synapse.persistence.table_prefix`)
- Sous-domaines : `brain_` (cognition) et `core_` (infra). Ne pas mélanger

### 2.8 Mesurer à chaque jalon

À la fin de chaque jalon, répondre à 2 questions :
1. **Le jalon a-t-il vraiment livré une nouvelle capacité d'association ?** (pas juste du code)
2. **Le jalon suivant apporte-t-il mesurablement quelque chose au-dessus du jalon courant ?**

Si la réponse à (2) est non → on s'arrête là, on documente pourquoi, on ne fait pas du code pour faire du code.

### 2.9 Validation expérimentale obligatoire de la qualité des synapses

**Les tests PHP ne suffisent pas.** PHPUnit valide la *correctness syntaxique* (un constructeur appelé avec les bons types, un retour correct). Brain v3 a besoin en plus de prouver la *correctness sémantique* : les synapses générées sont-elles **pertinentes** ?

Concrètement, à partir du jalon 3 (premier jalon qui produit/consomme des synapses), chaque plan de phase doit inclure une section **Validation qualité** :

- **Échantillon manuel annoté** : prendre N sources réelles (≥10) du corpus weecom, lister humainement les associations qu'on attendrait, comparer aux synapses produites par le brain
- **Métriques quantitatives** : précision / rappel / F1 sur les associations, taux de convergence mémorielle (§9 design), taux de polarity correcte
- **Cas de régression** : un jeu de fixtures versionné dans `tests/Brain/Quality/Fixtures/` qui doit toujours produire les mêmes associations entre 2 runs

Si la qualité **dégrade** entre 2 jalons → c'est bloquant. On n'avance pas au suivant tant que la régression n'est pas comprise et soit corrigée, soit documentée comme compromis assumé dans un ADR.

> Why — Brain v3 explore un terrain peu cartographié (polarity LLM, functional networks, I/O distincte). La justesse n'est pas une propriété qu'on prouve par green tests, c'est une propriété qu'on **mesure** sur corpus réel. Faute de quoi on peut avoir un brain qui compile parfaitement et qui associe n'importe quoi.

### 2.10 Calibration par comparaison, pas à l'œil

Les hyper-paramètres du brain (seuils cosine, taux de decay, formules de score, profondeur BFS, etc.) **ne se règlent jamais à l'œil**. À chaque jalon qui introduit un paramètre, on produit **≥2 jeux de synapses concurrents** (sets calibrés avec valeurs différentes) et on compare sur les fixtures de qualité.

Le set retenu fait l'objet d'un ADR qui justifie chiffré contre les variantes. Méthodologie complète : [07-calibration.md](07-calibration.md).

### 2.11 Revue systématique par sub-agents avant chaque commit Brain v3

Le user est seul sur le projet. Mes erreurs ne seront pas rattrapées par un reviewer humain. Pour combler ce manque, **chaque commit qui touche `src/Brain/` ou `docs/brain/`** doit passer par une revue automatisée via sub-agents dédiés.

**Pool de 6 agents complémentaires** (`.claude/agents/`) :

| Agent | Scope |
|---|---|
| `brain-charter-auditor` | Conformité charte (agnosticisme, anthropomorphisation, ADRs manquants) |
| `brain-code-reviewer` | Qualité PHP/Doctrine/tests/event-driven |
| `brain-adr-conformance` | Code respecte chaque ADR + design figé |
| `brain-bundle-pattern-auditor` | Utilisation correcte des services pivots du bundle (ChatService, EmbeddingService, EmbeddingUsageListener::PURPOSE_MAP) |
| `brain-isolation-paranoid` | Sécurité user isolation (ADR-006) |
| `brain-algorithm-and-edge-cases` | Correctness algos + edge cases tests |

**Workflow** :

1. **Pré-commit local** : `composer cs-fix && composer phpstan && phpunit`
2. **Audit en parallèle** : lancer les 4 agents systématiques (`charter`, `code`, `adr`, `bundle-pattern`)
3. **Audits conditionnels** :
   - Si `Synapse` / retrieval / ingestion touché → ajouter `isolation-paranoid`
   - Si algorithme nouveau/modifié → ajouter `algorithm-and-edge-cases`
4. **Interpréter** : `bloquant` = on fixe avant commit, `majeur` = à corriger avant fin de jalon, `mineur` = noté
5. **Commit** : mentionner dans le message les agents qui ont passé

**Anti-pattern** : sauter les agents "parce que c'est un petit changement" — c'est exactement comme ça qu'on a laissé passer le bug `brain_retrieval` non mappé dans `EmbeddingUsageListener::PURPOSE_MAP` au jalon 4.

Workflow détaillé : [reference-brain-subagents-workflow](memory/reference_brain_subagents_workflow.md).

## 3. Ce qu'on ne fait pas

- Pas de roadmap multi-jalons fusionnée en un seul commit/PR (cf. mémoire `feedback_big_chantiers_on_branch`)
- Pas de mock de la base sur les tests Brain (cf. principes d'intégrité référentielle polymorphe)
- Pas de copier-coller de logique entre aires : chaque aire a son service dédié
- Pas de re-vectorisation à chaque changement de prompt → sources immutables, neurones jetables, `RevectorizeCommand` quand on change d'extracteur
- Pas de "compat layer" avec l'ancien bundle : breaking direct, scripts de migration data fournis aux apps hôtes au merge

## 4. Référence métaphorique vs vérité technique

La biologie inspire le vocabulaire et l'architecture. Elle **ne tranche pas** un compromis technique. Quand un choix se présente :

| Si choix entre… | …on tranche par |
|---|---|
| Fidélité biologique vs simplicité d'implémentation | Simplicité, sauf si le défaut introduit une limitation observable |
| Fidélité biologique vs perf SQL | Perf SQL, et on documente le compromis |
| Fidélité biologique vs auditabilité | Auditabilité (le brain est aussi un laboratoire) |

## 5. Boussole simple

Quand tu hésites sur un design, demande-toi :

> *Ce que je suis en train de coder rend-il le brain meilleur pour **associer des idées** ?*

Si non, c'est probablement de l'infrastructure qui peut attendre.

---

**Liens** :
- Design figé : [../brain-v3-design.md](../brain-v3-design.md)
- Roadmap : [01-roadmap.md](01-roadmap.md)
- Méthodologie de travail : [02-methodologie-sessions.md](02-methodologie-sessions.md)
