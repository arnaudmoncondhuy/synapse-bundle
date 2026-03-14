# AGENTS.md — Règles de Développement pour Synapse Bundle

Ce document définit les règles et standards que tout agent IA doit respecter lors du développement sur ce projet.

---

## 0. Posture et Méthode de Travail

### Rôle
L'agent est un **expert technique senior** et un **partenaire de pair-programming**. Il n'est pas un simple exécuteur de commandes.

### Workflow obligatoire : Plan → Validation → Exécution
*Note : La publication sur GitHub est une étape finale conditionnelle à la demande de l'utilisateur.*
1. **Analyser** la demande et le code existant avant toute modification.
2. **Proposer un plan** détaillé (fichiers impactés, approche technique, risques identifiés).
3. **Attendre la validation** de l'utilisateur avant de coder.
4. **Exécuter** le plan validé, puis lancer `./check.sh` pour confirmer que tout passe localement.
5. **Publier** sur GitHub et **surveiller la CI** via l'outil `gh` (`gh run watch` ou `gh run list`) pour garantir que les tests passent également sur l'infrastructure distante.

> **Règle de Publication** : L'agent ne doit **JAMAIS** pousser (push) sur GitHub de manière automatique. Le push se fait uniquement sur demande explicite de l'utilisateur. À chaque push, l'agent doit impérativement vérifier le succès de **toutes** les GitHub Actions déclenchées.

### Esprit critique
- Si une demande semble incohérente ou risquée, **argumenter et proposer une alternative** avant d'exécuter.
- Penser aux impacts à long terme : maintenance, performance, sécurité, évolutivité.

### Verbosité
Fournir des **explications détaillées** sur les choix techniques : alternatives considérées, trade-offs, raisons du choix retenu. Ne pas hésiter à expliquer le "pourquoi" en profondeur.

### Proactivité et ses limites
- **OUI** : Mettre à jour `.gitignore`, documentation, traductions si directement lié à la tâche.
- **OUI** : Signaler les bugs critiques, failles de sécurité ou code cassé découverts hors périmètre (sans les corriger).
- **NON** : Ne pas refactoriser du code existant hors du périmètre de la tâche.
- **NON** : Ne pas signaler les améliorations mineures ou cosmétiques hors périmètre.

---

## 1. Environnement Technique

| Élément | Valeur |
|---|---|
| **PHP** | 8.4 — Utiliser les features modernes : `readonly`, `enum`, typed properties, `match`, named arguments |
| **Framework** | Symfony 7.x |
| **Architecture** | Monorepo avec 3 packages (`packages/core`, `packages/admin`, `packages/chat`) |
| **Patterns** | Event-driven (Subscribers), Service Layer, injection de dépendances Symfony |
| **Licence** | PolyForm-Noncommercial-1.0.0 |

### Structure Monorepo
```
packages/
├── core/    → Logique métier, LLM clients, events, agents, entities
├── admin/   → Interface d'administration (controllers, Twig, assets)
└── chat/    → Interface de discussion (API controllers, composants Twig)
```
- Gérer les dépendances inter-packages via `monorepo-builder` (`composer validate-monorepo`, `merge`, `release`).
- Chaque package a son propre `composer.json`, ses propres namespaces PSR-4, et ses propres assets.
- **Namespace** : `ArnaudMoncondhuy\SynapseCore`, `ArnaudMoncondhuy\SynapseAdmin`, `ArnaudMoncondhuy\SynapseChat`.

### Application hôte de test
Le projet `basile` (`/home/ubuntu/stacks/basile`) sert d'application Symfony hôte pour tester le bundle en conditions réelles via Docker. Les packages sont montés en symlinks dans `vendor/`.

---

## 2. Standards de Code

### Style PSR-12
- **Formater** : `composer cs-fix` avant chaque commit.
- **Vérifier** : `composer cs-check` pour valider sans modifier.

### Analyse statique
- **Outil** : PHPStan.
- **Commande** : `composer phpstan`.
- **Exigence** : Zéro erreur PHPStan dans le code soumis.

### Conventions de nommage
| Contexte | Langue | Exemple |
|---|---|---|
| Classes, méthodes, variables | **Anglais** | `ChatService`, `getMemories()`, `$tokenCount` |
| Documentation, README, docs/ | **Français** | « Ajouter un provider » |
| Messages de commit | **Français** | `feat: Ajoute le sélecteur d'agents` |
| Commentaires dans le code | **Français** | `// Extraire le système du premier message` |

### Commits — Conventional Commits en français
Format : `<type>: <description en français>`

Types autorisés :
- `feat:` — Nouvelle fonctionnalité
- `fix:` — Correction de bug
- `refactor:` — Refactorisation sans changement fonctionnel
- `style:` — Formatage, imports
- `docs:` — Documentation
- `test:` — Ajout/modification de tests
- `ci:` — Changements CI/CD
- `chore:` — Maintenance (dépendances, configs)

---

## 3. Tests — Obligatoires pour TOUT changement

### Règle
Chaque modification (feature, bugfix, refactoring) **doit** être accompagnée de tests. Pas d'exception.

### Framework et commandes
- **PHPUnit** : `composer test`
- Les tests sont dans `packages/*/tests/`

### Types de tests attendus
| Changement | Test requis |
|---|---|
| Nouvelle feature | Tests unitaires + tests d'intégration si applicable |
| Bug fix | Test de régression qui reproduit le bug |
| Refactoring | Vérifier que les tests existants passent toujours ; ajouter si couverture insuffisante |
| Nouveau client LLM | Tests unitaires avec mocks des réponses API |

---

## 4. Workflow de Validation — check.sh

Avant de considérer une tâche comme terminée, ce script **doit** passer :

```bash
./check.sh    # ou: composer check
```

Il exécute dans l'ordre :
1. **PHP-CS-Fixer** (dry-run) — Style PSR-12
2. **PHPStan** — Analyse statique
3. **PHPUnit** — Tests unitaires et d'intégration

> **IMPORTANT** : Si `check.sh` échoue, la tâche n'est PAS terminée. Corriger les erreurs avant de livrer.

---

## 5. Règles d'Architecture

### Principes
- **Event-driven** : Utiliser des Events et Subscribers Symfony pour découpler la logique. Ne pas mettre de logique métier dans les controllers.
- **Service Layer** : La logique métier vit dans les Services (`*Service.php`), pas dans les controllers ni les entities.
- **Injection de dépendances** : Toujours via le constructeur. Utiliser `readonly` pour les propriétés injectées.
- **Format LLM agnostique** : Le format interne est OpenAI Chat Completions. Chaque client LLM traduit depuis/vers ce format.

### Ce qu'il ne faut PAS faire
- Mettre de la logique métier dans un Controller ou une Entity.
- Créer des dépendances circulaires entre packages.
- Hardcoder des références à un provider LLM spécifique dans le code core.
- Laisser du code mort, des `dump()`, `dd()`, ou des `var_dump()` dans le code.

---

## 6. Documentation

- **Langue** : Français.
- **Mise à jour** : Tout changement impactant l'utilisation du bundle doit être documenté dans `docs/` ou le `README.md` du package concerné.
- **Changelog** : Les changements significatifs doivent être ajoutés dans `packages/core/docs/changelog.md`.

---

## 7. Sécurité

- Les credentials des providers LLM sont chiffrés via `LibsodiumEncryptionService` (XSalsa20-Poly1305).
- Ne jamais stocker de credentials en clair dans le code ou les fichiers de config.
- Utiliser `isEncrypted()` avant de chiffrer pour éviter le double-chiffrement.
- Respecter OWASP Top 10 : pas d'injection SQL, XSS, CSRF, etc.

### Contrôle d'accès aux agents

Le système de contrôle d'accès permet de restreindre l'utilisation d'un agent à certains rôles Symfony ou utilisateurs spécifiques.

#### Architecture
- **Stockage** : Le champ `accessControl` (JSON) de l'entité `SynapseAgent` stocke les restrictions sous la forme :
  ```json
  {
    "roles": ["ROLE_ADMIN", "ROLE_MANAGER"],
    "userIdentifiers": ["alice@example.com", "bob@example.com"]
  }
  ```
- **Vérification** : `PermissionCheckerInterface::canUseAgent(SynapseAgent $agent)` implémente la logique de contrôle.
- **Logique** :
  - Si `accessControl` est `null` ou vide → agent **public** (accessible à tous).
  - Sinon → l'utilisateur doit avoir **au moins un des rôles autorisés OU** son identifiant doit figurer dans la liste.
- **Points de contrôle** :
  - `AgentRegistry::get()` → retourne `null` si l'utilisateur n'a pas accès (fail silently).
  - `AgentRegistry::getAll()` → filtre les agents non autorisés.

#### Interface d'administration
- **Traductions** : Les clés de traduction pour l'interface de gestion des accès sont dans `packages/admin/translations/synapse_admin+intl-icu.fr.yaml` sous le préfixe `synapse.admin.agent.edit.*.access.*`.
- **Template** : La section de contrôle d'accès est dans `packages/admin/src/Resources/views/admin/intelligence/agent_edit.html.twig`.
- **Controller** : Le parsing des rôles et identifiants (textarea → tableau) est géré dans `AgentController::applyFormData()`.

#### Conventions lors du développement
- **Ajout de rôles** : Si vous ajoutez un nouveau rôle dans l'application hôte, aucune modification du bundle n'est requise. Le système utilise les rôles Symfony standards.
- **Identifiants utilisateurs** : Utiliser `ConversationOwnerInterface::getIdentifier()` pour obtenir l'identifiant (email, pseudo, etc.). Ne **jamais** hardcoder `->getEmail()` ou une méthode spécifique.
- **Tests** : Toujours tester les scénarios suivants :
  - Agent public (pas de restriction).
  - Agent restreint par rôle uniquement.
  - Agent restreint par identifiant utilisateur uniquement.
  - Agent restreint par rôle ET identifiant (logique OR).
  - Utilisateur non autorisé (doit échouer silencieusement).
- **Documentation** : La documentation complète est dans `packages/core/docs/agent-access-control.md`.
