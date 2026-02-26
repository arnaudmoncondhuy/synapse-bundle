# Stratégie de Versioning - Synapse 3 Packages

## Version Actuelle : 0.260226

**Explication** :
- `0` = Version majeure 0 (pré-1.0, development/beta)
- `26` = Février (mois 2) → 26ème jour
- `0226` = Jour (26) + Jour du mois en 2 chiffres (02) + Jour (26)

Plus simplement : **0.26.0** = Février 2026, Release 0

## Utilisation

### Dans chaque composer.json

```json
{
    "name": "arnaudmoncondhuy/synapse-core",
    "description": "...",
    "license": "PolyForm-Noncommercial-1.0.0",
    ...
}
```

**Note** : Ne pas ajouter `"version": "..."` dans composer.json - Packagist le déduit automatiquement du tag git.

### Tagging Git

```bash
# Créer les tags pour chaque package
git tag packages/core-0.260226
git tag packages/admin-0.260226
git tag packages/chat-0.260226

# Push les tags
git push origin --tags
```

## Évolution future

### Prochaines releases

```
0.260226  → v0 (Feb 26, 2026) - Release initiale
0.260228  → v1 (Feb 28, 2026) - Corrections urgentes
0.030326  → v2 (Mar 03, 2026) - Nouvelles features
0.050426  → v3 (Apr 05, 2026) - Stable
1.0.0     → v4 (?)            - Production ready
```

### Lorsque passer à 1.0.0

Critères :
- Toutes les APIs stabilisées (pas de breaking changes)
- Tests exhaustifs (unit + integration)
- Documentation complète
- Au moins 100 utilisateurs externes
- Retours positifs de la communauté

## Comparabilité multi-versions

### Requirements dans composer (utilisateurs finaux)

```json
{
    "require": {
        "arnaudmoncondhuy/synapse-core": "^0.26",
        "arnaudmoncondhuy/synapse-admin": "^0.26",
        "arnaudmoncondhuy/synapse-chat": "^0.26"
    }
}
```

Cela signifie : compatible avec 0.26.x, 0.27.x, etc., mais **pas** 1.0.0 (breaking).

### Backward compatibility

Entre 0.26.0 et 0.26.x :
- ✅ Nouvelles fonctionnalités autorisées
- ✅ Dépréciations autorisées (avec warnings)
- ❌ Breaking changes interdits

Entre 0.26.x et 0.27.0 :
- ✅ Nouvelles fonctionnalités
- ✅ Dépréciations
- ✅ Breaking changes mineures (si nécessaire)

Entre 0.x et 1.0.0 :
- Breaking changes complètes autorisées

## Maintenance des anciennes versions

### Abandon du bundle monolithique

L'ancien `arnaudmoncondhuy/synapse-bundle` (avant split) :
- Dernière version : la dernière avant le split
- Marquée comme deprecated sur Packagist
- Pas de nouvelles versions (à moins de wrapper meta)

### Support 1.0 meta-package (optionnel)

Si créer version 1.0.0 du bundle monolithique qui dépend des 3 packages :

```json
{
    "name": "arnaudmoncondhuy/synapse-bundle",
    "description": "[DEPRECATED] Use synapse-core, synapse-admin, synapse-chat",
    "type": "metapackage",
    "version": "1.0.0",
    "require": {
        "arnaudmoncondhuy/synapse-core": "^0.26",
        "arnaudmoncondhuy/synapse-admin": "^0.26",
        "arnaudmoncondhuy/synapse-chat": "^0.26"
    }
}
```

Permet aux anciennes installations de faire `composer update` et récupérer les 3 packages automatiquement.

## Checklist versioning

- [ ] Root composer.json sans "version" explicite
- [ ] packages/*/composer.json sans "version" explicite
- [ ] Tags git créés : `packages/core-0.260226`, etc.
- [ ] Tags pushés : `git push origin --tags`
- [ ] VERSION_STRATEGY.md dans le repo
- [ ] MIGRATION.md guide pour utilisateurs existants

## Mise à jour de version

Quand passer à 0.260228 ou 0.030326 :

```bash
# 1. Mettre à jour les tags
git tag packages/core-0.260228
git tag packages/admin-0.260228
git tag packages/chat-0.260228

# 2. Push
git push origin --tags

# 3. Packagist détecte automatiquement les nouveaux tags
# et crée les nouvelles versions des 3 packages
```

Aucun changement ne doit être fait aux composer.json - la version vient du tag git.
