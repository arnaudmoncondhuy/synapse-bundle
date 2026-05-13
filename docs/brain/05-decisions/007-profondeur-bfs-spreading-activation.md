---
status: accepté (amendé 2026-05-13)
date: 2026-05-13
jalon: 4
---

# ADR-007 — Critère d'arrêt du spreading activation : score cumulé + hard cap

## Statut

`accepté` (version amendée 2026-05-13 — version initiale "2 sauts fixes" remplacée par "score cumulé + hard cap" sur proposition utilisateur).

## Contexte

Le `SpreadingActivation` du jalon 4 propage depuis les neurones seeds vers leurs voisins via les synapses. Quand s'arrêter ?

**Version initiale (rejetée)** : profondeur BFS fixe (2 sauts par défaut). Simple mais arbitraire — ne tient pas compte de la qualité de la chaîne.

**Constatation** : la pertinence d'un saut dépend du score cumulé, pas du nombre brut. Une chaîne de 4 sauts avec décroissance 0.95 par saut donne 0.95⁴ ≈ 0.81 (encore pertinent). Une chaîne de 2 sauts avec décroissance 0.70 donne 0.49 (déjà fort dégradé).

Décroissance par saut variable selon :
- Force de la synapse (poids 0-1)
- Confiance (0-1)
- Decay constant par saut (ADR-008, defaut 0.7)

→ Le **score cumulé** est le vrai signal d'arrêt.

## Options considérées

### Option A — Profondeur fixe (rejetée)

`maxDepth = 2` (ou 3). Simple à implémenter, prévisible en perf.

**Inconvénients** : arbitraire. Coupe des chaînes pertinentes (synapses fortes à 3+ sauts). Garde des chaînes peu pertinentes (synapses faibles à 1-2 sauts).

### Option B — Score cumulé + hard cap (retenu)

L'algorithme propage **tant que** `score(neurone) ≥ minScore`. Une **hard cap** à X sauts protège contre les graphes denses avec poids très forts (où la décroissance serait trop lente).

```
while queue not empty:
    (current, depth, score) = queue.dequeue()

    // Arrêt principal : score insuffisant
    if score < query.minScore:
        skip propagation

    // Sécurité : profondeur excessive
    if depth >= HARD_MAX_DEPTH:
        skip propagation

    // ... propage
```

**Avantages** :
- S'adapte naturellement à la qualité du graphe
- Permet d'aller loin sur des chaînes solides (synapses bien renforcées)
- Coupe automatiquement sur chaînes faibles (même à 1 saut si confidence basse)
- La hard cap reste un filet de sécurité, pas un critère métier

### Option C — Apprentissage du seuil

Apprendre `minScore` optimal par requête via gradient descent / RL.

**Rejeté** : hors-scope jalon 4. Énorme overhead pour un gain incertain. À reconsidérer au jalon 6+ avec functional networks contextuels.

## Décision

**Option B retenue : arrêt par score cumulé + hard cap profondeur.**

Paramètres :
- `RetrievalQuery::$minScore` (défaut 0.1) — critère d'arrêt principal. Tout neurone touché avec score < minScore n'est pas inclus dans le résultat ET ne propage pas plus loin
- `SpreadingActivation::HARD_MAX_DEPTH = 5` — sécurité absolue, jamais dépassée même si le score reste élevé
- `RetrievalQuery::$maxDepth` (défaut 5) — borne user-configurable, plafonnée par `HARD_MAX_DEPTH`

Justification du `minScore = 0.1` par défaut :
- En dessous, un neurone serait juste du bruit (score < 10% du seed initial)
- Couplé avec `decay = 0.7` (ADR-008), permet jusqu'à `log(0.1) / log(0.7) ≈ 6.5` sauts dans un cas idéal (synapses parfaites). En pratique avec `weight × confidence ≈ 0.6`, on s'arrête à ~3-4 sauts. La hard cap à 5 sauts est cohérente.

Comportement attendu :
- Synapses fortes (weight=0.95, confidence=0.95) + decay 0.7 → cumul ~0.85 par saut → propage jusqu'à ~5 sauts (hard cap)
- Synapses moyennes (weight=0.6, confidence=0.6) + decay 0.7 → cumul ~0.25 par saut → propage 2 sauts puis stop
- Synapses faibles (weight=0.3, confidence=0.4) → cumul ~0.08 par saut → stop immédiatement (score < minScore)

## Conséquences

- **Code** :

```php
final readonly class RetrievalQuery
{
    public function __construct(
        // ...
        public int $maxDepth = 5,         // amendé : était 2
        public float $minScore = 0.1,     // amendé : critère principal d'arrêt
    ) {}
}

final readonly class SpreadingActivation
{
    public const HARD_MAX_DEPTH = 5;
    public const DEFAULT_DECAY_PER_HOP = 0.7;

    public function expand(RetrievalQuery $query, array $seeds): array
    {
        $effectiveMaxDepth = min($query->maxDepth, self::HARD_MAX_DEPTH);
        // BFS classique avec :
        //   if (score < query.minScore) continue;
        //   if (depth >= effectiveMaxDepth) continue;
    }
}
```

- **Tests** : `SpreadingActivationTest` doit couvrir :
  - Graphe avec synapses fortes → propagation jusqu'à hard cap
  - Graphe avec synapses faibles → arrêt au seuil minScore
  - Combinaison : chaîne forte + branche faible → arrêt sélectif

- **Doc** : Command `brain:query --min-score=0.05 --max-depth=8` permet de tester ad-hoc en assouplissant

- **ADRs à suivre** :
  - Bench retrieval (étape 11) : mesurer l'effet du `minScore` sur F1
  - Jalon 6 (functional networks) : `minScore` pourra varier selon le mode (mode "discovery" → minScore bas, mode "focus" → minScore haut)

## Apprentissage

L'idée initiale "profondeur fixe" est l'archétype du **réglage à l'œil** que la charte §2.10 combat. La proposition utilisateur (score cumulé) est plus principielle : on s'arrête quand ça n'apporte plus de valeur, pas après un nombre arbitraire de sauts. Le nombre de sauts devient un filet de sécurité, pas une décision métier.

Cohérent avec [[feedback-brain-synapse-calibration]] : le bon paramètre émerge d'une logique fonctionnelle (qualité du score), pas d'une heuristique arbitraire.

---

*Décidé au démarrage du jalon 4, amendé immédiatement après proposition utilisateur (2026-05-13).*
