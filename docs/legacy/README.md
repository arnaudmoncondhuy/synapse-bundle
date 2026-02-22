# Documentation Legacy - Système de Réflexion (Thinking)

Ce dossier contient la documentation relative à la **migration du système de réflexion legacy** vers le **thinking natif de Gemini**.

## Historique

- **Version 1.0.0** : Système de réflexion manuel utilisant les balises `<thinking>...</thinking>`
- **Version 1.1.0+** : Migration vers le **thinking natif via `thinkingConfig`** de l'API Gemini

## Fichiers Archivés

| Fichier | Description |
|---------|-------------|
| `CHANGELOG_THINKING.md` | Historique détaillé de la migration v1.0 → v1.1 |
| `PLAN_THINKING_CONFIG.md` | Plan d'implémentation technique (détails de développement) |
| `README_THINKING.md` | Documentation du thinking natif (pour utilisateurs finaux) |
| `TESTS_THINKING.md` | Suite complète de tests de validation |

## Pourquoi Cette Archive ?

Le code source a été **nettoyé** pour supprimer toutes les références au système legacy :
- ✅ Suppression du wrapping `<thinking>` tags dans `ChatService.php`
- ✅ Suppression du parsing regex fragile des balises
- ✅ Utilisation directe de la réflexion native de Gemini

La documentation reste archivée à titre historique et de référence pour comprendre l'évolution du projet.

## Références Actuelles

Pour la configuration actuelle du thinking :
- `config/packages/synapse.yaml` : Configuration YAML
- `src/Service/Infra/GeminiClient.php` : Client API avec support `thinkingConfig`
- `src/Service/PromptBuilder.php` : Construction des prompts techniques

## Notes de Nettoyage (v1.1.1+)

**Date** : 2026-02-22
**Changements** :
1. `ChatService.php:235-244` → Suppression du wrapping/parsing `<thinking>`
2. `ChatApiController.php:186-188` → Suppression du nettoyage regex inutile
3. Documentation → Archivée dans `docs/legacy/`

Le système de réflexion fonctionne maintenant **uniquement via les capacités natives de Gemini**.
