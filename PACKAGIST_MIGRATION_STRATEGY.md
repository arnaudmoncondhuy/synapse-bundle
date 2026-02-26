# Stratégie de Migration Packagist : De 1 Bundle à 3 Packages

## Contexte
- **Ancien package** : `arnaudmoncondhuy/synapse-bundle` (monolithique, publié sur Packagist)
- **Nouveaux packages** : `arnaudmoncondhuy/synapse-core`, `synapse-admin`, `synapse-chat` (dans `/packages/`)
- **Utilisateurs existants** : Dépendent de `arnaudmoncondhuy/synapse-bundle`

---

## Phase 1 : Versioning des 3 Nouveaux Packages

### Stratégie recommandée : Démarrer à v0.1.0

Pourquoi `0.1.0` et pas `1.0.0` ?
- Les 3 packages sont nouveaux (même si le code existe depuis longtemps)
- C'est une réorganisation majeure, pas une version stable finale
- Cela permet des corrections avant v1.0.0 public
- Compatible avec SemVer (0.x.y = version instable)

### Structure de versioning pour chaque package

**synapse-core** (fondations)
- v0.1.0 : Release initiale du split (contient tous les services, clients, entités)
- Dépendances : `symfony/*:^7.0|^8.0`, `doctrine/orm:^2.19|^3.0`

**synapse-admin** (interface admin)
- v0.1.0 : Release initiale avec Admin V2
- Dépend de : `synapse-core:^0.1`

**synapse-chat** (widget chat)
- v0.1.0 : Release initiale
- Dépend de : `synapse-core:^0.1`

---

## Phase 2 : Préparer les packages pour Packagist

### 2.1 - Structure de chaque package/*/composer.json

**packages/core/composer.json**
```json
{
    "name": "arnaudmoncondhuy/synapse-core",
    "description": "Synapse Core - LLM Integration Framework (Gemini, OVH AI, OpenAI compatible)",
    "type": "symfony-bundle",
    "license": "MIT",
    "version": "0.1.0",
    "require": {
        "php": ">=8.2",
        "symfony/framework-bundle": "^7.0|^8.0",
        "symfony/security-bundle": "^8.0",
        "symfony/twig-bundle": "^7.0|^8.0",
        "doctrine/orm": "^2.19|^3.0",
        "doctrine/doctrine-bundle": "^2.10|^3.0"
    },
    "autoload": {
        "psr-4": {
            "ArnaudMoncondhuy\\SynapseCore\\": "src/"
        }
    }
}
```

**packages/admin/composer.json**
```json
{
    "name": "arnaudmoncondhuy/synapse-admin",
    "description": "Synapse Admin - Complete LLM Admin Interface (Providers, Presets, Conversations, Memory)",
    "type": "symfony-bundle",
    "license": "MIT",
    "version": "0.1.0",
    "require": {
        "php": ">=8.2",
        "symfony/framework-bundle": "^7.0|^8.0",
        "symfony/twig-bundle": "^7.0|^8.0",
        "arnaudmoncondhuy/synapse-core": "^0.1"
    },
    "autoload": {
        "psr-4": {
            "ArnaudMoncondhuy\\SynapseAdmin\\": "src/"
        }
    }
}
```

**packages/chat/composer.json**
```json
{
    "name": "arnaudmoncondhuy/synapse-chat",
    "description": "Synapse Chat - Embeddable Chat Widget for LLM Integration",
    "type": "symfony-bundle",
    "license": "MIT",
    "version": "0.1.0",
    "require": {
        "php": ">=8.2",
        "symfony/framework-bundle": "^7.0|^8.0",
        "symfony/twig-bundle": "^7.0|^8.0",
        "symfony/stimulus-bundle": "^2.0",
        "arnaudmoncondhuy/synapse-core": "^0.1"
    },
    "autoload": {
        "psr-4": {
            "ArnaudMoncondhuy\\SynapseChat\\": "src/"
        }
    }
}
```

### 2.2 - Ajouter les fichiers obligatoires par package

Chaque package a besoin :
- ✅ `composer.json` (version v0.1.0)
- ✅ `LICENSE` (copie du principal)
- ✅ `README.md` (description du package spécifique)
- ✅ `src/SynapseCoreBundle.php` (et admin/chat variants)
- ✅ `src/Infrastructure/DependencyInjection/SynapseCoreExtension.php` (et variants)

---

## Phase 3 : Gestion de l'Ancien Package

### Option A : Créer une version 1.0.0 "Meta" du bundle existant

**Objectif** : Maintenir la compatibilité pour les utilisateurs existants

```php
// arnaudmoncondhuy/synapse-bundle v1.0.0 (nouveau) → redirige vers les 3 packages
{
    "name": "arnaudmoncondhuy/synapse-bundle",
    "description": "[DEPRECATED] Use synapse-core, synapse-admin, synapse-chat instead",
    "type": "meta-package",
    "version": "1.0.0",
    "require": {
        "arnaudmoncondhuy/synapse-core": "^0.1",
        "arnaudmoncondhuy/synapse-admin": "^0.1",
        "arnaudmoncondhuy/synapse-chat": "^0.1"
    },
    "require-dev": {
        "symfony/test-pack": "^1.2"
    }
}
```

**Avantages** :
- Les utilisateurs qui font `composer require arnaudmoncondhuy/synapse-bundle:^1.0` récupèrent les 3 packages automatiquement
- Pas de rupture complète, transition progressive possible

**Désavantages** :
- Peut créer de la confusion entre v1.0.0 (meta) et les v0.x.y réels

### Option B : Marquer l'ancien package comme DEPRECATED

```yaml
# Dans le README du repository
⚠️ **DEPRECATED** - Ce package a été réorganisé en 3 packages séparés :
- arnaudmoncondhuy/synapse-core
- arnaudmoncondhuy/synapse-admin
- arnaudmoncondhuy/synapse-chat

Veuillez migrer selon le guide ci-dessous.
```

**Avantages** :
- Clair et transparent pour les utilisateurs
- Force une migration explicite (meilleur long terme)

**Recommandation** : Option B + publier v1.0.0 meta pour compatibilité

---

## Phase 4 : Plan de Publication

### Étape 1 : Publier les 3 packages séparés

```bash
# Depuis la racine du monorepo
cd packages/core
git tag v0.1.0  # Attention : tag au niveau du monorepo, pas du package
composer validate
# Puis publier sur Packagist via l'interface web

cd ../admin
git tag v0.1.0
composer validate
# Publier...

cd ../chat
git tag v0.1.0
composer validate
# Publier...
```

**Important** : Tags au niveau du monorepo avec namespace
```bash
# Exemple :
git tag packages/core-v0.1.0
git tag packages/admin-v0.1.0
git tag packages/chat-v0.1.0
```

### Étape 2 : Mettre à jour l'ancien package

```bash
# À la racine du monorepo, créer une nouvelle version
vi composer.json  # Remplacer les requires directs par meta-package

git tag synapse-bundle-v1.0.0  # Tag pour la version meta
```

### Étape 3 : Mettre à jour le README principal

```markdown
# Synapse Bundle - Réorganisation v0.1.0

## Migration depuis l'ancien bundle (v0.x.x)

Ancien :
```bash
composer require arnaudmoncondhuy/synapse-bundle
```

Nouveau (recommandé) :
```bash
composer require \
  arnaudmoncondhuy/synapse-core \
  arnaudmoncondhuy/synapse-admin \
  arnaudmoncondhuy/synapse-chat
```

Ou pour compatibilité :
```bash
composer require arnaudmoncondhuy/synapse-bundle:^1.0
# Cela installe automatiquement les 3 packages
```
```

---

## Phase 5 : Workflow GitHub Actions pour Release

Créer `.github/workflows/release.yml` :

```yaml
name: Release to Packagist

on:
  push:
    tags:
      - 'packages/core-v*'
      - 'packages/admin-v*'
      - 'packages/chat-v*'

jobs:
  release:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4

      - name: Trigger Packagist Update
        env:
          PACKAGIST_USERNAME: ${{ secrets.PACKAGIST_USERNAME }}
          PACKAGIST_TOKEN: ${{ secrets.PACKAGIST_TOKEN }}
        run: |
          # Pour chaque package, envoyer un webhook à Packagist
          if [[ "${{ github.ref }}" =~ packages/core-v ]]; then
            curl -X POST https://packagist.org/api/update-package \
              -d username=$PACKAGIST_USERNAME \
              -d apiToken=$PACKAGIST_TOKEN \
              -d repository[url]=https://github.com/arnaudmoncondhuy/synapse-bundle.git
          fi
```

---

## Checklist avant publication

### Pour chaque package

- [ ] `composer.json` a une version explicite `"version": "0.1.0"`
- [ ] `LICENSE` existe (MIT)
- [ ] `README.md` est présent et décrit le package
- [ ] `src/SynapseCoreBundle.php` existe
- [ ] `src/Infrastructure/DependencyInjection/SynapseCoreExtension.php` existe
- [ ] Pas de dépendances circulaires entre les packages
- [ ] `composer validate` passe
- [ ] Tests unitaires passent
- [ ] Namespace PSR-4 correct : `ArnaudMoncondhuy\SynapseCore\`

### Pour le monorepo

- [ ] `/packages/core/composer.json` défini
- [ ] `/packages/admin/composer.json` défini
- [ ] `/packages/chat/composer.json` défini
- [ ] Root `composer.json` utilise `"repositories": [{"type": "path", "url": "packages/*"}]`
- [ ] Tag git pour chaque package : `packages/core-v0.1.0`
- [ ] Documentation de migration ajoutée au README

---

## Fichiers à créer/modifier

```
packages/
├── core/
│   ├── LICENSE (copie)
│   ├── README.md (spécifique au core)
│   └── composer.json (avec "version": "0.1.0")
│
├── admin/
│   ├── LICENSE (copie)
│   ├── README.md (spécifique à l'admin)
│   └── composer.json (avec "version": "0.1.0")
│
└── chat/
    ├── LICENSE (copie)
    ├── README.md (spécifique au chat)
    └── composer.json (avec "version": "0.1.0")

.github/workflows/
├── docs.yml (existant)
└── release.yml (nouveau - webhook Packagist)

Racine :
├── composer.json (v1.0.0 meta ou abandonné)
├── README.md (mise à jour avec migration guide)
└── PACKAGIST_MIGRATION_STRATEGY.md (ce document)
```

---

## Recommandation finale

1. **Court terme (aujourd'hui)** :
   - Ajouter `LICENSE` et `README.md` à chaque package
   - Mettre à jour les `composer.json` avec version `0.1.0`
   - Créer les tags git appropriés

2. **Moyen terme (cette semaine)** :
   - Publier les 3 packages sur Packagist manuellement
   - Ajouter le webhook release dans GitHub

3. **Long terme** :
   - Attendre les retours d'utilisateurs
   - Passer à v0.2.0 si corrections nécessaires
   - Puis v1.0.0 après stabilisation
