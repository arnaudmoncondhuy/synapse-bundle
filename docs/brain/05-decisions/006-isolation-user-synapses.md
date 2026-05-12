---
status: accepté
date: 2026-05-13
jalon: 3
---

# ADR-006 — Garde-fou isolation user sur les synapses

## Statut

`accepté` — bloquant pour la suite (le jalon 3 produit des synapses automatiquement → premier risque de fuite cross-user).

## Contexte

L'utilisateur a explicitement prévenu (cf. mémoire `feedback-brain-user-isolation`) : *"il faut faire attention qu'un synapse ne fasse pas le lien entre des neurone de 2 user différent."* Avec une précision sur les sources "open" (sans owner) qui sont autorisées à se lier à n'importe quoi.

Sans garde-fou actif :
- Le `ConvergenceDetector` (étape 7 du jalon 3) peut créer une synapse entre 2 neurones d'utilisateurs différents si leurs embeddings sont proches → **fuite d'information**
- Le spreading activation du jalon 4 propage à travers ces synapses → un user "voit" la mémoire d'un autre
- RGPD : impossible de garantir le droit à l'oubli si les synapses traversent les frontières user

Règle à appliquer :

| Source neuron owner | Target neuron owner | Admissible |
|---|---|---|
| user X | user X | ✅ |
| user X | open (null) | ✅ — l'open enrichit le privé |
| open (null) | open (null) | ✅ — couche partagée |
| user X | user Y (X ≠ Y) | ❌ — interdit, exception |

Où appliquer ce garde-fou ?

## Options considérées

### Option A — Assertion en code dans `Synapse::__construct`

Lecture de l'`ownerId` des deux neurones (via leur `MemorySource`), levée d'exception si différents et tous deux non-null.

**Avantages** :
- Centralisé : toute création de Synapse passe par le constructeur → garde-fou universel
- Erreur immédiate au moment de la création, pas plus tard à l'usage
- Testable unitairement avec stubs

**Inconvénients** :
- Nécessite de **résoudre l'ownerId** depuis le neurone, donc une requête supplémentaire vers `MemorySource` ou de stocker l'`ownerId` redondamment dans chaque neurone
- Si un caller bypasse le constructeur (Doctrine hydrate via reflection au load), la règle n'est plus active à la création depuis BDD — mais ça concerne juste le rechargement, pas la création initiale

### Option B — Trigger SQL

Trigger PostgreSQL qui vérifie avant INSERT sur `syn_brain_synapse` que les `owner_id` des sources correspondantes sont compatibles.

**Avantages** :
- Couvre TOUS les chemins d'écriture, y compris bypass code
- Garantie au niveau SGBD

**Inconvénients** :
- FK polymorphe → trigger complexe (le neurone source ou target peut être dans 7 tables différentes selon l'aire)
- Lecture du `MemorySource` pour chaque insertion = perte de perf
- Code SQL spécifique PostgreSQL → migration MySQL difficile
- Erreur SQL moins ergonomique côté code (pas typée)

### Option C — Les deux (ceinture + bretelles)

Assertion code dans Synapse::__construct + trigger SQL.

**Avantages** : Tous les filets.
**Inconvénients** : Maintenance double, trigger SQL toujours complexe.

## Décision

**Option A retenue : assertion en code dans `Synapse::__construct`.**

Justification :

1. **Centralisation effective** : aujourd'hui toutes les créations de Synapse passent par `new Synapse(...)`. Pas de path bypass à corriger
2. **Simplicité** vs trigger SQL polymorphe complexe
3. **Erreurs typées** côté PHP (`SynapseUserIsolationViolationException` à créer, hérite de `\InvalidArgumentException`)
4. **Performance** : on stocke l'`ownerId` directement dans la `Synapse` (champ dénormalisé `source_neuron_owner` + `target_neuron_owner`) au moment de la création — pas de jointure runtime ultérieure
5. **Évolution possible** : si on découvre plus tard qu'un chemin bypass existe (rechargement Doctrine hydraté mal contrôlé, etc.), on ajoutera un trigger SQL en complément. Pour l'instant l'assertion suffit

Implémentation :

```php
final class Synapse
{
    public function __construct(
        MemoryFragment $source,
        MemoryFragment $target,
        ?Uuid $sourceOwnerId,
        ?Uuid $targetOwnerId,
        // ... autres params
    ) {
        $this->assertUserIsolation($sourceOwnerId, $targetOwnerId);
        // ... reste du constructor
    }

    private function assertUserIsolation(?Uuid $sourceOwner, ?Uuid $targetOwner): void
    {
        if (null === $sourceOwner || null === $targetOwner) {
            return; // au moins un est open → admissible
        }
        if ($sourceOwner->equals($targetOwner)) {
            return; // même user → admissible
        }
        throw new SynapseUserIsolationViolationException(
            $this->sourceNeuronArea,
            $this->sourceNeuronId,
            $this->targetNeuronArea,
            $this->targetNeuronId,
        );
    }
}
```

Les callers (notamment `ConvergenceDetector`) doivent fournir les `ownerId` qu'ils ont récupérés des `MemorySource` des deux neurones.

## Conséquences

- **Code impacté** :
  - `Synapse` : 2 nouveaux paramètres constructeur (`?Uuid $sourceOwnerId`, `?Uuid $targetOwnerId`), 2 nouvelles colonnes `source_neuron_owner` / `target_neuron_owner` (nullable, indexées)
  - Nouvelle exception `Brain\Exception\SynapseUserIsolationViolationException`
  - `ConvergenceDetector` doit récupérer `ownerId` côté source de chaque neurone candidat avant tentative de création
- **Migrations** : ajout des 2 colonnes + 2 index sur `syn_brain_synapse` (migration `migrations-brain-v3/jalon-3/`)
- **Tests** : `SynapseUserIsolationTest` avec 4 cas (privé-privé même, privé-open, open-open, privé-privé différent → exception)
- **Documentation** : à intégrer dans le README brain pour les apps hôtes (warning sur les conséquences d'une violation)
- **ADRs à suivre** :
  - Si un cas de bypass est identifié plus tard → ADR pour ajouter un trigger SQL en complément
  - Si on veut un mode "soft" (warning au lieu d'exception) pour debug → ADR séparé

## Notes

- L'enrichissement `source_neuron_owner` / `target_neuron_owner` dans la table Synapse permettra aussi le **filtrage rapide par owner** du `MemoryRetriever` au jalon 4 (spreading activation par user), sans jointure répétée
- Compatible avec `feedback-brain-user-isolation`

---

*Décidé pendant le démarrage du jalon 3 (2026-05-13).*
