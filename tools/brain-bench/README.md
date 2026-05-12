# Brain Bench

> Outils de benchmark Brain v3 sur corpus réels. Lecture seule, adaptateurs côté hôte (charte §2.1).

## Pourquoi

Brain v3 a besoin de **mesurer la qualité** de ses extractions et synapses sur de vraies données pour pouvoir [calibrer](../../docs/brain/07-calibration.md) et [valider la qualité sémantique](../../docs/brain/00-charte.md) (charte §2.9 et §2.10). Ces outils servent à transformer un corpus tiers (par exemple les exports JSON Pipedrive de `stacks/weecom`) en `MemorySource` agnostiques que les extracteurs Brain peuvent ingérer.

## Discipline

- **Lecture seule absolue** sur le corpus source (`stacks/weecom/.tmp/`). Aucun écrit là-bas.
- **Adaptateurs côté hôte** : ces scripts ne sont PAS du noyau Brain. Ils traduisent une donnée métier (deal, note, activity) en `MemorySource` *générique*. Le mot "deal" peut apparaître dans le code de l'adaptateur ; il ne doit jamais traverser vers `src/Brain/`.
- **Pas de fixtures dans Git** : les sorties JSON volumineuses ne sont pas commitées. Les corpus de test reproductibles vivent dans `tests/Brain/Quality/Fixtures/` à partir du jalon 3.

## Outils disponibles

| Script | Rôle | Statut |
|---|---|---|
| `extract-corpus.php` | Lit les JSON Pipedrive (notes / activities / deals) et produit des `MemorySource` JSON normalisées | Jalon 2 |
| `score.php` | À venir — compare extractions vs annotations manuelles, calcule précision/rappel/F1 | Jalon 3 |

## Utilisation

### `extract-corpus.php`

```bash
# Lister les types de sources disponibles dans un dump weecom
php tools/brain-bench/extract-corpus.php --path=/home/ubuntu/stacks/weecom/.tmp/pipedrive/data --type=notes --limit=5

# Sortie : JSON Lines, un MemorySource par ligne, sur stdout
# Redirection :
php tools/brain-bench/extract-corpus.php --path=... --type=notes > /tmp/sources-notes.jsonl
```

Format de sortie (1 ligne par source) :

```json
{"provider":"webhook_pipedrive_note","external_id":"pipedrive-note-2","raw_payload":{"text":"...","timestamp":"...","actors":[...]}}
```

Le `raw_payload` est conçu pour être directement compatible avec :
- `SemanticExtractor` (extrait des faits stables depuis `text`)
- `EpisodicExtractor` (extrait un événement depuis `text` + `timestamp` + `actors`)
- `EncyclopedicExtractor` (chunke `text` si > seuil)

## Anonymisation (futur)

Au jalon 8 (ou avant si besoin), un script `anonymize.php` produira un corpus de test versionnable avec PII remplacées par des tokens stables (Alice42 → Alice42, mais le vrai prénom est masqué). Pour le jalon 2-3, on travaille en local sans publication.
