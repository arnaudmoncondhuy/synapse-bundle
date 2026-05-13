---
status: accepté
date: 2026-05-13
jalon: 4
---

# ADR-009 — Saturation soft + pattern event-driven contre l'emballement des poids synaptiques

## Statut

`accepté` — décidé pendant l'étape 7 du jalon 4 (HebbianReinforcer).

## Contexte

Le `HebbianReinforcer` du jalon 4 renforce les poids des synapses co-activées dans une requête (pattern Hebbien : *fire together, wire together*). Sans garde-fou :

- **Emballement haut** : retrieval répété sur les mêmes neurones → renforcement répété → tous les poids convergent vers 1.0 → perte d'information (impossible de distinguer "très fort" de "fort")
- **Boucle d'écho** : une chaîne renforcée devient plus probable au retrieval suivant → renforcée encore → concentration excessive sur quelques chaînes

Question : quel mécanisme empêche l'emballement ? Et plus généralement : où sont les **points d'extension** pour ajouter d'autres sécurités sans refactor ?

## Options considérées

### Option A — Renforcement linéaire avec clamp simple

```
weight = min(1.0, weight + delta)
```

**Inconvénient** : sature brutalement à 1.0 après ~20 renforcements. Plus aucune distinction au-dessus du seuil.

### Option B — Saturation soft (asymptotique)

```
weight = weight + delta × (1 - weight)
```

Plus on est haut, moins on monte. Asymptote vers 1.0 sans jamais l'atteindre.

Comportement (δ = 0.05) :
| Itération | Weight |
|---|---|
| 0 | 0.50 |
| 1 | 0.525 |
| 5 | 0.613 |
| 10 | 0.701 |
| 20 | 0.821 |
| 50 | 0.957 |
| 100 | 0.994 |
| ∞ | 1.000 (asymptote) |

**Avantage** : distinction préservée. Une synapse renforcée 100× est toujours distinguable d'une renforcée 50×. La règle Hebbienne classique est respectée (more = stronger) tout en évitant la perte d'information par saturation.

### Option C — Compétition latérale (somme constante)

Quand un poids monte, ses voisins (synapses sortantes du même neurone) descendent proportionnellement, gardant une somme constante. Pattern Kohonen / SOM.

**Reporté jalon 8** : complexité O(degree²) par renforcement, nécessite un service de normalisation par neurone. Pas indispensable au jalon 4.

### Option D — Decay continu + renforcement

Un cron périodique décroît tous les poids ; le renforcement les remonte. Équilibre dynamique.

**Reporté jalon 8** (`MemoryDecayService` déjà prévu dans le plan).

### Option E — Pattern event-driven (transversal)

Toute mutation de synapse dispatche un event typé. Les sécurités s'ajoutent comme listeners sans toucher au code central.

**Adopté en complément de B** — cf. [[feedback-brain-event-driven-synapse-mutations]] (user 2026-05-13).

## Décision

**Combinaison B + E retenue pour le jalon 4 :**

1. **Saturation soft** dans `HebbianReinforcer::reinforce()` :
   ```php
   $newWeight = $synapse->getWeight() + $delta * (1.0 - $synapse->getWeight());
   $synapse->setWeight(max(0.0, min(1.0, $newWeight)));
   ```
   Avec `δ = 0.05` (`HebbianReinforcer::DEFAULT_DELTA`) — calibrable plus tard via constructor.

2. **Pattern event-driven** : `HebbianReinforcer` dispatche un `SynapseReinforcedEvent` après chaque modification (avec valeur AVANT et APRÈS). Les sécurités futures se branchent comme listeners.

3. **Reportés au jalon 8** : decay continu, compétition latérale, habituation, normalization batch. Tous écrivables comme listeners de events Brain sans refactor.

## Conséquences

- **Code (jalon 4 étape 7)** :
  ```php
  // Brain\Service\Retrieval\HebbianReinforcer
  final readonly class HebbianReinforcer
  {
      public const DEFAULT_DELTA = 0.05;

      public function __construct(
          private EventDispatcherInterface $dispatcher,
          private float $delta = self::DEFAULT_DELTA,
      ) {}

      public function reinforce(Synapse $synapse): void
      {
          $oldWeight = $synapse->getWeight();
          $newWeight = $oldWeight + $this->delta * (1.0 - $oldWeight);
          $newWeight = max(0.0, min(1.0, $newWeight));

          if ($newWeight === $oldWeight) {
              return; // déjà au max
          }

          $synapse->setWeight($newWeight);
          $synapse->recordCorroboration(); // increment evidenceCount + lastActivatedAt

          $this->dispatcher->dispatch(new SynapseReinforcedEvent(
              synapse: $synapse,
              oldWeight: $oldWeight,
              newWeight: $newWeight,
              cause: 'hebbian_co_activation',
          ));
      }
  }
  ```

- **Nouvel event** : `Brain\Event\SynapseReinforcedEvent` (immutable, porte synapse + oldWeight + newWeight + cause)

- **Refacto attendu** :
  - `ConvergenceDetector` (jalon 3) ne dispatche pas encore d'event sur la création de synapse. À refactor (jalon 5 ou avant le test profondeur weecom)
  - `Synapse::setWeight` reste public mais sans event — les callers doivent passer par `HebbianReinforcer` pour bénéficier de l'event. Documenter dans la PHPDoc

- **Tests** : `HebbianReinforcerTest` vérifie :
  - Saturation soft (poids monte mais pas linéairement)
  - Clamp [0, 1] (jamais hors borne)
  - Event dispatché avec valeurs AVANT/APRÈS
  - Aucun dispatch si pas de changement (synapse déjà à 1.0)

- **ADRs à suivre** :
  - Jalon 5+ : refacto `ConvergenceDetector` pour dispatcher `SynapseCreatedEvent`
  - Jalon 8 : ADRs sur decay continu, compétition latérale (si bench montre besoin)

## Notes

- **Choix de `δ = 0.05`** : valeur conservatrice. Plus haut = renforcement plus rapide mais saturation plus vite. À recalibrer empiriquement sur le bench retrieval (étape 11) ou le test profondeur weecom
- **Pas de mutation directe via setWeight** sans event : règle à enforcer plus tard si on découvre des bypass. Pour l'instant, discipline manuelle (cf. memory `feedback-brain-event-driven-synapse-mutations`)

---

*Décidé à l'étape 7 du jalon 4 (2026-05-13), sur prompt utilisateur sur l'anti-emballement.*
