# Tests d'intégration Brain v3

> Tests qui nécessitent une vraie BDD PostgreSQL + pgvector. Pas dans `composer test` par défaut (lent et nécessitent Docker).

## Pourquoi un dossier dédié

À partir du **jalon 3** (Convergence mémorielle), le brain produit des synapses automatiques sur base de mesures objectives. Tester correctement ce comportement nécessite :

- Une vraie BDD relationnelle (pas SQLite — divergence sur JSON/UUID/vector)
- pgvector pour les futures recherches par similarité
- Isolation forte des autres bases (test ≠ dev ≠ corpus weecom)

## Lancer la BDD test

```bash
# Démarrer
docker compose -f tests/Integration/Brain/docker-compose.yaml up -d

# Vérifier
docker compose -f tests/Integration/Brain/docker-compose.yaml ps

# Arrêter (préserve les données)
docker compose -f tests/Integration/Brain/docker-compose.yaml stop

# Détruire (efface tout)
docker compose -f tests/Integration/Brain/docker-compose.yaml down -v
```

## Connexion

| Paramètre | Valeur |
|---|---|
| Host | `localhost` |
| Port | **`55432`** (custom pour éviter collision) |
| Database | `synapse_brain_test` |
| User | `brain_test` |
| Password | `brain_test` |
| DSN Doctrine | `postgresql://brain_test:brain_test@localhost:55432/synapse_brain_test?serverVersion=18&charset=utf8` |

## Lancer les tests d'intégration

```bash
# Démarre la BDD si pas déjà fait
docker compose -f tests/Integration/Brain/docker-compose.yaml up -d

# Variables d'env attendues par les tests
export DATABASE_URL='postgresql://brain_test:brain_test@localhost:55432/synapse_brain_test?serverVersion=18'

# Lance uniquement les tests d'intégration Brain
vendor/bin/phpunit -c qa/phpunit.xml.dist --testsuite=integration --filter='Brain'
```

## Schéma

À chaque test d'intégration, le setup applique les migrations `migrations-brain-v3/jalon-*/*.sql` dans l'ordre (via un script ou des fixtures Doctrine). À écrire au moment des premiers tests d'intégration (étape 4 du jalon 3).

## Non versionné

- Les **données de test** ne sont **jamais** versionnées (volume Docker local éphémère)
- Les **fixtures Brain** (corpus de qualité) sont versionnées dans `packages/core/tests/Brain/Quality/Fixtures/` — JSON normalisé, anonymisé si besoin

## Sécurité

- Mot de passe `brain_test` — local uniquement, ne **jamais** exposer au-delà de `localhost`
- Port 55432 non standard pour réduire le risque de connexion accidentelle
- Container isolé sans réseau partagé avec d'autres services

## Hors-scope

Cette infra est **uniquement** pour les tests d'intégration Brain. Elle ne se substitue **pas** :

- À une BDD dev locale pour lancer `synapse:doctor --init`
- À la BDD weecom (corpus de dogfooding, lecture seule)
- À une BDD basile (app hôte de test)
