# Méthodologie de travail

> Comment on attaque concrètement chaque jalon. Comment on session, commit, mesure, conclut.

## Rôle des deux côtés

| Côté | Rôle |
|---|---|
| **User (Arnaud)** | Donne l'intention, valide la surface UX, tranche les ADRs ambigus, arbitre les compromis. Ne code pas. |
| **Claude** | Lit le code, propose, code, teste, documente. Demande validation aux moments structurants. |

> *Cf. mémoire `feedback_challenge_surface_before_implementing` : la vision vient du user, l'implémentation vient de moi. Si la surface UX (API, table, méthode publique) me semble bizarre, je challenge avant de coder.*

## Alignement avec les règles du bundle

Ce dossier **complète** [`AGENTS.md`](../../AGENTS.md) pour le chantier Brain v3, il ne le remplace pas. En particulier :

- **Workflow Plan → Validation → Exécution** (AGENTS.md §0) s'applique à chaque jalon (plan dans `06-phases/` validé avant code)
- **Pas de push automatique** : `git push` uniquement sur demande explicite du user
- **Style code PSR-12** (`composer cs-fix`), **PHPStan zéro erreur**, **PHPUnit** : non négociables
- **Conventions de nommage** : code en anglais, doc en français, commits en français
- **Architecture event-driven** : Brain v3 s'intègre via Events + Subscribers (notamment en phase ENRICH du `PromptPipeline`)
- **Format LLM agnostique** OpenAI canonical : les neurones épisodiques stockent ce format

## Boucle session-jalon

Chaque jalon suit la même boucle :

```
┌─ Plan de phase (avant) ──────────────────────────┐
│  1. Claude écrit un plan dans 06-phases/jalon-N  │
│  2. User relit, ajuste, valide                   │
└──────────────────────────────────────────────────┘
                  ↓
┌─ Sessions d'exécution ───────────────────────────┐
│  3. Claude implémente, commit par étape          │
│  4. Pause si décision ambiguë → ADR ou question  │
└──────────────────────────────────────────────────┘
                  ↓
┌─ Bilan (après) ──────────────────────────────────┐
│  5. Claude remplit la section "Bilan" du plan    │
│  6. User valide → jalon N+1, OU on s'arrête      │
└──────────────────────────────────────────────────┘
```

## Plan de phase : structure obligatoire

Chaque fichier `06-phases/jalon-N-titre.md` contient les sections suivantes (voir le squelette dans chaque fichier de jalon) :

1. **Capacité d'association visée** — 1 phrase issue de la roadmap
2. **Test de sortie** — ce qu'on doit pouvoir démontrer
3. **Pré-requis** — jalons précédents, ADRs nécessaires
4. **Surface à concevoir** — tables, classes, contrats, API publique (avec exemples de code)
5. **Étapes d'implémentation** — checklist ordonnée, granularité commit
6. **Tests & dogfooding** — quels tests unitaires, quel test "à la main" sur corpus weecom
7. **Décisions ouvertes** — questions à trancher avant ou pendant (→ ADRs)
8. **Hors-scope du jalon** — ce qu'on ne fait pas ici (pour ne pas glisser)
9. **Bilan** (rempli à la fin) — capacité livrée, coût, surprises, go/no-go suivant

## Granularité des commits

Issue de la mémoire `feedback_big_chantiers_on_branch` : **jamais de roadmap multi-chantiers en un seul commit**.

Sur la branche `brain` :

```
Foundation commit       : la charte + méthodo + roadmap (= ce dossier)
Commit par étape jalon  : 1 commit ≈ 1 étape de la checklist du plan
Commit bilan            : remplit la section Bilan + met à jour MEMORY.md
```

Format de message — suit les Conventional Commits français du bundle (cf. AGENTS.md §2) avec scope `brain` :

```
feat(brain): description courte de l'étape

Pourquoi (1-3 lignes max, contexte si non évident).
Ref: docs/brain/06-phases/jalon-N-titre.md
```

Types utilisés sur la branche `brain` :

- `feat(brain):` — nouvelle capacité (entité, service, subscriber)
- `refactor(brain):` — restructuration sans changement fonctionnel
- `test(brain):` — ajout/modif de tests Brain
- `docs(brain):` — mise à jour de la méthodologie (`docs/brain/`) ou des guides
- `chore(brain):` — config, fixtures, scripts utilitaires

Pas de Co-Authored-By automatique sauf si le user le demande explicitement.

## check.sh est non négociable

Conformément à AGENTS.md §4, avant de considérer une étape comme terminée :

```bash
./check.sh    # PHP-CS-Fixer + PHPStan + PHPUnit
```

Si `check.sh` échoue, l'étape n'est pas terminée. On corrige avant de commiter. Cela vaut aussi pour les commits intermédiaires de jalon — un commit cassé sur la branche `brain` reste un commit cassé.

## ADRs : quand en écrire un

Un ADR (`05-decisions/NNN-titre.md`) est requis pour :

- Choix entre 2+ options techniques où la décision conditionne du code structurant
- Compromis qui s'écarte du design doc (qui reste la référence)
- Tout choix qui sera dur à inverser plus tard (modèle de données, contrat public)

Un ADR n'est **pas** requis pour :

- Choix purement esthétiques (nommage à la marge, ordre des champs)
- Décisions internes à une classe (factoring, ordre des méthodes)

Format : voir [05-decisions/000-template.md](05-decisions/000-template.md).

## Questions au user : quand

Mode normal : je tranche, j'avance, j'explique en text de session ce que j'ai décidé. Le user redirige si besoin.

Je m'arrête pour demander **seulement** quand :

- Surface UX visible côté user (UI graphe, API publique, contrat de tool) — vision côté user
- Décision irréversible et ambiguë (impossible de trancher seul honnêtement)
- ADR ouvert depuis plusieurs étapes sans clarification possible côté code

> *Cf. mémoire `feedback_overnight_autonomy` : 3 buckets clairs, commit discipline, rapport de fin de nuit obligatoire.*

## Rapports de session

À la fin d'une session active (qui livre du code), je produis 3-5 lignes dans le chat :

```
Session jalon-N (étapes A, B, C) :
- Fait : <ce qui a été commité>
- Bloqué : <s'il y a>
- Décidé : <ADRs créés, choix tranchés>
- Suivant : <prochaine étape attendue>
```

À la fin d'un jalon, je remplis la section "Bilan" du plan de phase et je propose une mise à jour de MEMORY.md.

## Mesure : pas de jalon livré sans démonstration

Le test de sortie d'un jalon doit être **rejouable** :

- Un script ou une commande Symfony (`bin/console brain:demo:jalon-N`)
- Un fichier de fixtures dérivé du dump weecom (anonymisé si besoin)
- Une assertion mesurable (taux de convergence, nb d'associations cross-aires, etc.)

Pas de "ça marche sur ma machine, regarde". Pas de capture d'écran isolée. Soit une commande qu'on relance, soit pas livré.

## Validation qualité des synapses (à partir du jalon 3) <a id="qualite-synapses"></a>

Cf. charte §2.9 — la validation expérimentale est **obligatoire**, pas optionnelle.

### Trois niveaux de test, complémentaires

1. **Tests unitaires PHPUnit** (`composer test`) — correctness syntaxique : signatures, états, erreurs attendues. Lancés à chaque commit via `./check.sh`.

2. **Benchmark sur corpus weecom** (`tools/brain-bench/` à créer) — correctness sémantique : on prend des sources réelles, on produit des synapses, on compare à des annotations humaines.

3. **Tests de régression qualité** (`packages/core/tests/Brain/Quality/`) — non-régression : un jeu de fixtures versionné qui doit toujours produire les mêmes associations. Lancés en CI à chaque jalon.

### Outils de bench à créer

- `tools/brain-bench/extract-corpus.php` — prend les JSON weecom (lecture seule), produit un set de `MemorySource` synthétiques
- `tools/brain-bench/annotate.php` — UI minimaliste (CLI ou Twig) pour annoter manuellement les associations attendues sur un échantillon
- `tools/brain-bench/score.php` — compare la sortie du brain aux annotations, calcule précision/rappel/F1
- `bin/console brain:bench:run` — orchestrateur qui lance le pipeline complet et imprime un rapport

Ces outils vivent côté hôte (`tools/brain-bench/`), **pas** dans `packages/core/src/Brain/`. Ils sont des adaptateurs côté hôte au sens charte §2.1.

### Accès aux données weecom

Le repo `stacks/weecom` contient des données réelles utilisables pour bencher. Autorisation et garde-fous :

- **Lecture seule absolue** sur les données (`.tmp/pipedrive/data/*.json`, base PostgreSQL locale)
- Si on a besoin d'un script de sondage/export qui doit cohabiter avec weecom : on crée une **branche dédiée** dans le repo weecom (nom suggéré : `synapse-brain-bench-readonly`). Cette branche est **jamais mergée** dans `main` de weecom — elle sert uniquement de zone de travail
- L'idéal reste de tout faire depuis le bundle (`tools/brain-bench/`) en accédant à weecom uniquement en lecture, mais l'option branche reste possible si nécessaire
- Aucune donnée personnelle de weecom ne quitte le poste (pas d'upload, pas de logs publiés)

### Métriques minimales par jalon

| Jalon | Métrique de qualité |
|---|---|
| 3 | Taux de convergence : 2 sources similaires → ≥80% de neurones identiques détectés |
| 4 | F1 retrieval ≥ baseline vector simple sur échantillon annoté (≥10 sources) |
| 5 | Précision polarity ≥ 0.7 sur fixtures de contradictions versionnées |
| 6 | Top-N retrieval mesurablement différent entre 2 functional networks |
| 7 | Audit UI : 100% des synapses produites sont visibles + cliquables |
| 8 | ≥1 association non-évidente par session sur corpus weecom (validation manuelle) |

### Règle de blocage qualité

Si la qualité **dégrade** entre 2 jalons (régression sur fixtures versionnées) → **bloquant**. On n'avance pas. Trois issues :

1. Corriger la régression
2. Documenter le compromis dans un ADR et accepter explicitement la dégradation
3. Reculer au jalon précédent et reprendre

Pas de "on corrigera au jalon suivant".

## Rétro <a id="rétro"></a>

Si un jalon dépasse 3 sessions actives sans livraison :

1. On suspend l'implémentation
2. On reprend le plan de phase et on liste honnêtement ce qui coince
3. Trois issues possibles :
   - Découper le jalon en 2 jalons (et mettre à jour la roadmap)
   - Réviser la surface (ADR de pivot)
   - Abandonner le jalon (et documenter pourquoi dans le bilan)

Pas de "encore une session pour voir". Au-delà de 3, on prend du recul.

## Outils utilisés à chaque session

- **TodoWrite** pour structurer les étapes du jalon en cours
- **Agent Explore** pour reconnaître des zones de code denses avant d'attaquer
- **Plan mode** pour les phases complexes (jalon 4 et 7 a priori)
- **Memory** pour persister tout principe ou décision durable
- **Sous-agents personnalisés Brain v3** (voir section ci-dessous)

## Sous-agents : règles d'usage <a id="sous-agents"></a>

Le chantier Brain v3 n'a pas de limite de tokens. Pour ne rien négliger, on s'appuie systématiquement sur des sous-agents en lecture seule qui tournent dans leur propre fenêtre de contexte et retournent un résumé. Cela préserve le contexte principal, applique des contraintes, et fournit une vue indépendante.

### Sous-agents projet dédiés Brain v3

Définis dans `.claude/agents/` à la racine du repo :

| Sous-agent | Rôle | Invocation |
|---|---|---|
| **`brain-charter-auditor`** | Relit code + doc contre la charte (`docs/brain/00-charte.md`) et le design figé (`docs/brain-v3-design.md`). Détecte vocabulaire métier, anthropomorphisation, sur-uniformisation. | Après tout ajout/modif significatif dans `src/Brain/` ou `docs/brain/` |
| **`brain-code-reviewer`** | Review technique senior : PSR-12, PHPStan, typage, Doctrine, tests, intégration event-driven, cohérence avec design. | Après chaque commit significatif sur la branche `brain`, ou avant un commit qui touche `packages/core/src/Brain/` |
| **`synapse-doc`** (existant) | Garde la doc publique en sync avec le code. | Quand la doc utilisateur de `packages/core/docs/` doit refléter du nouveau code Brain (typiquement jalon 8) |

### Sous-agents intégrés à privilégier

| Sous-agent intégré | Quand |
|---|---|
| **Explore** (Haiku, read-only) | Recherche/cartographie codebase dense avant d'attaquer un jalon. Niveau "very thorough" pour audit initial, "quick" pour lookup ciblé |
| **Plan** | Phases techniquement complexes (jalon 4 spreading activation, jalon 7 SNP+UI) — utiliser `/plan` |
| **general-purpose** | Recherches multi-étapes ou tâches qui mélangent exploration + action légère (vérification de référence externe, etc.) |

### Règles de discipline pour sous-agents

1. **Brief autosuffisant** — le sous-agent ne voit pas la conversation parent. Tout contexte nécessaire (intention, fichiers concernés, format de sortie attendu) doit être dans le prompt
2. **Briefer le format de retour** — préciser sections, longueur max, niveau de détail. Sinon retours noyés dans le générique
3. **Lancer en parallèle quand indépendants** — exemple : `brain-charter-auditor` + `brain-code-reviewer` sur le même commit = un seul message avec deux Agent tool calls
4. **Trust but verify** — un sous-agent décrit ce qu'il a *voulu* faire, pas toujours ce qu'il a fait. Si la sortie est ambiguë, je vérifie le code moi-même
5. **Ne pas dupliquer le travail** — si Explore a fait une cartographie, je n'en refais pas une avant que la précédente ne soit obsolète
6. **Read-only par défaut** — les sous-agents `brain-*` n'ont pas Write/Edit. La main reste dans le contexte parent qui décide et exécute

### Pattern de session typique avec sous-agents

```
1. Début de jalon
   └─ Agent Explore "very thorough" sur les zones de code concernées
   └─ (en // si pertinent) Agent general-purpose pour vérifier une référence externe

2. Conception
   └─ Plan mode si surface non triviale
   └─ Aller-retour avec user sur les choix de surface

3. Implémentation
   └─ Code + tests + commit
   └─ Après commit : brain-code-reviewer + brain-charter-auditor en //

4. Fin de jalon
   └─ Bilan rédigé dans le plan de phase
   └─ brain-charter-auditor une dernière fois sur l'ensemble du jalon
```

---

**Suite** :
- [03-audit-existant.md](03-audit-existant.md) — ce qui est déjà là et qu'on doit migrer
- [06-phases/](06-phases/) — plans détaillés par jalon
