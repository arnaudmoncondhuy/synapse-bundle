# Guide de Publication Packagist - Synapse 3 Packages

Versioning : **0.260226** (dev, date-based) pour maintenir cohérence avec l'ancien bundle

---

## 📋 Checklist Pre-Publication

### Pour chaque package (core, admin, chat)

- [ ] README.md présent ✓
- [ ] LICENSE présent ✓
- [ ] composer.json avec namespace correct ✓
- [ ] `src/SynapseCoreBundle.php` (ou admin/chat variant) ✓
- [ ] `src/Infrastructure/DependencyInjection/SynapseCoreExtension.php` (ou variant) ✓
- [ ] Pas de dépendances circulaires
- [ ] `composer validate` passe

### Root monorepo

- [ ] Root composer.json pour dev local
- [ ] PACKAGIST_MIGRATION_STRATEGY.md explique la transition
- [ ] Tags git préparés

---

## 🚀 Étapes de Publication

### Étape 1 : Préparer les Bundles

Vérifier que chaque bundle a sa classe principale :

```bash
# Core
ls -la packages/core/src/SynapseCoreBundle.php

# Admin
ls -la packages/admin/src/SynapseAdminBundle.php

# Chat
ls -la packages/chat/src/SynapseChatBundle.php
```

Si fichiers manquants, créer :

```php
<?php declare(strict_types=1);
namespace ArnaudMoncondhuy\SynapseCore;

use Symfony\Component\HttpKernel\Bundle\Bundle;

class SynapseCoreBundle extends Bundle {}
```

### Étape 2 : Vérifier les composer.json

Chaque package doit avoir :

```json
{
    "name": "arnaudmoncondhuy/synapse-core",
    "type": "symfony-bundle",
    "license": "PolyForm-Noncommercial-1.0.0",
    "description": "...",
    "autoload": {
        "psr-4": {
            "ArnaudMoncondhuy\\SynapseCore\\": "src/"
        }
    },
    "minimum-stability": "dev",
    "prefer-stable": true
}
```

**Important** : Ne PAS mettre de "version" explicit - Packagist le déduit du tag git.

### Étape 3 : Vérifier composer dans basile

**basile/composer.json** doit référencer les packages :

```json
{
    "require": {
        "arnaudmoncondhuy/synapse-core": "dev-main",
        "arnaudmoncondhuy/synapse-admin": "dev-main",
        "arnaudmoncondhuy/synapse-chat": "dev-main"
    },
    "repositories": [
        {
            "type": "path",
            "url": "../synapse-bundle/packages/core",
            "options": {"symlink": true}
        }
    ]
}
```

Localement (dev), composer utilise les `path://` repositories (symlinks).
Sur Packagist, composer télécharge les vrais packages.

### Étape 4 : Valider chaque package

```bash
cd packages/core
composer validate
# Devrait afficher "valid"

cd ../admin
composer validate

cd ../chat
composer validate
```

### Étape 5 : Tags Git

Créer des tags pour chaque package avec la branche. Les tags doivent refléter la structure monorepo :

```bash
# Option A : Tags monorepo (recommandé)
git tag packages/core-0.260226
git tag packages/admin-0.260226
git tag packages/chat-0.260226

# Option B : Tags roots (plus simple pour début)
# Utiliser le même tag pour tous : 0.260226
# Packagist récupèrera tous les packages du monorepo
```

**Note** : La plupart des monorepos utilisent une approche "root tag" où un seul tag déclenche la publication de tous les packages.

Pour Packagist, il faut d'abord **enregistrer manuellement** les 3 packages séparés.

### Étape 6 : Publier sur Packagist

#### 6a. Créer les packages sur Packagist

1. Aller sur https://packagist.org/
2. Login avec le compte `arnaudmoncondhuy`
3. **"Submit Package"** → https://packagist.org/packages/submit

Soumettre 3 fois :

**Package 1 : Core**
```
Repository URL: https://github.com/arnaudmoncondhuy/synapse-bundle.git
Subdirectory: packages/core
```

**Package 2 : Admin**
```
Repository URL: https://github.com/arnaudmoncondhuy/synapse-bundle.git
Subdirectory: packages/admin
```

**Package 3 : Chat**
```
Repository URL: https://github.com/arnaudmoncondhuy/synapse-bundle.git
Subdirectory: packages/chat
```

#### 6b. Ajouter le webhook GitHub

Pour chaque package sur Packagist :
1. Settings du package
2. "GitHub Webhook" → Activer
3. Copier l'URL du webhook
4. Ajouter dans GitHub Repository Settings :
   - Webhooks → Add webhook
   - URL : celle de Packagist
   - Events : Push, Create
   - Active : ✓

#### 6c. Tester la webhook

```bash
# Push un commit
git commit --allow-empty -m "test publish"
git push origin main

# Vérifier sur Packagist que les versions apparaissent
# curl https://repo.packagist.org/packages/arnaudmoncondhuy/synapse-core.json
```

### Étape 7 : Vérifier les versions

Une fois les webhooks actifs, chaque tag crée une version Packagist :

```bash
# Vérifier que Packagist voit les versions
curl https://repo.packagist.org/packages/arnaudmoncondhuy/synapse-core.json | jq '.versions'
```

Attendu :
```json
{
  "dev-main": {...},
  "0.260226": {...}
}
```

---

## 🔄 Migration des Utilisateurs Existants

### Communication officielle

Créer un fichier `MIGRATION.md` à la racine :

```markdown
# Migration depuis synapse-bundle

Si vous utilisiez `arnaudmoncondhuy/synapse-bundle` avant le 26 février 2026 :

## Option 1 : Migrer vers les 3 packages (recommandé)

Vos dépendances :
```bash
# Avant
composer require arnaudmoncondhuy/synapse-bundle

# Après
composer require \
  arnaudmoncondhuy/synapse-core:^0.26 \
  arnaudmoncondhuy/synapse-admin:^0.26 \
  arnaudmoncondhuy/synapse-chat:^0.26
```

Puis dans votre code, les namespaces changent :

```php
// Avant
use ArnaudMoncondhuy\SynapseBundle\Contract\AiToolInterface;

// Après
use ArnaudMoncondhuy\SynapseCore\Contract\AiToolInterface;
```

## Option 2 : Continuer avec meta-package (transitoire)

```bash
composer require arnaudmoncondhuy/synapse-bundle:^1.0
```

Cette version installe automatiquement les 3 packages (compatible).
```

### Update README root

```markdown
## Installation

### Nouvelle installation (recommandé)

```bash
composer require arnaudmoncondhuy/synapse-core
composer require arnaudmoncondhuy/synapse-admin
composer require arnaudmoncondhuy/synapse-chat
```

### Migration depuis l'ancien bundle

Voir [MIGRATION.md](./MIGRATION.md)
```

---

## 📊 Versioning Strategy

### Version 0.260226

- `0` = version majeure (pas encore 1.0)
- `26` = mois (février = 02, mais on utilise la date logique)
- `0226` = jour + micro (26 février)

Ou plus simple : `0.26.0` = février 2026, release 0

### Prochaines versions

```
0.26.0  → Avril 2026 : 0.26.1
0.26.1  → Mai 2026   : 0.26.2
...
1.0.0   → Stable (?)
```

---

## 🧪 Tester localement après publication

Une fois sur Packagist, tester dans **basile** :

```bash
# Modifier basile/composer.json
{
    "repositories": {
        "packagist": {
            "type": "composer"
        }
    },
    "require": {
        "arnaudmoncondhuy/synapse-core": "^0.26",
        "arnaudmoncondhuy/synapse-admin": "^0.26",
        "arnaudmoncondhuy/synapse-chat": "^0.26"
    }
}
```

```bash
cd basile
composer update

# Vérifier que tout fonctionne
php bin/console debug:router | grep synapse
# Devrait afficher 66 routes
```

---

## 🛠️ Dépannage courant

### "Package not found on Packagist"

**Cause** : Packagist n'a pas vu le tag ou la webhook est inactive
**Solution** :
- Vérifier le tag : `git tag -l`
- Vérifier la webhook : https://packagist.org/packages/arnaudmoncondhuy/synapse-core (Settings → Webhooks)
- Force update : https://packagist.org/api/update-package?username=USER&apiToken=TOKEN

### "Subdirectory packages/core not found"

**Cause** : Le chemin est incorrect
**Solution** : S'assurer que `packages/core/composer.json` existe

### "Namespace not PSR-4 autoloadable"

**Cause** : `"autoload": {"psr-4": {...}}` mal configuré
**Solution** : Vérifier que namespace et dossier `src/` correspondent

---

## ✅ Checklist Final

- [ ] 3 packages enregistrés manuellement sur Packagist
- [ ] Webhooks GitHub activées pour chaque package
- [ ] Tags git créés : `packages/core-0.260226` etc.
- [ ] Versions apparaissent sur Packagist
- [ ] `composer require arnaudmoncondhuy/synapse-core` fonctionne
- [ ] Basile fonctionne en récupérant de Packagist (test optionnel)
- [ ] MIGRATION.md créé pour les utilisateurs existants
- [ ] README root mis à jour avec les 3 packages

---

## Support

Pour questions sur Packagist :
- Docs Packagist : https://packagist.org/about
- FAQ monorepos : https://packagist.org/faq#how-do-i-handle-monorepos
- GitHub Webhook : https://docs.github.com/en/webhooks
