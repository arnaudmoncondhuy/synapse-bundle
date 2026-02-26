# Prochaines Étapes - Publication Packagist

## ✅ Fait aujourd'hui (26 Février 2026)

1. ✅ **Nettoyage du monorepo**
   - Suppression des anciens fichiers du bundle monolithique
   - Conservation des 3 packages séparés (core, admin, chat)

2. ✅ **Installation propre de Basile**
   - Symfony propre avec les 3 bundles Synapse injectés
   - Tous les namespaces corrigés (SynapseBundle → SynapseCore)
   - Toutes les 66 routes fonctionnelles avec accès admin par défaut
   - Test complet ✓

3. ✅ **Préparation pour Packagist**
   - README.md pour chaque package (core, admin, chat)
   - LICENSE copiée dans chaque package
   - Guides complets (PACKAGIST_MIGRATION_STRATEGY.md, PACKAGIST_PUBLICATION_GUIDE.md)
   - VERSION_STRATEGY.md avec versioning dev 0.260226
   - Script de validation (scripts/publish.sh)

## 📋 À faire maintenant (Prochaines heures/jours)

### Phase 1 : Valider la structure (15 min)

```bash
cd /home/ubuntu/stacks/synapse-bundle

# Exécuter le script de validation
./scripts/publish.sh 0.260226

# Devrait afficher :
# ✓ core structure OK
# ✓ admin structure OK
# ✓ chat structure OK
# ✓ Dépendances OK
# ✅ Pré-publication check complète !
```

### Phase 2 : Créer les tags Git (5 min)

```bash
# Créer les tags pour chaque package
git tag packages/core-0.260226
git tag packages/admin-0.260226
git tag packages/chat-0.260226

# Vérifier les tags
git tag -l | grep packages

# Pusher les tags
git push origin --tags
```

### Phase 3 : Enregistrer sur Packagist (30 min, MANUEL)

1. **Aller sur** : https://packagist.org/packages/submit

2. **Enregistrer Package 1 : Core**
   - Repository URL : `https://github.com/arnaudmoncondhuy/synapse-bundle.git`
   - Subdirectory : `packages/core`
   - Submit

3. **Enregistrer Package 2 : Admin**
   - Repository URL : `https://github.com/arnaudmoncondhuy/synapse-bundle.git`
   - Subdirectory : `packages/admin`
   - Submit

4. **Enregistrer Package 3 : Chat**
   - Repository URL : `https://github.com/arnaudmoncondhuy/synapse-bundle.git`
   - Subdirectory : `packages/chat`
   - Submit

Chaque package va créer une URL :
- `https://packagist.org/packages/arnaudmoncondhuy/synapse-core`
- `https://packagist.org/packages/arnaudmoncondhuy/synapse-admin`
- `https://packagist.org/packages/arnaudmoncondhuy/synapse-chat`

### Phase 4 : Configurer les Webhooks GitHub (10 min)

Pour chaque package sur Packagist :

1. **Aller dans** : https://packagist.org/packages/arnaudmoncondhuy/synapse-core
2. **Settings** → **GitHub Service Hook**
3. **Enable Service Hook**
4. Vérifier que le webhook apparaît dans :
   - https://github.com/arnaudmoncondhuy/synapse-bundle/settings/hooks

Répéter pour admin et chat.

### Phase 5 : Tester la publication (10 min)

```bash
# Test 1 : Packagist voir les versions
curl https://repo.packagist.org/packages/arnaudmoncondhuy/synapse-core.json | jq '.versions'

# Expected output:
# {
#   "dev-main": { ... },
#   "0.260226": { ... }
# }

# Test 2 : Basile récupère de Packagist
cd ../basile

# Modifier composer.json (optionnel - pour tester sans path://)
# "repositories": [] (vider les path://)
# "require": {
#   "arnaudmoncondhuy/synapse-core": "^0.26",
#   ...
# }

# composer update

# Vérifier que les dépendances sont installées
php bin/console debug:router | grep synapse | wc -l
# Expected: 66
```

## 📅 Timeline recommandée

| Moment | Action |
|--------|--------|
| **Jour 1** | Phase 1-2 (validation + tags) |
| **Jour 1-2** | Phase 3 (Packagist manual registration) |
| **Jour 2-3** | Phase 4 (GitHub webhooks) |
| **Jour 3** | Phase 5 (Testing) |

## 🚀 Après publication

### Communication aux utilisateurs

1. Créer une issue GitHub : "Migration guide v0.260226"
2. Publier un post / documentation : "Synapse 3 Packages Released"
3. Mettre à jour le README principal avec le guide de migration

### Versions futures

```bash
# Pour la prochaine version (ex: 0.260228)
git tag packages/core-0.260228
git tag packages/admin-0.260228
git tag packages/chat-0.260228
git push origin --tags

# Packagist détecte automatiquement et crée les nouvelles versions
# (grâce aux webhooks)
```

## ⚠️ Points importants

### Ne pas faire

❌ Ne pas modifier les composer.json pour ajouter une "version"
❌ Ne pas créer un tag racine unique (chaque package son tag)
❌ Ne pas oublier le webhook GitHub après Packagist registration

### À vérifier

✅ Tous les namespaces sont PSR-4 (ArnaudMoncondhuy\SynapseCore\)
✅ Pas de dépendances circulaires (core ← admin, core ← chat)
✅ composer validate passe sur chaque package
✅ LICENSE et README.md présents partout

## 📖 Documentation

- [PACKAGIST_MIGRATION_STRATEGY.md](./PACKAGIST_MIGRATION_STRATEGY.md) - Contexte et stratégie
- [PACKAGIST_PUBLICATION_GUIDE.md](./PACKAGIST_PUBLICATION_GUIDE.md) - Guide détaillé
- [VERSION_STRATEGY.md](./VERSION_STRATEGY.md) - Versioning expliqué
- [scripts/publish.sh](./scripts/publish.sh) - Script de validation
- [packages/core/README.md](./packages/core/README.md) - Doc core
- [packages/admin/README.md](./packages/admin/README.md) - Doc admin
- [packages/chat/README.md](./packages/chat/README.md) - Doc chat

## ❓ Questions ?

Consulte les guides ci-dessus ou les documentations Packagist officielles :
- https://packagist.org/about
- https://docs.github.com/en/webhooks

Succès ! 🎉
