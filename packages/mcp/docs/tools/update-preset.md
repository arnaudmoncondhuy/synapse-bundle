# update_preset

Met à jour les paramètres de génération d'un preset LLM existant.

## Namespace

```
ArnaudMoncondhuy\SynapseMcp\Tool\UpdatePresetTool
```

## Prérequis

Requiert `PermissionCheckerInterface::canAccessAdmin() = true`.

## Paramètres

| Paramètre | Type | Requis | Description |
|-----------|------|--------|-------------|
| `key` | `string` | oui | Clé unique du preset à modifier |
| `name` | `?string` | non | Nouveau nom d'affichage |
| `generationTemperature` | `?float` | non | Température (0.0–2.0) |
| `generationTopP` | `?float` | non | Top-P nucleus sampling |
| `generationMaxOutputTokens` | `?int` | non | Limite de tokens en sortie |
| `streamingEnabled` | `?bool` | non | Activer ou désactiver le streaming |
| `isActive` | `?bool` | non | Activer ou désactiver le preset |

Tout paramètre laissé à `null` est laissé inchangé.

!!! note "Provider et modèle non modifiables"
    Le provider et le modèle ne sont **pas** modifiables via cet outil — ils définissent l'identité du preset. Pour changer de provider ou de modèle, créer un nouveau preset avec [`create_sandbox_preset`](sandbox.md#create_sandbox_preset).

## Réponse (succès)

```json
{
  "status": "success",
  "key": "gemini_flash",
  "changed": ["generationTemperature", "streamingEnabled"],
  "preset": {
    "key": "gemini_flash",
    "name": "Gemini Flash",
    "providerName": "gemini",
    "model": "gemini-2.5-flash",
    "isActive": true,
    "isSandbox": false,
    "temperature": 0.7,
    "topP": 0.95,
    "topK": null,
    "maxOutputTokens": 8192,
    "streamingEnabled": false
  },
  "timestamp": "2026-04-11T10:00:00+00:00"
}
```

## Voir aussi

- [`list_presets`](list-presets.md) — lister les presets disponibles
- [`create_sandbox_preset`](sandbox.md#create_sandbox_preset) — créer un preset temporaire
- [`delete_preset`](delete-preset.md) — supprimer un preset
