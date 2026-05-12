---
status: proposé
date: 2026-05-12
jalon: 1
---

# ADR-001 — Préfixe SQL configurable via `synapse.persistence.table_prefix`

## Statut

`proposé` — à valider avant attaque du jalon 1.

## Contexte

Les 22 entités actuelles du bundle utilisent un préfixe `synapse_` **hardcodé** dans les annotations Doctrine (`#[ORM\Table(name: 'synapse_...')]`). Brain v3 introduit un nouveau partitionnement :

- `syn_brain_*` pour les tables de cognition (7 aires + synapses + signaux + audit)
- `syn_core_*` pour les tables d'infrastructure (agents, providers, presets, accounting, ...)

Le design figé (`docs/brain-v3-design.md` §3) précise que le préfixe doit être **configurable** par l'app hôte (`synapse.persistence.table_prefix: 'acme_'`) pour éviter les collisions avec les tables métier. Cette décision conditionne :

- Comment on déclare les tables dans les entités Doctrine
- Comment l'app hôte change le préfixe
- Comment la migration depuis `synapse_*` se passe

Trancher cet ADR **avant** de toucher la moindre entité — sinon on devra tout refactorer.

## Options considérées

### Option A — Naming Strategy Doctrine custom

Créer un `SynapseNamingStrategy` qui implémente `Doctrine\ORM\Mapping\NamingStrategy`. Les entités déclarent leur nom de table en `brain_neuron_episodic`, et la naming strategy ajoute le préfixe configurable au runtime.

**Avantages** :
- Idiomatic Doctrine
- Centralisé : un seul point de configuration
- Compatible PHPStan / IDE (les annotations restent lisibles)
- Pas de magie au boot

**Inconvénients** :
- Naming strategy globale → impacte aussi les colonnes / FK si pas configuré finement
- Un projet hôte qui a déjà une naming strategy doit composer (chaîne ou héritage)

### Option B — Subscriber `loadClassMetadata`

Un `EventSubscriber` Doctrine qui intercepte `loadClassMetadata` et réécrit le nom de table au runtime.

**Avantages** :
- Ciblé : on ne touche que les tables Synapse, pas les colonnes
- Coexiste sans conflit avec une naming strategy hôte
- Pattern déjà utilisé dans le bundle (cf. `Synapse*Subscriber.php`)

**Inconvénients** :
- Légère magie : le nom de table en BDD ≠ celui dans l'annotation
- Debugging plus délicat (un `\bin\console doctrine:schema:create --dump-sql` montre le résultat final, mais pas direct)

### Option C — Constante de classe + compiler pass

Chaque entité déclare `public const TABLE_NAME = 'brain_neuron_episodic'`. Un compiler pass DI lit la config `synapse.persistence.table_prefix` et passe la valeur préfixée au mapping Doctrine via un système custom.

**Avantages** :
- Pas de magie runtime
- Préfixe visible dans le code

**Inconvénients** :
- Reinvente Doctrine
- Lourd à maintenir pour 22+ entités
- Couplage fort au container Symfony

## Décision

**Option B retenue : EventSubscriber `loadClassMetadata`.**

Justification :

1. Pattern **déjà familier** au bundle — moins de surcoût cognitif que d'introduire une naming strategy custom
2. **N'interfère pas** avec une naming strategy hôte éventuelle (un projet client peut avoir la sienne)
3. **Ciblé** : on ne préfixe que les tables Synapse identifiées par un marker interface ou un namespace racine `ArnaudMoncondhuy\Synapse*`
4. Magie acceptable car **localisée** et documentée

Implementation prévue (jalon 1) :

```php
namespace ArnaudMoncondhuy\SynapseCore\Bridge\Doctrine;

final readonly class TablePrefixSubscriber implements EventSubscriberInterface
{
    public function __construct(private string $prefix) {} // injecté depuis config

    public function loadClassMetadata(LoadClassMetadataEventArgs $args): void
    {
        $metadata = $args->getClassMetadata();
        $className = $metadata->getName();

        if (!str_starts_with($className, 'ArnaudMoncondhuy\\Synapse')) {
            return;
        }

        $current = $metadata->getTableName();
        if (!str_starts_with($current, $this->prefix)) {
            $metadata->setPrimaryTable(['name' => $this->prefix . $current]);
        }
    }

    public static function getSubscribedEvents(): array
    {
        return [Events::loadClassMetadata];
    }
}
```

Les entités déclarent leur nom **sans préfixe** : `#[ORM\Table(name: 'brain_neuron_episodic')]`. Le subscriber ajoute le préfixe `syn_` (défaut) au runtime.

Config (racine `synapse`, alignée sur `packages/core/src/DependencyInjection/Configuration.php`) :

```yaml
# config/packages/synapse.yaml
synapse:
  persistence:
    table_prefix: 'syn_'   # défaut
```

## Conséquences

- **Code impacté** : nouveau service `TablePrefixSubscriber` + binding DI dans `synapse_core.yaml`. Annotations `#[ORM\Table]` des 22 entités existantes : renommer de `synapse_xxx` → `core_xxx` ou `brain_xxx` selon nature
- **Migrations** : scripts SQL fournis aux apps hôtes : `ALTER TABLE synapse_xxx RENAME TO syn_core_xxx;` etc. Un seul script de migration par entité
- **Tests** : ajouter un test unitaire `TablePrefixSubscriberTest` qui vérifie le préfixe avec valeur par défaut + valeur custom. Vérifier que `doctrine:schema:create --dump-sql` génère les bons noms
- **Documentation** : mettre à jour `packages/core/docs/getting-started/installation.md` avec la nouvelle clé de config
- **ADRs à suivre** :
  - ADR-002 (à écrire) : stratégie de migration data des apps hôtes existantes
  - ADR-003 (à écrire) : faut-il livrer DoctrineMigrationsBundle dans synapse-bundle ou laisser l'app hôte gérer ?

## Notes

- Pattern Doctrine officiel : [Doctrine Events documentation](https://www.doctrine-project.org/projects/doctrine-orm/en/latest/reference/events.html#loadclassmetadata)
- Le préfixe défaut `syn_` (au lieu de `synapse_`) évite la répétition `synapse_brain_*` ; conforme charte §2.7

---

*À valider avec le user avant attaque jalon 1.*
