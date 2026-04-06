# list_presets

Liste tous les presets de modèle Synapse disponibles avec leur configuration.

## Namespace

```
ArnaudMoncondhuy\SynapseMcp\Tool\ListPresetsTool
```

## Prérequis

Requiert `PermissionCheckerInterface::canAccessAdmin() = true`.

## Paramètres

| Paramètre | Type | Défaut | Description |
|-----------|------|--------|-------------|
| `includeSandbox` | `?bool` | `false` | Si `true`, inclut également les presets sandbox temporaires |

## Réponse

```json
{
  "status": "success",
  "count": 2,
  "presets": [
    {
      "key": "fast_creative",
      "name": "Créatif Rapide",
      "providerName": "gemini",
      "model": "gemini-2.5-flash",
      "isActive": true,
      "isSandbox": false,
      "temperature": 1.0,
      "topP": 0.95,
      "topK": 40,
      "maxOutputTokens": null,
      "streamingEnabled": true
    }
  ],
  "timestamp": "2026-04-06T10:00:00+00:00"
}
```

## Champs de sortie

| Champ | Description |
|-------|-------------|
| `key` | Clé unique du preset |
| `name` | Nom lisible |
| `providerName` | Nom du provider (ex: `gemini`, `openai`, `ovh`) |
| `model` | Identifiant du modèle LLM |
| `isActive` | Preset actuellement actif dans le système |
| `isSandbox` | Preset temporaire créé via MCP |
| `temperature` | Température de génération (0.0 — 2.0) |
| `topP` | Nucleus sampling (0.0 — 1.0) |
| `topK` | Filtrage top-K (nullable si non supporté par le modèle) |
| `maxOutputTokens` | Limite de tokens de sortie (null = illimité) |
| `streamingEnabled` | Streaming SSE activé |

## Comportement sandbox

Par défaut (`includeSandbox=false`), l'outil appelle `findAllPresets()` qui exclut les presets sandbox. Avec `includeSandbox=true`, il appelle `findAll()` qui les inclut.

## Voir aussi

- [`create_sandbox_preset`](sandbox.md#create_sandbox_preset) — créer un preset temporaire
- [Presets & Tons](../../../core/docs/guides/tones-presets.md) — gérer les presets depuis l'admin
